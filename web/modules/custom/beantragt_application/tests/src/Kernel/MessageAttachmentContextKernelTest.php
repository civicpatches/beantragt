<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Controller\ApplicationAttachmentApiController;
use Drupal\beantragt_comment\Entity\ApplicationComment;
use Drupal\file\Entity\File;
use Drupal\user\Entity\Role;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests message file context against real entity storage.
 */
#[CoversClass(ApplicationAttachmentApiController::class)]
#[Group('beantragt_application')]
final class MessageAttachmentContextKernelTest extends GroupKernelTestBase {

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
   * Only files belonging to sent messages expose message context.
   */
  public function testOnlySentAttachmentsHaveContext(): void {
    $this->installEntitySchema('application');
    $this->installEntitySchema('application_comment');
    $this->installEntitySchema('application_attachment');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
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
    if (!Role::load('case_worker')) {
      Role::create(['id' => 'case_worker', 'label' => 'Case worker'])->save();
    }
    $staff = $this->createUser([]);
    $staff->addRole('case_worker')->save();
    $this->setCurrentUser($staff);
    $manager = $this->container->get('entity_type.manager');
    $file = File::create(['uri' => 'private://message.pdf', 'filename' => 'message.pdf']);
    $file->save();
    $attachment = $manager->getStorage('application_attachment')->create([
      'field_application' => 1,
      'field_field_id' => 'comment_attachment',
      'field_file' => $file->id(),
    ]);
    $attachment->save();
    $application = $manager->getStorage('application')->load(1);
    $controller = ApplicationAttachmentApiController::create($this->container);
    $method = new \ReflectionMethod($controller, 'attachmentMessages');
    $this->assertSame([], $method->invoke($controller, $application, [$attachment]));
    $comment = ApplicationComment::create([
      'field_application' => 1,
      'field_body' => 'Test message',
      'field_author_role' => 'staff',
      'field_visibility' => 'internal',
      'field_attachments' => [['target_id' => $file->id()]],
      'status' => 1,
    ]);
    $comment->save();
    $messages = $method->invoke($controller, $application, [$attachment]);
    $this->assertSame((string) $comment->id(), $messages[$file->id()]['id']);
    $this->assertSame('internal', $messages[$file->id()]['visibility']);
    $this->assertArrayNotHasKey('body', $messages[$file->id()]);
    $this->setCurrentUser($this->createUser([]));
    $this->assertSame([], $method->invoke($controller, $application, [$attachment]));
    $this->setCurrentUser($staff);
    $comment->setUnpublished()->save();
    $this->assertSame([], $method->invoke($controller, $application, [$attachment]));
    $comment->delete();
    $this->assertSame([], $method->invoke($controller, $application, [$attachment]));
  }

}
