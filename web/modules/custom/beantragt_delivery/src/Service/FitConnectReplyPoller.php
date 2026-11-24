<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Uuid\Uuid;

/**
 * Imports verified replies into existing applications before acceptance.
 */
final class FitConnectReplyPoller {

  private const MAX_BOUND_CONTEXTS = 5;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FitConnectAccessTokenClient $tokens,
    private readonly FitConnectReplyApiClient $api,
    private readonly FitConnectReplyVerifier $verifier,
    private readonly FitConnectCaseRepository $cases,
    private readonly ExternalResponseService $responses,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApplicationDependentMutationLock $mutationLock,
    private readonly LockBackendInterface $lock,
    private readonly DeliveryLogRepository $logs,
    private readonly StateInterface $state,
    private readonly ?IntegrationCredentialProfiles $profiles = NULL,
    private readonly ?IntegrationRepository $integrations = NULL,
  ) {}

  /**
   * One bounded batch; all returned diagnostics exclude payload and secrets.
   */
  public function poll(int $limit = 50): array {
    $limit = max(1, min(50, $limit));
    $settings = FitConnectSettings::fromConfig($this->configFactory);
    $result = ['enabled' => $settings->replyEnabled, 'imported' => 0, 'accepted' => 0, 'skipped' => 0, 'failed' => 0];
    if ($settings->replyEnabled) {
      try {
        $result = $this->pollSettings($settings, $limit);
      }
      catch (\Throwable $exception) {
        if ($this->profiles === NULL) {
          throw $exception;
        }
        // A broken legacy configuration must not starve independent profiles.
        $result['failed']++;
      }
    }
    if ($this->profiles !== NULL) {
      $cursor_key = 'beantragt_delivery.reply_context_cursor';
      $ids = $this->cases->boundContextLogIds(self::MAX_BOUND_CONTEXTS, (int) $this->state->get($cursor_key, 0));
      if ($ids === []) {
        $ids = $this->cases->boundContextLogIds(self::MAX_BOUND_CONTEXTS);
      }
      $seen = [];
      foreach ($ids as $id) {
        $row = $this->logs->load($id);
        $key = $row === NULL ? '' : $this->contextKey($row);
        if (!isset($seen[$key]) && count($seen) >= self::MAX_BOUND_CONTEXTS) {
          break;
        }
        $this->state->set($cursor_key, $id);
        if ($row === NULL || isset($seen[$key])) {
          continue;
        }
        $seen[$key] = TRUE;
        $result['enabled'] = TRUE;
        try {
          $bound = $this->profiles->settings($row);
          if (!$bound->replyEnabled) {
            $result['skipped']++;
            continue;
          }
          $batch = $this->pollSettings($bound, $limit, $row);
          foreach (['imported', 'accepted', 'skipped', 'failed'] as $counter) {
            $result[$counter] += $batch[$counter];
          }
          if (!empty($batch['busy'])) {
            $result['busy'] = TRUE;
          }
        }
        catch (\Throwable) {
          // A revoked profile never falls back to another credential context.
          $result['failed']++;
        }
      }
    }
    return $result;
  }

  /**
   * Processes one credential/tenant/destination context without shared offsets.
   */
  private function pollSettings(FitConnectSettings $settings, int $limit, ?array $context = NULL): array {
    $result = ['enabled' => TRUE, 'imported' => 0, 'accepted' => 0, 'skipped' => 0, 'failed' => 0];
    $settings->assertTestTransportAllowed();
    $suffix = $context === NULL ? '' : ':' . $this->contextKey($context);
    $lock_key = 'beantragt_delivery:reply_poll' . $suffix;
    $offset_key = 'beantragt_delivery.reply_offset' . $suffix;
    if (!$this->lock->acquire($lock_key, 900)) {
      return [...$result, 'busy' => TRUE];
    }
    try {
      $token = $this->tokens->fetchToken($settings)->accessToken;
      $jwks = $this->api->keys($settings, $token);
      // Process a bounded snapshot before acknowledgements remove remote rows.
      $offset = (int) $this->state->get($offset_key, 0);
      $page = $this->api->listReplies($settings, $token, $offset, $limit);
      $this->state->set($offset_key, $offset + $limit < $page['totalCount'] ? $offset + $limit : 0);
      foreach ($page['replies'] as $summary) {
        try {
          $binding = $this->cases->load(['case_id' => $summary['caseId']]);
          if ($binding === NULL || !$this->matchesContext($binding, $context)) {
            $result['skipped']++;
            continue;
          }
          $application = $this->application($binding, $settings);
          if ($application === NULL) {
            $result['skipped']++;
            continue;
          }
          $remote_case = $this->api->getCase($settings, $token, $binding['case_id']);
          if (($remote_case['caseId'] ?? '') !== $binding['case_id'] || ($remote_case['destinationId'] ?? '') !== $binding['destination_id']) {
            throw new \RuntimeException('Reply destination mismatch.');
          }
          $envelope = $this->api->getReply($settings, $token, $summary['replyId']);
          if (($envelope['replyId'] ?? '') !== $summary['replyId']) {
            throw new \RuntimeException('Reply identity mismatch.');
          }
          $ids = $envelope['attachments'] ?? NULL;
          if (!is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > FitConnectReplyVerifier::MAX_DOCUMENTS) {
            throw new \RuntimeException('Invalid reply attachment manifest.');
          }
          foreach ($ids as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) {
              throw new \RuntimeException('Invalid reply attachment identity.');
            }
          }
          if (count(array_unique(array_map('strtolower', $ids))) !== count($ids)) {
            throw new \RuntimeException('Duplicate reply attachment identity.');
          }
          $attachments = [];
          foreach ($envelope['attachments'] as $attachment_id) {
            $attachments[$attachment_id] = $this->api->getAttachment($settings, $token, $summary['replyId'], $attachment_id);
          }
          $verified = $this->verifier->verify($envelope, $attachments, $this->api->events($settings, $token, $summary['replyId']), $jwks, $this->cases->privateKey($binding), $settings, $binding['case_id']);
          // Separate lock owner permits nested ingestion's lock release while
          // protecting the response + acknowledgement persistence window.
          $owner = clone $application;
          if (!$this->mutationLock->acquireApplication($owner)) {
            throw new \RuntimeException('Application is unavailable.');
          }
          try {
            $application = $this->application($binding, $settings);
            if ($application === NULL) {
              $result['skipped']++;
              continue;
            }
            $data = $verified['data'];
            $coupling = $this->responseCoupling($binding);
            $this->responses->ingest(
              $application, 'fit_connect', $summary['replyId'],
              $data['externalReference'], $data['title'], $verified['documents'],
              released: $settings->trustExternalRelease && $data['releaseStatus'] === 'released' && ($data['outcome'] ?? NULL) !== 'filed',
              closes: $coupling['closes_on_response'],
              contains_protected: TRUE,
              outcome: $data['outcome'] ?? NULL,
              outcomeLabel: $data['outcomeLabel'] ?? NULL,
              followUp: $coupling['follow_up'],
            );
            $this->cases->pendingAck($summary['replyId'], $binding, $verified['authenticationTags']);
            $result['imported']++;
          }
          finally {
            $this->mutationLock->release($owner);
          }
        }
        catch (\Throwable) {
          // No arbitrary remote exception text or citizen data in diagnostics.
          $result['failed']++;
        }
      }
      foreach (array_slice($this->cases->pendingAcks($context, $context === NULL), 0, $limit) as $ack) {
        try {
          $binding = $this->cases->load(['case_id' => $ack['case_id']]);
          if ($binding === NULL || !$this->matchesContext($binding, $context)) {
            $result['skipped']++;
            continue;
          }
          if ($this->application($binding, $settings) === NULL) {
            $result['skipped']++;
            continue;
          }
          $accepted_set = NULL;
          // A previous accept may have succeeded while its response was lost.
          foreach ($this->api->events($settings, $token, $ack['reply_id']) as $event) {
            try {
              $this->verifier->verifyAcceptance($event, $jwks, $settings, $ack['reply_id'], $ack['case_id'], json_decode($ack['tags'], TRUE, flags: JSON_THROW_ON_ERROR));
              $accepted_set = $event;
              break;
            }
            catch (\Throwable) {
              // Other event types are not acceptance evidence.
            }
          }
          if ($accepted_set === NULL) {
            $accepted_set = $this->api->accept($settings, $token, $ack['reply_id'], json_decode($ack['tags'], TRUE, flags: JSON_THROW_ON_ERROR));
            $this->verifier->verifyAcceptance($accepted_set, $jwks, $settings, $ack['reply_id'], $ack['case_id'], json_decode($ack['tags'], TRUE, flags: JSON_THROW_ON_ERROR));
          }
          $this->cases->accepted($ack['reply_id'], $accepted_set);
          $result['accepted']++;
        }
        catch (\Throwable) {
          $result['failed']++;
        }
      }
      return $result;
    }
    finally {
      $this->lock->release($lock_key);
    }
  }

  /**
   * How a reply over this case binding moves the status.
   *
   * The connection that sent the application decides: only the closure rule
   * on_response closes on a reply (on_receipt closed on acceptance already,
   * manual leaves it to staff), and its follow-up flags pick the target per
   * outcome. Without a connection (legacy global settings, deleted
   * connection) a reply closes with the default follow-up, as before.
   *
   * @return array{closes_on_response: bool, follow_up: array<string, string>}
   *   The coupling of the sending connection.
   */
  private function responseCoupling(array $binding): array {
    $presentation = IntegrationRepository::presentation(NULL);
    $log = $this->integrations === NULL ? NULL : $this->logs->load((int) ($binding['log_id'] ?? 0));
    $uuid = $log['integration_connection_uuid'] ?? NULL;
    if (!is_string($uuid) || $uuid === '') {
      return ['closes_on_response' => TRUE, 'follow_up' => $presentation['follow_up']];
    }
    $connection = IntegrationRepository::inScopeOf($this->integrations?->load('connection', $uuid), $log);
    $presentation = IntegrationRepository::presentation($connection['presentation'] ?? NULL);
    return [
      'closes_on_response' => $presentation['closure_rule'] === 'on_response',
      'follow_up' => $presentation['follow_up'],
    ];
  }

  /**
   * Identifies only the frozen non-secret polling context, never global config.
   */
  private function contextKey(array $row): string {
    $snapshot = $row['connection_snapshot'] ?? [];
    return hash('sha256', IntegrationRules::canonical([
      $row['scope_group_id'] ?? NULL,
      $row['integration_connection_uuid'] ?? NULL,
      $snapshot['credential_profile'] ?? NULL,
      $snapshot['destination_id'] ?? NULL,
      $snapshot['environment'] ?? NULL,
      $snapshot['reply_process_standard'] ?? NULL,
      $row['service_urn'] ?? NULL,
      $row['contract_key'] ?? NULL,
      $row['contract_version'] ?? NULL,
    ]));
  }

  /**
   * Never processes a bound case through legacy credentials or another tenant.
   */
  private function matchesContext(array $binding, ?array $context): bool {
    $row = $this->logs->load((int) $binding['log_id']);
    if ($row === NULL) {
      return FALSE;
    }
    return $context === NULL
      ? ($row['scope_group_id'] ?? NULL) === NULL
      : ($row['scope_group_id'] ?? NULL) !== NULL && hash_equals($this->contextKey($context), $this->contextKey($row));
  }

  /**
   * Reloads the application and validates its original delivery binding.
   */
  private function application(array $binding, FitConnectSettings $settings): ?ApplicationInterface {
    $storage = $this->entityTypeManager->getStorage('application');
    $storage->resetCache([(int) $binding['application_id']]);
    $application = $storage->load($binding['application_id']);
    if (!$application instanceof ApplicationInterface) {
      throw new \RuntimeException('Reply application missing.');
    }
    if ((json_decode($application->getData() ?? '', TRUE)['_anonymized'] ?? NULL) === TRUE) {
      return NULL;
    }
    $this->cases->assertBinding($binding, $application, $settings);
    $log = $this->logs->load((int) $binding['log_id']);
    if ($log === NULL || $log['adapter'] !== 'fit_connect' || $log['applicationUuid'] !== $application->uuid()
      || ($log['metadata']['fitConnect']['caseId'] ?? '') !== $binding['case_id']
      || ($log['metadata']['fitConnect']['submissionId'] ?? '') !== $binding['submission_id']) {
      throw new \RuntimeException('Original delivery binding missing.');
    }
    return $application;
  }

}
