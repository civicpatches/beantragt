<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\EventSubscriber;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Exception\WorkspaceCreateDenied;
use Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\jsonapi\Exception\UnprocessableHttpEntityException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Preserves workspace decisions on JSON:API form creates and group writes.
 */
final class WorkspaceCreateSubscriber implements EventSubscriberInterface {

  /**
   * JSON:API resource writes covered by the workspace contract.
   */
  private const ROUTES = [
    'jsonapi.form_schema--form_schema.collection.post' => 'form_schema',
    'jsonapi.form_schema_group--form_schema_group.collection.post' => 'form_schema_group',
    'jsonapi.form_schema_group--form_schema_group.individual.patch' => 'form_schema_group',
  ];

  /**
   * HTTP statuses for the workspace contract.
   */
  private const STATUS = [
    WorkspaceCreateDenied::WORKSPACE_NOT_FOUND => 404,
    WorkspaceCreateDenied::SCHEMA_NOT_FOUND => 404,
    WorkspaceCreateDenied::WRITE_FORBIDDEN => 403,
    'errors.schema.invalid' => 422,
  ];

  /**
   * Constructs the workspace create subscriber.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormSchemaWorkspaceScope $scope,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Preserves the intended type and UUID before core converts them to IDs.
   */
  public function onController(ControllerEvent $event): void {
    $request = $event->getRequest();
    $entity_type = self::writeEntityType($request);
    if ($entity_type === NULL || !$this->entityTypeManager->hasDefinition('group')) {
      return;
    }

    $types = [
      'field_jurisdiction' => ['group--jur'],
      'field_organisation' => ['group--org'],
      'field_group' => ['group--jur', 'group--org'],
    ];
    if ($entity_type === 'form_schema_group') {
      $types['field_schemas'] = ['form_schema--form_schema'];
    }
    $document = json_decode($request->getContent(), TRUE);
    $relationships = $request->attributes->get('_on_relationship')
      ? [$request->attributes->get('related') => $document]
      : $document['data']['relationships'] ?? [];
    $references = [];
    foreach ($types as $field => $allowed) {
      if (!isset($relationships[$field]) || !is_array($relationships[$field]) || !array_key_exists('data', $relationships[$field])) {
        continue;
      }
      $data = $relationships[$field]['data'];
      $references[$field] = [];
      if ($data === NULL) {
        continue;
      }
      // Core uses the first item's type to resolve all UUIDs and silently
      // drops 'virtual'. Neither is a valid substitution for a selected form.
      $many = $field === 'field_schemas';
      if (!is_array($data) || ($many && !array_is_list($data))) {
        throw new UnprocessableEntityHttpException('Invalid workspace or form relationship.');
      }
      foreach ($many ? $data : [$data] as $item) {
        if (!in_array($item['type'] ?? NULL, $allowed, TRUE)
          || !is_string($item['id'] ?? NULL) || !Uuid::isValid($item['id'])) {
          throw new UnprocessableEntityHttpException('Invalid workspace or form identifier.');
        }
        $references[$field][] = $item['id'];
      }
    }
    if ($references) {
      $request->attributes->set('_beantragt_write_references', $references);
      if ($entity_type === 'form_schema_group' && $this->currentUser->isAuthenticated()
        && !BeantragtRoles::isServiceAccount($this->currentUser)) {
        // Core may return early for unchanged or removed relationships, or
        // reject the HTTP method for their cardinality before validating.
        // An explicitly addressed, unreadable UUID must always be concealed.
        $message = $this->referenceViolation($entity_type, $request);
        $request->attributes->set('_beantragt_write_scope_message', $message);
      }
    }
  }

  /**
   * Gives only a stable status and code for permission and visibility denials.
   */
  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    $entity_type = self::writeEntityType($request);
    if ($entity_type === NULL) {
      return;
    }

