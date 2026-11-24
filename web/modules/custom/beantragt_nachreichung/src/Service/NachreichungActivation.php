<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\Service\DocumentRepository;
use Drupal\beantragt_nachreichung\Repository\CoverCodeRepository;
use Drupal\beantragt_nachreichung\Repository\NachforderungRepository;
use Drupal\beantragt_nachreichung\Repository\NachreichungEventLog;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Release of request letters: identifier valid, record open, older replaced.
 *
 * Released() runs inside docgen's release booking transaction. reconcile()
 * repairs rows of a letter released without it (idempotent) and runs on the
 * card read and from cron.
 */
class NachreichungActivation {

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly CoverCodeRepository $codes,
    private readonly NachforderungRepository $requests,
    private readonly NachreichungEventLog $events,
    private readonly DocumentRepository $documents,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Activates and opens for the released request letters.
   *
   * All request letters of one release went out in one response with one
   * deadline: they open together and supersede only records of earlier
   * releases, never each other.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param array<int, array<string, mixed>> $rows
   *   Docgen rows of the release.
   * @param string $response_uuid
   *   The response carrying the letters.
   * @param int $now
   *   Release time.
   */
  public function released(ApplicationInterface $application, array $rows, string $response_uuid, int $now): void {
    $letters = [];
    foreach ($rows as $row) {
      if (($row['purpose'] ?? '') === 'request_info') {
        $letters[(string) $row['uuid']] = (int) ($row['released_by'] ?? 0);
      }
    }
    if ($letters !== []) {
      $this->activate((int) $application->id(), $letters, $response_uuid, $now);
    }
  }

  /**
   * Repairs draft rows whose request letter is released.
   *
   * Letters are grouped by their response and repaired oldest release first,
   * so letters of one release never supersede each other.
   */
  public function reconcile(int $application_id): void {
    $documents = [];
    foreach ([$this->codes->drafts(200, $application_id), $this->requests->drafts(200, $application_id)] as $rows) {
      foreach ($rows as $row) {
        $documents[(string) $row['docgen_document_uuid']] = TRUE;
      }
    }
    $releases = [];
    foreach (array_keys($documents) as $document_uuid) {
      $row = $this->documents->find($application_id, (string) $document_uuid);
      if ($row === NULL || $row['state'] !== DocumentRepository::STATE_RELEASED) {
        continue;
      }
      $response_uuid = (string) ($row['response_uuid'] ?? '');
      $key = $response_uuid !== '' ? $response_uuid : 'document:' . $document_uuid;
      $releases[$key] ??= [
        'response' => $response_uuid,
        'at' => (int) ($row['released_at'] ?? $this->time->getRequestTime()),
        'letters' => [],
      ];
      $releases[$key]['letters'][(string) $document_uuid] = (int) ($row['released_by'] ?? 0);
    }
    uasort($releases, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
    foreach ($releases as $release) {
      $transaction = $this->database->startTransaction();
      try {
        $this->activate($application_id, $release['letters'], $release['response'], $release['at']);
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }
      unset($transaction);
    }
  }

  /**
   * Cron: reconcile released letters, drop orphaned drafts older than a day.
   */
  public function cron(): void {
    $now = $this->time->getRequestTime();
    $applications = [];
    foreach ([$this->codes->drafts(), $this->requests->drafts()] as $rows) {
      foreach ($rows as $row) {
        $application_id = (int) $row['application_id'];
        $document_uuid = (string) $row['docgen_document_uuid'];
        if ($this->documents->find($application_id, $document_uuid) === NULL) {
          if ((int) $row['created'] < $now - 86400) {
            $this->codes->deleteDrafts($document_uuid);
            $this->requests->deleteDraft($document_uuid);
          }
          continue;
        }
        $applications[$application_id] = TRUE;
      }
    }
    foreach (array_keys($applications) as $application_id) {
      $this->reconcile((int) $application_id);
    }
    $this->events->purge();
  }

  /**
   * Opens the records and activates the identifiers of one release.
   *
   * @param int $application_id
   *   The application.
   * @param array<string, int> $letters
   *   Released by (uid) per docgen document UUID of the release.
   * @param string $response_uuid
   *   The response carrying the letters ('' when unknown).
   * @param int $now
   *   Release time.
   */
  private function activate(int $application_id, array $letters, string $response_uuid, int $now): void {
    $opened = [];
    foreach ($letters as $document_uuid => $uid) {
      $request = $this->requests->draftForDocument((string) $document_uuid);
      if ($request === NULL) {
        continue;
      }
      $this->requests->update((int) $request['id'], [
        'state' => 'open',
        'released_at' => $now,
        'released_by' => $uid > 0 ? $uid : NULL,
        'response_uuid' => $response_uuid !== '' ? $response_uuid : NULL,
      ]);
      $this->events->append($application_id, (string) $request['uuid'], 'request_opened', ['deadline' => (string) $request['deadline']], $uid, $now);
      $opened[(int) $request['id']] = ['uuid' => (string) $request['uuid'], 'uid' => $uid];
    }
    // Only when this pass opened something: a second reconcile run changes
    // nothing (idempotent).
    if ($opened !== []) {
      $superseded = $this->requests->supersedeOthers($application_id, array_keys($opened), $now);
      if ($superseded > 0) {
        $first = reset($opened);
        $this->events->append($application_id, $first['uuid'], 'request_superseded', ['count' => $superseded], $first['uid'], $now);
      }
    }
    foreach ($letters as $document_uuid => $uid) {
      $activated = $this->codes->activateForDocument((string) $document_uuid, $now);
      if ($activated > 0) {
        $this->events->append($application_id, (string) $document_uuid, 'cover_activated', ['count' => $activated], $uid, $now);
      }
    }
  }

}
