<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

/**
 * Collects staff notification outcomes for the current request.
 */
final class NotificationOutcomeCollector {

  /**
   * Recorded notification outcomes.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $entries = [];

  /**
   * Records a notification outcome.
   *
   * @param array<string, mixed> $entry
   *   The notification outcome entry.
   */
  public function record(array $entry): void {
    $this->entries[] = $entry;
  }

  /**
   * Returns and clears all recorded notification outcomes.
   *
   * @return array<int, array<string, mixed>>
   *   The recorded entries.
   */
  public function drain(): array {
    $entries = $this->entries;
    $this->entries = [];
    return $entries;
  }

}
