<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Kernel;

use Drupal\beantragt_group\Controller\JurisdictionConfigController;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests jurisdiction-scoped access for notification mail settings.
 */
#[Group('beantragt_notification')]
#[Group('beantragt_group')]
final class NotificationTemplateCrossJurisdictionAccessTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'language',
    'serialization',
    'beantragt_compute_api',
    'beantragt_roles_api',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_workflow',
    'beantragt_notification',
  ];

  /**
   * Superuser account used for uid 1 access assertions.
   */
  private UserInterface $superUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_notification']);
    $this->installEntitySchema('workflow_status');
    $this->ensureRole('administrator');
    $this->ensureRole('department_admin');
    $this->ensureRole('case_worker');
    $this->superUser = $this->createUserWithRoles(['administrator']);
  }

  /**
   * Jurisdiction admins can manage their own notification templates only.
   */
  public function testJurisdictionNotificationMailAccessIsScoped(): void {
    $jurisdiction_a = $this->createJurisdiction('jur-a', 'Jurisdiction A');
    $jurisdiction_b = $this->createJurisdiction('jur-b', 'Jurisdiction B');
    $jur_a_admin = $this->createUserWithRoles(['department_admin']);
    $case_worker = $this->createUserWithRoles(['case_worker']);
    $plain_member = $this->createUser();

    $jurisdiction_a->addMember($jur_a_admin, ['group_roles' => ['jur-admin']]);
    $jurisdiction_a->addMember($case_worker, ['group_roles' => ['jur-member']]);
    $jurisdiction_a->addMember($plain_member, ['group_roles' => ['jur-member']]);

    $controller = JurisdictionConfigController::create($this->container);

    $jurisdiction_a_id = (string) $jurisdiction_a->id();
    $jurisdiction_b_id = (string) $jurisdiction_b->id();

    $this->assertTrue($controller->accessCheck($jur_a_admin, $jurisdiction_a_id, 'notification_mail')->isAllowed());
    $this->assertFalse($controller->accessCheck($jur_a_admin, $jurisdiction_b_id, 'notification_mail')->isAllowed());
    $this->assertFalse($controller->accessCheck($case_worker, $jurisdiction_a_id, 'notification_mail')->isAllowed());
    $this->assertFalse($controller->accessCheck($plain_member, $jurisdiction_a_id, 'notification_mail')->isAllowed());
    $this->assertTrue($controller->accessCheck($this->superUser, $jurisdiction_a_id, 'notification_mail')->isAllowed());
    $this->assertTrue($controller->accessCheck($this->superUser, $jurisdiction_b_id, 'notification_mail')->isAllowed());

    $get = $controller->getSection($jurisdiction_a_id, 'notification_mail');
    $this->assertSame(Response::HTTP_OK, $get->getStatusCode());
    $payload = json_decode((string) $get->getContent(), TRUE);
    $this->assertArrayHasKey('application_confirmation_request', $payload['data']['templates']);
    $this->assertTrue($payload['data']['templates']['application_confirmation_request']['lockedEnabled']);

    $patch = $controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'status_change' => [
              'enabled' => TRUE,
            ],
          ],
        ],
      ])),
      $jurisdiction_a_id,
      'notification_mail',
    );

    $this->assertSame(Response::HTTP_OK, $patch->getStatusCode());
    $stored = $this->jurisdictionConfig()->getSection($this->reloadGroup($jurisdiction_a), 'notification_mail');
    $this->assertTrue($stored['templates']['status_change']['enabled']);
    $this->assertSame([], $this->jurisdictionConfig()->getSection($this->reloadGroup($jurisdiction_b), 'notification_mail'));
  }

  /**
   * The update hook seeds missing templates without overwriting edits.
   */
  public function testUpdateHookSeedsMissingTemplatesIdempotently(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_notification/beantragt_notification.install';

    $jurisdiction = $this->createJurisdiction('seed-jur', 'Seed Jurisdiction');
    $this->jurisdictionConfig()->updateSection($jurisdiction, 'notification_mail', [
      'templates' => [
        'status_change' => [
          'default' => [
            'subject' => 'Operator subject',
          ],
        ],
      ],
    ]);

    $first = beantragt_notification_update_10015();
    $this->assertSame('Seeded jurisdiction notification_mail templates for jurisdictions: 1', $first);

    $reloaded = $this->reloadGroup($jurisdiction);
    $section = $this->jurisdictionConfig()->getSection($reloaded, 'notification_mail');
    $this->assertSame('Operator subject', $section['templates']['status_change']['default']['subject']);
    $this->assertNotEmpty($section['templates']['status_change']['default']['body']);
    $this->assertTrue($section['templates']['application_confirmation_request']['enabled']);

    $second = beantragt_notification_update_10015();
    $this->assertSame('Seeded jurisdiction notification_mail templates for jurisdictions: 0', $second);
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
   * Creates a user with global Drupal roles.
   *
   * @param list<string> $roles
   *   Role ids.
   */
  private function createUserWithRoles(array $roles): UserInterface {
    $user = $this->createUser();
    foreach ($roles as $role) {
      $user->addRole($role);
    }
    $user->save();
    return $user;
  }

  /**
   * Reloads a group after a service writes to storage.
   */
  private function reloadGroup(GroupInterface $group): GroupInterface {
    $reloaded = $this->entityTypeManager()->getStorage('group')->loadUnchanged($group->id());
    assert($reloaded instanceof GroupInterface);
    return $reloaded;
  }

  /**
   * Ensures a Drupal user role exists.
   */
  private function ensureRole(string $roleId): void {
    if (Role::load($roleId) !== NULL) {
      return;
    }
    Role::create([
      'id' => $roleId,
      'label' => $roleId,
    ])->save();
  }

  /**
   * Returns the jurisdiction config service.
   */
  private function jurisdictionConfig(): JurisdictionConfigServiceInterface {
    $service = $this->container->get('beantragt_group.jurisdiction_config');
    assert($service instanceof JurisdictionConfigServiceInterface);
    return $service;
  }

}
