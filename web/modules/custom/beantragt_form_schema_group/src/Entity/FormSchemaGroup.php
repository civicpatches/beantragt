<?php

declare(strict_types=1);

namespace Drupal\beantragt_form_schema_group\Entity;

use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Exception\WorkspaceCreateDenied;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Form Schema Group entity.
 *
 * A form_schema_group bundles multiple form_schema entities into one
 * citizen-facing application flow without using Drupal's tenancy-oriented
 * group entity.
 *
 * @ContentEntityType(
 *   id = "form_schema_group",
 *   label = @Translation("Form Schema Group"),
 *   label_collection = @Translation("Form Schema Groups"),
 *   label_singular = @Translation("form schema group"),
 *   label_plural = @Translation("form schema groups"),
 *   label_count = @PluralTranslation(
 *     singular = "@count form schema group",
 *     plural = "@count form schema groups",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\beantragt_form_schema_group\FormSchemaGroupListBuilder",
 *     "form" = {
 *       "default" = "Drupal\beantragt_form_schema_group\Form\FormSchemaGroupForm",
 *       "add" = "Drupal\beantragt_form_schema_group\Form\FormSchemaGroupForm",
 *       "edit" = "Drupal\beantragt_form_schema_group\Form\FormSchemaGroupForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "access" = "Drupal\beantragt_form_schema_group\FormSchemaGroupAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "form_schema_group",
 *   constraints = {
 *     "FormSchemaGroupCreateScope" = {},
 *   },
 *   admin_permission = "administer form_schema_group entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "uid",
 *     "published" = "status",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/form-schema-groups/{form_schema_group}",
 *     "add-form" = "/admin/content/form-schema-groups/add",
 *     "edit-form" = "/admin/content/form-schema-groups/{form_schema_group}/edit",
 *     "delete-form" = "/admin/content/form-schema-groups/{form_schema_group}/delete",
 *     "collection" = "/admin/content/form-schema-groups",
 *   },
 * )
 */
class FormSchemaGroup extends ContentEntityBase implements FormSchemaGroupInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;
  use EntityPublishedTrait;

  /**
   * Maximum number of form schemas in one application flow.
   */
  protected const MAX_SCHEMAS = 50;

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $message = $this->workspaceScopeViolation(\Drupal::currentUser());
    if ($message !== NULL) {
      throw new EntityStorageException($message);
    }
    $municipality = $this->municipalityMoveTarget($this->getOriginal());
    if ($this->isNew()) {
      $this->setWorkflowStatus('draft');
    }
    elseif ($municipality !== NULL) {
      // Otherwise the group presave hook can restore the previous office.
      $this->set('field_group', $municipality);
    }

    if ($this->get('field_schemas')->count() > static::MAX_SCHEMAS) {
      throw new \InvalidArgumentException(sprintf(
        'A form schema group can reference at most %d form schemas.',
        static::MAX_SCHEMAS,
      ));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function workspaceScopeViolation(AccountInterface $account): ?string {
    if ($this->isSyncing()) {
      return NULL;
    }

    /** @var \Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope $workspace_scope */
    $workspace_scope = \Drupal::service('beantragt_schema.workspace_scope');
    $exempt = $workspace_scope->accountIsExempt($account);
    // Technical writes use the existing group presave scope check.
    if (BeantragtRoles::isServiceAccount($account) || ($exempt && $account->isAnonymous())) {
      return NULL;
    }

    $retained_ids = [];
    $original = NULL;
    if (!$this->isNew()) {
      $original = $this->getOriginal() ?? $this->entityTypeManager()->getStorage($this->getEntityTypeId())->loadUnchanged($this->id());
      if ($original instanceof FormSchemaGroupInterface) {
        if (!$this->workspaceOrFormsChanged($original)) {
          return NULL;
        }
        $retained_ids = array_flip($original->getFormSchemaIds());
      }
    }

    // Typed workspace denials take precedence. Defer content errors until
    // hidden and missing form references have received the same decision.
    $violation = $workspace_scope->violationMessage($this, $account);
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $references */
    $references = $this->get('field_schemas');
    if ($references->isEmpty()) {
      return $violation ?? 'At least one form is required.';
    }
    $forms = $references->referencedEntities();
    if (count($forms) !== $references->count()) {
      throw new WorkspaceCreateDenied(WorkspaceCreateDenied::SCHEMA_NOT_FOUND);
    }
    // A view result computed during validation may predate a role revocation.
    $this->entityTypeManager()->getAccessControlHandler('form_schema')->resetCache();
    $municipality = $this->municipalityMoveTarget($original);
    $scope = $municipality === NULL
      ? _beantragt_group_entity_effective_access_scope($this)
      : ['type' => 'jur', 'group_id' => $municipality];
    $mismatched = FALSE;
    foreach ($forms as $form) {
      if (!$form instanceof FormSchemaInterface || $form->isNew()
        || (!isset($retained_ids[(int) $form->id()]) && !$form->access('view', $account))) {
        throw new WorkspaceCreateDenied(WorkspaceCreateDenied::SCHEMA_NOT_FOUND);
      }
      $form_scope = _beantragt_group_entity_effective_access_scope($form);
      if ($exempt && $scope === NULL) {
        // The existing presave hook derives an empty scope from the first form.
        $scope = $form_scope;
      }
      if ($scope === NULL || $form_scope !== $scope) {
        $mismatched = TRUE;
      }
    }
    return $violation ?? ($mismatched ? 'Every form must belong to the selected workspace.' : NULL);
  }

  /**
   * Resolves an explicit destination before the legacy alias is rewritten.
   */
  private function municipalityMoveTarget(?EntityInterface $original): ?int {
    if (!$original instanceof FormSchemaGroupInterface
      || !$this->hasField('field_group')
      || $this->get('field_jurisdiction')->isEmpty()
      || !$this->get('field_organisation')->isEmpty()
      || !$this->get('field_group')->equals($original->get('field_group'))) {
      return NULL;
    }
    return !$original->get('field_organisation')->isEmpty()
      || !$this->get('field_jurisdiction')->equals($original->get('field_jurisdiction'))
      ? (int) $this->get('field_jurisdiction')->target_id : NULL;
  }

  /**
   * Checks changes to ordered references and all three workspace fields.
   */
  private function workspaceOrFormsChanged(FormSchemaGroupInterface $original): bool {
    foreach (['field_schemas', 'field_jurisdiction', 'field_organisation', 'field_group'] as $field) {
      if ($this->hasField($field) && !$this->get($field)->equals($original->get($field))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime(int $timestamp): static {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): ?string {
    return $this->get('field_description')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getWorkflowStatus(): string {
    return $this->get('field_workflow_status')->value ?? 'draft';
  }

  /**
   * {@inheritdoc}
   */
  public function setWorkflowStatus(string $status): static {
    $this->set('field_workflow_status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormSchemaIds(): array {
    return array_values(array_map(
      static fn(array $item): int => (int) $item['target_id'],
      $this->get('field_schemas')->getValue(),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setDescription(t('The form schema group title, e.g. "Building permit".'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDescription(t('A short description of this form schema group.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_schemas'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Forms'))
      ->setDescription(t('Ordered form schemas that belong to this group.'))
      ->setRequired(TRUE)
      ->setCardinality(static::MAX_SCHEMAS)
      ->setSetting('target_type', 'form_schema')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -5,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_workflow_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Group Status'))
      ->setDescription(t('The workflow status of this form schema group.'))
      ->setDefaultValue('draft')
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']
      ->setLabel(t('Author'))
      ->setDescription(t('The user who created the form schema group.'))
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 15,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'author',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['status']
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 20,
        'settings' => [
          'display_label' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the form schema group was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time the form schema group was last edited.'));

    return $fields;
  }

}
