<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\ReferenceNumberGenerator;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the ReferenceNumberGenerator service.
 *
 * @coversDefaultClass \Drupal\beantragt_application\Service\ReferenceNumberGenerator
 * @group beantragt_application
 */
class ReferenceNumberGeneratorTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection $database;

  /**
   * The mocked group storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityStorageInterface $groupStorage;

  /**
   * The service under test.
   *
   * @var \Drupal\beantragt_application\Service\ReferenceNumberGenerator
   */
  protected ReferenceNumberGenerator $generator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->database = $this->createMock(Connection::class);
    $this->groupStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager->method('getStorage')
      ->with('group')
      ->willReturn($this->groupStorage);

    $this->generator = new ReferenceNumberGenerator(
      $this->entityTypeManager,
      $this->database,
    );
  }

  /**
   * Configures the database mock to return a sequence number.
   *
   * The new implementation uses LAST_INSERT_ID() instead of a separate SELECT.
   * Two query() calls are made:
   * 1. INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID(sequence + 1).
   * 2. SELECT LAST_INSERT_ID() returns the sequence value.
   *
   * @param int $sequence
   *   The sequence number to return from LAST_INSERT_ID().
   */
  protected function mockDatabaseSequence(int $sequence): void {
    // First call: INSERT ... ON DUPLICATE KEY UPDATE.
    $insertStatement = $this->createMock(StatementInterface::class);

    // Second call: SELECT LAST_INSERT_ID() (returns the sequence value).
    $selectStatement = $this->createMock(StatementInterface::class);
    $selectStatement->method('fetchField')->willReturn((string) $sequence);

    $this->database->method('query')
      ->willReturnOnConsecutiveCalls($insertStatement, $selectStatement);
  }

  /**
   * Creates a mock group with reference fields.
   *
   * @param string|null $prefix
   *   The reference prefix value, or NULL for empty.
   * @param string|null $pattern
   *   The reference pattern value, or NULL for empty.
   *
   * @return \Drupal\group\Entity\GroupInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The mocked group entity.
   */
  protected function createMockGroup(?string $prefix = NULL, ?string $pattern = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);

    $group->method('hasField')->willReturnCallback(function (string $name): bool {
      if ($name === 'field_reference_prefix') {
        return TRUE;
      }
      if ($name === 'field_reference_pattern') {
        return TRUE;
      }
      return FALSE;
    });

    $group->method('get')->willReturnCallback(function (string $name) use ($prefix, $pattern): FieldItemListInterface {
      $field = $this->createMock(FieldItemListInterface::class);
      if ($name === 'field_reference_prefix') {
        $field->method('isEmpty')->willReturn($prefix === NULL);
        $field->value = $prefix;
        // Use __get for the value property.
        $field->method('__get')->with('value')->willReturn($prefix);
      }
      elseif ($name === 'field_reference_pattern') {
        $field->method('isEmpty')->willReturn($pattern === NULL);
        $field->value = $pattern;
        $field->method('__get')->with('value')->willReturn($pattern);
      }
      return $field;
    });

    return $group;
  }

  /**
   * Tests default format BEA-YYYY-00001 without org context.
   *
   * @covers ::generate
   */
  public function testGenerateDefaultWithoutOrg(): void {
    $this->mockDatabaseSequence(1);

    $result = $this->generator->generate();
    $year = date('Y');

    $this->assertEquals("BEA-{$year}-00001", $result);
  }

  /**
   * Tests format matches the default pattern without org.
   *
   * @covers ::generate
   */
  public function testGenerateMatchesDefaultFormat(): void {
    $this->mockDatabaseSequence(1);

    $result = $this->generator->generate();
    $this->assertMatchesRegularExpression('/^BEA-\d{4}-\d{5}$/', $result);
  }

  /**
   * Tests sequential numbering increments.
   *
   * @covers ::generate
   */
  public function testGenerateSequentialNumber(): void {
    $this->mockDatabaseSequence(16);

    $result = $this->generator->generate();
    $year = date('Y');

    $this->assertEquals("BEA-{$year}-00016", $result);
  }

  /**
   * Tests org-specific prefix is used.
   *
   * @covers ::generate
   */
  public function testGenerateWithOrgPrefix(): void {
    $group = $this->createMockGroup('FRE');
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $this->mockDatabaseSequence(1);

    $result = $this->generator->generate(5);
    $year = date('Y');

    $this->assertEquals("FRE-{$year}-00001", $result);
  }

  /**
   * Tests org-specific custom pattern.
   *
   * @covers ::generate
   */
  public function testGenerateWithCustomPattern(): void {
    $group = $this->createMockGroup('ST-HND', '{ORG}-{YEAR}-{SEQ:3}');
    $this->groupStorage->method('load')->with(7)->willReturn($group);

    $this->mockDatabaseSequence(42);

    $result = $this->generator->generate(7);
    $year = date('Y');

    $this->assertEquals("ST-HND-{$year}-042", $result);
  }

  /**
   * Tests pattern with FORM_CODE token.
   *
   * @covers ::generate
   */
  public function testGenerateWithFormCode(): void {
    $group = $this->createMockGroup('FRE', '{ORG}-{FORM_CODE}-{YEAR}-{SEQ:3}');
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $this->mockDatabaseSequence(1);

    $result = $this->generator->generate(5, 'HND');
    $year = date('Y');

    $this->assertEquals("FRE-HND-{$year}-001", $result);
  }

  /**
   * Tests pattern with UUID_SHORT token.
   *
   * @covers ::generate
   */
  public function testGenerateWithUuidShort(): void {
    $group = $this->createMockGroup('FRE', '{ORG}-{UUID_SHORT}');
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $result = $this->generator->generate(5, NULL, 'a1b2c3d4-e5f6-7890-abcd-ef1234567890');

    $this->assertEquals('FRE-a1b2c3d4', $result);
  }

  /**
   * Tests that empty tokens produce clean output without double dashes.
   *
   * @covers ::generate
   */
  public function testGenerateCleanupDoubleDashes(): void {
    $group = $this->createMockGroup('FRE', '{ORG}-{FORM_CODE}-{YEAR}-{SEQ:3}');
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $this->mockDatabaseSequence(1);

    // No form_code provided, should not produce "FRE--2026-001".
    $result = $this->generator->generate(5, NULL);
    $year = date('Y');

    $this->assertEquals("FRE-{$year}-001", $result);
  }

  /**
   * Tests fallback to default when org group not found.
   *
   * @covers ::generate
   */
  public function testGenerateFallbackWhenOrgNotFound(): void {
    $this->groupStorage->method('load')->with(999)->willReturn(NULL);

    $this->mockDatabaseSequence(1);

    $result = $this->generator->generate(999);
    $year = date('Y');

    $this->assertEquals("BEA-{$year}-00001", $result);
  }

  /**
   * Tests zero-padding with various sequence numbers.
   *
   * @covers ::generate
   * @dataProvider paddingProvider
   */
  public function testZeroPadding(int $sequence, string $expected_suffix): void {
    $this->mockDatabaseSequence($sequence);

    $result = $this->generator->generate();
    $this->assertStringEndsWith($expected_suffix, $result);
  }

  /**
   * Data provider for zero-padding tests.
   *
   * @return array
   *   Test data: [sequence_number, expected_suffix].
   */
  public static function paddingProvider(): array {
    return [
      'first ever' => [1, '00001'],
      'single digit' => [2, '00002'],
      'three digits' => [100, '00100'],
      'four digits' => [1000, '01000'],
      'five digits' => [10000, '10000'],
      'overflow six digits' => [100000, '100000'],
    ];
  }

  /**
   * Tests that a per-form pattern override wins over the org pattern.
   *
   * @covers ::generate
   */
  public function testFormPatternOverridesOrgPattern(): void {
    // Org has its own pattern, but the per-form override must win.
    $group = $this->createMockGroup('FRE', '{ORG}-{YEAR}-{SEQ:5}');
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $this->mockDatabaseSequence(1);

    $result = $this->generator->generate(5, NULL, NULL, '{ORG}-SPECIAL-{YEAR}-{SEQ:3}', 12);
    $year = date('Y');

    // The {ORG} prefix is still resolved from the organisation.
    $this->assertEquals("FRE-SPECIAL-{$year}-001", $result);
  }

  /**
   * Tests that the per-form override is keyed to the form scope in the upsert.
   *
   * Verifies the INSERT binds scope_id = form id (collision-safe per-form
   * sequence) while the SELECT returns the assigned value.
   *
   * @covers ::generate
   */
  public function testFormPatternUsesFormScopeId(): void {
    $group = $this->createMockGroup('FRE');
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $captured_args = NULL;
    $insertStatement = $this->createMock(StatementInterface::class);
    $selectStatement = $this->createMock(StatementInterface::class);
    $selectStatement->method('fetchField')->willReturn('1');

    $this->database->method('query')->willReturnCallback(
      function (string $query, array $args = []) use (&$captured_args, $insertStatement, $selectStatement) {
        if (str_contains($query, 'INSERT INTO')) {
          $captured_args = $args;
          return $insertStatement;
        }
        return $selectStatement;
      }
    );

    $this->generator->generate(5, NULL, NULL, '{ORG}-{YEAR}-{SEQ:3}', 42);

    $this->assertNotNull($captured_args);
    $this->assertSame(5, $captured_args[':org_id']);
    $this->assertSame(42, $captured_args[':scope_id']);
  }

  /**
   * Tests that without a per-form pattern the org sequence scope (0) is used.
   *
   * Collision-safety: two org-default forms must share scope_id = 0 so they do
   * not both emit "{ORG}-{YEAR}-00001".
   *
   * @covers ::generate
   */
  public function testNoFormPatternUsesOrgScopeZero(): void {
    $group = $this->createMockGroup('FRE');
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $captured_args = NULL;
    $insertStatement = $this->createMock(StatementInterface::class);
    $selectStatement = $this->createMock(StatementInterface::class);
    $selectStatement->method('fetchField')->willReturn('1');

    $this->database->method('query')->willReturnCallback(
      function (string $query, array $args = []) use (&$captured_args, $insertStatement, $selectStatement) {
        if (str_contains($query, 'INSERT INTO')) {
          $captured_args = $args;
          return $insertStatement;
        }
        return $selectStatement;
      }
    );

    // A form id is passed, but with no per-form pattern it must be ignored.
    $this->generator->generate(5, NULL, NULL, NULL, 42);

    $this->assertNotNull($captured_args);
    $this->assertSame(5, $captured_args[':org_id']);
    $this->assertSame(0, $captured_args[':scope_id']);
  }

  /**
   * Tests an empty/whitespace override falls back to the org pattern + scope 0.
   *
   * @covers ::generate
   */
  public function testEmptyOverrideFallsBackToOrgPattern(): void {
    $group = $this->createMockGroup('FRE', '{ORG}-{YEAR}-{SEQ:4}');
    $this->groupStorage->method('load')->with(5)->willReturn($group);

    $captured_args = NULL;
    $insertStatement = $this->createMock(StatementInterface::class);
    $selectStatement = $this->createMock(StatementInterface::class);
    $selectStatement->method('fetchField')->willReturn('7');

    $this->database->method('query')->willReturnCallback(
      function (string $query, array $args = []) use (&$captured_args, $insertStatement, $selectStatement) {
        if (str_contains($query, 'INSERT INTO')) {
          $captured_args = $args;
          return $insertStatement;
        }
        return $selectStatement;
      }
    );

    // Whitespace-only override must be treated as "no override".
    $result = $this->generator->generate(5, NULL, NULL, '   ', 42);
    $year = date('Y');

    $this->assertEquals("FRE-{$year}-0007", $result);
    $this->assertSame(0, $captured_args[':scope_id']);
  }

  /**
   * Tests the DEFAULT_PATTERN constant value.
   *
   * @covers ::DEFAULT_PATTERN
   */
  public function testDefaultPatternConstant(): void {
    $this->assertEquals('{ORG}-{YEAR}-{SEQ:5}', ReferenceNumberGenerator::DEFAULT_PATTERN);
  }

  /**
   * Tests the DEFAULT_PREFIX constant value.
   *
   * @covers ::DEFAULT_PREFIX
   */
  public function testDefaultPrefixConstant(): void {
    $this->assertEquals('BEA', ReferenceNumberGenerator::DEFAULT_PREFIX);
  }

}
