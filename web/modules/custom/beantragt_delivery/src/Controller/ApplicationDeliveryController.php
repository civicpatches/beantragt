<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Controller;

use Drupal\beantragt_application\Delivery\DeliveryRoute;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_delivery\Exception\DeliveryRouteException;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Staff view and change of an application's delivery route (stage 1).
 *
 * The GET is the evidence per provided response: route and reason, email
 * notice, the applicant's first download and the postal dispatch. Staff
 * change the route on the citizen's request (letter, phone, ...) and record
 * the handover of a due postal dispatch to the mail room. Uids are never
 * projected, only display names; request bodies are never logged.
 */
final class ApplicationDeliveryController extends ControllerBase {

  /**
   * Largest accepted POST body in bytes.
   */
  private const MAX_BODY_BYTES = 1024;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\beantragt_delivery\Service\ScopeAccess $scopeAccess
   *   Case scope checks shared with the transfer routes.
   * @param \Drupal\beantragt_delivery\Service\ExternalResponseService $responses
   *   Provided responses and the effective route rules.
   * @param \Drupal\beantragt_application\Service\ApplicationDependentMutationLock $mutationLock
   *   Application lock shared with provision and deletion.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entities
   *   Entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The acting staff member.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time service.
   * @param object|null $formSettings
   *   The service beantragt_docgen.settings when docgen is enabled.
   */
  public function __construct(
    private readonly ScopeAccess $scopeAccess,
    private readonly ExternalResponseService $responses,
    private readonly ApplicationDependentMutationLock $mutationLock,
    private readonly EntityTypeManagerInterface $entities,
    private readonly AccountProxyInterface $account,
    private readonly TimeInterface $time,
    private readonly ?object $formSettings = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('beantragt_delivery.scope_access'),
      $container->get('beantragt_delivery.external_response_service'),
      $container->get('beantragt_application.dependent_mutation_lock'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      // Form settings live in beantragt_docgen, which this module does not
      // depend on.
      $container->has('beantragt_docgen.settings') ? $container->get('beantragt_docgen.settings') : NULL,
    );
  }

  /**
   * Guards the GET like the transfer list.
   */
  public function access(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return $this->scopeAccess->transferAccess($application, $account);
  }

  /**
   * Guards the route change and the postal handover.
   */
  public function changeAccess(ApplicationInterface $application, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf($this->scopeAccess->canApproveDispatch($application, $account))
      ->cachePerPermissions()->cachePerUser()->setCacheMaxAge(0);
  }

  /**
   * GET /api/admin/applications/{application}/delivery.
   */
  public function view(ApplicationInterface $application): JsonResponse {
    if (!$this->scopeAccess->transferAccess($application, $this->account)->isAllowed()) {
      return $this->error('delivery.access_denied', 403);
    }
    return $this->respond(['data' => $this->payload($application)]);
  }

