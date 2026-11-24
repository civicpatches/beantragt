<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_docgen\Service\VariableCatalog;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Placeholder catalog and the comparison with /inspect results.
 */
#[Group('beantragt_docgen')]
final class VariableCatalogTest extends UnitTestCase {

  /**
   * A small form with a protected field and a repeatable section.
   */
  private const SCHEMA = [
    'sections' => [
      [
        'id' => 'person',
        'title' => 'Person',
        'i18n' => ['en' => ['title' => 'Person EN']],
        'fields' => [
          ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'i18n' => ['en' => ['label' => 'Full name']]],
          ['id' => 'iban', 'type' => 'text', 'label' => 'IBAN', 'privacy' => ['kind' => 'financial']],
          ['id' => 'stellplatz', 'type' => 'checkbox', 'label' => 'Stellplatz'],
        ],
      ],
      [
        'id' => 'kinder',
        'title' => 'Kinder',
        'repeatable' => TRUE,
        'fields' => [
          ['id' => 'vorname', 'type' => 'text', 'label' => 'Vorname'],
        ],
      ],
    ],
  ];

  /**
   * System fields come first, form fields carry labels, types and flags.
   */
  public function testBuildListsSystemFormAndLoopTags(): void {
    $variables = VariableCatalog::build(self::SCHEMA, ['iban']);
    $tags = array_column($variables, 'tag');
    self::assertSame(['vorgang.aktenzeichen', 'vorgang.eingang', 'vorgang.status', 'vorgang.fehlende_nachweise'], array_slice($tags, 0, 4));
    self::assertSame(array_keys(VariableCatalog::SYSTEM), array_slice($tags, 0, count(VariableCatalog::SYSTEM)));
    self::assertSame(['antrag.full_name', 'antrag.iban', 'antrag.stellplatz', 'antrag.kinder'], array_slice($tags, count(VariableCatalog::SYSTEM)));
    $by_tag = array_column($variables, NULL, 'tag');
    self::assertSame(['de' => 'Name', 'en' => 'Full name'], $by_tag['antrag.full_name']['label']);
    self::assertSame('{antrag.full_name}', $by_tag['antrag.full_name']['syntax']);
    self::assertSame(['id' => 'person', 'title' => ['de' => 'Person', 'en' => 'Person EN']], $by_tag['antrag.full_name']['section']);
    self::assertTrue($by_tag['antrag.iban']['protected']);
    self::assertSame('financial', $by_tag['antrag.iban']['privacyKind']);
    self::assertFalse($by_tag['antrag.full_name']['protected']);
    self::assertTrue($by_tag['antrag.kinder']['repeatable']);
    self::assertSame('{#antrag.kinder}…{/antrag.kinder}', $by_tag['antrag.kinder']['syntax']);
    self::assertSame('{vorname}', $by_tag['antrag.kinder']['children'][0]['syntax']);
    self::assertSame('antrag.kinder.vorname', $by_tag['antrag.kinder']['children'][0]['tag']);
  }

  /**
   * Known, unknown with suggestion, protected and unused placeholders.
   */
  public function testCompareClassifiesPlaceholders(): void {
    $variables = VariableCatalog::build(self::SCHEMA, ['iban']);
    $result = VariableCatalog::compare([
      'placeholders' => [
        'vorgang.aktenzeichen',
        'antrag.ful_name',
        'antrag.iban',
        'antrag.kinder.vorname',
        'antrag.kinder.heute',
        'antrag.stellplatz.organisation.name',
        'aktenzeichen',
      ],
      'loops' => ['antrag.kinder', 'antrag.stellplatz'],
    ], $variables);
    self::assertSame([
      'antrag.iban',
      'antrag.kinder',
      'antrag.kinder.heute',
      'antrag.kinder.vorname',
      'antrag.stellplatz',
      'antrag.stellplatz.organisation.name',
      'vorgang.aktenzeichen',
    ], $result['known']);
    self::assertSame([
      ['tag' => 'antrag.ful_name', 'suggestion' => 'antrag.full_name'],
      ['tag' => 'aktenzeichen', 'suggestion' => 'vorgang.aktenzeichen'],
    ], $result['unknown']);
    self::assertSame(['antrag.iban'], $result['protected']);
    self::assertSame(['antrag.full_name'], $result['unused']);
  }

  /**
   * Far-off tags get no suggestion.
   */
  public function testSuggestionNeedsSimilarity(): void {
    self::assertNull(VariableCatalog::suggest('völlig.anders', ['vorgang.aktenzeichen', 'heute']));
    self::assertSame('heute', VariableCatalog::suggest('heut', ['vorgang.aktenzeichen', 'heute']));
  }

}
