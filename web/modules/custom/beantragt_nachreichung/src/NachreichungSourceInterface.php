<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung;

/**
 * Lets the module holding envelopes react to request and receipt changes.
 *
 * N1: bulk knows beantragt_nachreichung, never the reverse. Services with
 * the tag beantragt_nachreichung_source are collected by
 * beantragt_nachreichung.sources; every method runs inside the caller's
 * transaction where one exists.
 */
interface NachreichungSourceInterface {

  /**
   * An identifier was revoked; refreshes its undecided envelopes.
   *
   * @return int
   *   Number of envelopes refreshed (for the cover_revoked event).
   */
  public function coverRevoked(int $cover_id): int;

  /**
   * A request record changed (deadline extended, reopened).
   */
  public function requestChanged(int $nachforderung_id): void;

  /**
   * The receipt of an envelope was removed from its application.
   *
   * @param string $bulk_case_uuid
   *   The envelope.
   * @param int $uid
   *   Acting person.
   * @param string $mode
   *   Mode remove or reassign.
   */
  public function reverted(string $bulk_case_uuid, int $uid, string $mode): void;

  /**
   * Why the envelope cannot go back to the review, NULL when it can.
   *
   * @return string|null
   *   NULL or "reassign_window_closed" (batch files gone).
   */
  public function reassignRefusal(string $bulk_case_uuid): ?string;

  /**
   * Sends the envelope back to the review without a target.
   */
  public function reset(string $bulk_case_uuid, int $uid): void;

}
