<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Controller;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Plugin\Validation\Constraint\FormSchemaPublishPermissionConstraintValidator;
use Drupal\beantragt_schema\Repository\FormSetupLog;
use Drupal\beantragt_schema\Service\FormSetupState;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Internal routes of the guided form setup.
 *
 * Nuxt proxy only, never /api/v1. Errors use {"error": {"code": "..."}}.
 * Staff routes (capabilities, state, decisions) run in the staff session and
 * rely on entity access, which includes the group scoping of
 * beantragt_group. The recommendations route is for the Nuxt service
 * account only and checks its own permission instead of entity access.
 */
final class FormSetupController implements ContainerInjectionInterface {

  /**
   * Maximum decisions per request: 100 fields x 4 rows + wording (sec. 5).
   */
  public const MAX_DECISIONS = 401;

  /**
   * Maximum recommendations per run (section 6).
   */
  public const MAX_RECOMMENDATIONS = 30;

  /**
   * Maximum information sheets per run (section 6).
   */
  public const MAX_DOCUMENTS = 3;

  /**
   * Maximum request body size in bytes.
   */
  private const MAX_BODY_BYTES = 1048576;

  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

  /**
   * Rule ids the Nitro catalog produces (privacy catalog, gate, format rule).
   */
  private const RULE_ID_PATTERN = '/^(privacy-catalog|gate|format-rule):[A-Za-z0-9_.:-]{1,110}$/';

