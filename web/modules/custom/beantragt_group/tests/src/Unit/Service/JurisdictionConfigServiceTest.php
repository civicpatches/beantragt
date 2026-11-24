<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\beantragt_group\Service\JurisdictionConfigService;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the field_jur_config read-modify-write service.
 */
#[CoversClass(JurisdictionConfigService::class)]
#[Group('beantragt_group')]
final class JurisdictionConfigServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  private JurisdictionConfigService $service;

  /**
   * The group storage mock backing loadUnchanged().
   */
  private EntityStorageInterface $groupStorage;

  /**
   * The jurisdiction config lock mock.
   */
  private LockBackendInterface $lock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('group')->willReturn($this->groupStorage);

    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->lock->method('acquire')->willReturn(TRUE);

    $this->service = new JurisdictionConfigService($entity_type_manager, $this->lock);
  }

  /**
   * GetSection() returns the stored top-level key.
   */
  public function testGetSectionReturnsStoredSection(): void {
    $group = $this->groupWithConfig('{"theme":{"primary":"blue"},"languages":{"default":"de"}}');

    $this->assertSame(['primary' => 'blue'], $this->service->getSection($group, 'theme'));
    $this->assertSame(['default' => 'de'], $this->service->getSection($group, 'languages'));
  }

  /**
   * GetSection() returns [] for missing keys and non-array values.
   */
  public function testGetSectionMissingOrScalarSectionReturnsEmptyArray(): void {
    $group = $this->groupWithConfig('{"general":"not-an-object"}');

    $this->assertSame([], $this->service->getSection($group, 'theme'));
    $this->assertSame([], $this->service->getSection($group, 'general'));
  }

  /**
   * GetSection() tolerates invalid and empty stored JSON.
   */
  public function testGetSectionInvalidJsonReturnsEmptyArray(): void {
    $group = $this->groupWithConfig('{invalid json!!');

    $this->assertSame([], $this->service->getSection($group, 'theme'));
  }

  /**
   * GetSection() tolerates an empty field.
   */
  public function testGetSectionEmptyFieldReturnsEmptyArray(): void {
    $group = $this->groupWithConfig(NULL);

    $this->assertSame([], $this->service->getSection($group, 'theme'));
  }

  /**
   * UpdateSection() replaces only the given key and preserves all others.
   */
  public function testUpdateSectionPreservesOtherKeys(): void {
    $stored = NULL;
    $group = $this->groupWithConfig(
      '{"theme":{"primary":"blue"},"languages":{"default":"de","available":["de"]},"custom":{"keep":"me"}}',
      $stored
    );
    $group->expects($this->once())->method('save');
    $this->groupStorage->method('loadUnchanged')->willReturn($group);

    $result = $this->service->updateSection($group, 'theme', ['primary' => 'red']);

    $this->assertSame(['primary' => 'red'], $result);
    $this->assertIsString($stored);
    $decoded = json_decode($stored, TRUE);
    $this->assertSame(['primary' => 'red'], $decoded['theme']);
    $this->assertSame(['default' => 'de', 'available' => ['de']], $decoded['languages']);
    $this->assertSame(['keep' => 'me'], $decoded['custom']);
  }

  /**
   * UpdateSection() encodes with unescaped unicode and slashes.
   */
  public function testUpdateSectionEncodesUnescapedUnicodeAndSlashes(): void {
    $stored = NULL;
    $group = $this->groupWithConfig(NULL, $stored);
    $group->expects($this->once())->method('save');
    $this->groupStorage->method('loadUnchanged')->willReturn($group);

    $this->service->updateSection($group, 'general', [
      'platformName' => 'Bürgerbüro',
      'website' => 'https://example.com/path',
    ]);

    $this->assertIsString($stored);
    $this->assertStringContainsString('Bürgerbüro', $stored);
    $this->assertStringContainsString('https://example.com/path', $stored);
    $this->assertStringNotContainsString('\\/', $stored);
    $this->assertStringNotContainsString('\\u', $stored);
  }

  /**
   * UpdateSection() starts from an empty config when stored JSON is invalid.
   */
  public function testUpdateSectionInvalidStoredJsonStartsEmpty(): void {
    $stored = NULL;
    $group = $this->groupWithConfig('not json at all', $stored);
    $group->expects($this->once())->method('save');
    $this->groupStorage->method('loadUnchanged')->willReturn($group);

    $this->service->updateSection($group, 'languages', ['default' => 'en', 'available' => ['en']]);

    $this->assertIsString($stored);
    $this->assertSame(
      ['languages' => ['default' => 'en', 'available' => ['en']]],
      json_decode($stored, TRUE)
    );
  }

  /**
   * Reads and writes go through the untranslated (default) translation.
   */
  public function testServiceReadsAndWritesUntranslated(): void {
    $stored = NULL;
    $default = $this->groupWithConfig('{"theme":{"primary":"blue"}}', $stored);

    $translation = $this->createMock(GroupInterface::class);
    $translation->method('getUntranslated')->willReturn($default);
    $translation->expects($this->never())->method('hasField');
    $translation->expects($this->never())->method('set');
    $translation->expects($this->never())->method('save');

    $this->assertSame(['primary' => 'blue'], $this->service->getSection($translation, 'theme'));

    $default->expects($this->once())->method('save');
    $this->groupStorage->method('loadUnchanged')->willReturn($default);
    $this->service->updateSection($translation, 'theme', ['primary' => 'rose']);
    $this->assertIsString($stored);
  }

  /**
   * UpdateSection() merges onto the freshest stored blob, not the stale copy.
   *
   * Simulates a concurrent PATCH: the caller's entity carries a stale blob,
   * while storage already holds a newer "languages" section written by
   * another request. The stale copy must not clobber that section, and the
   * save must go through the freshly loaded entity.
   */
  public function testUpdateSectionMergesOntoFreshBlob(): void {
    $stored = NULL;
    $stale = $this->groupWithConfig(
      '{"theme":{"primary":"blue"},"languages":{"default":"de","available":["de"]}}',
      $stored
    );
    $stale->method('id')->willReturn(3);
    $stale->expects($this->never())->method('set');
    $stale->expects($this->never())->method('save');

    $fresh_stored = NULL;
    $fresh = $this->groupWithConfig(
      '{"theme":{"primary":"blue"},"languages":{"default":"en","available":["de","en"]}}',
      $fresh_stored,
    );
    $fresh->expects($this->once())->method('save');

    $this->groupStorage->expects($this->once())
      ->method('loadUnchanged')
      ->with(3)
      ->willReturn($fresh);

    $this->service->updateSection($stale, 'theme', ['primary' => 'rose']);

    $this->assertNull($stored);
    $this->assertIsString($fresh_stored);
    $decoded = json_decode($fresh_stored, TRUE);
    $this->assertSame(['primary' => 'rose'], $decoded['theme']);
    // The concurrent languages update from storage survives.
    $this->assertSame(['default' => 'en', 'available' => ['de', 'en']], $decoded['languages']);
  }

  /**
   * MergeSection() preserves concurrent values in the same section.
   */
  public function testMergeSectionUsesFreshValuesFromSameSection(): void {
    $stored = NULL;
    $stale = $this->groupWithConfig(
      '{"assistant":{"tone":"friendly","verbosity":"concise"}}',
      $stored
    );
    $stale->method('id')->willReturn(3);
    $stale->expects($this->never())->method('set');
    $stale->expects($this->never())->method('save');

    $fresh_stored = NULL;
    $fresh = $this->groupWithConfig(
      '{"assistant":{"tone":"formal","verbosity":"concise"}}',
      $fresh_stored,
    );
    $fresh->expects($this->once())->method('save');
    $this->groupStorage->expects($this->once())
      ->method('loadUnchanged')
      ->with(3)
      ->willReturn($fresh);

    $result = $this->service->mergeSection(
      $stale,
      'assistant',
      ['verbosity' => 'balanced']
    );

    $this->assertSame(
      ['tone' => 'formal', 'verbosity' => 'balanced'],
      $result
    );
    $this->assertNull($stored);
    $this->assertIsString($fresh_stored);
    $this->assertSame($result, json_decode($fresh_stored, TRUE)['assistant']);
  }

  /**
   * SaveGroup() persists the freshly locked group.
   */
  public function testSaveGroupUsesFreshGroupUnderSharedLock(): void {
    $stale = $this->groupWithConfig('{"assistant":{"tone":"friendly"}}');
    $stale->method('id')->willReturn(3);
    $stale->expects($this->never())->method('save');

    $fresh = $this->groupWithConfig('{"assistant":{"tone":"formal"}}');
    $fresh->method('id')->willReturn(3);
    $fresh->expects($this->once())->method('save');
    $this->groupStorage->expects($this->once())
      ->method('loadUnchanged')
      ->with(3)
      ->willReturn($fresh);

    $this->service->withLockedGroup(
      $stale,
      function (GroupInterface $locked): void {
        $this->service->saveGroup($locked);
      },
    );
  }

  /**
   * SaveGroup() rejects stale callers outside the lock-first mutation path.
   */
  public function testSaveGroupRejectsUnlockedMutation(): void {
    $group = $this->groupWithConfig('{"assistant":{"tone":"friendly"}}');
    $group->method('id')->willReturn(3);
    $group->expects($this->never())->method('save');

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('saveGroup() must be called inside withLockedGroup().');
    $this->service->saveGroup($group);
  }

  /**
   * A contended config lock gets one bounded wait and retry.
   */
  public function testUpdateSectionWaitsAndRetriesContendedLock(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->with('beantragt_group:jurisdiction_config:3', 30.0)
      ->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $lock->expects($this->once())
      ->method('wait')
      ->with('beantragt_group:jurisdiction_config:3', 5);
    $lock->expects($this->once())
      ->method('release')
      ->with('beantragt_group:jurisdiction_config:3');

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('group')->willReturn($this->groupStorage);
    $service = new JurisdictionConfigService($entity_type_manager, $lock);

    $group = $this->groupWithConfig('{"assistant":{"tone":"friendly"}}');
    $group->method('id')->willReturn(3);
    $group->expects($this->once())->method('save');
    $this->groupStorage->method('loadUnchanged')->willReturn($group);

    $service->updateSection($group, 'assistant', ['tone' => 'formal']);
  }

  /**
   * UpdateSection() rejects a mutation when the entity cannot be reloaded.
   */
  public function testUpdateSectionRejectsMissingStoredEntity(): void {
    $group = $this->groupWithConfig('{"custom":{"keep":"me"}}');
    $group->expects($this->never())->method('save');

    $this->groupStorage->method('loadUnchanged')->willReturn(NULL);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The jurisdiction could not be reloaded for mutation.');
    $this->service->updateSection($group, 'theme', ['primary' => 'red']);
  }

  /**
   * Builds a group mock exposing field_jur_config with the given raw value.
   *
   * @param string|null $json
   *   The stored JSON, or NULL for an empty field.
   * @param string|null $stored
   *   By-reference capture of the value written via set().
   */
  private function groupWithConfig(?string $json, ?string &$stored = NULL) {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($json === NULL);
    $field->method('__get')->willReturnCallback(
      static fn(string $property) => $property === 'value' ? $json : NULL
    );

    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturn($group);
    $group->method('hasField')->with(JurisdictionConfigService::CONFIG_FIELD)->willReturn(TRUE);
    $group->method('get')->with(JurisdictionConfigService::CONFIG_FIELD)->willReturn($field);
    $group->method('set')->willReturnCallback(
      static function (string $field_name, $value) use (&$stored, $group) {
        $stored = $value;
        return $group;
      }
    );

    return $group;
  }

}
