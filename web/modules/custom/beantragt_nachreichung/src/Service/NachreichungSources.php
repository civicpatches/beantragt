<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_nachreichung\NachreichungSourceInterface;

/**
 * Collects the envelope sources (tag beantragt_nachreichung_source).
 *
 * Without a source (bulk not installed) nothing refers to an envelope:
 * revocations count 0 envelopes and a receipt can always be reassigned
 * only when a source says so.
 */
class NachreichungSources {

  /**
   * Sources in collector order.
   *
   * @var \Drupal\beantragt_nachreichung\NachreichungSourceInterface[]
   */
  private array $sources = [];

  /**
   * Registers a source.
   */
  public function addSource(NachreichungSourceInterface $source): void {
    $this->sources[] = $source;
  }

  /**
   * Refreshes the envelopes of a revoked identifier; returns their count.
   */
  public function coverRevoked(int $cover_id): int {
    $count = 0;
    foreach ($this->sources as $source) {
      $count += $source->coverRevoked($cover_id);
    }
    return $count;
  }

  /**
   * Refreshes envelopes of a changed request record.
   */
  public function requestChanged(int $nachforderung_id): void {
    foreach ($this->sources as $source) {
      $source->requestChanged($nachforderung_id);
    }
  }

  /**
   * Marks the envelope of a removed receipt.
   */
  public function reverted(string $bulk_case_uuid, int $uid, string $mode): void {
    foreach ($this->sources as $source) {
      $source->reverted($bulk_case_uuid, $uid, $mode);
    }
  }

  /**
   * First refusal of any source; without a source a reassign is impossible.
   */
  public function reassignRefusal(string $bulk_case_uuid): ?string {
    if ($this->sources === []) {
      return 'reassign_window_closed';
    }
    foreach ($this->sources as $source) {
      $refusal = $source->reassignRefusal($bulk_case_uuid);
      if ($refusal !== NULL) {
        return $refusal;
      }
    }
    return NULL;
  }

  /**
   * Sends the envelope back to the review.
   */
  public function reset(string $bulk_case_uuid, int $uid): void {
    foreach ($this->sources as $source) {
      $source->reset($bulk_case_uuid, $uid);
    }
  }

}
