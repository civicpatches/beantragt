<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_comment\Kernel;

use Drupal\beantragt_comment\Entity\ApplicationComment;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the persisted body definition for attachment-only comments.
 */
#[CoversClass(ApplicationComment::class)]
#[Group('beantragt_comment')]
final class AttachmentOnlyCommentKernelTest extends GroupKernelTestBase {

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
   * An empty body survives SQL storage without a required-field violation.
   */
  public function testEmptyBodyCanBeStored(): void {
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
    $comment = ApplicationComment::create([
      'field_application' => 1,
      'field_body' => '',
      'field_author_role' => 'citizen',
      'uid' => 1,
    ]);
    $this->assertCount(0, $comment->get('field_body')->validate());
    $comment->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('application_comment');
    $storage->resetCache();
    $this->assertSame('', $storage->load($comment->id())->getBody() ?? '');
  }

}
