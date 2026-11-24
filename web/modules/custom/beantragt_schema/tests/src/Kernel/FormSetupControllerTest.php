<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Controller\FormSetupController;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Plugin\Validation\Constraint\ValidFormSchemaDefinitionConstraint;
use Drupal\beantragt_schema\Repository\FormSetupLog;
use Drupal\beantragt_schema\Service\FormSetupState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests the internal guided form setup routes.
 */
#[CoversClass(FormSetupController::class)]
#[CoversClass(FormSetupState::class)]
#[Group('beantragt_schema')]
final class FormSetupControllerTest extends KernelTestBase {

  use UserCreationTrait;

  private const PASSWORD = 'synthetic-setup-password';
  private const DOC_A = 'd0c5a0a1-1111-4111-8111-111111111111';
  private const DOC_B = 'd0c5a0a1-2222-4222-8222-222222222222';
  private const DOC_C = 'd0c5a0a1-3333-4333-8333-333333333333';
  private const DOC_D = 'd0c5a0a1-4444-4444-8444-444444444444';
  private const VERSION_A = 'revision:31:changed:1762600000';
  private const QUOTE = 'Bitte legen Sie eine Kopie der Haftpflichtversicherung bei.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'options',
    'serialization',
    'basic_auth',
    'jsonapi',
    'json_form_widget',
    'beantragt_roles_api',
    'beantragt_schema',
  ];

  /**
   * Accounts by persona.
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  private array $accounts = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('form_schema');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_schema', [FormSetupLog::TABLE]);

    // Burn uid 1: it bypasses every permission check.
    $this->createUser([], 'root');
    $edit = [
      'create form_schema entities',
      'edit any form_schema entities',
      'view published form_schema entities',
      'view unpublished form_schema entities',
    ];
    $roles = [
      'department_admin' => [...$edit, 'administer form_schema entities', 'publish form_schema entities'],
      'form_editor' => $edit,
      'form_viewer' => ['view published form_schema entities', 'view unpublished form_schema entities'],
      'service_account' => [...$edit, 'record form setup recommendations'],
      'nobody' => [],
    ];
    foreach ($roles as $role_id => $permissions) {
      $role = Role::create(['id' => $role_id, 'label' => $role_id]);
      foreach ($permissions as $permission) {
        $role->grantPermission($permission);
      }
      $role->save();
      $account = $this->createUser([], $role_id . '_user');
      $account->addRole($role_id);
      $account->setPassword(self::PASSWORD);
      $account->save();
      $this->accounts[$role_id] = $account;
    }
    $this->setCurrentUser($this->accounts['department_admin']);
  }

  /**
   * Every ineligibility reason of section 4, in order.
   */
  public function testEligibilityReasons(): void {
    $state = $this->setupState();
    self::assertNull($state->ineligibleReason($this->compiledDraft()));

    $archived = $this->compiledDraft();
    $archived->set('field_workflow_status', 'archived')->save();
    self::assertSame('not_draft', $state->ineligibleReason($archived));

    $once_live = $this->compiledDraft($this->classifiedSections());
    $once_live->set('field_workflow_status', 'published')->set('status', TRUE)->save();
    $once_live->set('field_workflow_status', 'draft')->set('status', FALSE)->save();
    self::assertSame('was_published', $state->ineligibleReason($once_live));

    self::assertSame('not_compiled', $state->ineligibleReason($this->compiledDraft(NULL, ['source' => ['type' => 'manual']])));
    self::assertSame('not_compiled', $state->ineligibleReason($this->compiledDraft(NULL, [], 'seed:demo:wohngeld-bulk')));
    self::assertSame('not_compiled', $state->ineligibleReason($this->compiledDraft(NULL, [], '')));
    self::assertNull($state->ineligibleReason($this->compiledDraft(NULL, ['source' => ['type' => 'image']])));

    self::assertNull($state->ineligibleReason($this->compiledDraft([$this->bigSection(100)])));
    self::assertSame('section_too_large', $state->ineligibleReason($this->compiledDraft([$this->bigSection(101)])));

    [$status, $body] = $this->call('state', $this->compiledDraft([$this->bigSection(101)]));
    self::assertSame(200, $status);
    self::assertFalse($body['eligible']);
    self::assertSame('section_too_large', $body['ineligibleReason']);
  }

  /**
   * A fresh form returns every key, empty lists and the blockers in order.
   */
  public function testFreshStateShape(): void {
    $form = $this->compiledDraft();

    [$status, $body] = $this->call('state', $form);

    self::assertSame(200, $status);
    self::assertSame(
      [
        'form',
        'eligible',
        'ineligibleReason',
        'canEdit',
        'canPublish',
        'blockers',
        'decisions',
        'recommendations',
        'run',
      ],
      array_keys($body),
    );
    self::assertSame([
      'id' => $form->uuid(),
      'revisionId' => (int) $form->getRevisionId(),
      'version' => 1,
      'workflowStatus' => 'draft',
      'sourceFileName' => 'hundesteuer.pdf',
    ], $body['form']);
    self::assertTrue($body['eligible']);
    self::assertNull($body['ineligibleReason']);
    self::assertTrue($body['canEdit']);
    self::assertTrue($body['canPublish']);
    self::assertSame([
      ['sectionId' => 'dog', 'fieldId' => 'krankheit', 'code' => 'sensitive_required'],
      ['sectionId' => 'dog', 'fieldId' => 'krankheit', 'code' => 'high_risk_kind_required'],
      ['sectionId' => 'review', 'fieldId' => 'vorname', 'code' => 'privacy_kind_required'],
    ], $body['blockers']);
    self::assertSame([], $body['decisions']);
    self::assertSame([], $body['recommendations']);
    self::assertNull($body['run']);

    $this->setCurrentUser($this->accounts['form_editor']);
    [, $editor_view] = $this->call('state', $form);
    self::assertFalse($editor_view['canPublish']);
  }

  /**
   * A text compilation uses the same setup and publish blockers as a PDF.
   */
  public function testTextCompilationKeepsTheGuidedReview(): void {
    $root = ['source' => ['type' => 'text', 'file' => 'source.txt']];
    $form = $this->compiledDraft(NULL, $root, 'source.txt');
    [$status, $body] = $this->call('state', $form);

    self::assertSame(200, $status);
    self::assertTrue($body['eligible']);
    self::assertNull($body['ineligibleReason']);
    self::assertSame('source.txt', $body['form']['sourceFileName']);
    self::assertSame([
      ['sectionId' => 'dog', 'fieldId' => 'krankheit', 'code' => 'sensitive_required'],
      ['sectionId' => 'dog', 'fieldId' => 'krankheit', 'code' => 'high_risk_kind_required'],
      ['sectionId' => 'review', 'fieldId' => 'vorname', 'code' => 'privacy_kind_required'],
    ], $body['blockers']);

    $this->setCurrentUser($this->accounts['form_editor']);
    [, $editor_view] = $this->call('state', $form);
    self::assertTrue($editor_view['canEdit']);
    self::assertFalse($editor_view['canPublish']);

    $state = $this->setupState();
    self::assertSame('not_compiled', $state->ineligibleReason($this->compiledDraft(NULL, $root, '')));
    self::assertSame('not_compiled', $state->ineligibleReason($this->compiledDraft(NULL, $root, 'seed:synthetic')));
    self::assertSame('section_too_large', $state->ineligibleReason($this->compiledDraft([$this->bigSection(101)], $root, 'source.txt')));

    $once_live = $this->compiledDraft($this->classifiedSections(), $root, 'source.txt');
    $once_live->set('field_workflow_status', 'published')->set('status', TRUE)->save();
    $once_live->set('field_workflow_status', 'draft')->set('status', FALSE)->save();
    self::assertSame('was_published', $state->ineligibleReason($once_live));
  }

  /**
   * Capabilities and the form loading codes of section 8.1.
   */
  public function testCapabilitiesAndAccessCodes(): void {
    $form = $this->compiledDraft();
    $expected = [
      'department_admin' => [200, ['canEdit' => TRUE, 'canPublish' => TRUE]],
      'form_editor' => [200, ['canEdit' => TRUE, 'canPublish' => FALSE]],
      'form_viewer' => [200, ['canEdit' => FALSE, 'canPublish' => FALSE]],
      'nobody' => [404, ['error' => ['code' => 'form_setup.form_not_found']]],
    ];
    foreach ($expected as $persona => [$status, $body]) {
      $this->setCurrentUser($this->accounts[$persona]);
      self::assertSame([$status, $body], $this->call('capabilities', $form), $persona);
    }

    $this->setCurrentUser($this->accounts['form_viewer']);
    self::assertSame([403, ['error' => ['code' => 'form_setup.access_denied']]], $this->call('state', $form));
    $this->setCurrentUser($this->accounts['department_admin']);
    $response = FormSetupController::create($this->container)->state('0b1f0c9e-7c1d-4e8a-9a51-3c2b1d0e9f99');
    self::assertSame(404, $response->getStatusCode());

    $controller = FormSetupController::create($this->container);
    self::assertTrue($controller->accessStaff($this->accounts['form_editor'])->isAllowed());
    self::assertFalse($controller->accessStaff($this->accounts['service_account'])->isAllowed());
    self::assertFalse($controller->accessStaff(new AnonymousUserSession())->isAllowed());
    self::assertTrue($controller->accessService($this->accounts['service_account'])->isAllowed());
    self::assertFalse($controller->accessService($this->accounts['department_admin'])->isAllowed(), 'A staff session cannot write recommendations.');
  }

  /**
   * Decision rules: revision, field, choice, value from the schema, current.
   */
  public function testDecisionRules(): void {
    $form = $this->compiledDraft();
    $revision = (int) $form->getRevisionId();
    $kind_rule = ['origin' => 'rule', 'ruleId' => 'privacy-catalog:label:health', 'value' => 'special_category'];

    self::assertSame([409, ['error' => ['code' => 'form_setup.revision_stale']]], $this->decide($form, $revision + 1, 'dog', [
      $this->item('rasse', 'privacy.kind', 'kept'),
    ]));
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('unbekannt', 'privacy.kind', 'kept'),
    ]));
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'nirgends', [
      $this->item('rasse', 'privacy.kind', 'kept'),
    ]));
    self::assertSame([422, ['error' => ['code' => 'form_setup.choice_mismatch']]], $this->decide($form, $revision, 'dog', [
      $this->item('krankheit', 'privacy.kind', 'accepted', $kind_rule),
    ]), 'The stored class is empty, so accepting special_category claims too much.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.choice_mismatch']]], $this->decide($form, $revision, 'dog', [
      $this->item('krankheit', 'privacy.kind', 'accepted'),
    ]), 'Accepted without a recommendation.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('nachweis', 'format', 'kept'),
    ]), 'Format on a file field.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('rasse', 'analysis.expectedDocumentType', 'kept'),
    ]), 'Analysis on a text field.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('rasse', 'legalBasis', 'kept'),
    ]), 'No legal basis question exists.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('rasse', 'privacy.kind', 'kept'),
      $this->item('rasse', 'privacy.kind', 'other'),
    ]), 'One decision per field and property.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('rasse', 'privacy.kind', 'kept') + ['value' => 'identity'],
    ]), 'The request never carries a value.');
    self::assertSame(0, $this->countRows($form), 'Refused requests wrote nothing.');

    // Nitro saved the class and the derived flag; the staff session logs it.
    $this->setCurrentUser($this->accounts['form_editor']);
    $form = $this->updateField($form, 'dog', 'krankheit', [
      'privacy' => ['kind' => 'special_category'],
      'sensitive' => TRUE,
    ]);
    $revision = (int) $form->getRevisionId();
    self::assertSame([200, ['saved' => 4]], $this->decide($form, $revision, 'dog', [
      $this->item('krankheit', 'privacy.kind', 'accepted', $kind_rule),
      $this->item('krankheit', 'sensitive', 'accepted', [
        'origin' => 'rule',
        'ruleId' => 'gate:sensitive_required',
        'value' => TRUE,
      ]),
      $this->item('rasse', 'privacy.kind', 'other'),
      $this->item('', 'wording', 'kept'),
    ]));

    $this->setCurrentUser($this->accounts['department_admin']);
    [, $state] = $this->call('state', $form);
    $decisions = array_column($state['decisions'], NULL, 'property');
    self::assertCount(4, $state['decisions']);
    $kind = $state['decisions'][0];
    self::assertSame('krankheit', $kind['fieldId']);
    self::assertSame('special_category', $kind['value']);
    self::assertTrue($kind['current']);
    self::assertSame([
      'origin' => 'rule',
      'ruleId' => 'privacy-catalog:label:health',
      'modelId' => NULL,
      'value' => 'special_category',
      'quote' => NULL,
      'source' => NULL,
    ], $kind['recommendation']);
    self::assertSame(['uid' => (int) $this->accounts['form_editor']->id(), 'name' => 'form_editor_user'], $kind['decidedBy']);
    self::assertSame($revision, $kind['revisionId']);
    self::assertSame('not_personal', $state['decisions'][2]['value'], 'The value comes from the schema.');
    self::assertNull($state['decisions'][2]['recommendation']);
    self::assertSame([
      ['id' => 'krankheit', 'label' => 'Krankheit des Hundes', 'placeholder' => NULL],
      ['id' => 'rasse', 'label' => 'Rasse', 'placeholder' => 'z. B. Dackel'],
      ['id' => 'kennung', 'label' => 'Kennung', 'placeholder' => NULL],
      ['id' => 'nachweis', 'label' => 'Nachweis', 'placeholder' => NULL],
    ], $decisions['wording']['value']);
    self::assertSame('', $decisions['wording']['fieldId']);
    self::assertSame([], array_values(array_filter($state['blockers'], static fn(array $b): bool => $b['fieldId'] === 'krankheit')));

    // A later schema change flips `current`; the decision row stays.
    $form = $this->updateField($form, 'dog', 'rasse', ['privacy' => ['kind' => 'identity']]);
    [, $state] = $this->call('state', $form);
    self::assertTrue($decisions['wording']['current'], 'Wording was current before the change.');
    self::assertFalse($state['decisions'][2]['current']);
    self::assertTrue($state['decisions'][0]['current']);
    self::assertTrue($state['decisions'][3]['current'], 'Labels did not change.');

    $archived = $this->compiledDraft();
    $archived->set('field_workflow_status', 'archived')->save();
    self::assertSame([409, ['error' => ['code' => 'form_setup.not_eligible']]], $this->decide($archived, (int) $archived->getRevisionId(), 'dog', [
      $this->item('rasse', 'privacy.kind', 'kept'),
    ]));
  }

  /**
   * A rule snapshot from the request is bounded to what the catalog makes.
   */
  public function testRuleRecommendationSnapshotIsBounded(): void {
    $form = $this->compiledDraft();
    $revision = (int) $form->getRevisionId();
    $rule = static fn(string $rule_id, mixed $value): array => [
      'origin' => 'rule',
      'ruleId' => $rule_id,
      'value' => $value,
    ];
    $refused = [
      'array value' => $rule('privacy-catalog:label:health', ['kind' => 'special_category']),
      'value over 200 characters' => $rule('privacy-catalog:label:health', str_repeat('ä', 201)),
      'float value' => $rule('format-rule:postal_code', 1.5),
      'unknown rule family' => $rule('model:77', 'identity'),
      'rule id without a name' => $rule('gate:', TRUE),
      'rule id with spaces' => $rule('privacy-catalog:label:health condition', 'identity'),
      'rule id over 128 characters' => $rule('privacy-catalog:' . str_repeat('a', 113), 'identity'),
    ];
    foreach ($refused as $case => $recommendation) {
      self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
        $this->item('rasse', 'privacy.kind', 'other', $recommendation),
      ]), $case);
    }
    self::assertSame(0, $this->countRows($form), 'Refused snapshots wrote nothing.');

    self::assertSame([200, ['saved' => 1]], $this->decide($form, $revision, 'dog', [
      $this->item('rasse', 'privacy.kind', 'other', $rule('privacy-catalog:label:animal.breed', str_repeat('ä', 200))),
    ]), 'A string of 200 characters and a catalog rule id pass.');
    self::assertSame([200, ['saved' => 2]], $this->decide($form, $revision, 'dog', [
      $this->item('rasse', 'privacy.kind', 'kept', $rule('privacy-catalog:section:person.circumstances', NULL)),
      $this->item('kennung', 'format', 'other', $rule('format-rule:reference_number', 'reference_number')),
    ]));
  }

  /**
   * Validation decisions need a model reference and store the projection.
   */
  public function testValidationDecisionStoresProjection(): void {
    $form = $this->compiledDraft();
    $this->storeRun($form, [$this->recommendation('kennung', 'validation', ['maxLength' => 100])]);
    [, $state] = $this->call('state', $form);
    $recommendation_id = $state['recommendations'][0]['id'];
    $revision = (int) $form->getRevisionId();

    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('kennung', 'validation', 'kept'),
    ]), 'No recommendation reference.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('kennung', 'validation', 'other', [
        'origin' => 'rule',
        'ruleId' => 'x',
        'value' => ['maxLength' => 100],
      ]),
    ]), 'A rule reference is not enough.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.input_invalid']]], $this->decide($form, $revision, 'dog', [
      $this->item('rasse', 'validation', 'kept', ['origin' => 'model', 'id' => $recommendation_id]),
    ]), 'The reference must belong to the same field.');
    self::assertSame([422, ['error' => ['code' => 'form_setup.choice_mismatch']]], $this->decide($form, $revision, 'dog', [
      $this->item('kennung', 'validation', 'accepted', ['origin' => 'model', 'id' => $recommendation_id]),
    ]), 'maxLength is not stored yet.');
    self::assertSame([200, ['saved' => 1]], $this->decide($form, $revision, 'dog', [
      $this->item('kennung', 'validation', 'kept', ['origin' => 'model', 'id' => $recommendation_id]),
    ]));
    [, $state] = $this->call('state', $form);
    self::assertSame(['maxLength' => NULL], $state['decisions'][0]['value'], '"So lassen" stores the projection.');

    // Nitro merged the recommended key; pattern and minLength stay.
    $form = $this->updateField($form, 'dog', 'kennung', [
      'validation' => ['pattern' => '^[A-Z0-9]+$', 'minLength' => 2, 'maxLength' => 100],
    ]);
    self::assertSame([200, ['saved' => 1]], $this->decide($form, (int) $form->getRevisionId(), 'dog', [
      $this->item('kennung', 'validation', 'accepted', ['origin' => 'model', 'id' => $recommendation_id]),
    ]));
    [, $state] = $this->call('state', $form);
    $decision = $state['decisions'][0];
    self::assertSame(['maxLength' => 100], $decision['value']);
    self::assertTrue($decision['current']);
    self::assertSame('model', $decision['recommendation']['origin']);
    self::assertSame(self::QUOTE, $decision['recommendation']['quote']);
    self::assertSame([
      'id' => self::DOC_A,
      'title' => 'Merkblatt Hundesteuer',
      'version' => self::VERSION_A,
      'page' => 2,
    ], $decision['recommendation']['source']);

    // `current` compares only the recommended keys.
    $form = $this->updateField($form, 'dog', 'kennung', ['validation' => ['minLength' => 3, 'maxLength' => 100]]);
    [, $state] = $this->call('state', $form);
    self::assertTrue($state['decisions'][0]['current']);
    $form = $this->updateField($form, 'dog', 'kennung', ['validation' => ['minLength' => 3, 'maxLength' => 80]]);
    [, $state] = $this->call('state', $form);
    self::assertFalse($state['decisions'][0]['current']);
  }

  /**
   * Up to 401 decisions per request are accepted, 402 are refused.
   */
  public function testDecisionCountBoundary(): void {
    $fields = [];
    for ($i = 1; $i <= 100; $i++) {
      $fields[] = [
        'id' => 'datei_' . $i,
        'type' => 'file',
        'label' => 'Datei ' . $i,
        'privacy' => ['kind' => 'not_personal'],
        'analysis' => ['enabled' => FALSE, 'egressPolicy' => 'no_ai', 'expectedDocumentType' => 'Beleg'],
      ];
    }
    $form = $this->compiledDraft([['id' => 'docs', 'title' => 'Unterlagen', 'fields' => $fields]]);
    $items = [$this->item('', 'wording', 'kept')];
    foreach ($fields as $field) {
      foreach (['privacy.kind', 'sensitive', 'analysis.document', 'analysis.expectedDocumentType'] as $property) {
        $items[] = $this->item($field['id'], $property, 'kept');
      }
    }
    self::assertCount(401, $items);

    self::assertSame([
      422,
      ['error' => ['code' => 'form_setup.input_invalid']],
    ], $this->decide($form, (int) $form->getRevisionId(), 'docs', [...$items, $this->item('', 'wording', 'kept')]));
    self::assertSame(0, $this->countRows($form));
    self::assertSame([200, ['saved' => 401]], $this->decide($form, (int) $form->getRevisionId(), 'docs', $items));
    self::assertSame(401, $this->countRows($form));
  }

  /**
   * Recommendation route rules, the run manifest and the run guard.
   */
  public function testRecommendationRules(): void {
    $form = $this->compiledDraft();
    $revision = (int) $form->getRevisionId();
    $valid = $this->recommendation('nachweis', 'analysis.expectedDocumentType', 'Kopie der Haftpflichtversicherung');
    $doc_a = ['id' => self::DOC_A, 'version' => self::VERSION_A];
    $invalid = [422, ['error' => ['code' => 'form_setup.input_invalid']]];

    $cases = [
      'document not selected' => [[['id' => self::DOC_C, 'version' => self::VERSION_A]], [$valid]],
      'document not enabled for the editor' => [
        [['id' => self::DOC_B, 'version' => 'revision:40:changed:1762600100']],
        [$valid],
      ],
      'document version differs' => [[['id' => self::DOC_A, 'version' => 'revision:30:changed:1762500000']], [$valid]],
      'no documents' => [[], [$valid]],
      'four documents' => [[$doc_a, $doc_a, $doc_a, $doc_a], [$valid]],
      'duplicate document' => [[$doc_a, $doc_a], [$valid]],
      'source not among the documents' => [
        [$doc_a],
        [['source' => ['id' => self::DOC_D, 'title' => 'X', 'version' => self::VERSION_A, 'page' => 1]] + $valid],
      ],
      'quote of 9 characters' => [[$doc_a], [['quote' => 'Zu kurz..'] + $valid]],
      'quote of 301 characters' => [[$doc_a], [['quote' => str_repeat('a', 301)] + $valid]],
      'property not allowed' => [[$doc_a], [['property' => 'privacy.kind', 'value' => 'identity'] + $valid]],
      'format on a file field' => [[$doc_a], [['property' => 'format', 'value' => 'iban'] + $valid]],
      'format changes the field type' => [[$doc_a], [$this->recommendation('kennung', 'format', 'currency')]],
      'validation with pattern' => [[$doc_a], [$this->recommendation('kennung', 'validation', ['pattern' => '.*'])]],
      'one item per field and property' => [[$doc_a], [$valid, $valid]],
      'unknown field' => [[$doc_a], [['fieldId' => 'gibtsnicht'] + $valid]],
      'extra key' => [[$doc_a], [$valid + ['page' => 2]]],
    ];
    foreach ($cases as $name => [$documents, $items]) {
      self::assertSame($invalid, $this->postRecommendations($form, $revision, $documents, $items), $name);
    }
    self::assertSame(
      [409, ['error' => ['code' => 'form_setup.revision_stale']]],
      $this->postRecommendations($form, $revision + 1, [$doc_a], [$valid]),
    );
    self::assertSame(0, $this->countRows($form), 'Nothing was stored.');

    // A run without kept items still stores the full manifest.
    self::assertSame([200, ['saved' => 0]], $this->postRecommendations($form, $revision, [$doc_a], [], 7));
    $run = $this->container->get('beantragt_schema.form_setup_log')->run((string) $form->uuid());
    self::assertSame(['documents' => [$doc_a], 'kept' => 0, 'dropped' => 7], $run['value']);
    self::assertSame([409, ['error' => ['code' => 'form_setup.run_exists']]], $this->postRecommendations($form, $revision, [$doc_a], [$valid]));
    [, $state] = $this->call('state', $form);
    self::assertSame(['createdAt' => $run['created'], 'modelId' => 'claude-test', 'kept' => 0, 'dropped' => 7], $state['run']);

    // A second form keeps one item per accepted property kind.
    $other = $this->compiledDraft();
    self::assertSame([200, ['saved' => 3]], $this->postRecommendations($other, (int) $other->getRevisionId(), [$doc_a], [
      $valid,
      $this->recommendation('kennung', 'format', 'iban'),
      $this->recommendation('rasse', 'validation', ['maxLength' => 40]),
    ], 5));
    [, $state] = $this->call('state', $other);
    self::assertCount(3, $state['recommendations']);
    self::assertSame([
      'id' => $state['recommendations'][0]['id'],
      'revisionId' => (int) $other->getRevisionId(),
      'screenId' => 'dog',
      'fieldId' => 'nachweis',
      'property' => 'analysis.expectedDocumentType',
      'value' => 'Kopie der Haftpflichtversicherung',
      'quote' => self::QUOTE,
      'modelId' => 'claude-test',
      'basisHash' => str_repeat('b', 64),
      'source' => ['id' => self::DOC_A, 'title' => 'Merkblatt Hundesteuer', 'version' => self::VERSION_A, 'page' => 2],
    ], $state['recommendations'][0]);
    self::assertSame([
      'createdAt' => $state['run']['createdAt'],
      'modelId' => 'claude-test',
      'kept' => 3,
      'dropped' => 5,
    ], $state['run']);

    $archived = $this->compiledDraft();
    $archived->set('field_workflow_status', 'archived')->save();
    self::assertSame([409, ['error' => ['code' => 'form_setup.not_eligible']]], $this->postRecommendations($archived, (int) $archived->getRevisionId(), [$doc_a], []));
  }

  /**
   * Real HTTP requests: Basic auth service account in, staff out.
   */
  public function testRecommendationRouteOverHttp(): void {
    $form = $this->compiledDraft();
    $this->container->get('router.builder')->rebuild();
    $body = [
      'revisionId' => (int) $form->getRevisionId(),
      'modelId' => 'claude-test',
      'dropped' => 0,
      'documents' => [['id' => self::DOC_A, 'version' => self::VERSION_A]],
      'recommendations' => [$this->recommendation('nachweis', 'analysis.expectedDocumentType', 'Kopie der Haftpflichtversicherung')],
    ];

    $service = $this->httpPost($this->accounts['service_account'], $form, $body);
    self::assertSame(200, $service->getStatusCode(), (string) $service->getContent());
    self::assertSame(['saved' => 1], json_decode((string) $service->getContent(), TRUE));
    $again = $this->httpPost($this->accounts['service_account'], $form, $body);
    self::assertSame(409, $again->getStatusCode());
    self::assertSame(['error' => ['code' => 'form_setup.run_exists']], json_decode((string) $again->getContent(), TRUE));
    // Last on purpose: the HTML access-denied page renders in a subrequest.
    $staff = $this->httpPost($this->accounts['department_admin'], $form, $body);
    self::assertSame(403, $staff->getStatusCode(), 'A staff account is refused even with valid credentials.');
  }

  /**
   * State blockers are empty exactly when the publish gate lets a form pass.
   */
  public function testBlockersMatchThePublishGate(): void {
    $text = static fn(array $extra): array => [
      ['id' => 'main', 'title' => 'Angaben', 'fields' => [['id' => 'feld', 'type' => 'text'] + $extra]],
    ];
    $schemas = [
      'classified' => $this->classifiedSections(),
      'personal without class' => $text(['label' => 'Nachname']),
      'personal with not_personal' => $text(['label' => 'E-Mail-Adresse', 'privacy' => ['kind' => 'not_personal']]),
      'high-risk without anything' => $text(['label' => 'Diagnose']),
      'high-risk class without flag' => $text(['label' => 'Diagnose', 'privacy' => ['kind' => 'special_category']]),
      'high-risk class spelled loosely' => $text(['label' => 'Diagnose', 'privacy' => ['kind' => 'Special Category']]),
      'only empty sections' => [['id' => 'main', 'title' => 'Angaben', 'fields' => []]],
    ];
    $state = $this->setupState();
    foreach ($schemas as $name => $sections) {
      $form = $this->compiledDraft($sections);
      $blockers = $state->blockers($form);
      $form->set('field_workflow_status', 'published')->set('status', TRUE);
      $violations = array_filter(
        iterator_to_array($form->validate()),
        static fn(ConstraintViolationInterface $violation): bool => $violation->getConstraint() instanceof ValidFormSchemaDefinitionConstraint,
      );
      self::assertSame($violations === [], $blockers === [], $name . ': ' . json_encode($blockers));
    }
    self::assertSame(
      [['sectionId' => NULL, 'fieldId' => NULL, 'code' => 'schema_invalid']],
      $state->blockers($this->compiledDraft($schemas['only empty sections'])),
    );
  }

  /**
   * Calls a GET route of the controller for a form.
   *
   * @return array{0: int, 1: array<string, mixed>}
   *   Status and decoded body.
   */
  private function call(string $route, FormSchemaInterface $form): array {
    $controller = FormSetupController::create($this->container);
    $response = $route === 'state'
      ? $controller->state((string) $form->uuid())
      : $controller->capabilities((string) $form->uuid());
    return $this->decode($response);
  }

  /**
   * Posts decisions as the current user.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param int $revision
   *   Revision id.
   * @param string $screen
   *   Section id.
   * @param list<array<string, mixed>> $decisions
   *   Decision items.
   *
   * @return array{0: int, 1: array<string, mixed>}
   *   Status and decoded body.
   */
  private function decide(FormSchemaInterface $form, int $revision, string $screen, array $decisions): array {
    $request = Request::create('/', 'POST', content: json_encode([
      'revisionId' => $revision,
      'screenId' => $screen,
      'decisions' => $decisions,
    ], JSON_THROW_ON_ERROR));
    return $this->decode(FormSetupController::create($this->container)->decisions($request, (string) $form->uuid()));
  }

  /**
   * Posts recommendations as the service account.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param int $revision
   *   Revision id.
   * @param list<array<string, mixed>> $documents
   *   Manifest.
   * @param list<array<string, mixed>> $items
   *   Recommendation items.
   * @param int $dropped
   *   Number of dropped items.
   *
   * @return array{0: int, 1: array<string, mixed>}
   *   Status and decoded body.
   */
  private function postRecommendations(FormSchemaInterface $form, int $revision, array $documents, array $items, int $dropped = 0): array {
    $previous = $this->container->get('current_user')->getAccount();
    $this->setCurrentUser($this->accounts['service_account']);
    $request = Request::create('/', 'POST', content: json_encode([
      'revisionId' => $revision,
      'modelId' => 'claude-test',
      'dropped' => $dropped,
      'documents' => $documents,
      'recommendations' => $items,
    ], JSON_THROW_ON_ERROR));
    try {
      return $this->decode(FormSetupController::create($this->container)->recommendations($request, (string) $form->uuid()));
    }
    finally {
      $this->container->get('current_user')->setAccount($previous);
    }
  }

  /**
   * Stores a run with the given items or fails.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param list<array<string, mixed>> $items
   *   Recommendation items.
   */
  private function storeRun(FormSchemaInterface $form, array $items): void {
    $result = $this->postRecommendations($form, (int) $form->getRevisionId(), [
      ['id' => self::DOC_A, 'version' => self::VERSION_A],
    ], $items);
    self::assertSame(200, $result[0], json_encode($result[1]));
  }

  /**
   * Sends one HTTP request to the recommendations route with Basic auth.
   *
   * @param \Drupal\user\UserInterface $account
   *   The account.
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param array<string, mixed> $body
   *   Request body.
   */
  private function httpPost(UserInterface $account, FormSchemaInterface $form, array $body): JsonResponse {
    $request = Request::create('/api/admin/form-setup/forms/' . $form->uuid() . '/recommendations', 'POST', server: [
      'HTTP_ACCEPT' => 'application/json',
      'CONTENT_TYPE' => 'application/json',
      'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($account->getAccountName() . ':' . self::PASSWORD),
      'PHP_AUTH_USER' => $account->getAccountName(),
      'PHP_AUTH_PW' => self::PASSWORD,
    ], content: json_encode($body, JSON_THROW_ON_ERROR));
    $response = $this->container->get('http_kernel')->handle($request);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    if (!$response instanceof JsonResponse) {
      $response = new JsonResponse(json_decode((string) $response->getContent(), TRUE), $response->getStatusCode());
    }
    return $response;
  }

  /**
   * One decision item.
   *
   * @param string $field_id
   *   Field id, empty for the screen.
   * @param string $property
   *   Property.
   * @param string $choice
   *   Choice.
   * @param array<string, mixed>|null $recommendation
   *   Recommendation reference.
   *
   * @return array<string, mixed>
   *   Item.
   */
  private function item(string $field_id, string $property, string $choice, ?array $recommendation = NULL): array {
    return ['fieldId' => $field_id, 'property' => $property, 'choice' => $choice, 'recommendation' => $recommendation];
  }

  /**
   * One recommendation item of the service request.
   *
   * @return array<string, mixed>
   *   Item.
   */
  private function recommendation(string $field_id, string $property, mixed $value): array {
    return [
      'screenId' => 'dog',
      'fieldId' => $field_id,
      'property' => $property,
      'value' => $value,
      'quote' => self::QUOTE,
      'basisHash' => str_repeat('b', 64),
      'source' => ['id' => self::DOC_A, 'title' => 'Merkblatt Hundesteuer', 'version' => self::VERSION_A, 'page' => 2],
    ];
  }

  /**
   * Decodes a controller response.
   *
   * @return array{0: int, 1: array<string, mixed>}
   *   Status and decoded body.
   */
  private function decode(JsonResponse $response): array {
    return [$response->getStatusCode(), json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR)];
  }

  /**
   * Saves a compiled draft as Nitro creates it.
   *
   * @param list<array<string, mixed>>|null $sections
   *   Sections, NULL for the default Hundesteuer sections.
   * @param array<string, mixed> $root
   *   Root keys overriding the defaults.
   * @param string $file_name
   *   Source file name.
   */
  private function compiledDraft(?array $sections = NULL, array $root = [], string $file_name = 'hundesteuer.pdf'): FormSchemaInterface {
    $schema = $root + [
      'title' => 'Hundesteuer',
      'source' => ['type' => 'pdf', 'file' => 'hundesteuer.pdf'],
      'contextSources' => [
        [
          'type' => 'public_document',
          'id' => self::DOC_A,
          'title' => 'Merkblatt Hundesteuer',
          'version' => self::VERSION_A,
        ],
        [
          'type' => 'public_document',
          'id' => self::DOC_B,
          'title' => 'Merkblatt Bürgerassistent',
          'version' => 'revision:40:changed:1762600100',
          'enabledFor' => ['citizen_assistant'],
        ],
        [
          'type' => 'public_document',
          'id' => self::DOC_D,
          'title' => 'Merkblatt Gebühren',
          'version' => self::VERSION_A,
          'enabledFor' => ['editor'],
        ],
      ],
      'sections' => $sections ?? $this->defaultSections(),
    ];
    $form = $this->container->get('entity_type.manager')->getStorage('form_schema')->create([
      'label' => 'Hundesteuer',
      'field_schema' => json_encode($schema, JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'draft',
      'field_publication_scope' => 'internet',
      'field_source_filename' => $file_name,
      'status' => FALSE,
    ]);
    assert($form instanceof FormSchemaInterface);
    $form->save();
    return $form;
  }

  /**
   * Changes keys of one field and saves a new revision, like Nitro does.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   The form.
   * @param string $section_id
   *   Section id.
   * @param string $field_id
   *   Field id.
   * @param array<string, mixed> $values
   *   Field keys to set.
   */
  private function updateField(FormSchemaInterface $form, string $section_id, string $field_id, array $values): FormSchemaInterface {
    $schema = json_decode((string) $form->getSchema(), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach ($schema['sections'] as &$section) {
      if ($section['id'] !== $section_id) {
        continue;
      }
      foreach ($section['fields'] as &$field) {
        if ($field['id'] === $field_id) {
          $field = $values + $field;
        }
      }
      unset($field);
    }
    unset($section);
    $form->set('field_schema', json_encode($schema, JSON_THROW_ON_ERROR))->save();
    $stored = $this->container->get('entity_type.manager')->getStorage('form_schema')->loadUnchanged($form->id());
    assert($stored instanceof FormSchemaInterface);
    return $stored;
  }

  /**
   * The default sections: blockers on dog and on the section "review".
   *
   * @return list<array<string, mixed>>
   *   Sections.
   */
  private function defaultSections(): array {
    return [
      [
        'id' => 'dog',
        'title' => 'Hund',
        'fields' => [
          ['id' => 'krankheit', 'type' => 'text', 'label' => 'Krankheit des Hundes'],
          [
            'id' => 'rasse',
            'type' => 'text',
            'label' => 'Rasse',
            'placeholder' => 'z. B. Dackel',
            'privacy' => ['kind' => 'not_personal'],
          ],
          [
            'id' => 'kennung',
            'type' => 'text',
            'label' => 'Kennung',
            'privacy' => ['kind' => 'not_personal'],
            'validation' => ['pattern' => '^[A-Z0-9]+$', 'minLength' => 2],
          ],
          [
            'id' => 'nachweis',
            'type' => 'file',
            'label' => 'Nachweis',
            'privacy' => ['kind' => 'not_personal'],
            'analysis' => ['enabled' => FALSE, 'egressPolicy' => 'no_ai', 'expectedDocumentType' => 'Versicherung'],
          ],
        ],
      ],
      [
        'id' => 'review',
        'title' => 'Halterin oder Halter',
        'fields' => [
          ['id' => 'vorname', 'type' => 'text', 'label' => 'Vorname'],
        ],
      ],
    ];
  }

  /**
   * Sections without any publish blocker.
   *
   * @return list<array<string, mixed>>
   *   Sections.
   */
  private function classifiedSections(): array {
    return [[
      'id' => 'dog',
      'title' => 'Hund',
      'fields' => [['id' => 'rasse', 'type' => 'text', 'label' => 'Rasse', 'privacy' => ['kind' => 'not_personal']]],
    ],
    ];
  }

  /**
   * A section with the given number of plain fields.
   *
   * @return array<string, mixed>
   *   Section.
   */
  private function bigSection(int $count): array {
    $fields = [];
    for ($i = 1; $i <= $count; $i++) {
      $fields[] = [
        'id' => 'feld_' . $i,
        'type' => 'text',
        'label' => 'Angabe ' . $i,
        'privacy' => ['kind' => 'not_personal'],
      ];
    }
    return ['id' => 'gross', 'title' => 'Viele Angaben', 'fields' => $fields];
  }

  /**
   * The state service.
   */
  private function setupState(): FormSetupState {
    return $this->container->get('beantragt_schema.form_setup_state');
  }

  /**
   * Counts log rows of a form.
   */
  private function countRows(FormSchemaInterface $form): int {
    return (int) $this->container->get('database')->select(FormSetupLog::TABLE, 'l')
      ->condition('form_uuid', (string) $form->uuid())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
