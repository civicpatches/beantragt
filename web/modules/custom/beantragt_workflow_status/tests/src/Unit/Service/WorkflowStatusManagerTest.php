<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_workflow_status\Unit\Service;

use Drupal\beantragt_workflow_status\Service\WorkflowStatusManager;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests workflow status delete-guard queries.
 */
#[CoversClass(WorkflowStatusManager::class)]
#[Group('beantragt_workflow_status')]
final class WorkflowStatusManagerTest extends UnitTestCase {

  /**
   * Unconfirmed applications still block workflow status deletion.
   */
  public function testUnconfirmedApplicationReferenceBlocksStatusDeletion(): void {
    $conditions = [];
    $manager = new WorkflowStatusManager($this->entityTypeManager(
      $this->storage($this->query([12 => 12], $conditions)),
    ));

    $this->assertTrue($manager->isReferencedByApplications(42));
    $this->assertSame([
      ['field_workflow_status', 42, NULL],
    ], $conditions);
  }

  /**
   * Unconfirmed application revisions still block workflow status deletion.
   */
  public function testUnconfirmedApplicationRevisionReferenceBlocksStatusDeletion(): void {
    $conditions = [];
    $query = $this->query([9001 => 9001], $conditions);
    $query->expects($this->once())->method('allRevisions')->willReturnSelf();
    $manager = new WorkflowStatusManager($this->entityTypeManager($this->storage($query)));

    $this->assertTrue($manager->isReferencedByApplicationRevisions(42));
    $this->assertSame([
      ['field_workflow_status', 42, NULL],
    ], $conditions);
  }

  /**
   * Builds an entity type manager with application storage.
   */
  private function entityTypeManager(EntityStorageInterface $storage): EntityTypeManagerInterface {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->with('application')
      ->willReturn(TRUE);
    $entity_type_manager->method('getStorage')
      ->with('application')
      ->willReturn($storage);
    return $entity_type_manager;
  }

  /**
   * Builds application storage with a prepared entity query.
   */
  private function storage(QueryInterface $query): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    return $storage;
  }

  /**
   * Builds an entity query and records condition calls.
   *
   * @param array<int, int> $ids
   *   Query result IDs.
   * @param array<int, array{0:string,1:mixed,2:string|null}> $conditions
   *   Recorded condition calls.
   */
  private function query(array $ids, array &$conditions): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')
      ->willReturnCallback(static function (string $field, mixed $value = NULL, ?string $operator = NULL) use (&$conditions, $query): QueryInterface {
        $conditions[] = [$field, $value, $operator];
        return $query;
      });
    $query->method('range')->with(0, 1)->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

}
