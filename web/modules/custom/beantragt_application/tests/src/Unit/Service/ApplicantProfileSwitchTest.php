<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicantProfileSwitch;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the fail-closed reader of the applicant profile switch.
 */
#[CoversClass(ApplicantProfileSwitch::class)]
#[Group('beantragt_application')]
final class ApplicantProfileSwitchTest extends UnitTestCase {

  /**
   * Only a boolean TRUE in the applicant_profile section switches it on.
   */
  #[DataProvider('configProvider')]
  public function testIsEnabledReadsOnlyBooleanTrue(?string $config, bool $expected): void {
    $this->assertSame($expected, (new ApplicantProfileSwitch())->isEnabled($this->group('jur', $config)));
  }

  /**
   * Provides stored config blobs and the expected switch state.
   */
  public static function configProvider(): array {
    return [
      'enabled' => ['{"applicant_profile":{"enabled":true,"scope":"organisation"}}', TRUE],
      'disabled' => ['{"applicant_profile":{"enabled":false}}', FALSE],
      'string true' => ['{"applicant_profile":{"enabled":"true"}}', FALSE],
      'integer one' => ['{"applicant_profile":{"enabled":1}}', FALSE],
      'section missing' => ['{"general":{"name":"Musterstadt"}}', FALSE],
      'section not an object' => ['{"applicant_profile":true}', FALSE],
      'damaged json' => ['{"applicant_profile":', FALSE],
      'field empty' => [NULL, FALSE],
    ];
  }

  /**
   * The bank checkbox needs the profile switch and its own boolean TRUE.
   */
  #[DataProvider('bankConfigProvider')]
  public function testIsBankEnabledNeedsBothSwitches(?string $config, bool $expected): void {
    $this->assertSame($expected, (new ApplicantProfileSwitch())->isBankEnabled($this->group('jur', $config)));
  }

  /**
   * Provides stored config blobs and the expected bank switch state.
   */
  public static function bankConfigProvider(): array {
    return [
      'both on' => ['{"applicant_profile":{"enabled":true,"bankConsent":true}}', TRUE],
      'bank default off' => ['{"applicant_profile":{"enabled":true}}', FALSE],
      'bank off' => ['{"applicant_profile":{"enabled":true,"bankConsent":false}}', FALSE],
      'profile off' => ['{"applicant_profile":{"enabled":false,"bankConsent":true}}', FALSE],
      'bank string true' => ['{"applicant_profile":{"enabled":true,"bankConsent":"true"}}', FALSE],
      'field empty' => [NULL, FALSE],
    ];
  }

  /**
   * Organisations and missing groups never count as switched on.
   */
  public function testIsEnabledRejectsNonJurisdictionGroups(): void {
    $switch = new ApplicantProfileSwitch();
    $this->assertFalse($switch->isEnabled(NULL));
    $this->assertFalse($switch->isEnabled($this->group('org', '{"applicant_profile":{"enabled":true}}')));
  }

  /**
   * Applications resolve the jurisdiction directly or through their org.
   */
  public function testIsEnabledForApplicationResolvesJurisdiction(): void {
    $switch = new ApplicantProfileSwitch();
    $jurisdiction = $this->group('jur', '{"applicant_profile":{"enabled":true}}');

    $direct = $this->entityWithReferences(ApplicationInterface::class, ['field_jurisdiction' => $jurisdiction]);
    $this->assertTrue($switch->isEnabledForApplication($direct));

    $organisation = $this->entityWithReferences(GroupInterface::class, ['field_jurisdiction' => $jurisdiction]);
    $via_org = $this->entityWithReferences(ApplicationInterface::class, ['field_organisation' => $organisation]);
    $this->assertTrue($switch->isEnabledForApplication($via_org));

    $unscoped = $this->entityWithReferences(ApplicationInterface::class, []);
    $this->assertFalse($switch->isEnabledForApplication($unscoped));
  }

  /**
   * Builds a group mock with an optional field_jur_config value.
   */
  private function group(string $bundle, ?string $config): GroupInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($config === NULL);
    $field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'value' ? $config : NULL);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->willReturnCallback(static fn(string $name): bool => $name === 'field_jur_config');
    $group->method('get')->willReturn($field);
    return $group;
  }

  /**
   * Builds an entity mock whose reference fields point at the given groups.
   *
   * @param class-string $class
   *   Interface to mock.
   * @param array<string, \Drupal\group\Entity\GroupInterface> $references
   *   Referenced groups keyed by field name.
   */
  private function entityWithReferences(string $class, array $references): object {
    $entity = $this->createMock($class);
    $entity->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($references[$name]));
    $entity->method('get')->willReturnCallback(function (string $name) use ($references): FieldItemListInterface {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(!isset($references[$name]));
      $field->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'entity' ? ($references[$name] ?? NULL) : NULL);
      return $field;
    });
    return $entity;
  }

}
