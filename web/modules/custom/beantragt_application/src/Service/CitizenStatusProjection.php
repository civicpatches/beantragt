<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\WorkflowStatusResolver;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Projects workflow statuses the way applicants see them.
 *
 * Applicants read the citizen text of a status (field_citizen_label_de/_en,
 * else the label), a connection text where the sending connection names a
 * handover or a decision (hook_beantragt_application_timeline_alter()), and
 * never an internal step twice in a row: consecutive entries with the same
 * text collapse into the first. Staff projections keep the internal labels.
 * The same effective text decides whether a status mail goes out.
 */
final class CitizenStatusProjection {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Effective citizen entry for one status of one application.
   *
   * Runs the timeline alter hook over this single entry, so lists, details
   * and the last timeline entry show the same text.
   *
   * @return array{status: string, label: string, color: string, icon: string}
   *   The entry; label is empty only without a status.
   */
  public function entry(ApplicationInterface $application, ?WorkflowStatusInterface $status, string $langcode): array {
    $entry = $this->statusEntry($status, $langcode);
    if ($status === NULL) {
      $entry['status'] = WorkflowStatusResolver::machineName($application, 'unknown');
      return $entry;
    }
    $timeline = [$entry];
    $this->moduleHandler->alter('beantragt_application_timeline', $timeline, $application, $langcode);
    $altered = $timeline[0] ?? $entry;
    foreach (['status', 'label', 'color', 'icon'] as $key) {
      $entry[$key] = is_string($altered[$key] ?? NULL) && $altered[$key] !== '' ? $altered[$key] : $entry[$key];
    }
    return $entry;
  }

  /**
   * Builds the citizen timeline: one entry per status change, then collapse.
   *
   * Entries carry status, timestamp (ISO 8601), label, color, icon and, from
   * the second entry on, actor ("staff" or NULL for the system and for
   * applicants). Empty strings are dropped as before. The alter hook runs
   * before collapsing, so connection texts count.
   *
   * @return array<int, array<string, string|null>>
   *   Timeline entries, oldest first.
   */
  public function timeline(ApplicationInterface $application, string $langcode): array {
    $timeline = [];
    $storage = $this->entityTypeManager->getStorage('application');
    if ($storage instanceof RevisionableStorageInterface && $application->id() !== NULL) {
      $revision_ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->allRevisions()
        ->condition('id', $application->id())
        ->sort('revision_id', 'ASC')
        ->execute();
      $previous = NULL;
      foreach (array_keys($revision_ids) as $revision_id) {
        $revision = $storage->loadRevision($revision_id);
        if (!$revision instanceof ApplicationInterface) {
          continue;
        }
        $status = $this->statusOf($revision);
        $machine_name = $status?->getMachineName() ?: WorkflowStatusResolver::machineName($revision, 'unknown');
        if ($previous !== NULL && $machine_name === $previous) {
          continue;
        }
        $entry = $this->statusEntry($status, $langcode);
        $entry['status'] = $machine_name;
        $entry = ['status' => $entry['status'], 'timestamp' => $this->timestamp($this->revisionCreated($revision))] + $entry;
        if ($previous !== NULL) {
          $entry['actor'] = $this->actor($revision);
        }
        $timeline[] = array_filter($entry, static fn(mixed $value): bool => $value !== '');
        $previous = $machine_name;
      }
    }
    if ($timeline === []) {
      $entry = $this->statusEntry($this->statusOf($application), $langcode);
      $entry['status'] = WorkflowStatusResolver::machineName($application, 'unknown');
      $timeline[] = array_filter(
        ['status' => $entry['status'], 'timestamp' => $this->timestamp($application->getCreatedTime())] + $entry,
        static fn(mixed $value): bool => $value !== '',
      );
    }
    $this->moduleHandler->alter('beantragt_application_timeline', $timeline, $application, $langcode);
    return self::collapse($timeline);
  }

