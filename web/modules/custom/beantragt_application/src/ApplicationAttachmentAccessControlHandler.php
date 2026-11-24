<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access control handler for application_attachment entities.
 */
final class ApplicationAttachmentAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  public function __construct(
    EntityTypeInterface $entity_type,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static($entity_type, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view' && (int) $account->id() === 0) {
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($entity);
    }

    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    if ($operation === 'view' && $account->hasPermission('view application_attachment entities')) {
      if ($entity instanceof ContentEntityInterface
        && $entity->hasField('field_application')
        && !$entity->get('field_application')->isEmpty()) {
        $application = $entity->get('field_application')->entity;
        if ($application instanceof ApplicationInterface) {
          $can_view_protected = $account->hasPermission('administer application entities')
            || $account->hasPermission('bypass beantragt group scope')
            || $account->hasPermission('view protected application data');
          $schema = PublicSubmission::protectedSchema($application, $this->entityTypeManager);
          $field_allowed = $can_view_protected || !$this->attachmentFieldIsSensitive($entity, $schema);
          $view = $application->access('view', $account, TRUE);
          $access = AccessResult::allowedIf($view->isAllowed() && $field_allowed)
            ->addCacheableDependency($view)
            ->cachePerPermissions()
            ->cachePerUser()
            ->addCacheableDependency($entity)
            ->addCacheableDependency($application);
          if ($schema instanceof FormSchemaInterface) {
            $access->addCacheableDependency($schema);
          }
          return $access;
        }
      }
    }

    if ($operation === 'delete') {
      return AccessResult::allowedIfHasPermission($account, 'delete application_attachment entities')
        ->addCacheableDependency($entity);
    }

    return AccessResult::neutral()->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIf(
      $account->hasPermission($this->entityType->getAdminPermission())
      || (
        BeantragtRoles::isServiceAccount($account)
        && $account->hasPermission('create application_attachment entities')
      )
    )->cachePerPermissions()->cachePerUser();
  }

  /**
   * Returns TRUE when an attachment's form field is sensitive or unknown.
   */
  private function attachmentFieldIsSensitive(ContentEntityInterface $attachment, ?FormSchemaInterface $schema_entity): bool {
    try {
      if (!$attachment->hasField('field_field_id')) {
        return TRUE;
      }
      $field_id = (string) ($attachment->get('field_field_id')->value ?? '');
      if ($field_id === '' || !$schema_entity instanceof FormSchemaInterface) {
        return TRUE;
      }
      $schema = json_decode((string) $schema_entity->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($schema)) {
        return TRUE;
      }
      $fields = $this->schemaFields($schema);
      return !isset($fields[$field_id]) || $this->isSensitiveSchemaField($fields[$field_id]);
    }
    catch (\Throwable) {
      return TRUE;
    }
  }

  /**
   * Returns schema fields keyed by field id.
   *
   * @return array<string, array<string, mixed>>
   *   Schema fields.
   */
  private function schemaFields(array $schema): array {
    $fields = [];
    foreach (($schema['sections'] ?? []) as $section) {
      if (!is_array($section)) {
        continue;
      }
      foreach (($section['fields'] ?? []) as $field) {
        if (is_array($field) && is_string($field['id'] ?? NULL) && $field['id'] !== '') {
          $fields[$field['id']] = $field;
        }
      }
    }
    return $fields;
  }

  /**
   * Returns TRUE when a schema field is sensitive or cannot be parsed safely.
   */
  private function isSensitiveSchemaField(array $field): bool {
    $default_sensitive = ($field['type'] ?? NULL) === 'file';
    if (!array_key_exists('sensitive', $field)) {
      return $default_sensitive;
    }

    $sensitive = $field['sensitive'];
    if (is_bool($sensitive)) {
      return $sensitive;
    }
    if (is_string($sensitive) || is_int($sensitive)) {
      $parsed = filter_var($sensitive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      return $parsed ?? $default_sensitive;
    }
    return $default_sensitive;
  }

}
