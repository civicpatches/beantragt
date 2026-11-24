<?php

declare(strict_types=1);

namespace Drupal\beantragt_workflow_status\Entity;

use Drupal\beantragt_workflow_status\Plugin\Field\DeleteBlockersItemList;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Workflow Status entity.
 *
 * A per-group, config-like status definition. NON-revisionable on purpose:
 * statuses are configuration data, not citizen content, so the scope is kept
 * tight. Each jurisdiction or organisation owns its own set of statuses with a
 * stable machine name, label, colour, icon, ordering weight, default/terminal
 * flags and an explicit allowed-transition graph (field_allowed_next).
 *
 * @ContentEntityType(
 *   id = "workflow_status",
 *   label = @Translation("Workflow Status"),
 *   label_collection = @Translation("Workflow Statuses"),
 *   label_singular = @Translation("workflow status"),
 *   label_plural = @Translation("workflow statuses"),
 *   label_count = @PluralTranslation(
 *     singular = "@count workflow status",
 *     plural = "@count workflow statuses",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "access" = "Drupal\beantragt_workflow_status\WorkflowStatusAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "workflow_status",
 *   admin_permission = "administer workflow_status entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/workflow-statuses/{workflow_status}",
 *     "add-form" = "/admin/content/workflow-statuses/add",
 *     "edit-form" = "/admin/content/workflow-statuses/{workflow_status}/edit",
 *     "delete-form" = "/admin/content/workflow-statuses/{workflow_status}/delete",
 *     "collection" = "/admin/content/workflow-statuses",
 *   },
 *   constraints = {
 *     "WorkflowStatusUniqueMachineName" = {},
 *     "WorkflowStatusAllowedNextInGroup" = {},
 *     "WorkflowStatusDefault" = {},
 *     "WorkflowStatusImmutableMachineName" = {},
 *     "WorkflowStatusCitizenLabel" = {},
 *   },
 * )
 */
