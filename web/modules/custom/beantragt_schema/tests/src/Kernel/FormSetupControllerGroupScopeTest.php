<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Controller\FormSetupController;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Repository\FormSetupLog;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Group scoping of the guided form setup routes.
 *
 * A department admin of another jurisdiction gets 404 on every staff route,
 * as entity access (beantragt_group) decides; the own admin gets 200.
 */
#[CoversClass(FormSetupController::class)]
#[Group('beantragt_schema')]
#[Group('beantragt_group')]
final class FormSetupControllerGroupScopeTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_roles_api',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_schema', [FormSetupLog::TABLE]);

    $role = Role::create(['id' => 'department_admin', 'label' => 'Department Admin']);
    foreach ([
      'create form_schema entities',
      'edit any form_schema entities',
      'view published form_schema entities',
      'view unpublished form_schema entities',
      'administer form_schema entities',
      'publish form_schema entities',
    ] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
  }

  /**
   * Another jurisdiction gets 404 on every staff route; the own one 200.
   */
  public function testOtherJurisdictionGets404(): void {
    $jurisdiction_a = $this->createJurisdiction('setup-jur-a', 'Jurisdiction A');
    $jurisdiction_b = $this->createJurisdiction('setup-jur-b', 'Jurisdiction B');
    $admin_a = $this->createDepartmentAdmin($jurisdiction_a);
    $admin_b = $this->createDepartmentAdmin($jurisdiction_b);
    $this->setCurrentUser($admin_a);
    $form = $this->createCompiledDraft($jurisdiction_a);
    $decisions = Request::create('/', 'POST', content: json_encode([
      'revisionId' => (int) $form->getRevisionId(),
      'screenId' => 'dog',
      'decisions' => [
        ['fieldId' => 'rasse', 'property' => 'privacy.kind', 'choice' => 'kept', 'recommendation' => NULL],
      ],
    ], JSON_THROW_ON_ERROR));

    $this->setCurrentUser($admin_b);
    $controller = FormSetupController::create($this->container);
    $not_found = [404, ['error' => ['code' => 'form_setup.form_not_found']]];
    self::assertSame($not_found, $this->decode($controller->capabilities((string) $form->uuid())));
    self::assertSame($not_found, $this->decode($controller->state((string) $form->uuid())));
    self::assertSame($not_found, $this->decode($controller->decisions($decisions, (string) $form->uuid())));

    $this->setCurrentUser($admin_a);
    $controller = FormSetupController::create($this->container);
    [$status, $state] = $this->decode($controller->state((string) $form->uuid()));
    self::assertSame(200, $status);
    self::assertTrue($state['eligible']);
    self::assertTrue($state['canPublish']);
    self::assertSame([200, ['saved' => 1]], $this->decode($controller->decisions($decisions, (string) $form->uuid())));
  }

  /**
   * Creates a department admin with a jurisdiction admin membership.
   */
  private function createDepartmentAdmin(GroupInterface $jurisdiction): UserInterface {
    $user = $this->createUser();
    $user->addRole('department_admin');
    $user->save();
    $jurisdiction->addMember($user, ['group_roles' => ['jur-admin']]);
    return $user;
  }

  /**
   * Creates a jurisdiction group.
   */
  private function createJurisdiction(string $slug, string $label): GroupInterface {
    return $this->createGroup([
      'type' => 'jur',
      'label' => $label,
      'field_slug' => $slug,
    ]);
  }

  /**
   * Creates a compiled draft as the jurisdiction administrator.
   */
  private function createCompiledDraft(GroupInterface $jurisdiction): FormSchemaInterface {
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Hundesteuer',
      'field_schema' => json_encode([
        'title' => 'Hundesteuer',
        'source' => ['type' => 'pdf', 'file' => 'hundesteuer.pdf'],
        'sections' => [[
          'id' => 'dog',
          'title' => 'Hund',
          'fields' => [['id' => 'rasse', 'type' => 'text', 'label' => 'Rasse', 'privacy' => ['kind' => 'not_personal']]],
        ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'draft',
      'field_publication_scope' => 'internet',
      'field_source_filename' => 'hundesteuer.pdf',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_group' => ['target_id' => $jurisdiction->id()],
      'status' => FALSE,
    ]);
    assert($form instanceof FormSchemaInterface);
    $form->save();
    return $form;
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

}
