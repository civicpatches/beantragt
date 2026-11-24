<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_nachreichung\Repository\CoverCodeRepository;
use Drupal\beantragt_nachreichung\Repository\EingangRepository;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\beantragt_nachreichung\Repository\NachforderungRepository;
use Drupal\Core\Session\AccountInterface;

/**
 * The staff card "Nachreichungen" (contract section 9).
 *
 * Never an identifier, code hash, QR hash or key version, never a name or
 * a file name.
 */
class NachreichungCard {

  /**
   * Constructs the card builder.
   */
  public function __construct(
    private readonly NachreichungSectionValidator $section,
    private readonly CoverPrintService $print,
    private readonly CoverCodeRepository $codes,
    private readonly NachforderungRepository $requests,
    private readonly ?EingangRepository $receipts = NULL,
    private readonly ?NachreichungSources $sources = NULL,
    private readonly ?EntityTypeManagerInterface $manager = NULL,
  ) {}

  /**
   * Builds the card.
   *
   * @return array<string, mixed>
   *   The card.
   */
  public function build(ApplicationInterface $application, AccountInterface $account): array {
    $id = (int) $application->id();
    $blocker = $this->print->printBlocker($application);
    $int = static fn (mixed $value): ?int => $value === NULL || $value === '' ? NULL : (int) $value;
    $records = $this->requests->forApplication($id);
    $canUpdate = $application->access('update', $account);
    return [
      'coverSheet' => $this->section->coverSheet($application),
      'canPrint' => $blocker === NULL,
      'printBlocker' => $blocker,
      'canRevoke' => $application->access('update', $account),
      'activeCount' => $this->codes->activeCount($id),
      'activeLimit' => CoverCodeRepository::ACTIVE_LIMIT,
      'nachforderungen' => array_map(static fn (array $row): array => [
        'id' => (string) $row['uuid'],
        'state' => (string) $row['state'],
        'releasedAt' => $int($row['released_at']),
        'deadlinePrinted' => (string) $row['deadline_printed'],
        'deadline' => (string) $row['deadline'],
        'extended' => $row['deadline_extended_at'] !== NULL,
        'deadlineReason' => $row['deadline_reason'] !== NULL ? (string) $row['deadline_reason'] : NULL,
        'canExtend' => $canUpdate && in_array($row['state'], NachforderungRepository::WAITING, TRUE),
      ], $records),
      'eingaenge' => $this->entries($id, $records, $account),
      'deckblaetter' => array_map(static fn (array $row): array => [
        'id' => (string) $row['uuid'],
        'origin' => (string) $row['origin'],
        'state' => (string) $row['state'],
        'createdAt' => (int) $row['created'],
        'activatedAt' => $int($row['activated_at']),
        'revokedAt' => $int($row['revoked_at']),
        'revokeReason' => $row['revoke_reason'] === NULL ? NULL : (string) $row['revoke_reason'],
      ], $this->codes->forApplication($id)),
    ];
  }