class WorkflowStatus extends ContentEntityBase implements WorkflowStatusInterface {

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    if ($this->isTerminal()) {
      $this->set('field_sla_days', 0);
      $this->set('field_escalation_days', 0);
    }
    foreach (self::CITIZEN_LANGCODES as $langcode) {
      $field = 'field_citizen_label_' . $langcode;
      if ($this->hasField($field) && !$this->get($field)->isEmpty()) {
        $this->set($field, trim((string) $this->get($field)->value));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCitizenLabel(string $langcode): string {
    $german = $this->citizenText('de');
    $text = $langcode === 'en' ? ($this->citizenText('en') ?: $german) : $german;
    return $text !== '' ? $text : trim((string) ($this->label() ?? ''));
  }

  /**
   * {@inheritdoc}
   */
  public function hasCitizenLabelOverride(): bool {
    $label = trim((string) ($this->label() ?? ''));
    foreach (self::CITIZEN_LANGCODES as $langcode) {
      $text = $this->citizenText($langcode);
      if ($text !== '' && $text !== $label) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reads one stored citizen text, trimmed; empty when unset.
   */
  private function citizenText(string $langcode): string {
    $field = 'field_citizen_label_' . $langcode;
    if (!$this->hasField($field) || $this->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) ($this->get($field)->value ?? ''));
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupId(): ?int {
    if ($this->get('field_group')->isEmpty()) {
      return NULL;
    }
    $target_id = (int) $this->get('field_group')->target_id;
    return $target_id > 0 ? $target_id : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineName(): string {
    return (string) ($this->get('field_machine_name')->value ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getColor(): ?string {
    return $this->get('field_color')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): ?string {
    return $this->get('field_icon')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return (int) ($this->get('field_weight')->value ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function isDefault(): bool {
    return (bool) $this->get('field_is_default')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isTerminal(): bool {
    return (bool) $this->get('field_is_terminal')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSlaDays(): int {
    if (!$this->hasField('field_sla_days') || $this->get('field_sla_days')->isEmpty()) {
      return 0;
    }
    return max(0, (int) ($this->get('field_sla_days')->value ?? 0));
  }

  /**
   * {@inheritdoc}
   */
  public function getEscalationDays(): int {
    if (!$this->hasField('field_escalation_days') || $this->get('field_escalation_days')->isEmpty()) {
      return 0;
    }
    return max(0, (int) ($this->get('field_escalation_days')->value ?? 0));
  }

  /**
   * {@inheritdoc}
   */
  public function getSemanticFlags(): array {
    $flags = [];
    foreach ($this->get('field_semantic_flags') as $item) {
      $value = (string) ($item->value ?? '');
      if ($value !== '') {
        $flags[$value] = $value;
      }
    }
    return array_values($flags);
  }

  /**
   * {@inheritdoc}
   */
  public function hasSemanticFlag(string $flag): bool {
    return in_array($flag, $this->getSemanticFlags(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedNextIds(): array {
    $ids = [];
    foreach ($this->get('field_allowed_next') as $item) {
      $target_id = (int) ($item->target_id ?? 0);
      if ($target_id > 0) {
        $ids[$target_id] = $target_id;
      }
    }
    return array_values($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllowedNextMachineNames(): array {
    $names = [];
    foreach ($this->get('field_allowed_next')->referencedEntities() as $status) {
      if ($status instanceof WorkflowStatusInterface) {
        $name = $status->getMachineName();
        if ($name !== '') {
          $names[$name] = $name;
        }
      }
    }
    return array_values($names);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Label'))
      ->setDescription(t('The human-readable status label shown to citizens and staff.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
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

    foreach ([
      'de' => t('Text shown to applicants instead of the label (German). Empty uses the label.'),
      'en' => t('Text shown to applicants instead of the label (English). Empty uses the German citizen text or the label.'),
    ] as $langcode => $description) {
      $fields['field_citizen_label_' . $langcode] = BaseFieldDefinition::create('string')
        ->setLabel($langcode === 'de' ? t('Citizen text (German)') : t('Citizen text (English)'))
        ->setDescription($description)
        ->setRequired(FALSE)
        ->setDefaultValue('')
        ->setSetting('max_length', 128)
        ->setDisplayOptions('form', [
          'type' => 'string_textfield',
          'weight' => -9.5,
        ])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayOptions('view', [
          'label' => 'inline',
          'type' => 'string',
          'weight' => -9.5,
        ])
        ->setDisplayConfigurable('view', TRUE);
    }

    $fields['field_group'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Group'))
      ->setDescription(t('The jurisdiction or organisation group that owns this status.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'group')
      ->setSetting('handler', 'default:group')
      ->setSetting('handler_settings', [
        'target_bundles' => [
          'jur' => 'jur',
          'org' => 'org',
        ],
      ])
      ->setCardinality(1)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -9,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'entity_reference_label',
        'weight' => -9,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Machine name'))
      ->setDescription(t('A stable machine-readable id, unique within the owning group (e.g. "new", "in_review").'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -8,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_color'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Color'))
      ->setDescription(t('A hex color used for status badges (e.g. "#3b82f6").'))
      ->setSetting('max_length', 9)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -7,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -7,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_icon'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Icon'))
      ->setDescription(t('A Lucide icon name (e.g. "i-lucide-inbox").'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -6,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Weight'))
      ->setDescription(t('Ordering weight; lower values sort first.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_is_default'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Default status'))
      ->setDescription(t('Whether new applications in this group start in this status. Exactly one per group.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_is_terminal'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Terminal status'))
      ->setDescription(t('Whether this status has no outgoing transitions. Terminal statuses are never escalated.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => -3,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_sla_days'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('SLA due date in days'))
      ->setDescription(t('Number of local calendar days after entering this status before the application is due. 0 disables automatic due-date changes.'))
      ->setDefaultValue(0)
      ->setSetting('min', 0)
      ->setSetting('max', 3650)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_escalation_days'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Escalation in days'))
      ->setDescription(t('Number of days after entering this status before staff escalation is due. 0 disables status-specific escalation.'))
      ->setDefaultValue(0)
      ->setSetting('min', 0)
      ->setSetting('max', 3650)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => -2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_allowed_next'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Allowed next statuses'))
      ->setDescription(t('The statuses an application may transition to from this status. References must belong to the same group.'))
      ->setSetting('target_type', 'workflow_status')
      ->setSetting('handler', 'default')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => -2,
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
        'weight' => -2,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['field_semantic_flags'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Semantic flags'))
      ->setDescription(t('Machine-readable workflow semantics used by notifications and automation.'))
      ->setSetting('allowed_values', [
        'received' => 'Received',
        'request_info' => 'Request information from citizen',
        'hand_over' => 'Hand over to the case system',
        'in_case_system' => 'Handed over to the case system',
        'decision_issued' => 'Decision notice issued by the case system',
        'approved' => 'Approved or positive outcome',
        'rejected' => 'Rejected or negative outcome',
        'filed' => 'Filed by the case system without a decision notice',
        'closed' => 'Closed',
        'withdrawn' => 'Withdrawn',
        'documents_received' => 'Documents received',
      ])
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => -1,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Read-only, computed per read: why the status cannot be deleted, as
    // codes of WorkflowStatusManager::DELETE_BLOCKER_CODES. Field access
    // shows it only to definition administrators and never caches it.
    $fields['delete_blockers'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Delete blockers'))
      ->setDescription(t('Why this status cannot be deleted, as machine-readable codes.'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setClass(DeleteBlockersItemList::class);

    return $fields;
  }

}