    $error = $event->getThrowable();
    $reason = NULL;
    if ($error instanceof UnprocessableHttpEntityException) {
      foreach ($error->getViolations() as $violation) {
        if (isset(self::STATUS[$violation->getCode()])) {
          $reason = $violation->getCode();
          break;
        }
      }
    }
    elseif ($error instanceof NotFoundHttpException || ($error instanceof BadRequestHttpException && $request->attributes->get('_on_relationship'))) {
      // Core rejects unknown UUIDs before validation, with HTTP 400 on its
      // relationship routes and HTTP 404 on its resource routes.
      try {
        $message = $request->attributes->has('_beantragt_write_scope_message')
          ? $request->attributes->get('_beantragt_write_scope_message')
          : $this->referenceViolation($entity_type, $request);
        if ($message !== NULL) {
          $event->setResponse(self::errorResponse('errors.schema.invalid', $message));
        }
      }
      catch (WorkspaceCreateDenied $denied) {
        $event->setResponse(self::errorResponse($denied->reason));
      }
      return;
    }
    else {
      // The storage layer may wrap a workspace denial raised during presave.
      while ($error !== NULL) {
        if ($error instanceof WorkspaceCreateDenied) {
          $reason = $error->reason;
          break;
        }
        $error = $error->getPrevious();
      }
    }
    if ($reason !== NULL) {
      $event->setResponse(self::errorResponse($reason));
    }
  }

  /**
   * Returns the assignment error after concealing inaccessible references.
   *
   * @throws \Drupal\beantragt_schema\Exception\WorkspaceCreateDenied
   *   When the proposed target or an explicitly addressed UUID is denied.
   */
  private function referenceViolation(string $entity_type, Request $request): ?string {
    $references = $request->attributes->get('_beantragt_write_references', []);
    if (!$references) {
      return NULL;
    }
    $forms = array_unique($references['field_schemas'] ?? []);
    unset($references['field_schemas']);
    // A resource or relationship PATCH inherits all omitted workspace fields.
    $existing = $request->attributes->get('entity');
    $entity = $existing instanceof ContentEntityInterface
      ? clone $existing
      : $this->entityTypeManager->getStorage($entity_type)->create();
    assert($entity instanceof ContentEntityInterface);
    $group_ids = [];
    $group_uuids = array_unique(array_column($references, 0));
    if ($group_uuids) {
      $groups = $this->entityTypeManager->getStorage('group')->loadByProperties(['uuid' => $group_uuids]);
      foreach ($groups as $group) {
        $group_ids[$group->uuid()] = $group->id();
      }
    }
    foreach ($references as $field => $uuids) {
      if (!$uuids) {
        $entity->set($field, NULL);
        continue;
      }
      [$uuid] = $uuids;
      if (!isset($group_ids[$uuid])) {
        throw new WorkspaceCreateDenied(WorkspaceCreateDenied::WORKSPACE_NOT_FOUND);
      }
      $entity->set($field, $group_ids[$uuid]);
    }
    // Match the entity validator: permission denials take precedence, while
    // invalid assignments are reported only after hiding inaccessible forms.
    $message = $this->scope->violationMessage($entity, $this->currentUser);
    if ($forms) {
      // Core indexes related entities by their exact UUID string, even on a
      // database with case-insensitive collation.
      $found = $this->entityTypeManager->getStorage('form_schema')->loadByProperties(['uuid' => $forms]);
      $this->entityTypeManager->getAccessControlHandler('form_schema')->resetCache();
      $found_uuids = [];
      foreach ($found as $form) {
        if (!$form->access('view', $this->currentUser)) {
          throw new WorkspaceCreateDenied(WorkspaceCreateDenied::SCHEMA_NOT_FOUND);
        }
        $found_uuids[] = $form->uuid();
      }
      if (array_diff($forms, $found_uuids)) {
        throw new WorkspaceCreateDenied(WorkspaceCreateDenied::SCHEMA_NOT_FOUND);
      }
    }
    return $message;
  }

  /**
   * Creates a JSON:API error without exposing inaccessible targets.
   */
  private static function errorResponse(string $reason, ?string $detail = NULL): JsonResponse {
    $status = self::STATUS[$reason];
    $error = ['status' => (string) $status, 'code' => $reason];
    if ($detail !== NULL) {
      $error['detail'] = $detail;
    }
    return new JsonResponse(['errors' => [$error]], $status, [
      'Content-Type' => 'application/vnd.api+json',
      'Cache-Control' => 'private, no-store',
    ]);
  }

  /**
   * Returns the entity type on supported write routes.
   */
  private static function writeEntityType(Request $request): ?string {
    $route = (string) $request->attributes->get('_route');
    if ($request->attributes->get('_on_relationship')
      && str_starts_with($route, 'jsonapi.form_schema_group--form_schema_group.')
      && in_array($request->getMethod(), ['PATCH', 'POST', 'DELETE'], TRUE)) {
      return 'form_schema_group';
    }
    return self::ROUTES[$route] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::CONTROLLER => 'onController',
      // Handle typed denials before JSON:API serializes exceptions.
      KernelEvents::EXCEPTION => ['onException', 90],
    ];
  }

}
