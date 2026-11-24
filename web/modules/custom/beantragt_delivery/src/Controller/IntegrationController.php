<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Controller;

use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\DataContractRegistry;
use Drupal\beantragt_delivery\Service\IntegrationCredentialProfiles;
use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_delivery\Service\IntegrationAudit;
use Drupal\beantragt_delivery\Service\TransferPlanningRecovery;
use Drupal\beantragt_delivery\Service\IntegrationFormCatalog;
use Drupal\beantragt_delivery\Service\TransferFailures;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Strict, scope-bound management API; it never invokes a delivery gateway.
 */
final class IntegrationController extends ControllerBase {

  /**
   * Constructs the management controller.
   */
  public function __construct(
    private readonly IntegrationRepository $repository,
    private readonly ScopeAccess $scopeAccess,
    private readonly DataContractRegistry $registry,
    private readonly IntegrationCredentialProfiles $profiles,
    private readonly AccountProxyInterface $account,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly IntegrationAudit $audit,
    private readonly IntegrationFormCatalog $forms,
    private readonly ?TransferPlanningRecovery $recovery = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_delivery.integration_repository'),
      $container->get('beantragt_delivery.scope_access'),
      $container->get('beantragt_delivery.contract_registry'),
      $container->get('beantragt_delivery.credential_profiles'),
      $container->get('current_user'),
      $container->get('csrf_token'),
      $container->get('beantragt_delivery.integration_audit'),
      $container->get('beantragt_delivery.integration_form_catalog'),
      $container->get('beantragt_delivery.planning_recovery'),
    );
  }

  /**
   * Dispatches only operations declared by this module's static routes.
   */
  public function handle(Request $request): JsonResponse {
    $scope = NULL;
    $body = [];
    try {
      $resource = (string) $request->attributes->get('integration_resource');
      $operation = (string) $request->attributes->get('integration_operation');
      $permission = $resource === 'connection' || $resource === 'profile'
        ? 'administer integration connections' : 'administer integration mappings';
      $permitted = $this->account->hasPermission($permission)
        || ($resource === 'contract' && $this->account->hasPermission('administer integration connections'));
      if (!$this->account->isAuthenticated() || !$permitted) {
        throw new IntegrationException('integration.access_denied', 403);
      }
      if (!$request->isMethodSafe()) {
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if ($token === '' || (!$this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)
          && !$this->csrfToken->validate($token, 'rest'))) {
          throw new IntegrationException('integration.csrf_invalid', 403);
        }
      }
      $uuid = $request->attributes->get('uuid');
      $row = NULL;
      if (is_string($uuid)) {
        if ($request->query->all() !== []) {
          throw new IntegrationException('integration.unknown_key', 400);
        }
        $scope = $resource === 'form_schema'
          ? $this->forms->scope($uuid)
          : $this->repository->scopeOf($resource, $uuid);
        if ($scope === NULL || !$this->scopeAccess->allowsAdministration($scope, $this->account)) {
          throw new IntegrationException(TransferFailures::resourceNotFound($resource), 404);
        }
        $row = $resource === 'form_schema'
          ? ['scope_group_id' => $scope]
          : $this->repository->load($resource, $uuid);
        if (!$row || (int) $row['scope_group_id'] !== $scope) {
          throw new IntegrationException(TransferFailures::resourceNotFound($resource), 404);
        }
      }
      else {
        $scope = $this->scope($request);
      }
      $body = $request->isMethodSafe() ? [] : $this->body($request);
      $uid = (int) $this->account->id();
      $version = (int) $request->attributes->get('version', 0);
      if (in_array($operation, ['activate', 'disable', 'deactivate', 'retire', 'check', 'check_version'], TRUE) && $body !== []) {
        throw new IntegrationException('integration.unknown_key', 400);
      }
      $before = $operation === 'update_version' ? $this->repository->version($uuid, $version) : $row;
      $data = match ($operation) {
        'contracts' => $this->registry->list(),
        'paths' => $this->registry->paths((string) $request->attributes->get('key'), (string) $request->attributes->get('contract_version')),
        'profiles' => $this->profiles->options($scope),
        'list' => $this->repository->listResult($resource, $scope),
        'forms' => $this->forms->list($scope),
        'fields' => $this->forms->fields($uuid, (int) $request->attributes->get('revision_id')),
        'read_version' => $this->repository->version($uuid, $version),
        'check_version' => $this->repository->checkVersion($uuid, $version),
        'compatibility' => $this->repository->compatibility($uuid, $version, (int) $request->attributes->get('revision_id')),
        'replace' => $this->replace($uuid, $body),
        'adopt_revision' => $this->adoptRevision($uuid, $body, $uid),
        'read' => $row,
        'create' => $this->repository->create($resource, $body + ['scope_group_id' => $scope], $uid),
        'update' => $this->repository->update($resource, $uuid, $body),
        'presentation' => $this->repository->updatePresentation($uuid, $body),
        'coupling' => $this->repository->updateCoupling($uuid, $body),
        'create_version' => $this->repository->createVersion($uuid, $body, $uid),
        'update_version' => $this->repository->updateVersion($uuid, $version, $body),
        'check' => $this->repository->checkConnection($uuid),
        'retire' => $version > 0
          ? $this->repository->transitionVersion($uuid, $version, 'retired')
          : throw new IntegrationException('integration.transition_invalid'),
        'activate' => $version > 0
          ? $this->repository->transitionVersion($uuid, $version, 'active')
          : $this->repository->transition($resource, $uuid, 'active'),
        'disable' => $this->repository->transition($resource, $uuid, 'disabled'),
        'deactivate' => $this->repository->transition($resource, $uuid, 'inactive'),
        default => throw new IntegrationException('integration.route_not_found', 404),
      };
      if (!$request->isMethodSafe() && $operation !== 'check_version') {
        $fields = [];
        // Presentation values sit nested; compare there so a closure-rule
        // flip is attributable in the audit trail.
        $compare_before = $operation === 'presentation' ? ($before['presentation'] ?? []) : $before;
        $compare_after = $operation === 'presentation' ? ($data['presentation'] ?? []) : $data;
        foreach (array_keys($body) as $field) {
          if (($compare_before[$field] ?? NULL) !== ($compare_after[$field] ?? NULL)) {
            $fields[] = $field;
          }
        }
        $this->audit->record($request, $scope, 'success', $fields, $data['uuid'] ?? $uuid, $data['version'] ?? NULL);
        if ($operation === 'adopt_revision') {
          $draft = clone $request;
          $draft->attributes->set('integration_resource', 'mapping');
          $this->audit->record($draft, $scope, 'success', [], $data['draft']['mapping_uuid'] ?? NULL, $data['draft']['version'] ?? NULL);
        }
        if ($operation === 'replace') {
          $previous = clone $request;
          $previous->attributes->set('integration_operation', 'deactivate');
          $this->audit->record($previous, $scope, 'success', [], $uuid);
        }
      }
      if (in_array($operation, ['list', 'forms'], TRUE)) {
        $data['data'] = array_map($this->project(...), $data['data']);
        $data['meta']['pending_planning_count'] = ($this->recovery ?? NULL)?->pendingCount($scope) ?? 0;
        return $this->respond($data, 200);
      }
      return $this->respond(['data' => $this->project($data)], in_array($operation, ['create', 'create_version'], TRUE) ? 201 : 200);
    }
    catch (IntegrationException $exception) {
      $this->audit->record($request, $scope, in_array($exception->status, [403, 404], TRUE) ? 'denied' : 'failed', [], code: $exception->errorCode);
      if ($exception->status >= 500) {
        $request->attributes->set('_integration_audited', TRUE);
        throw $exception;
      }
      return $this->respond(['error' => ['code' => $exception->errorCode, 'details' => $exception->errors]], $exception->status);
    }
    catch (\Throwable $exception) {
      if ($exception instanceof \InvalidArgumentException && $exception->getMessage() === 'integration.contract_not_found') {
        $this->audit->record($request, $scope, 'failed', [], code: 'integration.contract_not_found');
        return $this->respond(['error' => ['code' => 'integration.contract_not_found', 'details' => []]], 404);
      }
      // Core logs unexpected exceptions with full server-side context. The
      // integration subscriber runs afterwards and removes response internals.
      $this->audit->record($request, $scope, 'failed', [], code: 'integration.internal_error');
      $request->attributes->set('_integration_audited', TRUE);
      throw $exception;
    }
  }

  /**
   * Requires one replacement identity; repository enforces scope and slot.
   */
  private function replace(string $uuid, array $body): array {
    if (array_keys($body) !== ['replacement_uuid'] || !is_string($body['replacement_uuid'])) {
      throw new IntegrationException('integration.input_invalid');
    }
    return $this->repository->replaceActive($uuid, $body['replacement_uuid']);
  }

  /**
   * Requires one positive form revision; repository checks form and scope.
   */
  private function adoptRevision(string $uuid, array $body, int $uid): array {
    if (array_keys($body) !== ['revision_id'] || !is_int($body['revision_id']) || $body['revision_id'] < 1) {
      throw new IntegrationException('integration.input_invalid');
    }
    $adoption = $this->repository->adoptRevision($uuid, $body['revision_id'], $uid);
    $adoption['draft'] = $this->project($adoption['draft']);
    return $adoption;
  }

  /**
   * Creator IDs are audit metadata, not tenant-facing editor fields.
   */
  private function project(array $data): array {
    unset($data['uid']);
    if (isset($data['versions'])) {
      $data['versions'] = array_map($this->project(...), $data['versions']);
    }
    return $data;
  }

  /**
   * Requires an explicit, authorized scope for collection and catalog routes.
   */
  private function scope(Request $request): int {
    $query = $request->query->all();
    if (array_diff(array_keys($query), ['scope']) !== []) {
      throw new IntegrationException('integration.unknown_key', 400);
    }
    $raw = $query['scope'] ?? NULL;
    if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D', $raw)) {
      throw new IntegrationException('integration.scope_required', 400);
    }
    $scope = (int) $raw;
    if (!$this->scopeAccess->allowsAdministration($scope, $this->account)) {
      throw new IntegrationException('integration.scope_not_found', 404);
    }
    return $scope;
  }

  /**
   * Reads one bounded JSON object envelope and rejects caller-owned scope IDs.
   */
  private function body(Request $request): array {
    $raw = (string) $request->getContent();
    if (strlen($raw) > 131072) {
      throw new IntegrationException('integration.input_too_large', 413);
    }
    if ($raw === '') {
      return [];
    }
    if ($request->getContentTypeFormat() !== 'json') {
      throw new IntegrationException('integration.json_required', 415);
    }
    try {
      $object = json_decode($raw, FALSE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new IntegrationException('integration.invalid_json', 400);
    }
    if (!$object instanceof \stdClass || array_keys(get_object_vars($object)) !== ['data'] || !$object->data instanceof \stdClass) {
      throw new IntegrationException('integration.invalid_input', 400);
    }
    foreach (['compatible_revisions'] as $list) {
      if (property_exists($object->data, $list) && !is_array($object->data->{$list})) {
        throw new IntegrationException('integration.input_invalid');
      }
    }
    if (property_exists($object->data, 'rules')) {
      $rules = $object->data->rules;
      if (!$rules instanceof \stdClass || !is_array($rules->rules ?? NULL)
        || (property_exists($rules, 'occurrences') && !is_array($rules->occurrences))) {
        throw new IntegrationException('integration.rules_invalid');
      }
      foreach ($rules->rules as $rule) {
        if (!$rule instanceof \stdClass || (property_exists($rule, 'valueMap') && !$rule->valueMap instanceof \stdClass)) {
          throw new IntegrationException('integration.rules_invalid');
        }
      }
      foreach ($rules->occurrences ?? [] as $occurrence) {
        if (!$occurrence instanceof \stdClass) {
          throw new IntegrationException('integration.rules_invalid');
        }
      }
    }
    $data = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR)['data'];
    // Numeric option keys are still JSON object properties, never a rule list.
    if (isset($object->data->rules->rules) && is_array($object->data->rules->rules)) {
      foreach ($object->data->rules->rules as $index => $rule) {
        if ($rule instanceof \stdClass && property_exists($rule, 'valueMap')) {
          $data['rules']['rules'][$index]['valueMap'] = $rule->valueMap;
        }
      }
    }
    if (array_key_exists('scope_group_id', $data)) {
      throw new IntegrationException('integration.unknown_key', 400);
    }
    return $data;
  }

  /**
   * Emits the common private JSON envelope.
   */
  private function respond(array $data, int $status): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