  /**
   * POST /api/admin/applications/{application}/delivery.
   *
   * Body {"route": "postal", "receivedAt"?: 1762590000, "channel": "letter"}.
   * Resubmitting the route returns 200 with changed FALSE and writes nothing.
   */
  public function change(ApplicationInterface $application, Request $request): JsonResponse {
    try {
      if (!$this->scopeAccess->canApproveDispatch($application, $this->account)) {
        throw new DeliveryRouteException('delivery.access_denied', 403);
      }
      $input = $this->body($request);
      if (!$this->mutationLock->acquireApplication($application)) {
        throw new DeliveryRouteException('delivery.locked', 409);
      }
      try {
        $current = $this->freshApplication($application);
        $now = $this->time->getCurrentTime();
        $route = $this->requireRoute($input, $current);
        $received_at = $this->requireReceivedAt($input, $current, $now);
        $channel = $input['channel'] ?? NULL;
        if (!is_string($channel) || !in_array($channel, DeliveryRoute::CHANNELS, TRUE)) {
          throw new DeliveryRouteException('delivery.input_invalid', 422, ['field' => 'channel']);
        }
        $previous = $current->getDeliveryRoute();
        $changed = $route !== $previous;
        if ($changed) {
          $record = $current->getDeliveryRecord();
          // A change toward post is always written (the safe route must
          // never be refused). Only other changes stop at the cap, so the
          // record holds at most RECORD_LIMIT + 1 events.
          if ($route !== DeliveryRoute::POSTAL && count($record) >= DeliveryRoute::RECORD_LIMIT) {
            throw new DeliveryRouteException('delivery.record_full', 409);
          }
          $record[] = [
            'event' => 'route_changed',
            'at' => $now,
            'receivedAt' => $received_at,
            'route' => $route,
            'previous' => $previous,
            'source' => 'staff',
            'byUid' => (int) $this->account->id(),
            'channel' => $channel,
          ];
          $current->set('field_delivery_route', $route);
          $current->set('field_delivery_record', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
          $current->setNewRevision(TRUE);
          $current->setRevisionUserId((int) $this->account->id());
          $current->setRevisionCreationTime($now);
          $current->save();
        }
        return $this->respond(['data' => $this->payload($current) + ['changed' => $changed]]);
      }
      finally {
        $this->mutationLock->release($application);
      }
    }
    catch (DeliveryRouteException $exception) {
      return $this->error($exception->errorCode, $exception->status, $exception->details);
    }
  }

  /**
   * POST …/responses/{response_id}/postal-handover.
   *
   * Records the time and actor and never overwrites a recorded handover.
   */
  public function handOver(ApplicationInterface $application, string $response_id, Request $request): JsonResponse {
    try {
      if (!$this->scopeAccess->canApproveDispatch($application, $this->account)) {
        throw new DeliveryRouteException('delivery.access_denied', 403);
      }
      if ($request->query->all() !== [] || !in_array(trim((string) $request->getContent()), ['', '{}'], TRUE)) {
        throw new DeliveryRouteException('delivery.input_invalid', 422, ['field' => 'body']);
      }
      $this->responses->handOver($application, $response_id, $this->account);
      return $this->respond(['data' => $this->payload($application)]);
    }
    catch (\OutOfBoundsException) {
      return $this->error('delivery.not_found', 404);
    }
    catch (DeliveryRouteException $exception) {
      // Includes delivery.locked from the service; any other runtime error,
      // such as a database failure, surfaces as a logged 500.
      return $this->error($exception->errorCode, $exception->status, $exception->details);
    }
  }

  /**
   * The staff GET payload.
   *
   * @return array<string, mixed>
   *   Route, its origin, options, release previews, events and documents.
   */
  private function payload(ApplicationInterface $application): array {
    $record = $application->getDeliveryRecord();
    $latest = $record === [] ? NULL : $record[array_key_last($record)];
    $chosen = NULL;
    foreach ($record as $event) {
      if ($event['event'] === 'route_chosen') {
        $chosen = $event;
        break;
      }
    }
    $names = [];
    return [
      'route' => $application->getDeliveryRoute(),
      'source' => is_string($latest['source'] ?? NULL) ? $latest['source'] : NULL,
      'chosenAt' => is_int($latest['at'] ?? NULL) ? $latest['at'] : NULL,
      'offered' => is_array($chosen['offered'] ?? NULL) ? array_values(array_filter($chosen['offered'], 'is_string')) : [],
      'routeOptions' => $this->routeOptions($application),
      'canEdit' => $this->scopeAccess->canApproveDispatch($application, $this->account),
      'preview' => $this->responses->deliveryPreview($application),
      'events' => array_map(fn (array $event): array => [
        'event' => $event['event'],
        'at' => is_int($event['at'] ?? NULL) ? $event['at'] : NULL,
        'receivedAt' => is_int($event['receivedAt'] ?? NULL) ? $event['receivedAt'] : NULL,
        'route' => is_string($event['route'] ?? NULL) ? $event['route'] : NULL,
        'source' => is_string($event['source'] ?? NULL) ? $event['source'] : NULL,
        'reason' => is_string($event['reason'] ?? NULL) ? $event['reason'] : NULL,
        'channel' => is_string($event['channel'] ?? NULL) ? $event['channel'] : NULL,
        'byName' => is_int($event['byUid'] ?? NULL) ? $this->userName($event['byUid'], $names) : NULL,
      ], $record),
      'documents' => array_map(fn (array $row): array => [
        'responseId' => (string) $row['uuid'],
        'title' => (string) $row['title'],
        'isDecision' => (int) ($row['is_decision'] ?? 0) === 1,
        'providedAt' => self::timestamp($row['released'] ?? NULL),
        'route' => (string) ($row['delivery_route'] ?? ''),
        'reason' => isset($row['route_reason']) && $row['route_reason'] !== '' ? (string) $row['route_reason'] : NULL,
        'notificationState' => (string) $row['notification_state'],
        'notificationSentAt' => self::timestamp($row['notification_sent_at'] ?? NULL),
        'retrievedAt' => self::timestamp($row['retrieved'] ?? NULL),
        'postalDueAt' => self::timestamp($row['postal_due_at'] ?? NULL),
        'postalHandedOverAt' => self::timestamp($row['postal_handed_over_at'] ?? NULL),
        'postalHandedOverByName' => isset($row['postal_handed_over_by']) ? $this->userName((int) $row['postal_handed_over_by'], $names) : NULL,
      ], $this->responses->releasedResponses($application)),
    ];
  }

  /**
   * Routes staff may set: what the form stores, plus postal.
   *
   * @return string[]
   *   Routes in DeliveryRoute::ROUTES order.
   */
  private function routeOptions(ApplicationInterface $application): array {
    $form = $application->hasField('field_schema') ? ($application->get('field_schema')->entity ?? NULL) : NULL;
    $stored = [];
    if ($form !== NULL && $this->formSettings !== NULL && method_exists($this->formSettings, 'formSettings')) {
      $settings = $this->formSettings->formSettings((string) $form->uuid());
      $stored = is_array($settings['delivery']['routes'] ?? NULL) ? $settings['delivery']['routes'] : [];
    }
    $options = $stored === [] ? [DeliveryRoute::PORTAL, DeliveryRoute::POSTAL] : [...$stored, DeliveryRoute::POSTAL];
    return array_values(array_intersect(DeliveryRoute::ROUTES, $options));
  }

  /**
   * Reads the JSON object of a route change.
   *
   * @return array<string, mixed>
   *   The body.
   */
  private function body(Request $request): array {
    $raw = (string) $request->getContent();
    if ($request->query->all() !== [] || strlen($raw) > self::MAX_BODY_BYTES) {
      throw new DeliveryRouteException('delivery.input_invalid', 422, ['field' => 'body']);
    }
    try {
      $input = json_decode($raw, TRUE, 4, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new DeliveryRouteException('delivery.input_invalid', 422, ['field' => 'body']);
    }
    if (!is_array($input) || ($input !== [] && array_is_list($input))) {
      throw new DeliveryRouteException('delivery.input_invalid', 422, ['field' => 'body']);
    }
    foreach (array_keys($input) as $key) {
      if (!in_array($key, ['route', 'receivedAt', 'channel'], TRUE)) {
        throw new DeliveryRouteException('delivery.input_invalid', 422, ['field' => 'body']);
      }
    }
    return $input;
  }

  /**
   * The requested route, one of the route options.
   */
  private function requireRoute(array $input, ApplicationInterface $application): string {
    $route = $input['route'] ?? NULL;
    if (!is_string($route) || !in_array($route, $this->routeOptions($application), TRUE)) {
      throw new DeliveryRouteException('delivery.input_invalid', 422, ['field' => 'route']);
    }
    return $route;
  }

  /**
   * The receipt time defaults to the current time and cannot be in the future.
   */
  private function requireReceivedAt(array $input, ApplicationInterface $application, int $now): int {
    if (!array_key_exists('receivedAt', $input)) {
      return $now;
    }
    $received_at = $input['receivedAt'];
    if (!is_int($received_at) || $received_at > $now || $received_at < (int) $application->getCreatedTime()) {
      throw new DeliveryRouteException('delivery.input_invalid', 422, ['field' => 'receivedAt']);
    }
    return $received_at;
  }

  /**
   * Loads the stored application past the entity cache (lock held).
   */
  private function freshApplication(ApplicationInterface $application): ApplicationInterface {
    $storage = $this->entities->getStorage('application');
    $storage->resetCache([(int) $application->id()]);
    $current = $storage->load((int) $application->id());
    if (!$current instanceof ApplicationInterface) {
      throw new DeliveryRouteException('delivery.not_found', 404);
    }
    return $current;
  }

  /**
   * Display name of a staff account, NULL when the account is gone.
   *
   * @param int $uid
   *   User ID.
   * @param array<int, ?string> $names
   *   Names loaded for this payload.
   */
  private function userName(int $uid, array &$names): ?string {
    if (!array_key_exists($uid, $names)) {
      $user = $uid > 0 ? $this->entities->getStorage('user')->load($uid) : NULL;
      $names[$uid] = $user instanceof UserInterface ? (string) $user->getDisplayName() : NULL;
    }
    return $names[$uid];
  }

  /**
   * A stored timestamp as int, or NULL.
   */
  private static function timestamp(mixed $value): ?int {
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * An error envelope.
   *
   * @param string $code
   *   The delivery.* code.
   * @param int $status
   *   HTTP status.
   * @param array<string, string> $details
   *   Non-sensitive details.
   */
  private function error(string $code, int $status, array $details = []): JsonResponse {
    return $this->respond(['error' => ['code' => $code, 'details' => (object) $details]], $status);
  }

  /**
   * An uncacheable JSON response.
   */
  private function respond(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, [
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
