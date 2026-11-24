<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_nachreichung\Repository\EingangRepository;
use Drupal\beantragt_nachreichung\Repository\NachforderungRepository;

/**
 * The proofs of an "Erneut nachfordern" letter (D18).
 *
 * When the newest waiting request record is partly answered and its newest
 * receipt record (not reverted) decided "request_again", the next request
 * letter asks only for what was insufficient or still missing, with the
 * reasons the clerk gave. A proof that came but does not suffice is never
 * reported as missing.
 */
class RequestAgainProofs {

  /**
   * Constructs the provider.
   */
  public function __construct(
    private readonly NachforderungRepository $requests,
    private readonly EingangRepository $receipts,
  ) {}

  /**
   * The request-again list in the shape of RequestedProofs::requested().
   *
   * @return list<array{key: string, fieldId: string, personBlock: ?string, label: string, reason: string, criteria: list<string>}>|null
   *   Entries, NULL when no request-again decision applies.
   */
  public function forApplication(ApplicationInterface $application): ?array {
    $source = $this->source($application);
    if ($source === NULL) {
      return NULL;
    }
    [$record, $receipt] = $source;
    $labels = [];
    $decoded = json_decode((string) ($record['requested'] ?? ''), TRUE);
    foreach (is_array($decoded) ? $decoded : [] as $entry) {
      if (is_array($entry) && is_string($entry['key'] ?? NULL)) {
        $labels[$entry['key']] = $entry;
      }
    }
    $entries = [];
    foreach ($receipt['result'] as $answer) {
      if (!is_array($answer) || !in_array($answer['status'] ?? NULL, ['insufficient', 'missing'], TRUE)) {
        continue;
      }
      $requested = $labels[$answer['key'] ?? ''] ?? NULL;
      if (!is_array($requested) || !is_string($requested['fieldId'] ?? NULL) || !is_string($requested['label'] ?? NULL)) {
        continue;
      }
      $reason = $answer['status'] === 'missing' ? 'missing' : (string) ($answer['reason'] ?? '');
      $entries[] = [
        'key' => (string) $answer['key'],
        'fieldId' => $requested['fieldId'],
        'personBlock' => is_string($requested['personBlock'] ?? NULL) ? $requested['personBlock'] : NULL,
        'label' => mb_substr($requested['label'], 0, 255),
        'reason' => in_array($reason, ['missing', 'person_unmatched', 'requirement_not_met', 'illegible'], TRUE) ? $reason : 'missing',
        'criteria' => $reason === 'requirement_not_met' && is_array($answer['criteria'] ?? NULL)
          ? array_values(array_filter($answer['criteria'], 'is_string')) : [],
      ];
    }
    return $entries;
  }

  /**
   * Form revision of the record the list belongs to, for criterion texts.
   */
  public function formRevision(ApplicationInterface $application): ?int {
    $source = $this->source($application);
    $revision = $source !== NULL ? (int) ($source[0]['form_revision_id'] ?? 0) : 0;
    return $revision > 0 ? $revision : NULL;
  }

  /**
   * The partly answered record and its request-again receipt.
   *
   * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
   *   Record and receipt, NULL when none applies.
   */
  private function source(ApplicationInterface $application): ?array {
    $record = $this->requests->newestWaiting((int) $application->id());
    if ($record === NULL || $record['state'] !== 'partly_answered') {
      return NULL;
    }
    $receipt = $this->receipts->newestForRequest((int) $application->id(), (int) $record['id']);
    return $receipt !== NULL && $receipt['decision'] === 'request_again' ? [$record, $receipt] : NULL;
  }

}