  /**
   * Effective citizen labels of statuses, through the same alter hook.
   *
   * Used for the mail rule: a status mail goes out only when the effective
   * text changes. A NULL status yields an empty string.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param array<int, \Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface|null> $statuses
   *   The statuses, for example [from, to].
   * @param string $langcode
   *   The reader's language.
   *
   * @return list<string>
   *   One label per status, in the given order.
   */
  public function labels(ApplicationInterface $application, array $statuses, string $langcode): array {
    $labels = [];
    foreach ($statuses as $status) {
      $labels[] = $status instanceof WorkflowStatusInterface ? $this->entry($application, $status, $langcode)['label'] : '';
    }
    return $labels;
  }

  /**
   * Citizen text of one status without any connection text.
   */
  public static function citizenLabel(?WorkflowStatusInterface $status, string $langcode): string {
    return $status instanceof WorkflowStatusInterface ? $status->getCitizenLabel($langcode) : '';
  }

  /**
   * Drops entries whose label repeats the previous entry's label.
   *
   * The first entry of a run keeps its timestamp, actor, color and icon.
   *
   * @param array<int, array<string, mixed>> $timeline
   *   Timeline entries, oldest first.
   *
   * @return array<int, array<string, mixed>>
   *   Collapsed entries, re-indexed.
   */
  public static function collapse(array $timeline): array {
    $collapsed = [];
    $previous = NULL;
    foreach ($timeline as $entry) {
      $label = (string) ($entry['label'] ?? '');
      if ($previous !== NULL && $label !== '' && $label === $previous) {
        continue;
      }
      $collapsed[] = $entry;
      $previous = $label;
    }
    return $collapsed;
  }

  /**
   * Builds the unaltered entry of one status.
   *
   * @return array{status: string, label: string, color: string, icon: string}
   *   The entry.
   */
  private function statusEntry(?WorkflowStatusInterface $status, string $langcode): array {
    if ($status === NULL) {
      return ['status' => 'unknown', 'label' => '', 'color' => '', 'icon' => ''];
    }
    return [
      'status' => $status->getMachineName() !== '' ? $status->getMachineName() : 'unknown',
      'label' => $status->getCitizenLabel($langcode),
      'color' => trim((string) ($status->getColor() ?? '')),
      'icon' => trim((string) ($status->getIcon() ?? '')),
    ];
  }

  /**
   * Loads the status an application or revision references.
   */
  private function statusOf(ApplicationInterface $application): ?WorkflowStatusInterface {
    if (!$application->hasField('field_workflow_status') || $application->get('field_workflow_status')->isEmpty()) {
      return NULL;
    }
    $status = $application->get('field_workflow_status')->entity;
    return $status instanceof WorkflowStatusInterface ? $status : NULL;
  }

  /**
   * Reads the creation time of a revision.
   */
  private function revisionCreated(ApplicationInterface $revision): int {
    if ($revision->hasField('revision_created') && !$revision->get('revision_created')->isEmpty()) {
      return (int) $revision->get('revision_created')->value;
    }
    return 0;
  }

  /**
   * Formats a timestamp as ISO 8601; empty for no time.
   */
  private function timestamp(int $timestamp): string {
    return $timestamp > 0 ? date(\DateTimeInterface::ATOM, $timestamp) : '';
  }

  /**
   * Resolves a role-only actor label, never personal data.
   *
   * @return string|null
   *   "staff" for staff accounts, NULL for the system and applicants.
   */
  private function actor(ApplicationInterface $revision): ?string {
    if (!$revision->hasField('revision_user') || $revision->get('revision_user')->isEmpty()) {
      return NULL;
    }
    $uid = (int) $revision->get('revision_user')->target_id;
    if ($uid <= 0) {
      return NULL;
    }
    if ($uid === 1) {
      return 'staff';
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    return $user instanceof AccountInterface && BeantragtRoles::isStaffAccount($user) ? 'staff' : NULL;
  }

}
