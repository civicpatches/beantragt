<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\beantragt_group\Service\JurisdictionHierarchyResolver;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the JurisdictionHierarchyResolver service.
 *
 * @group beantragt_group
 * @coversDefaultClass \Drupal\beantragt_group\Service\JurisdictionHierarchyResolver
 */
final class JurisdictionHierarchyResolverTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $groupStorage;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The service under test.
   *
   * @var \Drupal\beantragt_group\Service\JurisdictionHierarchyResolver
   */
  protected JurisdictionHierarchyResolver $resolver;

  /**
   * Child group bundle overrides for loadMultiple() callbacks.
   *
   * @var array<int, string>
   */
  protected array $childBundleOverrides = [];

  /**
   * Child IDs that should not resolve in loadMultiple() callbacks.
   *
   * @var array<int, bool>
   */
  protected array $missingChildIds = [];

  /**
   * Configured jurisdiction group type for the service under test.
   */
  protected string $jurisdictionGroupType = 'jur';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->logger = $this->createMock(LoggerInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($this->groupStorage);
    $this->groupStorage->method('loadMultiple')
      ->willReturnCallback(function (array $ids): array {
        $groups = [];
        foreach ($ids as $id) {
          $id = (int) $id;
          if (isset($this->missingChildIds[$id])) {
            continue;
          }
          $groups[$id] = $this->createMockGroup(
            $id,
            $this->childBundleOverrides[$id] ?? $this->jurisdictionGroupType,
          );
        }
        return $groups;
      });

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('beantragt_group')
      ->willReturn($this->logger);

    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturnCallback(fn(): string => $this->jurisdictionGroupType);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('beantragt_group.settings')
      ->willReturn($config);

    $this->resolver = new JurisdictionHierarchyResolver(
      $this->entityTypeManager,
      $this->database,
      $loggerFactory,
      $configFactory,
    );
  }

  /**
   * Creates a mock jurisdiction group entity.
   *
   * @param int $id
   *   The group ID.
   * @param string $bundle
   *   The group type.
   * @param int|null $parentId
   *   The parent jurisdiction ID, or NULL if root.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockGroup(int $id, string $bundle = 'jur', ?int $parentId = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn((string) $id);
    $group->method('bundle')->willReturn($bundle);

    $parentField = $this->targetField($parentId);
    $group->method('hasField')
      ->willReturnCallback(static fn(string $name): bool => $name === 'field_parent_jurisdiction');
    $group->method('get')
      ->willReturnCallback(function (string $name) use ($parentField): mixed {
        if ($name === 'field_parent_jurisdiction') {
          return $parentField;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    return $group;
  }

  /**
   * Creates a target ID field item list stub.
   *
   * @param int|null $targetId
   *   The target ID, or NULL for an empty field.
   *
   * @return object
   *   The field item list stub.
   */
  protected function targetField(?int $targetId): object {
    return new class($targetId) implements \Iterator, \Countable {

      /**
       * The target entity ID.
       */
      public ?int $targetId;

      /**
       * Whether the iterator is still valid.
       */
      private bool $valid = TRUE;

      /**
       * Constructs the field item stub.
       */
      public function __construct(?int $targetId) {
        $this->targetId = $targetId;
      }

      /**
       * Provides Drupal field item property access.
       */
      public function __get(string $name): mixed {
        return $name === 'target_id' ? $this->targetId : NULL;
      }

      /**
       * Checks if the field is empty.
       */
      public function isEmpty(): bool {
        return $this->targetId === NULL;
      }

      /**
       * Returns the current element.
       */
      public function current(): mixed {
        return $this;
      }

      /**
       * Returns the key of the current element.
       */
      public function key(): int {
        return 0;
      }

      /**
       * Moves to the next element.
       */
      public function next(): void {
        $this->valid = FALSE;
      }

      /**
       * Rewinds the iterator.
       */
      public function rewind(): void {
        $this->valid = TRUE;
      }

      /**
       * Checks if the current position is valid.
       */
      public function valid(): bool {
        return $this->valid && $this->targetId !== NULL;
      }

      /**
       * Returns the number of elements.
       */
      public function count(): int {
        return $this->targetId === NULL ? 0 : 1;
      }

    };
  }

  /**
   * Sets up a mock DB query for group__field_parent_jurisdiction.
   *
   * @param array<int, int[]> $childrenByParent
   *   Child IDs keyed by parent jurisdiction ID.
   */
  protected function mockChildQuery(array $childrenByParent): void {
    $conditionParentId = NULL;

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')
      ->willReturnCallback(function (string $field, mixed $value) use ($select, &$conditionParentId): Select {
        if ($field === 'field_parent_jurisdiction_target_id') {
          $conditionParentId = (int) $value;
        }
        return $select;
      });
    $select->method('execute')
      ->willReturnCallback(function () use (&$conditionParentId, $childrenByParent): StatementInterface {
        $statement = $this->createMock(StatementInterface::class);
        $children = $childrenByParent[$conditionParentId] ?? [];
        $statement->method('fetchCol')->willReturn(array_map('strval', $children));
        return $statement;
      });

    $this->database->method('select')
      ->with('group__field_parent_jurisdiction', 'p')
      ->willReturn($select);
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testRootJurisdictionReturnsItself(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);

    $this->assertSame(10, $this->resolver->getRootJurisdictionId(10));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testChildResolvesToParent(): void {
    $child = $this->createMockGroup(20, 'jur', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $root],
      ]);

    $this->assertSame(10, $this->resolver->getRootJurisdictionId(20));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testConfiguredJurisdictionBundleResolvesToParent(): void {
    $this->jurisdictionGroupType = 'jurisdiction';
    $child = $this->createMockGroup(20, 'jurisdiction', 10);
    $root = $this->createMockGroup(10, 'jurisdiction');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $root],
      ]);

    $this->assertSame(10, $this->resolver->getRootJurisdictionId(20));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testDeepHierarchyResolvesToRoot(): void {
    $grandchild = $this->createMockGroup(30, 'jur', 20);
    $child = $this->createMockGroup(20, 'jur', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [30, $grandchild],
        [20, $child],
        [10, $root],
      ]);

    $this->assertSame(10, $this->resolver->getRootJurisdictionId(30));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testCircularReferenceDetection(): void {
    $groupA = $this->createMockGroup(1, 'jur', 2);
    $groupB = $this->createMockGroup(2, 'jur', 1);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [1, $groupA],
        [2, $groupB],
      ]);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Circular parent reference'),
        $this->anything()
      );

    $this->assertNull($this->resolver->getRootJurisdictionId(1));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testParentDepthGuardFailsClosed(): void {
    $map = [];
    for ($id = 1; $id <= 51; $id++) {
      $map[] = [$id, $this->createMockGroup($id, 'jur', $id + 1)];
    }

    $this->groupStorage->method('load')
      ->willReturnMap($map);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Maximum parent hierarchy depth exceeded'),
        $this->anything()
      );

    $this->assertNull($this->resolver->getRootJurisdictionId(1));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testNonExistentGroupFailsClosedWithoutWarning(): void {
    $this->groupStorage->method('load')
      ->with(999)
      ->willReturn(NULL);

    $this->logger->expects($this->never())
      ->method('warning');

    $this->assertNull($this->resolver->getRootJurisdictionId(999));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testNonJurGroupTypeFailsClosed(): void {
    $org = $this->createMockGroup(5, 'org');
    $this->groupStorage->method('load')
      ->with(5)
      ->willReturn($org);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Expected jurisdiction group'),
        $this->callback(fn(array $context): bool => $context['@id'] === 5 && $context['@bundle'] === 'org')
      );

    $this->assertNull($this->resolver->getRootJurisdictionId(5));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testParentOfWrongBundleFailsClosed(): void {
    $child = $this->createMockGroup(20, 'jur', 10);
    $orgParent = $this->createMockGroup(10, 'org');

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $orgParent],
      ]);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Expected jurisdiction group'),
        $this->callback(fn(array $context): bool => $context['@id'] === 10 && $context['@bundle'] === 'org')
      );

    $this->assertNull($this->resolver->getRootJurisdictionId(20));
  }

  /**
   * @covers ::getRootJurisdictionId
   */
  public function testMissingParentFailsClosedWithoutWarning(): void {
    $child = $this->createMockGroup(20, 'jur', 99);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [99, NULL],
      ]);

    $this->logger->expects($this->never())
      ->method('warning');

    $this->assertNull($this->resolver->getRootJurisdictionId(20));
  }

  /**
   * @covers ::isChildJurisdiction
   */
  public function testIsChildReturnsTrueForChild(): void {
    $child = $this->createMockGroup(20, 'jur', 10);
    $this->groupStorage->method('load')
      ->with(20)
      ->willReturn($child);

    $this->assertTrue($this->resolver->isChildJurisdiction(20));
  }

  /**
   * @covers ::isChildJurisdiction
   */
  public function testIsChildReturnsFalseForRoot(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);

    $this->assertFalse($this->resolver->isChildJurisdiction(10));
  }

  /**
   * @covers ::isChildJurisdiction
   */
  public function testIsChildReturnsFalseForNonExistent(): void {
    $this->groupStorage->method('load')
      ->with(999)
      ->willReturn(NULL);

    $this->logger->expects($this->never())
      ->method('warning');

    $this->assertFalse($this->resolver->isChildJurisdiction(999));
  }

  /**
   * @covers ::isChildJurisdiction
   */
  public function testIsChildReturnsFalseForNonJurGroup(): void {
    $org = $this->createMockGroup(5, 'org');
    $this->groupStorage->method('load')
      ->with(5)
      ->willReturn($org);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Expected jurisdiction group'),
        $this->callback(fn(array $context): bool => $context['@id'] === 5 && $context['@bundle'] === 'org')
      );

    $this->assertFalse($this->resolver->isChildJurisdiction(5));
  }

  /**
   * @covers ::getAllRootJurisdictionIds
   */
  public function testGetAllRootJurisdictionIdsUsesDistinctIds(): void {
    $subSelect = $this->createMock(Select::class);
    $subSelect->method('fields')->willReturnSelf();
    $subSelect->method('where')->willReturnSelf();
    $subSelect->method('condition')->willReturnSelf();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchCol')->willReturn(['1', '5']);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())->method('distinct')->willReturnSelf();
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('notExists')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')
      ->willReturnCallback(function (string $table, string $alias) use ($select, $subSelect): Select {
        return $table === 'groups_field_data' ? $select : $subSelect;
      });

    $this->assertSame([1, 5], $this->resolver->getAllRootJurisdictionIds());
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsLeafNode(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->mockChildQuery([
      10 => [],
    ]);

    $this->assertSame([10], $this->resolver->getDescendantIds(10));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsWithChildren(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->mockChildQuery([
      10 => [20, 30],
      20 => [],
      30 => [],
    ]);

    $result = $this->resolver->getDescendantIds(10);
    sort($result);
    $this->assertSame([10, 20, 30], $result);
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsAcceptsConfiguredJurisdictionChildren(): void {
    $this->jurisdictionGroupType = 'jurisdiction';
    $root = $this->createMockGroup(10, 'jurisdiction');
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->childBundleOverrides[20] = 'jurisdiction';
    $this->mockChildQuery([
      10 => [20],
      20 => [],
    ]);

    $this->assertSame([10, 20], $this->resolver->getDescendantIds(10));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsCircularChildDetection(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->mockChildQuery([
      10 => [20],
      20 => [10],
    ]);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Circular child reference'),
        $this->anything()
      );

    $result = $this->resolver->getDescendantIds(10);
    sort($result);
    $this->assertSame([10, 20], $result);
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsSkipsNonJurChildren(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->childBundleOverrides[20] = 'org';
    $this->mockChildQuery([
      10 => [20],
    ]);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Expected jurisdiction group'),
        $this->callback(fn(array $context): bool => $context['@operation'] === 'load child jurisdiction'
          && $context['@id'] === 20
          && $context['@bundle'] === 'org')
      );

    $this->assertSame([10], $this->resolver->getDescendantIds(10));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsSkipsMissingChildrenWithWarning(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->missingChildIds[20] = TRUE;
    $this->mockChildQuery([
      10 => [20],
    ]);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Jurisdiction hierarchy references missing child group'),
        $this->callback(fn(array $context): bool => $context['@id'] === 20)
      );

    $this->assertSame([10], $this->resolver->getDescendantIds(10));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsRejectsWrongRootBundle(): void {
    $org = $this->createMockGroup(10, 'org');
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($org);

    $this->logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Expected jurisdiction group'),
        $this->callback(fn(array $context): bool => $context['@operation'] === 'load descendant jurisdictions'
          && $context['@id'] === 10
          && $context['@bundle'] === 'org')
      );

    $this->database->expects($this->never())
      ->method('select');

    $this->assertSame([], $this->resolver->getDescendantIds(10));
  }

  /**
   * @covers ::getDescendantIds
   */
  public function testGetDescendantIdsDepthGuardStopsTraversal(): void {
    $root = $this->createMockGroup(1);
    $this->groupStorage->method('load')
      ->with(1)
      ->willReturn($root);

    $conditionParentId = NULL;
    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')
      ->willReturnCallback(function (string $field, mixed $value) use ($select, &$conditionParentId): Select {
        if ($field === 'field_parent_jurisdiction_target_id') {
          $conditionParentId = (int) $value;
        }
        return $select;
      });
    $select->method('execute')
      ->willReturnCallback(function () use (&$conditionParentId): StatementInterface {
        $statement = $this->createMock(StatementInterface::class);
        $children = $conditionParentId < 53 ? [(string) ($conditionParentId + 1)] : [];
        $statement->method('fetchCol')->willReturn($children);
        return $statement;
      });

    $this->database->method('select')
      ->with('group__field_parent_jurisdiction', 'p')
      ->willReturn($select);

    $this->logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Maximum child hierarchy depth exceeded'),
        $this->anything()
      );

    $this->assertSame(range(1, 51), $this->resolver->getDescendantIds(1));
  }

  /**
   * @covers ::getTermJurisdictionIds
   */
  public function testGetTermJurisdictionIdsFromChildRoot(): void {
    $child = $this->createMockGroup(20, 'jur', 10);
    $root = $this->createMockGroup(10);

    $this->groupStorage->method('load')
      ->willReturnMap([
        [20, $child],
        [10, $root],
      ]);
    $this->mockChildQuery([
      10 => [20],
      20 => [],
    ]);

    $this->assertSame([10, 20], $this->resolver->getTermJurisdictionIds(20));
  }

  /**
   * @covers ::getTermJurisdictionIds
   */
  public function testGetTermJurisdictionIdsFailsClosedForInvalidGroup(): void {
    $this->groupStorage->method('load')
      ->with(99)
      ->willReturn(NULL);

    $this->assertSame([], $this->resolver->getTermJurisdictionIds(99));
  }

  /**
   * @covers ::isSameOrDescendantById
   */
  public function testIsSameOrDescendantAcceptsSameIdWithoutTreeLookup(): void {
    $this->groupStorage->expects($this->never())
      ->method('load');

    $this->assertTrue($this->resolver->isSameOrDescendantById(10, 10));
  }

  /**
   * @covers ::isSameOrDescendantById
   */
  public function testIsSameOrDescendantRejectsInvalidIds(): void {
    $this->assertFalse($this->resolver->isSameOrDescendantById(0, 10));
    $this->assertFalse($this->resolver->isSameOrDescendantById(10, 0));
  }

  /**
   * @covers ::isSameOrDescendantById
   */
  public function testIsSameOrDescendantUsesDescendantTree(): void {
    $root = $this->createMockGroup(10);
    $this->groupStorage->method('load')
      ->with(10)
      ->willReturn($root);
    $this->mockChildQuery([
      10 => [20],
      20 => [],
    ]);

    $this->assertTrue($this->resolver->isSameOrDescendantById(20, 10));
    $this->assertFalse($this->resolver->isSameOrDescendantById(30, 10));
  }

}
