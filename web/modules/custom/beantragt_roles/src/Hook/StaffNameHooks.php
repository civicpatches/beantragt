<?php

declare(strict_types=1);

namespace Drupal\beantragt_roles\Hook;

use Drupal\beantragt_roles\StaffName;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;

/**
 * Staff display names: format, field access and label visibility.
 */
final class StaffNameHooks {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Shows "First Last" instead of the login name when a name is stored.
   *
   * Every getDisplayName() call goes through here: assignment candidates,
   * status history, transfer approvals, decision notices and staff mails.
   * Accounts without a stored name keep their login name.
   */
  #[Hook('user_format_name_alter')]
  public function formatNameAlter(mixed &$name, AccountInterface $account): void {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return;
    }
    $user = $account instanceof UserInterface
      ? $account
      : $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }
    $display_name = StaffName::fromUser($user);
    if ($display_name !== '') {
      $name = $display_name;
    }
  }

  /**
   * Hides the label of other accounts from citizens and anonymous visitors.
   *
   * Core treats user labels as public, and JSON:API prints a label-only
   * resource object with "display_name" for every account a visitor cannot
   * view. With real names in the label that would publish staff names, so
   * only staff, user administrators, service accounts and the account itself
   * may read it.
   */
  #[Hook('user_access')]
  public function userAccess(UserInterface $entity, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'view label' || (int) $entity->id() <= 0) {
      return AccessResult::neutral();
    }
    if (StaffName::viewerSeesNames($account) || BeantragtRoles::isServiceAccount($account)) {
      return self::byRoles(AccessResult::neutral(), $entity);
    }
    // Below this line the result depends on who is looking.
    $result = (int) $entity->id() === (int) $account->id()
      ? AccessResult::neutral()
      : AccessResult::forbidden('Names of other accounts are visible to staff only.');
    return self::byRoles($result, $entity)->cachePerUser();
  }

  /**
   * Restricts the name fields to staff and the account itself.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($field_definition->getTargetEntityTypeId() !== 'user'
      || !in_array($field_definition->getName(), [StaffName::FIRST_NAME_FIELD, StaffName::LAST_NAME_FIELD], TRUE)) {
      return AccessResult::neutral();
    }
    $own = $items !== NULL
      && (int) $account->id() > 0
      && (int) $items->getEntity()->id() === (int) $account->id();

    $entity = $items?->getEntity();

    if ($operation === 'view') {
      if (StaffName::viewerSeesNames($account)) {
        return self::byRoles(AccessResult::allowed(), $entity);
      }
      $result = $own
        ? AccessResult::allowed()
        : AccessResult::forbidden('Staff names are visible to staff only.');
      return self::byRoles($result, $entity)->cachePerUser();
    }

    if ($account->hasPermission('administer users')) {
      return self::byRoles(AccessResult::allowed(), $entity);
    }
    $result = $own && ((int) $account->id() === 1 || BeantragtRoles::isStaffAccount($account))
      ? AccessResult::allowed()
      : AccessResult::forbidden('Only the account itself or a user administrator may change the name.');
    return self::byRoles($result, $entity)->cachePerUser();
  }

  /**
   * Adds the cache contexts of the role based decisions.
   *
   * Staff roles, "administer users" and uid 1 decide without looking at the
   * viewer's identity, so those results are shared per role set. Results that
   * compare the viewer with the viewed account add the user context.
   */
  private static function byRoles(AccessResult $result, ?EntityInterface $entity = NULL): AccessResult {
    $result->addCacheContexts(['user.roles', 'user.permissions', 'user.is_super_user']);
    if ($entity !== NULL) {
      $result->addCacheableDependency($entity);
    }
    return $result;
  }

  /**
   * Rejects markup and control characters in name fields.
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   Bundle field definitions.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function entityBundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() !== 'user') {
      return;
    }
    foreach ([StaffName::FIRST_NAME_FIELD, StaffName::LAST_NAME_FIELD] as $field_name) {
      if (isset($fields[$field_name]) && $fields[$field_name] instanceof FieldConfigInterface) {
        $fields[$field_name]->addPropertyConstraints('value', [
          'Regex' => [
            'pattern' => '/^[^<>\x00-\x1F\x7F]*$/u',
            // Field definitions are cached per interface language, so the
            // message follows it once a translation exists.
            'message' => (string) new TranslatableMarkup('Der Name darf keine spitzen Klammern oder Steuerzeichen enthalten.'),
          ],
        ]);
      }
    }
  }

}
