<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Shared disclosure boundary for current and legacy delivery history routes.
 */
final class TransferHistoryProjection {

  /**
   * Constructs the history boundary.
   */
  public function __construct(private readonly ScopeAccess $scopeAccess) {}

  /**
   * Returns a value-free history entry, or NULL for locally visible rows.
   */
  public function neutral(ApplicationInterface $application, array $row): ?array {
    $foreign = isset($row['scope_group_id']) && (int) $row['scope_group_id'] !== $this->scopeAccess->integrationScope($application);
    $foreign = $foreign || (($row['adapter'] ?? '') === 'fit_connect' && !isset($row['scope_group_id']));
    $code = explode(':', (string) ($row['last_error_code'] ?? $row['lastError'] ?? ''), 2)[0];
    $anonymised = $code === 'integration.application_anonymised'
      || (json_decode($application->getData() ?? '', TRUE)['_anonymized'] ?? NULL) === TRUE;
    if (!$foreign && !$anonymised) {
      return NULL;
    }
    $result = [
      'uuid' => (string) $row['uuid'],
      'status' => (string) $row['status'],
      'created' => (int) ($row['created'] ?? 0),
      'changed' => (int) ($row['changed'] ?? 0),
      'processed' => $row['processed'] ?? NULL,
      'meta' => ['canApproveDispatch' => FALSE, 'canRetry' => FALSE, 'canResume' => FALSE, 'canReplan' => FALSE],
    ];
    if ($foreign) {
      $result['foreign_scope'] = TRUE;
      $result['transport_status'] = 'transferred_by_another_tenant';
    }
    else {
      $result['anonymised'] = TRUE;
      $result['last_error_code'] = 'integration.application_anonymised';
    }
    return $result;
  }

}
