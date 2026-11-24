<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_delivery\Service\DataContractRegistry;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\IntegrationRules;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests explicit, value-free single occurrences and complete FIM definitions.
 */
#[CoversClass(IntegrationRules::class)]
#[Group('beantragt_delivery')]
final class IntegrationOccurrencesTest extends UnitTestCase {

  /**
   * Creates a registry that reads only the module or a private test directory.
   */
  private function registry(?string $root = NULL): SyntheticDataContractRegistry {
    return new SyntheticDataContractRegistry(contractDirectory: $root ?? dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
  }

  /**
   * The real contract is activatable with every field and occurrence explicit.
   */
  public function testCompleteFimMappingAndMissingOccurrence(): void {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/us116-complete-fim-mapping.json'), TRUE, 128, JSON_THROW_ON_ERROR);
    $rules = new IntegrationRules($this->registry());
    $report = $rules->report($fixture['rules'], $fixture['form'], 'fim:S00000096', '1.0');
    $this->assertTrue($report['valid'], json_encode($report));
    $this->assertSame([], $report['errors']);
    $this->assertGreaterThan(50, $report['coverage']['required_total']);
    $this->assertSame($report['coverage']['required_total'], $report['coverage']['required_covered']);
    $canonical = IntegrationRules::canonical($rules->normalize($fixture['rules']));
    $this->assertSame($canonical, IntegrationRules::canonical($rules->decode($canonical)));
    $missing = $fixture['rules'];
    $removed = array_pop($missing['occurrences']);
    $this->assertContains([
      'pointer' => $removed['target'],
      'code' => 'integration.repeating_source_unsupported',
    ], $rules->check($missing, $fixture['form'], 'fim:S00000096', '1.0'));
    unset($missing['occurrences']);
    $this->assertFalse($rules->report($missing, $fixture['form'], 'fim:S00000096', '1.0')['valid']);
    $missing = $fixture['rules'];
    array_shift($missing['rules']);
    $report = $rules->report($missing, $fixture['form'], 'fim:S00000096', '1.0');
    $this->assertFalse($report['valid']);
    $this->assertLessThan($report['coverage']['required_total'], $report['coverage']['required_covered']);
  }

  /**
   * Nested arrays each require a declaration, and no value is defaulted.
   */
  public function testOccurrenceBoundsAndShapes(): void {
    $schema = [
      'type' => 'object',
      'required' => ['rows'],
      'properties' => [
        'rows' => [
          'type' => 'array',
          'minItems' => 1,
          'items' => [
            'type' => 'object',
            'required' => ['nested'],
            'properties' => [
              'nested' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
            ],
          ],
        ],
      ],
    ];
    $definition = [
      'version' => 1,
      'rules' => [['source' => 'synthetic', 'target' => '/rows/*/nested/*']],
      'occurrences' => [
        ['target' => '/rows', 'mode' => 'single'],
        ['target' => '/rows/*/nested', 'mode' => 'single'],
      ],
    ];
    $form = ['sections' => [['fields' => [['id' => 'synthetic', 'type' => 'text', 'required' => TRUE]]]]];
    $this->withSchema($schema, function (IntegrationRules $rules) use ($definition, $form): void {
      $this->assertTrue($rules->report($definition, $form, 'fim:S00000096', '1.0')['valid']);
      $empty = $definition;
      $empty['rules'] = [];
      $this->assertContains([
        'pointer' => '/rows/*/nested/*',
        'code' => 'integration.required_target_missing',
      ], $rules->check($empty, $form, 'fim:S00000096', '1.0'));
      $missing = $definition;
      array_pop($missing['occurrences']);
      $this->assertContains([
        'pointer' => '/rows/*/nested',
        'code' => 'integration.repeating_source_unsupported',
      ], $rules->check($missing, $form, 'fim:S00000096', '1.0'));
      $invalid = $definition;
      $invalid['occurrences'][] = ['target' => '/rows/*/nested/*', 'mode' => 'single'];
      $this->assertContains('integration.occurrence_target_invalid', array_column($rules->check($invalid, $form, 'fim:S00000096', '1.0'), 'code'));
      $invalid['occurrences'][] = ['target' => '/rows', 'mode' => 'single'];
      $this->assertContains('integration.occurrence_duplicate', array_column($rules->check($invalid, $form, 'fim:S00000096', '1.0'), 'code'));
    });
    $schema['properties']['rows']['minItems'] = 2;
    $this->withSchema($schema, function (IntegrationRules $rules) use ($definition, $form): void {
      $this->assertContains([
        'pointer' => '/rows',
        'code' => 'integration.occurrence_min_items',
      ], $rules->check($definition, $form, 'fim:S00000096', '1.0'));
    });
    $schema['properties']['rows']['minItems'] = 0;
    $schema['properties']['rows']['maxItems'] = 0;
    $this->withSchema($schema, function (IntegrationRules $rules) use ($definition, $form): void {
      $this->assertContains('integration.occurrence_max_items', array_column($rules->check($definition, $form, 'fim:S00000096', '1.0'), 'code'));
    });
  }

  /**
   * Future root/unknown keywords and reference siblings fail closed.
   */
  public function testUnsupportedRootAndReferenceSiblings(): void {
    foreach (['allOf' => [], 'const' => 'synthetic', 'dependentRequired' => []] as $keyword => $value) {
      $schema = ['type' => 'object', $keyword => $value];
      $this->withSchema($schema, function (IntegrationRules $rules, DataContractRegistry $registry) use ($keyword): void {
        $paths = $registry->paths('fim:S00000096', '1.0');
        $this->assertSame('', $paths[0]['pointer']);
        $this->assertContains($keyword, $paths[0]['unsupported']);
        $definition = ['version' => 1, 'rules' => []];
        $this->assertContains([
          'pointer' => '',
          'code' => 'integration.target_unsupported',
        ], $rules->check($definition, [], 'fim:S00000096', '1.0'));
      });
    }
    $this->withSchema([
      'type' => 'object',
      '$ref' => '#/$defs/base',
      '$defs' => ['base' => ['type' => 'object', 'required' => ['original']]],
      'required' => ['replacement'],
    ], function (IntegrationRules $rules): void {
      $definition = ['version' => 1, 'rules' => []];
        $this->assertContains([
          'pointer' => '',
          'code' => 'integration.target_unsupported',
        ], $rules->check($definition, [], 'fim:S00000096', '1.0'));
    });
  }

  /**
   * Numeric codelists normalize consistently for non-HTTP callers.
   */
  public function testNumericMapNormalizationAndStrictListDecode(): void {
    $rules = new IntegrationRules($this->registry());
    $definition = [
      'version' => 1,
      'rules' => [[
        'source' => 'synthetic',
        'target' => '/code',
        'valueMap' => ['0' => '001', '1' => '002'],
      ],
      ],
    ];
    $normalized = $rules->normalize($definition);
    $rules->validate($definition);
    $this->assertInstanceOf(\stdClass::class, $normalized['rules'][0]['valueMap']);
    $json = IntegrationRules::canonical($normalized);
    $this->assertSame($json, IntegrationRules::canonical($rules->decode($json)));
    $this->expectException(IntegrationException::class);
    $rules->decode('{"version":1,"rules":{"0":{"source":"synthetic","target":"/code"}}}');
  }

  /**
   * Runs synthetic-schema checks through the existing fixed contract key.
   */
  private function withSchema(array $schema, callable $callback): void {
    $root = sys_get_temp_dir() . '/beantragt-occurrences-' . bin2hex(random_bytes(8));
    $directory = $root . '/fim/S00000096/1.0';
    mkdir($directory, 0700, TRUE);
    $schema += ['$schema' => 'https://json-schema.org/draft/2020-12/schema', '$id' => 'urn:synthetic:occurrences'];
    $bytes = json_encode($schema, JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/schema.json', $bytes);
    $manifest = [
      'key' => 'fim:S00000096',
      'version' => '1.0',
      'sourceUri' => $schema['$id'],
      'sha256' => hash('sha256', $bytes),
    ];
    file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    try {
      $registry = $this->registry($root);
      $registry->pinFixture($manifest);
      $callback(new IntegrationRules($registry), $registry);
    }
    finally {
      unlink($directory . '/schema.json');
      unlink($directory . '/manifest.json');
      rmdir($directory);
      rmdir(dirname($directory));
      rmdir(dirname($directory, 2));
      rmdir($root);
    }
  }

}
