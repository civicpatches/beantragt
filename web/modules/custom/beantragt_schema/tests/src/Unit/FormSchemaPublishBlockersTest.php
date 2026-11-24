<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Unit;

use Drupal\beantragt_schema\Entity\FormSchema;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the publish blocker collector behind the publish gate.
 */
#[CoversClass(FormSchema::class)]
#[Group('beantragt_schema')]
final class FormSchemaPublishBlockersTest extends UnitTestCase {

  /**
   * Each gate condition yields its code, and the gate throws its message.
   *
   * @param array<string, mixed> $field
   *   The single field of the schema.
   * @param list<string> $codes
   *   Expected blocker codes in order.
   * @param string $message
   *   Message of the throwing gate, unchanged from before the collector.
   */
  #[DataProvider('gateConditions')]
  public function testEachGateConditionHasItsCodeAndMessage(array $field, array $codes, string $message): void {
    $schema = $this->schema([['id' => 'main', 'title' => 'Main', 'fields' => [$field]]]);

    $blockers = FormSchema::collectPublishBlockers($schema);

    self::assertSame($codes, array_column($blockers, 'code'));
    foreach ($blockers as $blocker) {
      self::assertSame('main', $blocker['sectionId']);
      self::assertSame($field['id'], $blocker['fieldId']);
    }

    try {
      FormSchema::validatePublishableFormSchemaDefinition($schema);
      self::fail('The publish gate accepted a blocked schema.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertSame($message, $exception->getMessage());
    }
  }

  /**
   * The four conditions of the publish gate.
   *
   * @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: string}>
   *   Field, expected codes, expected gate message.
   */
  public static function gateConditions(): array {
    return [
      'high-risk class without sensitive flag' => [
        ['id' => 'notiz', 'type' => 'text', 'label' => 'Notiz', 'privacy' => ['kind' => 'special_category']],
        ['sensitive_required'],
        'Field "Notiz" must be marked sensitive before publishing.',
      ],
      'high-risk pattern without sensitive flag' => [
        [
          'id' => 'krankheit',
          'type' => 'text',
          'label' => 'Krankheit des Hundes',
          'privacy' => ['kind' => 'other_personal'],
        ],
        ['sensitive_required', 'high_risk_kind_required'],
        'Field "Krankheit des Hundes" must be marked sensitive before publishing.',
      ],
      'high-risk pattern without high-risk class' => [
        [
          'id' => 'diagnose',
          'type' => 'text',
          'label' => 'Diagnose',
          'sensitive' => TRUE,
          'privacy' => ['kind' => 'other_personal'],
        ],
        ['high_risk_kind_required'],
        'Field "Diagnose" must have a high-risk privacy.kind classification before publishing.',
      ],
      'personal field without class' => [
        ['id' => 'vorname', 'type' => 'text', 'label' => 'Vorname'],
        ['privacy_kind_required'],
        'Field "Vorname" must have a privacy.kind classification before publishing.',
      ],
      'personal field classified not_personal' => [
        ['id' => 'email', 'type' => 'email', 'label' => 'E-Mail', 'privacy' => ['kind' => 'not_personal']],
        ['privacy_kind_required'],
        'Field "E-Mail" must have a privacy.kind classification before publishing.',
      ],
      'unclassified high-risk field fails three ways' => [
        ['id' => 'krankheit', 'type' => 'text', 'label' => 'Name der Krankheit'],
        ['sensitive_required', 'high_risk_kind_required', 'privacy_kind_required'],
        'Field "Name der Krankheit" must be marked sensitive before publishing.',
      ],
    ];
  }

  /**
   * Several blockers across sections come in schema order.
   */
  public function testSeveralBlockersComeInSchemaOrder(): void {
    $schema = $this->schema([
      [
        'id' => 'person',
        'title' => 'Person',
        'fields' => [
          ['id' => 'nachname', 'type' => 'text', 'label' => 'Nachname'],
          ['id' => 'hinweis', 'type' => 'text', 'label' => 'Hinweis', 'privacy' => ['kind' => 'not_personal']],
          ['id' => 'telefon', 'type' => 'tel', 'label' => 'Telefon'],
        ],
      ],
      ['id' => 'empty', 'title' => 'Leer', 'fields' => []],
      [
        'id' => 'dog',
        'title' => 'Hund',
        'fields' => [
          ['id' => 'attest', 'type' => 'file', 'label' => 'Attest', 'privacy' => ['kind' => 'special_category']],
          ['id' => 'rasse', 'type' => 'text', 'label' => 'Rasse', 'privacy' => ['kind' => 'not_personal']],
        ],
      ],
    ]);

    $blockers = FormSchema::collectPublishBlockers($schema);

    self::assertSame([
      ['person', 'nachname', 'privacy_kind_required'],
      ['person', 'telefon', 'privacy_kind_required'],
      ['dog', 'attest', 'sensitive_required'],
    ], array_map(
      static fn(array $blocker): array => [$blocker['sectionId'], $blocker['fieldId'], $blocker['code']],
      $blockers,
    ));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Field "Nachname" must have a privacy.kind classification before publishing.');
    FormSchema::validatePublishableFormSchemaDefinition($schema);
  }

  /**
   * A classified schema has no blockers and passes the gate.
   */
  public function testClassifiedSchemaHasNoBlockers(): void {
    $schema = $this->schema([
      [
        'id' => 'person',
        'title' => 'Person',
        'fields' => [
          ['id' => 'nachname', 'type' => 'text', 'label' => 'Nachname', 'privacy' => ['kind' => 'identity']],
          [
            'id' => 'krankheit',
            'type' => 'text',
            'label' => 'Krankheit',
            'sensitive' => TRUE,
            'privacy' => ['kind' => 'special_category'],
          ],
          ['id' => 'rasse', 'type' => 'text', 'label' => 'Rasse'],
        ],
      ],
    ]);

    self::assertSame([], FormSchema::collectPublishBlockers($schema));
    FormSchema::validatePublishableFormSchemaDefinition($schema);
  }

  /**
   * Malformed input yields no blockers, as the gate skips it.
   */
  public function testMalformedSchemaYieldsNoBlockers(): void {
    self::assertSame([], FormSchema::collectPublishBlockers('{"title":'));
    self::assertSame([], FormSchema::collectPublishBlockers('[]'));
    self::assertSame([], FormSchema::collectPublishBlockers('{"sections":"x"}'));
    self::assertSame([], FormSchema::collectPublishBlockers('{"sections":["x",{"fields":"y"},{"fields":["z"]}]}'));
  }

  /**
   * Encodes a schema with the given sections.
   *
   * @param list<array<string, mixed>> $sections
   *   Sections.
   */
  private function schema(array $sections): string {
    return json_encode(['title' => 'Hundesteuer', 'sections' => $sections], JSON_THROW_ON_ERROR);
  }

}
