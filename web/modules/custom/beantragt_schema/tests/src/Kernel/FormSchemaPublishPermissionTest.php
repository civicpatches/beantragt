<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Plugin\Validation\Constraint\FormSchemaPublishPermissionConstraint;
use Drupal\beantragt_schema\Plugin\Validation\Constraint\FormSchemaPublishPermissionConstraintValidator;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests the visibility level rule of the publish permission.
 */
#[CoversClass(FormSchemaPublishPermissionConstraintValidator::class)]
#[Group('beantragt_schema')]
final class FormSchemaPublishPermissionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'options',
    'serialization',
    'jsonapi',
    'json_form_widget',
    'beantragt_roles_api',
    'beantragt_schema',
  ];

  /**
   * Edit rights without the publish permission.
   */
  private UserInterface $editor;

  /**
   * Edit rights plus the publish permission.
   */
  private UserInterface $publisher;

  /**
   * Only the admin permission of the entity type.
   */
  private UserInterface $entityAdmin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('form_schema');
    $this->installSchema('file', ['file_usage']);

    // Burn uid 1: it bypasses every permission check.
    $this->createUser([], 'root');
    $edit = [
      'create form_schema entities',
      'edit any form_schema entities',
      'view unpublished form_schema entities',
    ];
    $this->editor = $this->createUser($edit, 'editor');
    $this->publisher = $this->createUser([...$edit, 'publish form_schema entities'], 'publisher');
    $this->entityAdmin = $this->createUser(['administer form_schema entities'], 'entity_admin');
  }

  /**
   * The level rule as a table, for an editor without the permission.
   */
  public function testLevelRuleForEditorWithoutPermission(): void {
    $this->setCurrentUser($this->editor);
    foreach (self::transitions() as $name => [$stored, $change, $raises]) {
      $form = $this->formFor($stored, $change);
      $violations = $this->permissionViolations($form);
      self::assertCount($raises ? 1 : 0, $violations, $name);
      foreach ($violations as $violation) {
        self::assertSame('', $violation->getPropertyPath(), $name);
        self::assertSame(
          htmlspecialchars(FormSchemaPublishPermissionConstraint::DETAIL, ENT_QUOTES),
          (string) $violation->getMessage(),
          $name,
        );
      }
    }
  }

  /**
   * With the permission, no transition is refused.
   */
  public function testLevelRuleWithPermission(): void {
    $this->setCurrentUser($this->publisher);
    foreach (self::transitions() as $name => [$stored, $change]) {
      self::assertCount(0, $this->permissionViolations($this->formFor($stored, $change)), $name);
    }
  }

  /**
   * The admin permission of the entity type does not imply publishing.
   */
  public function testAdministerPermissionAloneIsNotEnough(): void {
    $this->setCurrentUser($this->entityAdmin);
    foreach (self::transitions() as $name => [$stored, $change, $raises]) {
      self::assertCount($raises ? 1 : 0, $this->permissionViolations($this->formFor($stored, $change)), $name);
    }
  }

  /**
   * A programmatic save without validate() still publishes (seed path).
   */
  public function testProgrammaticSaveWithoutValidationStillPublishes(): void {
    $this->setCurrentUser($this->editor);
    $form = $this->createForm(['workflow' => 'draft', 'flag' => FALSE, 'scope' => 'internet']);
    $form->set('field_workflow_status', 'published');
    $form->setPublished();
    $form->save();

    $stored = $this->container->get('entity_type.manager')->getStorage('form_schema')->loadUnchanged($form->id());
    assert($stored instanceof FormSchemaInterface);
    self::assertSame(
      FormSchemaPublishPermissionConstraintValidator::LEVEL_INTERNET,
      FormSchemaPublishPermissionConstraintValidator::visibilityLevel($stored),
    );
  }

  /**
   * A stale save cannot restore visibility that a concurrent save lowered.
   *
   * An editor without the permission sends a label-only change
   * for a live internet form; it validates against the stored live state.
   * Before it is written, another request switches the published flag off
   * or the scope to intranet. That change must advance the fenced revision,
   * so the stale save fails instead of writing published/internet back.
   */
  public function testConcurrentVisibilityDecreaseFencesStaleSave(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('form_schema');
    $live_internet = ['workflow' => 'published', 'flag' => TRUE, 'scope' => 'internet'];
    $decreases = [
      'published flag off' => ['status', FALSE],
      'scope to intranet' => ['field_publication_scope', 'intranet'],
    ];
    foreach ($decreases as $name => [$field, $value]) {
      $this->setCurrentUser($this->publisher);
      $id = $this->createForm($live_internet)->id();

      // Request A: label only, validated against the live form.
      $this->setCurrentUser($this->editor);
      $stale = $storage->loadUnchanged($id);
      assert($stale instanceof FormSchemaInterface);
      $stale->set('label', 'Neuer Titel');
      self::assertCount(0, $this->permissionViolations($stale), $name);

      // Request B lowers the visibility in between.
      $concurrent = $storage->loadUnchanged($id);
      assert($concurrent instanceof FormSchemaInterface);
      $revision_before = (int) $concurrent->getRevisionId();
      $concurrent->set($field, $value);
      $concurrent->save();

      $refused = FALSE;
      try {
        $stale->save();
      }
      catch (EntityStorageException) {
        $refused = TRUE;
      }

      $stored = $storage->loadUnchanged($id);
      assert($stored instanceof FormSchemaInterface);
      self::assertLessThan(
        FormSchemaPublishPermissionConstraintValidator::LEVEL_INTERNET,
        FormSchemaPublishPermissionConstraintValidator::visibilityLevel($stored),
        $name . ': the lowered visibility stays',
      );
      self::assertTrue($refused, $name . ': the stale save is refused');
      self::assertSame('Hundesteuer', $stored->label(), $name);
      self::assertGreaterThan($revision_before, (int) $stored->getRevisionId(), $name . ': the decrease advanced the revision');
    }
  }

  /**
   * Visibility transitions: stored state, change, whether the level rises.
   *
   * A NULL stored state means a new, unsaved form.
   *
   * @return array<string, array{0: array{workflow: string, flag: bool, scope: string}|null, 1: array{workflow?: string, flag?: bool, scope?: string, label?: string}, 2: bool}>
   *   Named transitions.
   */
  private static function transitions(): array {
    $draft = ['workflow' => 'draft', 'flag' => FALSE, 'scope' => 'intranet'];
    $draft_flag_on = ['workflow' => 'draft', 'flag' => TRUE, 'scope' => 'intranet'];
    $live_intranet = ['workflow' => 'published', 'flag' => TRUE, 'scope' => 'intranet'];
    $live_internet = ['workflow' => 'published', 'flag' => TRUE, 'scope' => 'internet'];
    $published_flag_off = ['workflow' => 'published', 'flag' => FALSE, 'scope' => 'internet'];

    return [
      'draft to published' => [$draft, ['workflow' => 'published', 'flag' => TRUE], TRUE],
      'workflow only on a draft with the flag on' => [$draft_flag_on, ['workflow' => 'published'], TRUE],
      'flag off to on for a form stored as published' => [$published_flag_off, ['flag' => TRUE], TRUE],
      'intranet to internet for a live form' => [$live_intranet, ['scope' => 'internet'], TRUE],
      'new form created live' => [NULL, ['workflow' => 'published', 'flag' => TRUE, 'scope' => 'intranet'], TRUE],
      'new form created live for the internet' => [
        NULL,
        ['workflow' => 'published', 'flag' => TRUE, 'scope' => 'internet'],
        TRUE,
      ],
      'draft save' => [$draft, ['label' => 'Neuer Titel'], FALSE],
      'draft save with the flag on' => [$draft, ['flag' => TRUE], FALSE],
      'new draft' => [NULL, ['workflow' => 'draft', 'flag' => TRUE, 'scope' => 'internet'], FALSE],
      'published to draft' => [$live_internet, ['workflow' => 'draft'], FALSE],
      'flag on to off' => [$live_internet, ['flag' => FALSE], FALSE],
      'internet to intranet' => [$live_internet, ['scope' => 'intranet'], FALSE],
      'scope change of a draft' => [$draft, ['scope' => 'internet'], FALSE],
      'label change on a live form' => [$live_internet, ['label' => 'Neuer Titel'], FALSE],
      'workflow to archived' => [$live_internet, ['workflow' => 'archived'], FALSE],
    ];
  }

  /**
   * Returns a stored or new form with the change applied, not saved.
   *
   * @param array{workflow: string, flag: bool, scope: string}|null $stored
   *   Stored state, NULL for a new form.
   * @param array{workflow?: string, flag?: bool, scope?: string, label?: string} $change
   *   Values to apply.
   */
  private function formFor(?array $stored, array $change): FormSchemaInterface {
    if ($stored === NULL) {
      $form = $this->newForm($change + ['workflow' => 'draft', 'flag' => FALSE, 'scope' => 'intranet']);
      return $form;
    }
    $form = $this->createForm($stored);
    if (isset($change['workflow'])) {
      $form->set('field_workflow_status', $change['workflow']);
    }
    if (isset($change['flag'])) {
      $form->set('status', $change['flag']);
    }
    if (isset($change['scope'])) {
      $form->set('field_publication_scope', $change['scope']);
    }
    if (isset($change['label'])) {
      $form->set('label', $change['label']);
    }
    return $form;
  }

  /**
   * Creates and saves a form programmatically, like seed tooling.
   *
   * @param array{workflow: string, flag: bool, scope: string} $state
   *   Stored state.
   */
  private function createForm(array $state): FormSchemaInterface {
    $form = $this->newForm($state);
    $form->save();
    return $form;
  }

  /**
   * Creates an unsaved form with a publishable schema.
   *
   * @param array{workflow: string, flag: bool, scope: string} $state
   *   State values.
   */
  private function newForm(array $state): FormSchemaInterface {
    $form = $this->container->get('entity_type.manager')->getStorage('form_schema')->create([
      'label' => 'Hundesteuer',
      'field_schema' => json_encode([
        'title' => 'Hundesteuer',
        'sections' => [[
          'id' => 'dog',
          'title' => 'Hund',
          'fields' => [[
            'id' => 'rasse',
            'type' => 'text',
            'label' => 'Rasse',
            'privacy' => ['kind' => 'not_personal'],
          ],
          ],
        ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => $state['workflow'],
      'field_publication_scope' => $state['scope'],
      'status' => $state['flag'],
    ]);
    assert($form instanceof FormSchemaInterface);
    return $form;
  }

  /**
   * Returns the violations of the publish permission constraint only.
   *
   * @return list<\Symfony\Component\Validator\ConstraintViolationInterface>
   *   Violations.
   */
  private function permissionViolations(FormSchemaInterface $form): array {
    return array_values(array_filter(
      iterator_to_array($form->validate()),
      static fn(ConstraintViolationInterface $violation): bool => $violation->getConstraint() instanceof FormSchemaPublishPermissionConstraint,
    ));
  }

}