  /**
   * Maximum length in characters of a string rule value.
   */
  private const MAX_RULE_VALUE_LENGTH = 200;

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly FormSetupLog $log,
    private readonly FormSetupState $setupState,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('beantragt_schema.form_setup_log'),
      $container->get('beantragt_schema.form_setup_state'),
      $container->get('logger.channel.beantragt_schema'),
    );
  }

  /**
   * Staff session routes: any authenticated account except the service one.
   *
   * Form access is checked per request with the codes of section 8.1.
   */
  public function accessStaff(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($account->isAuthenticated() && !BeantragtRoles::isServiceAccount($account))
      ->cachePerPermissions()
      ->cachePerUser();
  }

  /**
   * Recommendations route: the service account with its own permission.
   */
  public function accessService(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(
      $account->isAuthenticated()
      && BeantragtRoles::isServiceAccount($account)
      && $account->hasPermission('record form setup recommendations'),
    )->cachePerPermissions()->cachePerUser();
  }

  /**
   * GET /api/admin/form-setup/forms/{form}/capabilities.
   */
  public function capabilities(string $form): JsonResponse {
    return $this->handle(function () use ($form): array {
      $entity = $this->requireForm($form, FALSE);
      $can_edit = $entity->access('update', $this->currentUser);
      return [
        'canEdit' => $can_edit,
        'canPublish' => $this->canPublish($can_edit),
      ];
    });
  }

  /**
   * GET /api/admin/form-setup/forms/{form}/state.
   */
  public function state(string $form): JsonResponse {
    return $this->handle(function () use ($form): array {
      $entity = $this->requireForm($form, TRUE);
      $uuid = (string) $entity->uuid();
      $schema = $this->setupState->decodedSchema($entity);
      $reason = $this->setupState->ineligibleReason($entity);
      $decisions = $this->log->latestDecisions($uuid);
      $names = $this->userNames(array_column($decisions, 'uid'));
      $run = $this->log->run($uuid);
      $source_file = $entity->getSourceFilename();

      return [
        'form' => [
          'id' => $uuid,
          'revisionId' => (int) $entity->getRevisionId(),
          'version' => $entity->getVersion(),
          'workflowStatus' => $entity->getSchemaStatus(),
          'sourceFileName' => $source_file === NULL || $source_file === '' ? NULL : $source_file,
        ],
        'eligible' => $reason === NULL,
        'ineligibleReason' => $reason,
        'canEdit' => TRUE,
        'canPublish' => $this->canPublish(TRUE),
        'blockers' => $this->setupState->blockers($entity),
        'decisions' => array_map(fn(array $row): array => [
          'id' => $row['id'],
          'revisionId' => $row['revision_id'],
          'screenId' => $row['screen_id'],
          'fieldId' => $row['field_id'],
          'property' => $row['property'],
          'choice' => $row['choice'],
          'value' => $row['value'],
          'current' => $this->setupState->isCurrent($schema, $row),
          'recommendation' => $row['origin'] === NULL ? NULL : [
            'origin' => $row['origin'],
            'ruleId' => $row['origin_ref'],
            'modelId' => $row['model_id'],
            'value' => $row['recommended_value'],
            'quote' => $row['quote'],
            'source' => $this->sourceProjection($row),
          ],
          // A deleted account keeps a non-empty name, so one old row never
          // breaks the strict parser in Nitro.
          'decidedBy' => ['uid' => $row['uid'], 'name' => $names[$row['uid']] ?? '#' . $row['uid']],
          'decidedAt' => $row['created'],
        ], $decisions),
        'recommendations' => array_map(fn(array $row): array => [
          'id' => $row['id'],
          'revisionId' => $row['revision_id'],
          'screenId' => $row['screen_id'],
          'fieldId' => $row['field_id'],
          'property' => $row['property'],
          'value' => $row['value'],
          'quote' => $row['quote'],
          'modelId' => $row['model_id'],
          'basisHash' => $row['basis_hash'],
          'source' => $this->sourceProjection($row),
        ], $this->log->recommendations($uuid)),
        'run' => $run === NULL ? NULL : [
          'createdAt' => $run['created'],
          'modelId' => $run['model_id'],
          'kept' => (int) ($run['value']['kept'] ?? 0),
          'dropped' => (int) ($run['value']['dropped'] ?? 0),
        ],
      ];
    });
  }

  /**
   * POST /api/admin/form-setup/forms/{form}/decisions.
   *
   * The request names choices, never values: the value is read from the
   * schema of the named (current) revision (D9).
   */
  public function decisions(Request $request, string $form): JsonResponse {
    return $this->handle(function () use ($request, $form): array {
      $entity = $this->requireForm($form, TRUE);
      $body = $this->strict($this->body($request), ['revisionId', 'screenId', 'decisions']);
      $revision_id = $this->positiveInt($body['revisionId']);
      $screen_id = $this->string($body['screenId'], 128);
      $items = $this->list($body['decisions'], 1, self::MAX_DECISIONS);
      $this->requireEligibleCurrent($entity, $revision_id);

      $section = $this->setupState->section($this->setupState->decodedSchema($entity), $screen_id)
        ?? throw $this->error(422, 'form_setup.input_invalid');
      $uuid = (string) $entity->uuid();
      $rows = [];
      $seen = [];
      foreach ($items as $item) {
        $item = $this->strict($item, ['fieldId', 'property', 'choice', 'recommendation']);
        $field_id = $this->string($item['fieldId'], 128, TRUE);
        $property = $this->oneOf($item['property'], FormSetupState::DECISION_PROPERTIES);
        $choice = $this->oneOf($item['choice'], ['accepted', 'other', 'kept']);
        $field = NULL;
        if ($field_id !== '') {
          $field = $this->setupState->field($section, $field_id) ?? throw $this->error(422, 'form_setup.input_invalid');
        }
        if (!$this->setupState->propertyFits($property, $field)) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
        $key = $field_id . "\0" . $property;
        if (isset($seen[$key])) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
        $seen[$key] = TRUE;

        $snapshot = $this->recommendationSnapshot($uuid, $field_id, $property, $item['recommendation']);
        $validation_keys = [];
        if ($property === 'validation') {
          if ($snapshot === NULL || $snapshot['origin'] !== 'model' || !is_array($snapshot['recommended_value'])) {
            throw $this->error(422, 'form_setup.input_invalid');
          }
          $validation_keys = array_map('strval', array_keys($snapshot['recommended_value']));
        }
        $value = $this->setupState->valueAt($section, $field, $property, $validation_keys);
        if ($choice === 'accepted'
          && ($snapshot === NULL || !FormSetupState::sameValue($value, $snapshot['recommended_value']))) {
          throw $this->error(422, 'form_setup.choice_mismatch');
        }

        $rows[] = [
          'field_id' => $field_id,
          'property' => $property,
          'value' => $value,
          'choice' => $choice,
        ] + ($snapshot ?? []);
      }

      return ['saved' => $this->log->appendDecisions($uuid, $revision_id, $screen_id, $rows, (int) $this->currentUser->id())];
    });
  }

  /**
   * POST /api/admin/form-setup/forms/{form}/recommendations (service).
   *
   * Stores the verified recommendations of one information sheet run and
   * the one run row per form in one transaction.
   */
  public function recommendations(Request $request, string $form): JsonResponse {
    return $this->handle(function () use ($request, $form): array {
      $entity = $this->loadForm($form) ?? throw $this->error(404, 'form_setup.form_not_found');
      $body = $this->strict($this->body($request), ['revisionId', 'modelId', 'dropped', 'documents', 'recommendations']);
      $revision_id = $this->positiveInt($body['revisionId']);
      $model_id = $this->string($body['modelId'], 128);
      $dropped = $this->boundedInt($body['dropped'], 0, 100000);
      $documents = $this->list($body['documents'], 1, self::MAX_DOCUMENTS);
      $items = $this->list($body['recommendations'], 0, self::MAX_RECOMMENDATIONS);
      $this->requireEligibleCurrent($entity, $revision_id);
      $uuid = (string) $entity->uuid();
      if ($this->log->run($uuid) !== NULL) {
        throw $this->error(409, 'form_setup.run_exists');
      }

      $schema = $this->setupState->decodedSchema($entity);
      $selected = $this->setupState->editorContextSources($schema);
      $manifest = [];
      $versions = [];
      foreach ($documents as $document) {
        $document = $this->strict($document, ['id', 'version']);
        $id = $this->uuid($document['id']);
        $version = $this->string($document['version'], 128);
        if (isset($versions[$id]) || !array_key_exists($id, $selected) || $selected[$id] !== $version) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
        $versions[$id] = $version;
        $manifest[] = ['id' => $id, 'version' => $version];
      }

      $rows = [];
      $seen = [];
      foreach ($items as $item) {
        $item = $this->strict($item, ['screenId', 'fieldId', 'property', 'value', 'quote', 'basisHash', 'source']);
        $section = $this->setupState->section($schema, $this->string($item['screenId'], 128))
          ?? throw $this->error(422, 'form_setup.input_invalid');
        $field_id = $this->string($item['fieldId'], 128);
        $field = $this->setupState->field($section, $field_id) ?? throw $this->error(422, 'form_setup.input_invalid');
        $property = $this->oneOf($item['property'], FormSetupState::RECOMMENDATION_PROPERTIES);
        if (!$this->setupState->propertyFits($property, $field)) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
        $key = $field_id . "\0" . $property;
        if (isset($seen[$key])) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
        $seen[$key] = TRUE;
        $value = $this->recommendedValue($property, $field, $item['value']);
        $quote = $this->string($item['quote'], 300);
        if (mb_strlen($quote) < 10) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
        if (!is_string($item['basisHash']) || preg_match('/^[0-9a-f]{64}$/', $item['basisHash']) !== 1) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
        $source = $this->strict($item['source'], ['id', 'title', 'version', 'page']);
        $source_id = $this->uuid($source['id']);
        $source_version = $this->string($source['version'], 128);
        if (($versions[$source_id] ?? NULL) !== $source_version) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
        $page = $source['page'] === NULL ? NULL : $this->boundedInt($source['page'], 1, 100000);

        $rows[] = [
          'screen_id' => (string) $section['id'],
          'field_id' => $field_id,
          'property' => $property,
          'value' => $value,
          'origin' => 'model',
          'quote' => $quote,
          'basis_hash' => $item['basisHash'],
          'source_id' => $source_id,
          'source_title' => $this->string($source['title'], 255),
          'source_version' => $source_version,
          'source_page' => $page,
        ];
      }

      try {
        $saved = $this->log->appendRun($uuid, $revision_id, $model_id, $rows, [
          'documents' => $manifest,
          'kept' => count($rows),
          'dropped' => $dropped,
        ]);
      }
      catch (IntegrityConstraintViolationException) {
        throw $this->error(409, 'form_setup.run_exists');
      }
      return ['saved' => $saved];
    });
  }

  /**
   * Runs an operation and renders its result or error code as JSON.
   */
  private function handle(callable $operation): JsonResponse {
    try {
      return new JsonResponse($operation());
    }
    catch (HttpException $exception) {
      return new JsonResponse(['error' => ['code' => $exception->getMessage()]], $exception->getStatusCode());
    }
    catch (\Throwable $exception) {
      // Database exceptions carry the query and its arguments; only the class
      // goes to the log.
      $message = $exception instanceof DatabaseException
        ? '(database error, details withheld)'
        : mb_substr(preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? '', 0, 300);
      $this->logger->error('Form setup API failed: @class @message', [
        '@class' => $exception::class,
        '@message' => $message,
      ]);
      return new JsonResponse(['error' => ['code' => 'form_setup.internal_error']], 500);
    }
  }

  /**
   * Loads a form for a staff route with the codes of section 8.1.
   *
   * Unknown or not viewable: 404. Viewable without update access when
   * $update is set: 403. Entity access includes the group scoping.
   */
  private function requireForm(string $uuid, bool $update): FormSchemaInterface {
    $form = $this->loadForm($uuid);
    if (!$form instanceof FormSchemaInterface || !$form->access('view', $this->currentUser)) {
      throw $this->error(404, 'form_setup.form_not_found');
    }
    if ($update && !$form->access('update', $this->currentUser)) {
      throw $this->error(403, 'form_setup.access_denied');
    }
    return $form;
  }

  /**
   * Loads a form by UUID without access checks.
   */
  private function loadForm(string $uuid): ?FormSchemaInterface {
    $uuid = strtolower($uuid);
    if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
      return NULL;
    }
    $forms = $this->entityTypeManager->getStorage('form_schema')->loadByProperties(['uuid' => $uuid]);
    $form = reset($forms);
    return $form instanceof FormSchemaInterface ? $form : NULL;
  }

  /**
   * Requires an eligible form at the named current revision.
   */
  private function requireEligibleCurrent(FormSchemaInterface $form, int $revision_id): void {
    if ($this->setupState->ineligibleReason($form) !== NULL) {
      throw $this->error(409, 'form_setup.not_eligible');
    }
    if ($revision_id !== (int) $form->getRevisionId()) {
      throw $this->error(409, 'form_setup.revision_stale');
    }
  }

  /**
   * Whether the current user may publish, given edit access.
   */
  private function canPublish(bool $can_edit): bool {
    return $can_edit && $this->currentUser->hasPermission(FormSchemaPublishPermissionConstraintValidator::PERMISSION);
  }

  /**
   * Builds the recommendation snapshot columns of a decision row.
   *
   * Rule recommendations come from the request (the catalog lives in
   * Nitro) and are bounded to what the catalog produces: a known rule
   * family and a scalar value. Model recommendations are copied from the
   * stored row of the same form, field and property.
   *
   * @return array<string, mixed>|null
   *   Snapshot columns, NULL when no recommendation was shown.
   */
  private function recommendationSnapshot(string $form_uuid, string $field_id, string $property, mixed $recommendation): ?array {
    if ($recommendation === NULL) {
      return NULL;
    }
    if (!is_array($recommendation) || !isset($recommendation['origin'])) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    if ($recommendation['origin'] === 'rule') {
      $recommendation = $this->strict($recommendation, ['origin', 'ruleId', 'value']);
      $rule_id = $this->string($recommendation['ruleId'], 128);
      if (preg_match(self::RULE_ID_PATTERN, $rule_id) !== 1 || !$this->isRuleValue($recommendation['value'])) {
        throw $this->error(422, 'form_setup.input_invalid');
      }
      return [
        'origin' => 'rule',
        'origin_ref' => $rule_id,
        'model_id' => NULL,
        'recommended_value' => $recommendation['value'],
        'quote' => NULL,
        'source_id' => NULL,
        'source_title' => NULL,
        'source_version' => NULL,
        'source_page' => NULL,
      ];
    }
    if ($recommendation['origin'] === 'model') {
      $recommendation = $this->strict($recommendation, ['origin', 'id']);
      $row = $this->log->recommendation($form_uuid, $this->positiveInt($recommendation['id']));
      if ($row === NULL || $row['field_id'] !== $field_id || $row['property'] !== $property) {
        throw $this->error(422, 'form_setup.input_invalid');
      }
      return [
        'origin' => 'model',
        'origin_ref' => NULL,
        'model_id' => $row['model_id'],
        'recommended_value' => $row['value'],
        'quote' => $row['quote'],
        'source_id' => $row['source_id'],
        'source_title' => $row['source_title'],
        'source_version' => $row['source_version'],
        'source_page' => $row['source_page'],
      ];
    }
    throw $this->error(422, 'form_setup.input_invalid');
  }

  /**
   * Validates a recommended value for its property and the field type.
   *
   * @param string $property
   *   Recommendation property.
   * @param array<string, mixed> $field
   *   Target field.
   * @param mixed $value
   *   Value from the request.
   */
  private function recommendedValue(string $property, array $field, mixed $value): mixed {
    $type = $field['type'] ?? NULL;
    if ($property === 'format') {
      if (!is_string($value) || (FormSetupState::FORMAT_FIELD_TYPES[$value] ?? NULL) !== $type) {
        throw $this->error(422, 'form_setup.input_invalid');
      }
      return $value;
    }
    if ($property === 'validation') {
      $allowed = FormSetupState::VALIDATION_KEYS[$type] ?? [];
      if (isset($field['format']) || !is_array($value) || $value === [] || array_is_list($value)) {
        throw $this->error(422, 'form_setup.input_invalid');
      }
      foreach ($value as $key => $limit) {
        if (!in_array($key, $allowed, TRUE) || !is_int($limit)
          || (in_array($key, ['minLength', 'maxLength'], TRUE) && ($limit < 0 || $limit > 1000000))) {
          throw $this->error(422, 'form_setup.input_invalid');
        }
      }
      return $value;
    }
    $text = $this->string($value, 200);
    if (trim($text) === '') {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    return $text;
  }

  /**
   * Source projection of a log row, NULL without a source.
   *
   * @param array<string, mixed> $row
   *   Decoded row.
   *
   * @return array{id: string, title: string|null, version: string|null, page: int|null}|null
   *   Source.
   */
  private function sourceProjection(array $row): ?array {
    if ($row['source_id'] === NULL) {
      return NULL;
    }
    return [
      'id' => $row['source_id'],
      'title' => $row['source_title'],
      'version' => $row['source_version'],
      'page' => $row['source_page'],
    ];
  }

  /**
   * Display names of the deciding staff users.
   *
   * @param list<int> $uids
   *   User ids.
   *
   * @return array<int, string>
   *   Names keyed by uid.
   */
  private function userNames(array $uids): array {
    $uids = array_values(array_unique(array_filter($uids)));
    if ($uids === []) {
      return [];
    }
    $names = [];
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple($uids) as $user) {
      $names[(int) $user->id()] = (string) $user->label();
    }
    return $names;
  }

  /**
   * Decodes a JSON object body.
   *
   * @return array<string, mixed>
   *   Decoded body.
   */
  private function body(Request $request): array {
    $content = (string) $request->getContent();
    if ($content === '' || strlen($content) > self::MAX_BODY_BYTES) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    try {
      $body = json_decode($content, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    if (!is_array($body)) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    return $body;
  }

  /**
   * Requires a JSON object with exactly the given keys.
   *
   * @param mixed $value
   *   Decoded value.
   * @param list<string> $keys
   *   Required keys, no others allowed.
   *
   * @return array<string, mixed>
   *   The object.
   */
  private function strict(mixed $value, array $keys): array {
    if (!is_array($value) || array_is_list($value)) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    $given = array_keys($value);
    sort($given);
    $expected = $keys;
    sort($expected);
    if ($given !== $expected) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    return $value;
  }

  /**
   * Requires a JSON array with a bounded number of entries.
   *
   * @return list<mixed>
   *   The list.
   */
  private function list(mixed $value, int $min, int $max): array {
    if (!is_array($value) || !array_is_list($value) || count($value) < $min || count($value) > $max) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    return $value;
  }

  /**
   * Requires a string of bounded length (in characters).
   */
  private function string(mixed $value, int $max, bool $allow_empty = FALSE): string {
    if (!is_string($value) || (!$allow_empty && $value === '') || mb_strlen($value) > $max) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    return $value;
  }

  /**
   * Requires one of the given strings.
   *
   * @param mixed $value
   *   Value.
   * @param list<string> $allowed
   *   Allowed values.
   */
  private function oneOf(mixed $value, array $allowed): string {
    if (!is_string($value) || !in_array($value, $allowed, TRUE)) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    return $value;
  }

  /**
   * Requires a lowercase-normalized UUID.
   */
  private function uuid(mixed $value): string {
    $uuid = is_string($value) ? strtolower($value) : '';
    if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    return $uuid;
  }

  /**
   * Requires a positive integer.
   */
  private function positiveInt(mixed $value): int {
    return $this->boundedInt($value, 1, PHP_INT_MAX);
  }

  /**
   * Whether a rule recommendation value is one the catalog can produce.
   *
   * Rule values are a class, a format or the flag TRUE: a scalar or NULL,
   * never a list or an object, and strings stay short.
   */
  private function isRuleValue(mixed $value): bool {
    return $value === NULL
      || is_bool($value)
      || is_int($value)
      || (is_string($value) && mb_strlen($value) <= self::MAX_RULE_VALUE_LENGTH);
  }

  /**
   * Requires an integer within bounds.
   */
  private function boundedInt(mixed $value, int $min, int $max): int {
    if (!is_int($value) || $value < $min || $value > $max) {
      throw $this->error(422, 'form_setup.input_invalid');
    }
    return $value;
  }

  /**
   * Builds a coded error.
   */
  private function error(int $status, string $code): HttpException {
    return new HttpException($status, $code);
  }

}
