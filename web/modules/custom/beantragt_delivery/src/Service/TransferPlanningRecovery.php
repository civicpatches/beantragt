<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Durable planning intents bridge application commit and outbox creation.
 */
final class TransferPlanningRecovery {

  public const WINDOW = 2592000;

  /**
   * Constructs bounded, local-only recovery.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ScopeAccess $scopeAccess,
    private readonly TransferPlanner $planner,
    private readonly TimeInterface $time,
    private readonly ?ConfigFactoryInterface $config = NULL,
  ) {}

  /**
   * Declares value-free intent storage with one decision per application.
   */
  public static function schema(): array {
    return [
      'description' => 'Durable eligibility recorded at the confirmed submission trigger.',
      'fields' => [
        'application_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'application_uuid' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE],
        'assignment_uuid' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => FALSE],
        'scope_group_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'binding_checksum' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => FALSE],
        'source_sha256' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => FALSE],
        'status' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE],
        'code' => ['type' => 'varchar_ascii', 'length' => 128, 'not null' => TRUE, 'default' => ''],
        // Which trigger recorded the intent: submission or status.
        'trigger_source' => ['type' => 'varchar_ascii', 'length' => 16, 'not null' => TRUE, 'default' => 'submission'],
        // The person whose status change triggered (status only).
        'actor_uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'created' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
      ],
      'primary key' => ['application_id'],
      'indexes' => ['pending' => ['status', 'changed']],
    ];
  }

  /**
   * Records eligibility in the application transaction, before its callback.
   *
   * A submission under a rule that plans on a status or only by staff is
   * decided at once (code integration.trigger_deferred), so cron never plans
   * it. A status trigger replaces the intent of the submission and keeps the
   * person who set the status, for a live approval check on recovery.
   */
  public function record(ApplicationInterface $application, string $trigger_source = 'submission', ?int $actor_uid = NULL): void {
    if (!$this->database->schema()->tableExists('beantragt_transfer_plan')) {
      // Installation and the pre-update bootstrap have no intent store yet.
      return;
    }
    if (!$application->isEmailConfirmed() || $this->planner->isAnonymised($application)) {
      return;
    }
    $scope = $this->scopeAccess->integrationScope($application);
    $identity = [];
    $code = '';
    try {
      $identity = $this->planner->planningIdentity($application);
    }
    catch (IntegrationException $exception) {
      $code = $exception->errorCode;
    }
    catch (\Throwable) {
      // The application can remain internal if configuration cannot be read.
      $code = 'integration.planning_failed';
    }
    $eligible = $identity !== [] && ($this->config === NULL || $this->config->get('beantragt_delivery.settings')->get('enabled'));
    if ($eligible && $trigger_source === 'submission' && ($this->planner->triggerOf($application)['mode'] ?? 'submission') !== 'submission') {
      $eligible = FALSE;
      $code = 'integration.trigger_deferred';
    }
    $fields = [
      'application_id' => (int) $application->id(),
      'application_uuid' => $application->uuid(),
      'assignment_uuid' => $identity['assignment_uuid'] ?? NULL,
      'binding_checksum' => $identity['binding_checksum'] ?? NULL,
      'source_sha256' => $identity['source_sha256'] ?? NULL,
      'scope_group_id' => $scope ?: NULL,
      'status' => $eligible ? 'pending' : 'decided',
      'code' => $code,
      'trigger_source' => $trigger_source === 'status' ? 'status' : 'submission',
      'actor_uid' => $trigger_source === 'status' && $actor_uid !== NULL && $actor_uid > 0 ? $actor_uid : NULL,
      'created' => $this->time->getCurrentTime(),
      'changed' => $this->time->getCurrentTime(),
    ];
    $merge = $this->database->merge('beantragt_transfer_plan')->key('application_id', (int) $application->id())->insertFields($fields);
    if ($trigger_source === 'status') {
      // The status change is a new intent; the submission one is history.
      $update = $fields;
      unset($update['application_id']);
      $merge->updateFields($update);
    }
    $merge->execute();
  }

  /**
   * Marks the committed callback complete, including deliberate non-delivery.
   */
  public function complete(int $application_id): void {
    if (!$this->database->schema()->tableExists('beantragt_transfer_plan')) {
      return;
    }
    $this->database->update('beantragt_transfer_plan')
      ->fields([
        'status' => 'decided',
        'changed' => $this->time->getCurrentTime(),
      ])
      ->condition('application_id', $application_id)
      ->execute();
  }

  /**
   * Closes a pending status intent because its trigger status was left.
   *
   * A rollback or any change out of the trigger status makes the earlier
   * status change void for planning; a submission intent stays untouched.
   */
  public function revokeStatusIntent(int $application_id): void {
    if (!$this->database->schema()->tableExists('beantragt_transfer_plan')) {
      return;
    }
    $this->database->update('beantragt_transfer_plan')
      ->fields([
        'status' => 'decided',
        'code' => 'integration.trigger_reverted',
        'changed' => $this->time->getCurrentTime(),
      ])
      ->condition('application_id', $application_id)
      ->condition('status', 'pending')
      ->condition('trigger_source', 'status')
      ->execute();
  }

  /**
   * Marks one intent decided with a code.
   */
  private function decide(int $application_id, string $code): void {
    $this->database->update('beantragt_transfer_plan')
      ->fields([
        'status' => 'decided',
        'code' => $code,
        'changed' => $this->time->getCurrentTime(),
      ])
      ->condition('application_id', $application_id)
      ->execute();
  }

  /**
   * Recovers at most fifty recent intents; planning itself is idempotent.
   */
  public function recover(): int {
    $rows = $this->database->select('beantragt_transfer_plan', 'p')->fields('p')
      ->condition('status', 'pending')->condition('created', $this->time->getCurrentTime() - self::WINDOW, '>=')
      ->orderBy('changed')->range(0, 50)->execute()->fetchAll();
    $count = 0;
    foreach ($rows as $row) {
      if ($row->code !== '' && (int) $row->changed > $this->time->getCurrentTime() - 60) {
        continue;
      }
      $application = $this->entities->getStorage('application')->load($row->application_id);
      if (!$application instanceof ApplicationInterface || $this->planner->isAnonymised($application)) {
        $this->complete((int) $row->application_id);
        continue;
      }
      if ($application->uuid() !== $row->application_uuid) {
        $this->complete((int) $row->application_id);
        continue;
      }
      try {
        if ($this->config !== NULL && !$this->config->get('beantragt_delivery.settings')->get('enabled')) {
          $this->complete((int) $row->application_id);
          continue;
        }
        $identity = $this->planner->planningIdentity($application);
        if ($identity['assignment_uuid'] !== $row->assignment_uuid
          || !hash_equals((string) $row->binding_checksum, $identity['binding_checksum'])
          || !hash_equals((string) $row->source_sha256, $identity['source_sha256'])) {
          // Changed eligibility is a staff decision, never an automatic send.
          $this->complete((int) $row->application_id);
          continue;
        }
        if (($row->trigger_source ?? 'submission') === 'status') {
          // The status change is the human act: once it was reverted or the
          // case moved on, sending is a staff decision again.
          $trigger = $this->planner->triggerOf($application);
          if ($trigger === NULL || $trigger['mode'] !== 'status' || !$this->planner->currentStatusHasFlag($application, $trigger['flag'])) {
            $this->decide((int) $row->application_id, 'integration.trigger_reverted');
            continue;
          }
          // Plans, and approves only while the person still holds the right.
          $this->planner->planOnStatus($application, (int) ($row->actor_uid ?? 0));
        }
        else {
          $this->planner->plan($application, NULL, 0, 'submission');
        }
        $this->complete((int) $row->application_id);
        $count++;
      }
      catch (IntegrationException $exception) {
        $this->database->update('beantragt_transfer_plan')->fields([
          'code' => $exception->errorCode,
          'changed' => $this->time->getCurrentTime(),
          'status' => TransferFailures::recoverable($exception->errorCode)
          && in_array($exception->errorCode, TransferFailures::PLANNING_RETRY, TRUE) ? 'pending' : 'decided',
        ])
          ->condition('application_id', $row->application_id)->execute();
      }
    }
    return $count;
  }

  /**
   * Exposes the bounded recovery backlog without any case data.
   */
  public function pendingCount(int $scope): int {
    if (!$this->database->schema()->tableExists('beantragt_transfer_plan')) {
      // Lists stay usable before update 10009 has installed the intent store.
      return 0;
    }
    return (int) $this->database->select('beantragt_transfer_plan', 'p')->condition('status', 'pending')
      ->condition('scope_group_id', $scope)
      ->condition('created', $this->time->getCurrentTime() - self::WINDOW, '>=')->countQuery()->execute()->fetchField();
  }

}
