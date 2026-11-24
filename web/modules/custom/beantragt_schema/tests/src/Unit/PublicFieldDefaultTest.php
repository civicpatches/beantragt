<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Unit;

use Drupal\beantragt_schema\Service\PublicFieldDefault;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shared public-v1 default-value contract.
 */
#[CoversClass(PublicFieldDefault::class)]
#[Group('beantragt_schema')]
final class PublicFieldDefaultTest extends UnitTestCase {

  /**
   * Preserves only valid defaults, including their original scalar type.
   */
  #[DataProvider('defaultCases')]
  public function testProject(array $field, mixed $value, bool $valid): void {
    $this->assertSame($valid ? $value : NULL, PublicFieldDefault::project($field, $value));
  }

  /**
   * Loads the same contract cases as the citizen proxy tests.
   */
  public static function defaultCases(): iterable {
    $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/public-field-default-cases.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
      yield $case['name'] => [$case['field'], $case['value'], $case['valid']];
    }
  }

  /**
   * Bounds work for Unicode and reject non-finite or excessive values.
   */
  public function testLimits(): void {
    $this->assertNull(PublicFieldDefault::project(['type' => 'number'], INF));
    $this->assertNull(PublicFieldDefault::project(['type' => 'number'], NAN));
    $this->assertNull(PublicFieldDefault::project(['type' => 'number'], str_repeat(' ', 10000) . '1'));
    $this->assertNull(PublicFieldDefault::project(['type' => 'text'], str_repeat('a', 10001)));
    $this->assertSame(str_repeat('🌿', 10000), PublicFieldDefault::project(['type' => 'text'], str_repeat('🌿', 10000)));
  }

}
