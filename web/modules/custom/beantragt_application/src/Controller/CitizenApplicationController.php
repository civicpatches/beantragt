<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Controller;

use Drupal\beantragt_application\ApplicationConsentProjection;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\CitizenStatusProjection;
use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\beantragt_application\WorkflowStatusResolver;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_workflow_status\Entity\WorkflowStatusInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns the authenticated citizen's own application data.
 */
final class CitizenApplicationController extends ControllerBase {

  /**
   * Public API version emitted by v1 citizen application endpoints.
   */
  private const API_VERSION = '1.0';

  /**
   * Default page size for the v1 application list.
   */
  private const DEFAULT_LIMIT = 20;

  /**
   * Maximum page size for the v1 application list.
   */
  private const MAX_LIMIT = 100;

  /**
   * Maximum offset accepted by the v1 application list.
   */
  private const MAX_OFFSET = 10000;

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly ApplicationDataProtectionService $dataProtection,
    private readonly CitizenStatusProjection $statusProjection,
    private readonly ConfirmedApplicationMailbox $confirmedMailbox,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_application.data_protection'),
      $container->get('beantragt_application.citizen_status_projection'),
      $container->get('beantragt_application.confirmed_mailbox'),
    );
  }

  /**
   * Lists applications owned by the current citizen.
   */
  public function list(Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      throw new AccessDeniedHttpException();
    }
    $account = $this->currentUser();
    $scope = $this->resolveWorkspaceScope($request);

    $storage = $this->entityTypeManager()->getStorage('application');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_email_confirmed', TRUE)
      ->sort('created', 'DESC');
    $this->restrictToOwnedApplications($query, $account);
    $this->applyWorkspaceScope($query, $scope);
    $ids = $query->execute();

    $applications = [];
    foreach ($storage->loadMultiple($ids) as $application) {
      if ($application instanceof ApplicationInterface
        && $this->canViewOwnApplication($application, $account)) {
        $this->confirmedMailbox->auditRead($application, $account);
        $applications[] = $this->normalizeApplication($application, FALSE, $this->requestLangcode($request));
      }
    }

    return $this->noStoreResponse(['applications' => $applications]);
  }

  /**
   * Lists applications owned by the current citizen in the public v1 shape.
   */
  public function listV1(Request $request): JsonResponse {
    $account = $this->currentUser();
    $auth_error = $this->authorizeApiV1($account);
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }

    $pagination = $this->parsePagination($request);
    if ($pagination instanceof JsonResponse) {
      return $pagination;
    }

    $scope = $this->resolveRequiredWorkspaceScopeV1($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }

    $storage = $this->entityTypeManager()->getStorage('application');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_email_confirmed', TRUE)
      ->sort('created', 'DESC');
    $this->restrictToOwnedApplications($query, $account);
    $this->applyWorkspaceScope($query, $scope);
    $ids = $query->execute();

    $applications = [];
    foreach ($storage->loadMultiple($ids) as $application) {
      if ($application instanceof ApplicationInterface
        && $this->canViewOwnApplication($application, $account)) {
        $applications[] = $application;
      }
    }

    $has_more = count($applications) > ($pagination['offset'] + $pagination['limit']);
    $page = [];
    foreach (array_slice($applications, $pagination['offset'], $pagination['limit']) as $application) {
      $this->confirmedMailbox->auditRead($application, $account);
      $page[] = $this->normalizeApplicationV1($application, FALSE, $this->requestLangcode($request));
    }

    return $this->noStoreResponse([
      'data' => $page,
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'applications',
        'count' => count($page),
        'page' => $this->paginationMeta($pagination['limit'], $pagination['offset'], $has_more),
      ],
    ]);
  }

  /**
   * Returns a single application owned by the current citizen.
   */
  public function detail(string $application_uuid, Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      throw new AccessDeniedHttpException();
    }
    $account = $this->currentUser();
    $scope = $this->resolveWorkspaceScope($request);

    $storage = $this->entityTypeManager()->getStorage('application');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_email_confirmed', TRUE)
      ->condition('uuid', $application_uuid)
      ->range(0, 1);
    $this->restrictToOwnedApplications($query, $account, $application_uuid);
    $this->applyWorkspaceScope($query, $scope);
    $ids = $query->execute();
    if (!$ids) {
      throw new NotFoundHttpException();
    }

    $application = $storage->load(reset($ids));
    if (!$application instanceof ApplicationInterface
      || !$this->canViewOwnApplication($application, $account)) {
      throw new NotFoundHttpException();
    }

    $this->confirmedMailbox->auditRead($application, $account);
    return $this->noStoreResponse([
      'application' => $this->normalizeApplication($application, TRUE, $this->requestLangcode($request)),
    ]);
  }

  /**
   * Returns a single application owned by the current citizen in the v1 shape.
   */
  public function detailV1(string $application_uuid, Request $request): JsonResponse {
    $account = $this->currentUser();
    $auth_error = $this->authorizeApiV1($account);
    if ($auth_error instanceof JsonResponse) {
      return $auth_error;
    }

    $scope = $this->resolveRequiredWorkspaceScopeV1($request);
    if ($scope instanceof JsonResponse) {
      return $scope;
    }

    $storage = $this->entityTypeManager()->getStorage('application');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_email_confirmed', TRUE)
      ->condition('uuid', $application_uuid)
      ->range(0, 1);
    $this->restrictToOwnedApplications($query, $account, $application_uuid);
    $this->applyWorkspaceScope($query, $scope);
    $ids = $query->execute();
    if (!$ids) {
      return $this->applicationNotFoundV1();
    }

    $application = $storage->load(reset($ids));
    if (!$application instanceof ApplicationInterface
      || !$this->canViewOwnApplication($application, $account)) {
      return $this->applicationNotFoundV1();
    }

    $this->confirmedMailbox->auditRead($application, $account);
    return $this->noStoreResponse([
      'data' => $this->normalizeApplicationV1($application, TRUE, $this->requestLangcode($request)),
      'meta' => [
        'apiVersion' => self::API_VERSION,
        'resource' => 'application',
        'protectedDataRedacted' => $this->dataProtection->applicationHasSensitiveFields($application),
      ],
    ]);
  }

  /**
   * Selects direct owners and confirmed anonymous mailbox candidates.
   */
  private function restrictToOwnedApplications(QueryInterface $query, AccountInterface $account, ?string $application_uuid = NULL): void {
    $owners = $query->orConditionGroup()->condition('uid', (int) $account->id());
    $mailbox_ids = $this->confirmedMailbox->matchingApplicationIds($account, $application_uuid);
    if ($mailbox_ids !== []) {
      $owners->condition('id', $mailbox_ids, 'IN');
    }
    $query->condition($owners);
  }

  /**
   * Requires citizen ownership even if the account has separate staff rights.
   */
  private function canViewOwnApplication(ApplicationInterface $application, AccountInterface $account): bool {
    return !$this->isServiceAccount($account)
      && ((int) $application->getOwnerId() === (int) $account->id() || $this->confirmedMailbox->allowsRead($application, $account))
      && $application->access('view', $account);
  }

  /**
   * Authorizes the citizen-facing public v1 application read profile.
   */
  private function authorizeApiV1(AccountInterface $account): ?JsonResponse {
    if ((int) $account->id() <= 0) {
      return $this->apiV1Error(
        'auth.required',
        'Authentication required.',
        Response::HTTP_UNAUTHORIZED,
      );
    }
    if (!$account->hasPermission('view own application entities')) {
      return $this->apiV1Error(
        'application.forbidden',
        'The authenticated account is not allowed to read own applications.',
        Response::HTTP_FORBIDDEN,
      );
    }
    if ($this->isServiceAccount($account)) {
      return $this->apiV1Error(
        'auth.unsupported_profile',
        'Service accounts cannot read citizen application data through this endpoint.',
        Response::HTTP_FORBIDDEN,
      );
    }
    return NULL;
  }

  /**
   * Returns TRUE when an account is a backend service account.
   */
  private function isServiceAccount(AccountInterface $account): bool {
    return BeantragtRoles::isServiceAccount($account);
  }

  /**
   * Creates a private, non-cacheable JSON response.
   */
  private function noStoreResponse(array $data): JsonResponse {
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Creates a private v1 error envelope.
   */
  private function apiV1Error(string $code, string $message, int $status): JsonResponse {
    return $this->noStoreResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
      'meta' => [
        'apiVersion' => self::API_VERSION,
      ],
    ])->setStatusCode($status);
  }

  /**
   * Creates the v1 not-found response for hidden or missing applications.
   */
  private function applicationNotFoundV1(): JsonResponse {
    return $this->apiV1Error(
      'application.not_found',
      'Application not found.',
      Response::HTTP_NOT_FOUND,
    );
  }

  /**
   * Normalizes an application into the citizen API response shape.
   */
  private function normalizeApplication(ApplicationInterface $application, bool $include_data = FALSE, ?string $langcode = NULL): array {
    $langcode ??= $this->requestLangcode(NULL);
    $schema = $this->submittedSchema($application);
    $status_info = $this->citizenStatusInfo($application, $langcode);
    $data = [
      'id' => $application->uuid(),
      'referenceNumber' => $application->getReferenceNumber() ?: strtoupper(substr($application->uuid(), 0, 8)),
      'formTitle' => $schema?->label() ?: 'Antrag',
      'status' => $status_info['status'],
      'statusLabel' => $status_info['label'],
      'statusColor' => $status_info['color'],
      'statusIcon' => $status_info['icon'],
      'createdAt' => $this->formatTimestamp($application->getCreatedTime()),
      'updatedAt' => $this->formatTimestamp($application->getChangedTime()),
    ];

    if ($include_data) {
      $data += ApplicationConsentProjection::project($application);
      $data['data'] = $this->decodeApplicationData($application);
      $data['fieldLabels'] = $this->fieldLabels($schema, $langcode);
      $data['timeline'] = $this->buildTimeline($application, $langcode);
    }

    return $data;
  }

  /**
   * Maps form field ids to their labels in the request language.
   *
   * @return array<string, string>
   *   Labels keyed by field id; empty when the schema is unavailable.
   */
  private function fieldLabels(?EntityInterface $schema, string $langcode): array {
    if (!$schema instanceof FormSchemaInterface) {
      return [];
    }
    $payload = json_decode((string) $schema->getSchema(), TRUE);
    if (!is_array($payload)) {
      return [];
    }
    $labels = [];
    $walk = function (array $node) use (&$walk, &$labels, $langcode): void {
      foreach (['sections', 'steps', 'fields', 'children'] as $key) {
        foreach (is_array($node[$key] ?? NULL) ? $node[$key] : [] as $child) {
          if (!is_array($child)) {
            continue;
          }
          $id = $child['id'] ?? NULL;
          $translated = $child['i18n'][$langcode]['label'] ?? NULL;
          $label = is_string($translated) && trim($translated) !== '' ? $translated : ($child['label'] ?? NULL);
          if ($key === 'fields' && is_string($id) && $id !== '' && is_string($label) && trim($label) !== '') {
            $labels[$id] = trim($label);
          }
          $walk($child);
        }
      }
    };
    $walk($payload);
    return $labels;
  }

  /**
   * Loads the schema revision the application was submitted against.
   */
  private function submittedSchema(ApplicationInterface $application): ?EntityInterface {
    return PublicSubmission::submittedSchema($application, $this->entityTypeManager());
  }

  /**
   * Language for citizen-facing labels: the UI's ?lang=, else Drupal's.
   *
   * Nuxt calls Drupal without a language prefix, so the negotiated language
   * is always the site default.
   */
  private function requestLangcode(?Request $request): string {
    $requested = $request?->query->get('lang');
    if (in_array($requested, ['de', 'en'], TRUE)) {
      return $requested;
    }
    $current = $this->languageManager()->getCurrentLanguage()->getId();
    return in_array($current, ['de', 'en'], TRUE) ? $current : 'de';
  }

  /**
   * Normalizes an application into the external v1 response shape.
   */
  private function normalizeApplicationV1(ApplicationInterface $application, bool $include_data = FALSE, ?string $langcode = NULL): array {
    $langcode ??= $this->requestLangcode(NULL);
    $schema = $this->submittedSchema($application);
    $status_info = $this->citizenStatusInfo($application, $langcode);
    $form = [
      'title' => $schema instanceof EntityInterface ? (string) ($schema->label() ?? 'Antrag') : 'Antrag',
    ];
    if ($schema instanceof EntityInterface && is_string($schema->uuid()) && $schema->uuid() !== '') {
      $form['id'] = $schema->uuid();
    }

    $data = [
      'type' => 'application',
      'id' => $application->uuid(),
      'referenceNumber' => $application->getReferenceNumber() ?: strtoupper(substr($application->uuid(), 0, 8)),
      'form' => $form,
      'status' => array_filter([
        'code' => $status_info['status'],
        'label' => $status_info['label'],
        'color' => $status_info['color'],
        'icon' => $status_info['icon'],
      ], static fn(mixed $value): bool => $value !== ''),
      'submittedAt' => $this->formatTimestamp($application->getCreatedTime()),
      'updatedAt' => $this->formatTimestamp($application->getChangedTime()),
    ];

    if ($include_data) {
      $data['submittedData'] = $this->dataProtection->redactApplicationData($application);
      $data['timeline'] = $this->buildTimeline($application, $langcode);
      $data['delivery'] = $this->deliveryProjection($application);
    }

    return $data;
  }

  /**
   * Applies the public workspace route scope to an application query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The application entity query.
   * @param array{jurisdiction_id?: int, organisation_id?: int} $scope
   *   Resolved workspace scope IDs.
   */
  private function applyWorkspaceScope(QueryInterface $query, array $scope): void {
    if (!empty($scope['organisation_id'])) {
      $query->condition('field_organisation', $scope['organisation_id']);
      return;
    }
    if (!empty($scope['jurisdiction_id'])) {
      $query->condition('field_jurisdiction', $scope['jurisdiction_id']);
    }
  }

  /**
   * Resolves optional public workspace slugs to internal group IDs.
   *
   * @return array{jurisdiction_id?: int, organisation_id?: int}
   *   Resolved workspace scope IDs.
   */
  private function resolveWorkspaceScope(Request $request): array {
    $jurisdiction_slug = $this->normalizePublicSlug($request->query->get('jurisdiction'));
    $organisation_slug = $this->normalizePublicSlug(
      $request->query->get('organisation')
      ?? $request->query->get('organization')
      ?? $request->query->get('org')
    );

    if (($request->query->has('jurisdiction') && $jurisdiction_slug === NULL)
      || (($request->query->has('organisation') || $request->query->has('organization') || $request->query->has('org')) && $organisation_slug === NULL)) {
      throw new NotFoundHttpException();
    }

    $scope = [];
    $jurisdiction = $jurisdiction_slug ? $this->loadGroupBySlug('jur', $jurisdiction_slug) : NULL;
    if ($jurisdiction_slug && !$jurisdiction) {
      throw new NotFoundHttpException();
    }
    if ($jurisdiction) {
      $scope['jurisdiction_id'] = (int) $jurisdiction->id();
    }

    if ($organisation_slug) {
      $organisation = $this->loadOrganisationBySlug($organisation_slug, $scope['jurisdiction_id'] ?? NULL);
      if (!$organisation) {
        throw new NotFoundHttpException();
      }
      $scope['organisation_id'] = (int) $organisation->id();
      if (empty($scope['jurisdiction_id'])
        && $organisation->hasField('field_jurisdiction')
        && !$organisation->get('field_jurisdiction')->isEmpty()) {
        $scope['jurisdiction_id'] = (int) $organisation->get('field_jurisdiction')->target_id;
      }
    }

    return $scope;
  }

  /**
   * Resolves the required public v1 workspace scope.
   *
   * @return array{jurisdiction_id?: int, organisation_id?: int}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Resolved workspace scope, or an error response.
   */
  private function resolveRequiredWorkspaceScopeV1(Request $request): array|JsonResponse {
    if (!$request->query->has('jurisdiction')) {
      return $this->apiV1Error(
        'workspace.required',
        'Query parameter jurisdiction is required.',
        Response::HTTP_BAD_REQUEST,
      );
    }

    try {
      return $this->resolveWorkspaceScope($request);
    }
    catch (NotFoundHttpException) {
      return $this->apiV1Error(
        'workspace.not_found',
        'Workspace scope not found.',
        Response::HTTP_NOT_FOUND,
      );
    }
  }

  /**
   * Parses the v1 list pagination query.
   *
   * @return array{limit: int, offset: int}|\Symfony\Component\HttpFoundation\JsonResponse
   *   Parsed pagination values, or an error response.
   */
  private function parsePagination(Request $request): array|JsonResponse {
    $limit = $this->parseIntegerQuery($request, 'limit', self::DEFAULT_LIMIT);
    $offset = $this->parseIntegerQuery($request, 'offset', 0);
    if ($limit === NULL || $limit < 1 || $limit > self::MAX_LIMIT) {
      return $this->apiV1Error(
        'pagination.invalid',
        'Query parameter limit must be an integer between 1 and 100.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    if ($offset === NULL || $offset < 0 || $offset > self::MAX_OFFSET) {
      return $this->apiV1Error(
        'pagination.invalid',
        'Query parameter offset must be an integer between 0 and 10000.',
        Response::HTTP_BAD_REQUEST,
      );
    }
    return [
      'limit' => $limit,
      'offset' => $offset,
    ];
  }

  /**
   * Parses an integer query parameter.
   */
  private function parseIntegerQuery(Request $request, string $name, int $default): ?int {
    $raw = $request->query->get($name);
    if ($raw === NULL || $raw === '') {
      return $default;
    }
    $value = filter_var((string) $raw, FILTER_VALIDATE_INT);
    return $value === FALSE ? NULL : (int) $value;
  }

  /**
   * Builds the v1 pagination metadata.
   *
   * @return array{limit: int, offset: int, hasMore: bool, nextOffset?: int}
   *   Pagination metadata.
   */
  private function paginationMeta(int $limit, int $offset, bool $has_more): array {
    $page = [
      'limit' => $limit,
      'offset' => $offset,
      'hasMore' => $has_more,
    ];
    if ($has_more) {
      $page['nextOffset'] = $offset + $limit;
    }
    return $page;
  }

  /**
   * Normalizes a public workspace slug.
   */
  private function normalizePublicSlug(mixed $value): ?string {
    if (is_array($value)) {
      $value = reset($value);
    }
    if (!is_string($value)) {
      return NULL;
    }
    $slug = strtolower(trim($value));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Loads a jurisdiction or organisation group by public slug.
   */
  private function loadGroupBySlug(string $bundle, string $slug): ?FieldableEntityInterface {
    $storage = $this->entityTypeManager()->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('field_slug', $slug)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $group = $storage->load(reset($ids));
    return $group instanceof FieldableEntityInterface ? $group : NULL;
  }

  /**
   * Loads an organisation group by public slug and optional jurisdiction.
   */
  private function loadOrganisationBySlug(string $slug, ?int $jurisdiction_id): ?FieldableEntityInterface {
    $storage = $this->entityTypeManager()->getStorage('group');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_slug', $slug)
      ->range(0, 50);
    if ($jurisdiction_id !== NULL) {
      $query->condition('field_jurisdiction', $jurisdiction_id);
    }
    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }
    $group = $storage->load(reset($ids));
    return $group instanceof FieldableEntityInterface ? $group : NULL;
  }

  /**
   * Builds display metadata for the current workflow status.
   *
   * @return array{status: string, label: string, color: string, icon: string}
   *   Render-ready workflow status data.
   */
  private function workflowStatusInfo(EntityInterface $application): array {
    $status = WorkflowStatusResolver::machineName($application, 'unknown');
    $info = [
      'status' => $status,
      'label' => '',
      'color' => '',
      'icon' => '',
    ];

    if (!$application instanceof FieldableEntityInterface
      || !$application->hasField('field_workflow_status')
      || $application->get('field_workflow_status')->isEmpty()) {
      return $info;
    }

    $status_entity = $application->get('field_workflow_status')->entity;
    if (!$status_entity instanceof FieldableEntityInterface) {
      return $info;
    }

    $info['label'] = (string) ($status_entity->label() ?? '');
    $info['color'] = $this->fieldValue($status_entity, 'field_color');
    $info['icon'] = $this->fieldValue($status_entity, 'field_icon');
    return $info;
  }

  /**
   * Builds display metadata of the current status as applicants see it.
   *
   * The citizen text of the status (or the connection text) replaces the
   * internal label; the same projection feeds the timeline, so list, detail
   * and the last timeline entry agree.
   *
   * @return array{status: string, label: string, color: string, icon: string}
   *   Render-ready workflow status data.
   */
  private function citizenStatusInfo(ApplicationInterface $application, string $langcode): array {
    $status = $application->hasField('field_workflow_status') && !$application->get('field_workflow_status')->isEmpty()
      ? $application->get('field_workflow_status')->entity
      : NULL;
    if (!$status instanceof WorkflowStatusInterface) {
      return $this->workflowStatusInfo($application);
    }
    $entry = $this->statusProjection->entry($application, $status, $langcode);
    return [
      'status' => WorkflowStatusResolver::machineName($application, 'unknown'),
      'label' => $entry['label'],
      'color' => $entry['color'],
      'icon' => $entry['icon'],
    ];
  }

  /**
   * Builds a non-PII workflow timeline from application revisions.
   *
   * Citizen texts, connection texts and collapsed internal steps come from
   * the citizen status projection. Entries without a staff actor carry no
   * actor key.
   *
   * @return array<int, array<string, string>>
   *   Timeline entries.
   */
  private function buildTimeline(ApplicationInterface $application, string $langcode): array {
    return array_map(
      static fn(array $entry): array => array_filter($entry, static fn(mixed $value): bool => is_string($value) && $value !== ''),
      $this->statusProjection->timeline($application, $langcode),
    );
  }

  /**
   * Reads a scalar field value from a fieldable entity.
   */
  private function fieldValue(FieldableEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }
    return trim((string) $entity->get($field_name)->value);
  }

  /**
   * Delivery block of the v1 detail: route, where it came from and when.
   *
   * Only the latest event; the record itself stays with staff.
   *
   * @return array{route: string, source: ?string, chosenAt: ?string}
   *   The block; source and chosenAt NULL without a record.
   */
  private function deliveryProjection(ApplicationInterface $application): array {
    $record = $application->getDeliveryRecord();
    $latest = $record === [] ? NULL : $record[array_key_last($record)];
    return [
      'route' => $application->getDeliveryRoute(),
      'source' => is_string($latest['source'] ?? NULL) ? $latest['source'] : NULL,
      'chosenAt' => is_int($latest['at'] ?? NULL) && $latest['at'] > 0 ? $this->formatTimestamp($latest['at']) : NULL,
    ];
  }

  /**
   * Formats a Drupal timestamp as an ISO-8601 string.
   */
  private function formatTimestamp(int $timestamp): string {
    return $timestamp > 0 ? date(\DateTimeInterface::ATOM, $timestamp) : '';
  }

  /**
   * Decodes the submitted application data for the authenticated owner.
   */
  private function decodeApplicationData(ApplicationInterface $application): array {
    return $this->dataProtection->revealDataJson($application->getData());
  }

}
