<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen;

/**
 * Optional request-scoped batching of appendix release checks.
 */
interface DocgenAppendixBatchInterface extends DocgenAppendixInterface {

  /**
   * Runs checks with cover requirements prefetched for an authorized page.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface[] $applications
   *   Authorized applications, keyed by numeric ID.
   * @param array<int, array<string, mixed>> $rows
   *   Document metadata rows to evaluate.
   * @param callable $read
   *   Release check callback.
   *
   * @return mixed
   *   Callback result.
   */
  public function withCoverRequirements(array $applications, array $rows, callable $read): mixed;

}
