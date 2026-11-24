<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Records administrative actions without bodies, credentials or field values.
 */
final class IntegrationAudit {

  /**
   * Shared bounded durable writer, absent only in logger-only unit contexts.
   */
  private readonly ?TransferAuditRepository $transferAudit;

  private const FIELDS = [
    'label', 'adapter', 'environment', 'credential_profile', 'destination_id',
    'service_urn', 'service_name', 'contract_key', 'contract_version',
    'reply_process_standard', 'form_schema_uuid', 'compatible_revisions', 'rules',
    'connection_uuid', 'mapping_uuid', 'mapping_version', 'dispatch_mode',
    'replacement_uuid', 'handover_label', 'response_label', 'approved_label', 'rejected_label', 'filed_label', 'closure_rule',
  ];

  /**
   * Constructs the dedicated audit logger.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $account,
    private readonly ?Connection $database = NULL,
    ?TransferAuditRepository $transfer_audit = NULL,
  ) {
    $this->transferAudit = $transfer_audit ?? ($database === NULL ? NULL : new TransferAuditRepository($database));
  }

  /**
   * Appends one bounded event containing identifiers and changed field names.
   */
  public function record(Request $request, ?int $scope, string $outcome, array $fields = [], ?string $uuid = NULL, ?int $version = NULL, ?string $code = NULL): void {
    $uuid ??= $request->attributes->get('uuid');
    $requested_scope = $request->query->all()['scope'] ?? NULL;
    if ($scope === NULL && is_string($requested_scope) && preg_match('/^[1-9][0-9]{0,9}$/D', $requested_scope)) {
      $scope = (int) $requested_scope;
    }
    $resource = (string) $request->attributes->get('integration_resource', 'unknown');
    $action = (string) $request->attributes->get('integration_operation', 'unknown');
    $event = [
      'uid' => (int) $this->account->id(),
      'timestamp' => $this->time->getCurrentTime(),
      'scope_group_id' => $scope,
      'resource' => preg_match('/^[a-z_]{1,32}$/D', $resource) ? $resource : 'unknown',
      'uuid' => is_string($uuid) && Uuid::isValid($uuid) ? strtolower($uuid) : NULL,
      'version' => $version ?? ((int) $request->attributes->get('version', 0) ?: NULL),
      'action' => preg_match('/^[a-z_]{1,32}$/D', $action) ? $action : 'unknown',
      'outcome' => $outcome,
      'changed_fields' => array_values(array_intersect(self::FIELDS, $fields)),
      'code' => $code,
    ];
    if ($resource === 'transfer' && $outcome === 'denied' && $this->database !== NULL) {
      $recorded = $this->transferAudit?->append([
        'application_id' => (int) $request->attributes->get('application_id', 0),
        'application_uuid' => (string) $request->attributes->get('application_uuid', ''),
        'scope_group_id' => $scope,
        'transfer_uuid' => $event['uuid'] ?? '',
        'uid' => $event['uid'],
        'action' => $event['action'],
        'outcome' => 'denied',
        'code' => $code ?? '',
        'created' => $event['timestamp'],
      ]);
      if (!$recorded) {
        return;
      }
    }
    // Durable transfer evidence is independent of rotating or failing dblog.
    try {
      $this->logger->notice('Integration administrative event: @event', ['@event' => json_encode($event, JSON_THROW_ON_ERROR)]);
    }
    catch (\Throwable) {
      // Never turn a committed, durably audited approval into an HTTP failure.
    }
  }

}
