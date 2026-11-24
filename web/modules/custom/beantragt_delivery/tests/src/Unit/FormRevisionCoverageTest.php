<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\FormRevisionCoverage;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Enforces one revision policy for planning, sending and API projections.
 */
#[CoversClass(FormRevisionCoverage::class)]
#[Group('beantragt_delivery')]
final class FormRevisionCoverageTest extends UnitTestCase {

  /**
   * Exact references and revisions with identical schema bytes are covered.
   *
   * Revision 4 is a workflow-only change: a higher form version, the same
   * fields. It produces the same transfer data and stays covered.
   */
  public function testExactAndLifecycleCoverage(): void {
    $forms = [
      1 => $this->form('synthetic-form', 2, '{"title":"SYNTHETIC"}'),
      2 => $this->form('synthetic-form', 2, '{"title":"SYNTHETIC"}'),
      3 => $this->form('synthetic-form', 2, '{"title":"CHANGED"}'),
      4 => $this->form('synthetic-form', 3, '{"title":"SYNTHETIC"}'),
      5 => $this->form('foreign-form', 2, '{"title":"SYNTHETIC"}'),
      6 => $this->form('synthetic-form', 0, '{"title":"SYNTHETIC"}'),
      7 => $this->form('synthetic-form', 2, '{ "title": "SYNTHETIC" }'),
    ];
    $coverage = $this->coverage($forms);
    self::assertTrue($coverage->covers(1, [1]));
    self::assertTrue($coverage->covers(2, [1]));
    self::assertTrue($coverage->covers(1, [2]));
    self::assertTrue($coverage->covers(4, [1]));
    self::assertTrue($coverage->covers(1, [4]));
    // A revision without a content version never covers or is covered.
    self::assertFalse($coverage->covers(1, [6]));
    foreach ([3, 5, 6, 7, 8] as $uncovered) {
      self::assertFalse($coverage->covers($uncovered, [1]));
    }
    self::assertFalse($coverage->covers(1, []));
    self::assertFalse($coverage->covers(1, [999]));
    self::assertTrue($coverage->covers(3, [3]));
  }

  /**
   * Legacy unpinned cases resolve only an unambiguous historical content set.
   */
  public function testLegacyResolutionAndExplicitPins(): void {
    $form = $this->form('synthetic-form', 9, '{"title":"CURRENT"}');
    $historical = $this->form('synthetic-form', 2, '{"title":"SYNTHETIC"}');
    $coverage = $this->coverage([1 => $historical, 2 => $historical], [2 => 10, 1 => 10]);
    self::assertSame(2, $coverage->resolve($this->application(NULL), $form));
    self::assertSame(1, $coverage->resolve($this->application(1), $form));
    self::assertTrue($coverage->covers($coverage->resolve($this->application(NULL), $form), [1]));

    $invalid = [
      [[], []],
      [[1 => $historical], [2 => 10]],
      [[1 => $historical, 2 => $this->form('synthetic-form', 2, '{"title":"CHANGED"}')], [2 => 10, 1 => 10]],
      [[1 => $this->form('foreign-form', 2, '{}')], [1 => 10]],
      [[1 => $this->form('synthetic-form', 3, '{}')], [1 => 10]],
      [[1 => $historical], array_fill_keys(range(1, 1001), 10)],
    ];
    foreach ($invalid as [$forms, $ids]) {
      try {
        $this->coverage($forms, $ids)->resolve($this->application(NULL), $form);
        self::fail('Ambiguous, foreign and unavailable historical revisions must fail closed.');
      }
      catch (IntegrationException $e) {
        self::assertSame('integration.revision_not_covered', $e->errorCode);
      }
    }
  }

  /**
   * Builds the shared service over isolated historical storage fixtures.
   */
  private function coverage(array $forms, array $revision_ids = []): FormRevisionCoverage {
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'allRevisions', 'condition', 'sort', 'range'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn($revision_ids);
    $storage = $this->createMock(ContentEntityStorageInterface::class);
    $storage->method('loadRevision')->willReturnCallback(static fn(int $id): ?FormSchemaInterface => $forms[$id] ?? NULL);
    $storage->method('getQuery')->willReturn($query);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('form_schema')->willReturn($storage);
    return new FormRevisionCoverage($entities);
  }

  /**
   * Builds a historical form with exact stored schema bytes.
   */
  private function form(string $uuid, int $version, string $json): FormSchemaInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('value')->willReturn($json);
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('id')->willReturn('10');
    $form->method('uuid')->willReturn($uuid);
    $form->method('getVersion')->willReturn($version);
    $form->method('get')->with('field_schema')->willReturn($field);
    return $form;
  }

  /**
   * Builds an application with a current exact pin or a legacy NULL pin.
   */
  private function application(?int $revision): ApplicationInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($revision === NULL);
    $field->method('__get')->with('value')->willReturn($revision);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasField')->with('field_schema_revision_id')->willReturn(TRUE);
    $application->method('get')->with('field_schema_revision_id')->willReturn($field);
    $application->method('getSchemaVersion')->willReturn(2);
    return $application;
  }

}
