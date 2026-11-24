<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_nachreichung\Exception\NachreichungException;
use Drupal\beantragt_nachreichung\Repository\EingangRepository;
use Drupal\beantragt_nachreichung\Repository\NachforderungRepository;
use Drupal\beantragt_nachreichung\Repository\NachreichungEventLog;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Deadline extension and unassigning of receipts (3.12, 3.13).
 */
class NachreichungEntries {

  public const DEADLINE_REASONS = ['applicant_request', 'third_party_delay', 'other'];

  /**
   * Latest new deadline after the printed one, in days.
   */
  public const MAX_EXTENSION_DAYS = 365;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $manager,
    private readonly FileSystemInterface $files,
    private readonly TimeInterface $time,
    private readonly NachforderungRepository $requests,
    private readonly EingangRepository $receipts,
    private readonly NachreichungEventLog $events,
    private readonly NachreichungSources $sources,
  ) {}

  /**
   * Extends the deadline of a request record, also after expiry (5.8).
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string $request_uuid
   *   The request record.
   * @param array $data
   *   Exactly {deadline, reason}.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Acting person.
   */
  public function extendDeadline(ApplicationInterface $application, string $request_uuid, array $data, AccountInterface $account): void {
    $keys = array_keys($data);
    sort($keys);
    if ($keys !== ['deadline', 'reason'] || !in_array($data['reason'], self::DEADLINE_REASONS, TRUE) || !is_string($data['deadline'])) {
      throw new NachreichungException('nachreichung.input_invalid', 400);
    }
    $record = Uuid::isValid($request_uuid) ? $this->requests->findForApplication((int) $application->id(), $request_uuid) : NULL;
    if ($record === NULL || $record['state'] === 'draft') {
      throw new NachreichungException('nachreichung.request_not_found', 404);
    }
    if (!in_array($record['state'], NachforderungRepository::WAITING, TRUE)) {
      throw new NachreichungException('nachreichung.request_closed', 409);
    }
    $new = self::day($data['deadline']);
    $current = self::day((string) $record['deadline']);
    $printed = self::day((string) $record['deadline_printed']);
    if ($new === NULL || $current === NULL || $printed === NULL || $new <= $current
      || $new > $printed->modify('+' . self::MAX_EXTENSION_DAYS . ' days')) {
      throw new NachreichungException('nachreichung.deadline_invalid', 422);
    }
    $now = $this->time->getCurrentTime();
    $transaction = $this->database->startTransaction();
    try {
      $this->requests->update((int) $record['id'], [
        'deadline' => $data['deadline'],
        'deadline_extended_at' => $now,
        'deadline_extended_by' => (int) $account->id(),
        'deadline_reason' => $data['reason'],
      ]);
      $this->events->append((int) $application->id(), (string) $record['uuid'], 'deadline_extended', [
        'days' => (int) $current->diff($new)->format('%a'),
        'code' => $data['reason'],
      ], (int) $account->id(), $now);
      $this->sources->requestChanged((int) $record['id']);
      unset($transaction);
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      throw $error;
    }
  }

  /**
   * Removes (6a) or reassigns (6b) a receipt.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param string $entry_uuid
   *   The receipt record.
   * @param string $mode
   *   Mode remove or reassign.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Acting person.
   */
  public function revert(ApplicationInterface $application, string $entry_uuid, string $mode, AccountInterface $account): void {
    $entry = Uuid::isValid($entry_uuid) ? $this->receipts->findForApplication((int) $application->id(), $entry_uuid) : NULL;
    if ($entry === NULL) {
      throw new NachreichungException('nachreichung.entry_not_found', 404);
    }
    if ($entry['reverted_at'] !== NULL) {
      throw new NachreichungException('nachreichung.entry_reverted', 409);
    }
    if ($mode === 'reassign' && ($refusal = $this->sources->reassignRefusal((string) $entry['bulk_case_uuid'])) !== NULL) {
      throw new NachreichungException('nachreichung.' . $refusal, 409);
    }
    $uid = (int) $account->id();
    $now = $this->time->getCurrentTime();
    $transaction = $this->database->startTransaction();
    try {
      if (!$this->receipts->revert((int) $entry['id'], $uid, $mode, $now)) {
        throw new NachreichungException('nachreichung.entry_reverted', 409);
      }
      $this->restoreRequest($application, $entry, $uid, $now);
      $this->events->append((int) $application->id(), (string) $entry['uuid'], 'nachreichung_reverted', ['code' => $mode], $uid, $now);
      $this->sources->reverted((string) $entry['bulk_case_uuid'], $uid, $mode);
      if ($mode === 'reassign') {
        $this->sources->reset((string) $entry['bulk_case_uuid'], $uid);
      }
      // Last, because a file leaves the disk with its entity.
      $this->deleteAttachments($application, (string) $entry['uuid']);
      unset($transaction);
    }
    catch (\Throwable $error) {
      $transaction->rollBack();
      throw $error;
    }
    $directory = 'private://application_attachments/nachreichung-' . $entry['uuid'];
    if (is_dir($directory)) {
      $this->files->deleteRecursive($directory);
    }
  }

  /**
   * D16: back to the state before, only while nobody changed it since.
   */
  private function restoreRequest(ApplicationInterface $application, array $entry, int $uid, int $now): void {
    $before = $entry['request_state_before'];
    $after = $entry['request_state_after'];
    if ($entry['nachforderung_id'] === NULL || $before === NULL || $after === NULL || $before === $after) {
      return;
    }
    $record = $this->requests->find((int) $entry['nachforderung_id']);
    if ($record === NULL || $record['state'] !== $after || $this->receipts->laterForRequest((int) $record['id'], (int) $entry['id'])) {
      return;
    }
    if ($this->requests->transition((int) $record['id'], (string) $after, (string) $before, NULL)) {
      $this->events->append((int) $application->id(), (string) $record['uuid'], 'request_reopened', [], $uid, $now);
      $this->sources->requestChanged((int) $record['id']);
    }
  }

  /**
   * Deletes the attachments of a receipt and their files.
   */
  private function deleteAttachments(ApplicationInterface $application, string $entry_uuid): void {
    $storage = $this->manager->getStorage('application_attachment');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('field_application', $application->id())
      ->condition('field_nachreichung', $entry_uuid)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $attachment) {
      if (!$attachment instanceof ApplicationAttachmentInterface) {
        continue;
      }
      $file = $attachment->get('field_file')->entity;
      $attachment->delete();
      $file?->delete();
    }
  }

  /**
   * A real YYYY-MM-DD date, NULL otherwise.
   */
  private static function day(string $value): ?\DateTimeImmutable {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
      return NULL;
    }
    $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
    return $day instanceof \DateTimeImmutable && $day->format('Y-m-d') === $value ? $day : NULL;
  }

}
