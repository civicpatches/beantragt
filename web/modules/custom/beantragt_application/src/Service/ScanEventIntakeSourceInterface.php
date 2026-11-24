<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\Core\Database\Query\SelectInterface;

/**
 * Adds attachment findings from a further intake channel to scan events.
 *
 * The open backend has none. A module that brings attachments in through
 * another channel, such as batch intake of scanned paper applications,
 * provides one so that its findings mark the attachment like a scan result.
 */
interface ScanEventIntakeSourceInterface {

  /**
   * Joins the intake rows to the scan event query and applies the cutoff.
   *
   * The query selects attachment events as "e". An implementation adds the
   * columns item_uuid, classification, bulk_result, bulk_changed and
   * bound_application, and must keep events changed after the cutoff.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The scan event query.
   * @param int $cutoff
   *   Only events or intake rows changed after this time are kept.
   */
  public function extendScanQuery(SelectInterface $query, int $cutoff): void;

  /**
   * Whether the intake result of a bound row has a finding for the field.
   *
   * @param array<string, mixed> $row
   *   The scan event row including the columns added above.
   * @param string $target
   *   The field ID of the attachment.
   */
  public function hasFinding(array $row, string $target): bool;

}
