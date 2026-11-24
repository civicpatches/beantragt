<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Form;

use Drupal\Component\Utility\Html;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Imports pasted FormSchema JSON as a new draft entity.
 */
final class FormSchemaImportForm extends FormBase {

  private const VALIDATED_ENTITY_KEY = 'beantragt_schema_import_entity';

  /**
   * Constructs a FormSchemaImportForm.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected FormSchemaWorkspaceScope $workspaceScope,
    protected LanguageManagerInterface $languageManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('beantragt_schema.workspace_scope'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'beantragt_schema_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $workspace_scope_enabled = $this->workspaceScopeEnabled();
    $workspace_options = $workspace_scope_enabled
      ? $this->workspaceScope->workspaceOptions($this->currentUser)
      : [
        'jurisdictions' => [],
        'organisations' => [],
        'organisation_required_jurisdictions' => [],
        'scope_required' => FALSE,
      ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#description' => $this->t('Optional. If left empty, the JSON title is used.'),
      '#maxlength' => 255,
    ];
    $form['schema_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Form schema JSON'),
      '#description' => $this->t('Paste one JSON object. The import always creates an unpublished draft.'),
      '#required' => TRUE,
      '#rows' => 24,
    ];
    if ($workspace_scope_enabled) {
      $form['field_jurisdiction'] = [
        '#type' => 'select',
        '#title' => $this->t('Jurisdiction'),
        '#options' => $workspace_options['jurisdictions'],
        '#empty_option' => $this->t('- Select a jurisdiction -'),
        '#required' => $workspace_options['scope_required'],
      ];
      $german = $this->languageManager->getCurrentLanguage()->getId() === 'de';
      $form['field_organisation'] = [
        '#type' => 'select',
        '#title' => $german ? $this->t('Dienststelle') : $this->t('Organisation'),
        '#description' => $german
          ? $this->t('Der Name der Dienststelle enthält die zugehörige Kommune. Wer eine Dienststelle verwaltet, muss eine auswählen. Wer die Kommune verwaltet, kann das Feld leer lassen.')
          : $this->t('Organisation labels include their jurisdiction. Organisation administrators must select an organisation; jurisdiction administrators may leave this empty.'),
        '#options' => $workspace_options['organisations'],
        '#empty_option' => $this->t('- None -'),
        '#required' => FALSE,
      ];
      if ($workspace_options['organisation_required_jurisdictions'] !== []) {
        $form['field_organisation']['#states']['required'] = $this->requiredWhenJurisdictionSelected(
          $workspace_options['organisation_required_jurisdictions'],
          ':input[name="field_jurisdiction"]',
        );
      }
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Validate and import'),
      '#button_type' => 'primary',
      '#disabled' => $workspace_scope_enabled && $workspace_options['jurisdictions'] === [],
    ];
    if ($workspace_scope_enabled && $workspace_options['jurisdictions'] === []) {
      $form['workspace_notice'] = [
        '#type' => 'container',
        '#weight' => -20,
        '#attributes' => [
          'class' => ['messages', 'messages--warning'],
          'role' => 'status',
        ],
        'message' => [
          '#markup' => $this->t('No manageable jurisdiction workspace is available for your account. Ask a workspace administrator to assign the required group role.'),
        ],
      ];
    }

    $uid = (int) $this->currentUser->id();
    $form['#cache']['contexts'] = ['user', 'user.permissions'];
    if ($workspace_scope_enabled) {
      $form['#cache']['tags'] = ['group_list'];
      if ($uid > 0) {
        $form['#cache']['tags'][] = "group_relationship_list:plugin:group_membership:entity:$uid";
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $json = trim((string) $form_state->getValue('schema_json'));
    if ($json === '') {
      $form_state->setErrorByName('schema_json', $this->t('Enter a form schema JSON object.'));
      return;
    }
    if (!json_validate($json)) {
      $form_state->setErrorByName('schema_json', $this->t('The JSON is invalid: @error', [
        '@error' => json_last_error_msg(),
      ]));
      return;
    }

    $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $label = trim((string) $form_state->getValue('label'));
    if ($label === '' && is_array($decoded) && is_string($decoded['title'] ?? NULL)) {
      $label = trim($decoded['title']);
    }
    if ($label === '') {
      $label = (string) $this->t('Imported form schema');
    }

    $values = [
      'label' => $label,
      'field_schema' => $json,
      'field_workflow_status' => 'draft',
      'field_publication_scope' => 'intranet',
      'uid' => (int) $this->currentUser->id(),
      'status' => FALSE,
    ];

    $entity = $this->entityTypeManager->getStorage('form_schema')->create($values);
    if (!$entity instanceof FormSchemaInterface) {
      $form_state->setErrorByName('schema_json', $this->t('The form schema entity could not be created.'));
      return;
    }
    if ($entity->hasField('field_jurisdiction')) {
      $workspace_options = $this->workspaceScope->workspaceOptions($this->currentUser);
      $jurisdiction_value = $form_state->getValue('field_jurisdiction');
      $organisation_value = $form_state->getValue('field_organisation');
      if ($workspace_options['scope_required'] && $this->submittedValueIsEmpty($jurisdiction_value)) {
        $form_state->setErrorByName('field_jurisdiction', $this->t('Select a jurisdiction workspace.'));
        return;
      }
      if ($this->submittedValueIsEmpty($jurisdiction_value)
        && !$this->submittedValueIsEmpty($organisation_value)) {
        $form_state->setErrorByName('field_jurisdiction', $this->t('Select a jurisdiction workspace for the organisation.'));
        return;
      }
      if (!$this->submittedValueIsEmpty($jurisdiction_value)) {
        $jurisdiction_id = $this->positiveSubmittedId($jurisdiction_value);
        if ($jurisdiction_id === NULL) {
          $form_state->setErrorByName('field_jurisdiction', $this->t('The selected jurisdiction workspace is invalid.'));
          return;
        }
        $entity->set('field_jurisdiction', ['target_id' => $jurisdiction_id]);

        $organisation_id = $this->positiveSubmittedId($organisation_value);
        if (!$this->submittedValueIsEmpty($organisation_value) && $organisation_id === NULL) {
          $form_state->setErrorByName('field_organisation', $this->t('The selected organisation workspace is invalid.'));
          return;
        }
        if (in_array($jurisdiction_id, $workspace_options['organisation_required_jurisdictions'], TRUE)
          && $organisation_id === NULL) {
          $form_state->setErrorByName('field_organisation', $this->t('Select an organisation for this jurisdiction workspace.'));
          return;
        }
        if ($organisation_id !== NULL && $entity->hasField('field_organisation')) {
          $entity->set('field_organisation', ['target_id' => $organisation_id]);
        }
      }
    }

    $errors = [];
    $violations = $entity->validate();
    foreach ($violations as $violation) {
      $error_name = $this->violationErrorName(
        $violation->getPropertyPath(),
        (string) $violation->getMessage(),
      );
      $errors[$error_name][] = $violation->getMessage();
    }
    foreach ($errors as $error_name => $messages) {
      $form_state->setErrorByName($error_name, $this->renderErrorMessages($messages));
    }
    if ($errors !== []) {
      return;
    }

    $form_state->set(self::VALIDATED_ENTITY_KEY, $entity);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity = $form_state->get(self::VALIDATED_ENTITY_KEY);
    if (!$entity instanceof FormSchemaInterface) {
      throw new \LogicException('A validated form schema is required before import.');
    }

    $entity->save();
    $this->messenger()->addStatus($this->t('Form schema %label has been imported as a draft.', [
      '%label' => $entity->label(),
    ]));
    $form_state->setRedirect('entity.form_schema.edit_form', [
      'form_schema' => $entity->id(),
    ]);
  }

  /**
   * Maps entity and field violations to a real, focusable form control.
   */
  private function violationErrorName(string $propertyPath, string $message): string {
    if (str_starts_with($propertyPath, 'label')) {
      return 'label';
    }
    if (str_contains($propertyPath, 'field_jurisdiction')
      || str_contains($message, 'jurisdiction workspace')) {
      return 'field_jurisdiction';
    }
    if (str_contains($propertyPath, 'field_organisation')
      || str_contains($message, 'organisation workspace')
      || str_contains($message, 'organisation must belong')) {
      return 'field_organisation';
    }
    return 'schema_json';
  }

  /**
   * Renders one or more safely escaped validation messages.
   *
   * @param array<int, mixed> $messages
   *   Validation messages for one form control.
   */
  private function renderErrorMessages(array $messages): mixed {
    if (count($messages) === 1) {
      return reset($messages);
    }

    $items = array_map(
      static fn (mixed $message): string => '<li>' . Html::escape(Html::decodeEntities((string) $message)) . '</li>',
      $messages,
    );
    return Markup::create('<ul>' . implode('', $items) . '</ul>');
  }

  /**
   * Returns TRUE when beantragt_group supplied the workspace scope fields.
   */
  private function workspaceScopeEnabled(): bool {
    $entity = $this->entityTypeManager->getStorage('form_schema')->create([]);
    return $entity instanceof FormSchemaInterface
      && $entity->hasField('field_jurisdiction');
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
   * Returns a submitted positive entity ID without coercing empty values.
   */
  private function positiveSubmittedId(mixed $value): ?int {
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

  /**
   * Returns TRUE for an unselected scalar form value.
   */
  private function submittedValueIsEmpty(mixed $value): bool {
    return $value === NULL || $value === '';
  }

}
