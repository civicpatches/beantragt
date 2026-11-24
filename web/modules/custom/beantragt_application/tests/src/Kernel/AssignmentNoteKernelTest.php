<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Controller\ApplicationAssignmentController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\user\Entity\User;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Tests assignment note persistence and its internal visibility.
 */
#[CoversClass(ApplicationAssignmentController::class)]
#[Group('beantragt_application')]
final class AssignmentNoteKernelTest extends GroupKernelTestBase {

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
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
    'beantragt_workflow',
    'beantragt_notification',
    'beantragt_comment',
  ];

  /**
   * An assignment note survives SQL storage as an internal staff entry.
   */
  public function testAssignmentNotePersistsInternally(): void {
    $this->installEntitySchema('application');
    $this->installEntitySchema('application_comment');
    $database = $this->container->get('database');
    $database->insert('application')->fields([
      'id' => 1,
      'revision_id' => 1,
      'uuid' => '90000000-0000-4000-8000-000000000001',
      'uid' => 0,
      'status' => 1,
    ])->execute();
    $database->insert('application_revision')->fields([
      'id' => 1,
      'revision_id' => 1,
      'uid' => 0,
      'status' => 1,
    ])->execute();
    $manager = $this->container->get('entity_type.manager');
    $application = $manager->getStorage('application')->load(1);
    $target = User::create(['name' => 'Receiving colleague', 'status' => 1]);
    $target->save();
    $controller = ApplicationAssignmentController::create($this->container);
    $method = new \ReflectionMethod($controller, 'createAssignmentAuditComment');
    $method->invoke($controller, $application, $target, 'Please check the evidence.');
    $storage = $manager->getStorage('application_comment');
    $storage->resetCache();
    $comments = $storage->loadByProperties(['field_application' => 1]);
    $this->assertCount(1, $comments);
    $comment = reset($comments);
    $this->assertSame('internal', $comment->getVisibility());
    $this->assertSame('staff', $comment->getAuthorRole());
    $this->assertStringStartsWith("beantragt_application:assignment\n", $comment->getBody());
    $this->assertStringContainsString('Note: Please check the evidence.', $comment->getBody());
  }

  /**
   * The full assignment path stores one note alongside a team assignment.
   */
  public function testAssignStoresOneInternalNote(): void {
    [$application, $team] = $this->assignmentFixture();
    $response = $this->assign($application, [
      'team_uuid' => $team->uuid(),
      'note' => 'Please check the evidence.',
    ]);
    $this->assertSame(200, $response->getStatusCode());
    $stored = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertSame((int) $team->id(), (int) $stored->get('field_assigned_team')->target_id);
    $comments = $this->entityTypeManager()->getStorage('application_comment')->loadByProperties([
      'field_application' => $application->id(),
    ]);
    $this->assertCount(1, $comments);
    $comment = reset($comments);
    $this->assertSame('internal', $comment->getVisibility());
    $this->assertSame('staff', $comment->getAuthorRole());
    $this->assertStringContainsString('Note: Please check the evidence.', $comment->getBody());
  }

  /**
   * Removing a team assignment discards a supplied note.
   */
  public function testUnassignDiscardsNote(): void {
    [$application, $team] = $this->assignmentFixture();
    $this->assign($application, ['team_uuid' => $team->uuid()]);
    $response = $this->assign($application, ['note' => 'Must not be stored.']);
    $this->assertSame(200, $response->getStatusCode());
    $stored = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertTrue($stored->get('field_assigned_team')->isEmpty());
    $this->assertSame([], $this->entityTypeManager()->getStorage('application_comment')->loadByProperties([
      'field_application' => $application->id(),
    ]));
  }

  /**
   * A failed assignment save rolls its already saved note back.
   */
  public function testFailedAssignmentSaveRollsBackNote(): void {
    [$application, $team] = $this->assignmentFixture();
    $failing = $this->createMock(ApplicationInterface::class);
    foreach (['id', 'getChangedTime', 'getRevisionId', 'hasField', 'get', 'validate'] as $method) {
      $failing->method($method)->willReturnCallback(fn(...$args) => $application->$method(...$args));
    }
    $failing->method('set')->willReturnCallback(function (...$args) use ($application, $failing) {
      $application->set(...$args);
      return $failing;
    });
    $failing->expects($this->once())->method('save')->willThrowException(new EntityStorageException('Assignment save failed.'));
    try {
      $this->assign($failing, ['team_uuid' => $team->uuid(), 'note' => 'Must roll back.']);
      $this->fail('The assignment save must fail.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('Assignment save failed.', $exception->getMessage());
    }
    $comments = $this->entityTypeManager()->getStorage('application_comment');
    $comments->resetCache();
    $this->assertSame([], $comments->loadByProperties(['field_application' => $application->id()]));
    $stored = $this->entityTypeManager()->getStorage('application')->loadUnchanged($application->id());
    $this->assertTrue($stored->get('field_assigned_team')->isEmpty());
  }

  /**
   * Creates a persisted, scoped case with its real workflow catalogue.
   *
   * @return array
   *   Application and eligible team.
   */
  private function assignmentFixture(): array {
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installEntitySchema('application_comment');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->installSchema('file', ['file_usage']);
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope', 'administer application entities']));
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Test jurisdiction', 'field_slug' => 'test-jur']);
    $team = $this->createGroup([
      'type' => 'org',
      'label' => 'Test team',
      'field_slug' => 'test-team',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Test form',
      'field_schema' => json_encode([
        'sections' => [[
          'id' => 'main',
          'title' => 'Main',
          'fields' => [[
            'id' => 'note',
            'type' => 'text',
            'label' => 'Note',
            'privacy' => ['kind' => 'other_personal'],
          ],
          ],
        ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'draft',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $team->id()],
      'field_group' => ['target_id' => $team->id()],
    ]);
    $form->save();
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'field_schema' => ['target_id' => $form->id()],
      'field_data' => json_encode(['note' => 'synthetic'], JSON_THROW_ON_ERROR),
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_organisation' => ['target_id' => $team->id()],
      'field_group' => ['target_id' => $team->id()],
      'status' => 1,
    ]);
    $application->save();
    return [$application, $team];
  }

  /**
   * Calls the public controller entry point with current concurrency values.
   */
  private function assign(ApplicationInterface $application, array $values): JsonResponse {
    return ApplicationAssignmentController::create($this->container)->assign($application, new Request(
      [], [], [], [], [], [], json_encode($values + [
        'expected_changed' => $application->getChangedTime(),
        'expected_revision_id' => $application->getRevisionId(),
      ], JSON_THROW_ON_ERROR),
    ));
  }

}