  /**
   * Receipt records of the application, newest first, at most 50.
   *
   * @param int $id
   *   The application.
   * @param array<int, array<string, mixed>> $records
   *   Its request records.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Viewer.
   *
   * @return list<array<string, mixed>>
   *   Entries.
   */
  private function entries(int $id, array $records, AccountInterface $account): array {
    if ($this->receipts === NULL) {
      return [];
    }
    $byId = array_column($records, NULL, 'id');
    $mayRevert = $account->hasPermission('revert nachreichung');
    $entries = [];
    foreach ($this->receipts->forApplication($id, 50) as $row) {
      $record = $row['nachforderung_id'] !== NULL ? ($byId[$row['nachforderung_id']] ?? $this->requests->find((int) $row['nachforderung_id'])) : NULL;
      $labels = [];
      $decoded = $record !== NULL ? json_decode((string) ($record['requested'] ?? ''), TRUE) : NULL;
      foreach (is_array($decoded) ? $decoded : [] as $requested) {
        if (is_array($requested) && is_string($requested['key'] ?? NULL)) {
          $labels[$requested['key']] = $requested;
        }
      }
      $titles = $this->criterionTitles((int) ($record['form_revision_id'] ?? 0));
      $results = [];
      foreach ($row['result'] as $answer) {
        if (!is_array($answer)) {
          continue;
        }
        $requested = $labels[$answer['key'] ?? ''] ?? [];
        $field = (string) ($answer['fieldId'] ?? ($requested['fieldId'] ?? ''));
        $results[] = [
          'key' => (string) ($answer['key'] ?? ''),
          'label' => is_string($requested['label'] ?? NULL) ? mb_substr($requested['label'], 0, 255) : NULL,
          'personLabel' => self::personLabel($answer['personBlock'] ?? ($requested['personBlock'] ?? NULL)),
          'status' => (string) ($answer['status'] ?? ''),
          'reason' => is_string($answer['reason'] ?? NULL) ? $answer['reason'] : NULL,
          'criteria' => array_values(array_filter(array_map(
            static fn (mixed $key): ?string => is_string($key) ? ($titles[$field][$key] ?? NULL) : NULL,
            is_array($answer['criteria'] ?? NULL) ? $answer['criteria'] : [],
          ))),
        ];
      }
      $reverted = $row['reverted_at'] !== NULL;
      $canRemove = $mayRevert && !$reverted;
      $entries[] = [
        'id' => (string) $row['uuid'],
        'receivedOn' => (string) $row['received_on'],
        'receivedOnSource' => (string) $row['received_on_source'],
        'batchReceivedOn' => (string) $row['batch_received_on'],
        'decision' => (string) $row['decision'],
        'decidedAt' => (int) $row['decided_at'],
        'identityConfirmed' => (int) $row['identity_confirmed'] === 1,
        'cover' => (int) $row['cover'] === 1,
        'pageCount' => (int) $row['page_count'],
        'results' => $results,
        'attachments' => array_values(array_map(static fn (array $file): array => [
          'id' => (string) ($file['attachmentUuid'] ?? ''),
          'coverPages' => array_values(array_filter(is_array($file['coverPages'] ?? NULL) ? $file['coverPages'] : [], 'is_int')),
        ], array_filter($row['files'], 'is_array'))),
        'reverted' => $reverted ? ['at' => (int) $row['reverted_at'], 'mode' => (string) $row['reverted_mode']] : NULL,
        'canRemove' => $canRemove,
        'canReassign' => $canRemove && $this->sources !== NULL && $this->sources->reassignRefusal((string) $row['bulk_case_uuid']) === NULL,
      ];
    }
    return $entries;
  }

  /**
   * Person label: Antragsteller or Haushaltsmitglied n, never a name.
   */
  private static function personLabel(mixed $block): ?string {
    if (!is_string($block) || preg_match('/#([0-9]+)$/D', $block, $match) !== 1) {
      return NULL;
    }
    return (int) $match[1] === 0 ? 'Antragsteller' : 'Haushaltsmitglied ' . ((int) $match[1] + 1);
  }

  /**
   * Criterion titles of a form revision per file field and key.
   *
   * @return array<string, array<string, string>>
   *   Titles; legibility and keys without a title are left out.
   */
  private function criterionTitles(int $revision): array {
    $storage = $revision > 0 ? $this->manager?->getStorage('form_schema') : NULL;
    $form = $storage instanceof RevisionableStorageInterface ? $storage->loadRevision($revision) : NULL;
    if (!$form instanceof FormSchemaInterface) {
      return [];
    }
    $schema = json_decode((string) $form->getSchema(), TRUE);
    $titles = [];
    foreach (is_array($schema) ? ($schema['sections'] ?? []) : [] as $section) {
      foreach (is_array($section['fields'] ?? NULL) ? $section['fields'] : [] as $field) {
        if (($field['type'] ?? '') !== 'file' || !is_string($field['id'] ?? NULL)) {
          continue;
        }
        foreach ($field['analysis']['resultSchema']['properties'] ?? [] as $key => $property) {
          $title = is_array($property) ? ($property['title'] ?? NULL) : NULL;
          if ($key !== 'legible' && is_string($title) && trim($title) !== '') {
            $titles[$field['id']][(string) $key] = trim($title);
          }
        }
      }
    }
    return $titles;
  }

}
