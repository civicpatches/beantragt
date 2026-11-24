<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Access\ApplicationCommentStaffScope;
use Drupal\beantragt_application\Access\ApplicationMembershipBatch;
use Drupal\beantragt_application\Controller\ApplicationAssignmentController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Database;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\Language;
use Drupal\user\Entity\Role;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real membership queries stay bounded while original owner checks still run.
 */
#[Group('beantragt_application')]
final class ApplicationMembershipBatchKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget',
    'serialization', 'language', 'beantragt_schema', 'beantragt_workflow',
    'beantragt_workflow_status', 'beantragt_application', 'beantragt_group',
    'beantragt_roles_api', 'beantragt_comment', 'beantragt_notification',
    'beantragt_delivery', 'beantragt_docgen',
  ];

  /**
   * Thirty visible cases do not repeat membership SQL per case and kind.
   */
  public function testOwnerChecksReuseMembershipsAndDiscardSnapshot(): void {
    $this->installConfig(['user', 'beantragt_group']);
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('file', ['file_usage']);
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Event membership test']);
    // Register permissions from the installed owners on an isolated test role.
    $role = Role::create(['id' => 'event_reader', 'label' => 'Event reader']);
    foreach ([
      'view published application entities', 'view workflow assignments',
      'manage application deliveries', 'release docgen documents',
    ] as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
    $reader = $this->createUser([], NULL, FALSE, ['roles' => ['event_reader']]);
    $jurisdiction->addMember($reader, ['group_roles' => ['jur-admin']]);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__isset')->willReturn(TRUE);
    $field->method('__get')->willReturnMap([
      ['target_id', (int) $jurisdiction->id()], ['entity', $jurisdiction],
    ]);
    $applications = [];
    for ($id = 1; $id <= 30; $id++) {
      $application = $this->createMock(ApplicationInterface::class);
      $application->method('id')->willReturn($id);
      $application->method('getEntityTypeId')->willReturn('application');
      $application->method('getEntityType')->willReturn($this->container->get('entity_type.manager')->getDefinition('application'));
      $application->method('language')->willReturn(new Language(['id' => 'en']));
      $application->method('bundle')->willReturn('application');
      $application->method('uuid')->willReturn(sprintf('00000000-0000-4000-8000-%012d', $id));
      $application->method('isPublished')->willReturn(TRUE);
      $application->method('access')->willReturn(TRUE);
      $application->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_jurisdiction');
      $application->method('get')->with('field_jurisdiction')->willReturn($field);
      $application->method('getCacheContexts')->willReturn([]);
      $application->method('getCacheTags')->willReturn([]);
      $application->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
      $applications[] = $application;
    }
    $manager = $this->container->get('entity_type.manager');
    $scope = new ApplicationCommentStaffScope($manager);
    $access = $manager->getAccessControlHandler('application');
    $delivery = new ScopeAccess($manager);
    $protected = $this->container->get('beantragt_application.protected_data_gate');
    $docgen = new DocgenScopeAccess($manager);
    $assignment = new ApplicationAssignmentController($reader, $this->container->get('datetime.time'), $manager, $this->container->get('beantragt_application.application_case_scope'));
    $counts = [];
    foreach ([1, 30] as $size) {
      $page = array_slice($applications, 0, $size);
      $this->container->get('cache.group_memberships_chained')->deleteAll();
      foreach (['group_relationship', 'user', 'group'] as $entity_type) {
        $manager->getStorage($entity_type)->resetCache();
      }
      Database::startLog('event-memberships-' . $size);
      ApplicationMembershipBatch::run($manager, $reader, $page, function () use ($scope, $page, $reader, $access, $delivery, $protected, $docgen, $assignment): void {
        $this->assertTrue(ApplicationMembershipBatch::active($reader));
        foreach ($page as $application) {
          $this->assertTrue($scope->allows($application, $reader));
          $this->assertTrue($access->access($application, 'view', $reader));
          $delivery_access = $delivery->access($application, $reader);
          $this->assertTrue($delivery_access->isAllowed(), method_exists($delivery_access, 'getReason') ? $delivery_access->getReason() : 'Delivery scope denied.');
          $this->assertTrue($delivery->transferAccess($application, $reader)->isAllowed());
          $this->assertTrue($protected->allows($application, $reader));
          $this->assertTrue($docgen->canWorkOnCase($application, $reader, DocgenScopeAccess::PERMISSION_RELEASE, TRUE));
          $this->assertTrue($assignment->viewAccess($application, $reader)->isAllowed());
        }
      });
      $queries = Database::getLog('event-memberships-' . $size);
      $counts[$size] = count(array_filter($queries, static fn(array $entry): bool => str_contains($entry['query'], 'group_relationship')));
    }
    $this->assertFalse(ApplicationMembershipBatch::active($reader));
    $this->assertNull(ApplicationMembershipBatch::membership((int) $jurisdiction->id(), $reader));
    $this->assertGreaterThan(0, $counts[1]);
    $this->assertSame($counts[1], $counts[30], 'All owning access services must keep membership SQL constant with page size.');
    $this->assertTrue($scope->allows($applications[0], $reader));
    $jurisdiction->removeMember($reader);
    $this->assertFalse(ApplicationMembershipBatch::run($manager, $reader, $applications, fn(): bool => $scope->allows($applications[0], $reader)));
  }

}
