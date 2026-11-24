<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\ApplicationDataEditValidator;
use Drupal\beantragt_application\Service\FormValueParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every PHP condition evaluator answers as the citizen form renderer.
 *
 * The table tests/fixtures/condition-cases.json of this module is generated
 * from isConditionMet() of frontend/app/utils/form-conditional.ts: every row
 * is that function's answer for the condition and data[fieldId]. The
 * frontend runs the same file against the renderer function.
 */
#[CoversClass(FormValueParser::class)]
#[CoversClass(ApplicationDataEditValidator::class)]
#[Group('beantragt_application')]
final class ConditionCasesTest extends UnitTestCase {

  /**
   * The cases of the shared fixture.
   *
   * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}>
   *   Condition, data and the expected visibility.
   */
  public static function cases(): iterable {
    $cases = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/condition-cases.json'), TRUE, 16, JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
      yield $case['case'] => [$case['condition'], $case['data'], $case['met']];
    }
  }

  /**
   * The shared helper (citizen submission, batch intake) follows the table.
   *
   * @param array<string, mixed> $condition
   *   The condition.
   * @param array<string, mixed> $data
   *   The data.
   * @param bool $visible
   *   The expected visibility.
   */
  #[DataProvider('cases')]
  public function testSharedHelper(array $condition, array $data, bool $visible): void {
    $this->assertSame($visible, FormValueParser::conditionMatches($condition, $data));
  }

  /**
   * The staff data edit follows the same table.
   *
   * @param array<string, mixed> $condition
   *   The condition.
   * @param array<string, mixed> $data
   *   The data.
   * @param bool $visible
   *   The expected visibility.
   */
  #[DataProvider('cases')]
  public function testDataEditValidator(array $condition, array $data, bool $visible): void {
    $method = new \ReflectionMethod(ApplicationDataEditValidator::class, 'fieldIsVisible');
    $field = ['id' => 'target', 'conditionalOn' => $condition];
    $this->assertSame($visible, $method->invoke(new ApplicationDataEditValidator(), $field, $data));
  }

  /**
   * A condition without a usable fieldId restricts nothing.
   */
  public function testConditionWithoutFieldIdRestrictsNothing(): void {
    $this->assertTrue(FormValueParser::conditionMatches([], ['dep' => NULL]));
    $this->assertTrue(FormValueParser::conditionMatches(NULL, []));
    $this->assertTrue(FormValueParser::conditionMatches(['fieldId' => 7, 'value' => 'a'], []));
  }

  /**
   * A stored "" in a field with options is empty, not an invalid option.
   */
  public function testEmptyStringInFieldWithOptionsIsEmpty(): void {
    $method = new \ReflectionMethod(ApplicationDataEditValidator::class, 'validateFieldValue');
    $validator = new ApplicationDataEditValidator();
    foreach (['select', 'radio', 'checkbox'] as $type) {
      $method->invoke($validator, ['id' => 'choice', 'type' => $type, 'options' => ['a', 'b']], '');
    }
    $this->addToAssertionCount(3);
  }

}
