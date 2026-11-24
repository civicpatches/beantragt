<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the Form Schema entity add/edit forms.
 */
final class FormSchemaForm extends ContentEntityForm {

  /**
   * Workspace option and authorization service.
   */
  protected FormSchemaWorkspaceScope $workspaceScope;

  /**
   * Current form account.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a FormSchemaForm.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    FormSchemaWorkspaceScope $workspace_scope,
    AccountProxyInterface $current_user,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->workspaceScope = $workspace_scope;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('beantragt_schema.workspace_scope'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->getEntity();
    if (!$entity instanceof FormSchemaInterface || !$entity->hasField('field_jurisdiction')) {
      return $form;
    }

    $workspace_options = $this->workspaceScope->workspaceOptions($this->currentUser);
    $jurisdiction_options = $workspace_options['jurisdictions'];
    $organisation_options = $workspace_options['organisations'];
    $this->appendCurrentWorkspaceOption($jurisdiction_options, $entity, 'field_jurisdiction');
    $this->appendCurrentWorkspaceOption($organisation_options, $entity, 'field_organisation');

    $this->replaceAutocompleteWithSelect(
      $form,
      'field_jurisdiction',
      $jurisdiction_options,
      (string) $this->t('- Select a jurisdiction -'),
      $workspace_options['scope_required'],
    );
    $this->replaceAutocompleteWithSelect(
      $form,
      'field_organisation',
      $organisation_options,
      (string) $this->t('- None -'),
    );

    $legacy_options = $jurisdiction_options + $organisation_options;
    $this->appendCurrentWorkspaceOption($legacy_options, $entity, 'field_group');
    $this->replaceAutocompleteWithSelect(
      $form,
      'field_group',
      $legacy_options,
      (string) $this->t('- None -'),
    );

    if ($workspace_options['organisation_required_jurisdictions'] !== []
      && isset($form['field_organisation']['widget'][0]['target_id'])) {
      $form['field_organisation']['widget'][0]['target_id']['#states']['required'] = $this->requiredWhenJurisdictionSelected(
        $workspace_options['organisation_required_jurisdictions'],
        ':input[name="field_jurisdiction[0][target_id]"]',
      );
    }
    $form['#beantragt_schema_organisation_required_jurisdictions'] = $workspace_options['organisation_required_jurisdictions'];
    $form['#cache']['contexts'][] = 'user';
    $form['#cache']['contexts'][] = 'user.permissions';
    $form['#cache']['tags'][] = 'group_list';
    $uid = (int) $this->currentUser->id();
    if ($uid > 0) {
      $form['#cache']['tags'][] = "group_relationship_list:plugin:group_membership:entity:$uid";
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    $entity = parent::validateForm($form, $form_state);

    $required_jurisdictions = $form['#beantragt_schema_organisation_required_jurisdictions'] ?? [];
    $jurisdiction_id = $this->submittedTargetId($form_state, 'field_jurisdiction');
    $organisation_id = $this->submittedTargetId($form_state, 'field_organisation');
    if ($jurisdiction_id !== NULL
      && in_array($jurisdiction_id, $required_jurisdictions, TRUE)
      && $organisation_id === NULL
      && isset($form['field_organisation']['widget'][0]['target_id'])) {
      $form_state->setError(
        $form['field_organisation']['widget'][0]['target_id'],
        $this->t('Select an organisation for this jurisdiction workspace.'),
      );
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $entity = $this->getEntity();
    $message_args = ['%label' => $entity->toLink()->toString()];

    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Form Schema %label has been created.', $message_args));
    }
    else {
      $this->messenger()->addStatus($this->t('Form Schema %label has been updated.', $message_args));
    }

    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $result;
  }

  /**
   * Replaces one unscoped entity autocomplete with a bounded select.
   *
   * @param array<string, mixed> $form
   *   Complete entity form array.
   * @param string $field_name
   *   Entity-reference field to restrict.
   * @param array<int, string> $options
   *   Allowed workspace labels keyed by group entity ID.
   * @param string $empty_option
   *   Empty select option label.
   * @param bool $required
   *   Whether the select is always required.
   */
  private function replaceAutocompleteWithSelect(
    array &$form,
    string $field_name,
    array $options,
    string $empty_option,
    bool $required = FALSE,
  ): void {
    if (!isset($form[$field_name]['widget'][0]['target_id'])) {
      return;
    }
    $element = &$form[$field_name]['widget'][0]['target_id'];
    $default_value = $element['#default_value'] ?? NULL;
    if ($default_value instanceof GroupInterface) {
      $default_value = (int) $default_value->id();
    }
    elseif (is_array($default_value) && reset($default_value) instanceof GroupInterface) {
      $default_group = reset($default_value);
      $default_value = (int) $default_group->id();
    }

    $element['#type'] = 'select';
    $element['#options'] = $options;
    $element['#empty_option'] = $empty_option;
    $element['#default_value'] = $default_value;
    $element['#required'] = $required;
    foreach ([
      '#target_type',
      '#selection_handler',
      '#selection_settings',
      '#validate_reference',
      '#maxlength',
      '#size',
      '#placeholder',
    ] as $property) {
      unset($element[$property]);
    }
  }

  /**
   * Keeps an existing entity's current workspace selectable on edit forms.
   *
   * @param array<int, string> $options
   *   Scoped workspace labels keyed by group entity ID.
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $entity
   *   Form schema being edited.
   * @param string $field_name
   *   Workspace field whose current value should remain selectable.
   */
  private function appendCurrentWorkspaceOption(
    array &$options,
    FormSchemaInterface $entity,
    string $field_name,
  ): void {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return;
    }
    $group = $entity->get($field_name)->entity;
    if ($group instanceof GroupInterface) {
      $options[(int) $group->id()] ??= (string) $group->label();
      natcasesort($options);
    }
  }

  /**
   * Builds Drupal states conditions for organisation-only jurisdictions.
   *
   * @param list<int> $jurisdiction_ids
   *   Jurisdictions which require an organisation selection.
   * @param string $selector
   *   Form states selector for the jurisdiction element.
   */
  private function requiredWhenJurisdictionSelected(array $jurisdiction_ids, string $selector): array {
    $conditions = [];
    foreach ($jurisdiction_ids as $jurisdiction_id) {
      if ($conditions !== []) {
        $conditions[] = 'or';
      }
      $conditions[] = [
        $selector => ['value' => (string) $jurisdiction_id],
      ];
    }
    return count($conditions) === 1 ? $conditions[0] : $conditions;
  }

  /**
   * Returns one submitted workspace target ID.
   */
  private function submittedTargetId(FormStateInterface $form_state, string $field_name): ?int {
    $value = $form_state->getValue([$field_name, 0, 'target_id']);
    if (!is_int($value) && !is_string($value)) {
      return NULL;
    }
    $string_value = (string) $value;
    if ($string_value === '' || !ctype_digit($string_value)) {
      return NULL;
    }
    $id = (int) $string_value;
    return $id > 0 ? $id : NULL;
  }

}
