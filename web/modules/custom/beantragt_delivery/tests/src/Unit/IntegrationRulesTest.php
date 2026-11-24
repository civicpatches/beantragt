<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\IntegrationRules;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests static mapping safety independently of database storage.
 */
#[CoversClass(IntegrationRules::class)]
#[Group('beantragt_delivery')]
final class IntegrationRulesTest extends UnitTestCase {

  /**
   * Exercises required children, types, codelists and conditional sources.
   */
  public function testStaticCompletenessAndCompatibility(): void {
    $root = sys_get_temp_dir() . '/beantragt-rules-' . bin2hex(random_bytes(8));
    $directory = $root . '/fim/S00000096/1.0';
    mkdir($directory, 0700, TRUE);
    $schema = [
      '$schema' => 'https://json-schema.org/draft/2020-12/schema',
      '$id' => 'urn:synthetic:rules',
      'type' => 'object',
      'properties' => [
        'group' => [
          'type' => 'object',
          'required' => ['name', 'code'],
          'properties' => [
            'name' => ['type' => 'string'],
            'code' => ['type' => 'string', 'enum' => ['001', '002']],
          ],
        ],
      ],
    ];
    $bytes = json_encode($schema, JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/schema.json', $bytes);
    $manifest = [
      'key' => 'fim:S00000096',
      'version' => '1.0',
      'sourceUri' => 'urn:synthetic:rules',
      'sha256' => hash('sha256', $bytes),
    ];
    file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    $registry = new SyntheticDataContractRegistry(contractDirectory: $root);
    $registry->pinFixture($manifest);
    $rules = new IntegrationRules($registry);
    try {
      $form = [
        'sections' => [[
          'fields' => [
        ['id' => 'fake_name', 'type' => 'text', 'required' => TRUE],
        ['id' => 'fake_code', 'type' => 'select', 'required' => TRUE, 'options' => ['0', '1']],
          ],
        ],
        ],
      ];
      $definition = [
        'version' => 1,
        'rules' => [
        ['source' => 'fake_name', 'target' => '/group/name'],
        ['source' => 'fake_code', 'target' => '/group/code', 'valueMap' => (object) ['0' => '001', '1' => '002']],
        ],
      ];
      self::assertSame([], $rules->check($definition, $form, 'fim:S00000096', '1.0'));
      $incomplete = $definition;
      array_pop($incomplete['rules']);
      self::assertContains('integration.required_target_missing', array_column($rules->check($incomplete, $form, 'fim:S00000096', '1.0'), 'code'));
      $changed = $form;
      $changed['sections'][0]['fields'][0]['type'] = 'checkbox';
      self::assertContains('integration.type_incompatible', array_column($rules->check($definition, $changed, 'fim:S00000096', '1.0'), 'code'));
      self::assertSame('integration.source_changed', $rules->compatibility($definition, $form, $changed)[0]['code']);
      $changed = $form;
      $changed['sections'][0]['fields'][0]['conditionalOn'] = ['field' => 'fake_code', 'value' => '0'];
      self::assertContains('integration.required_source_unguaranteed', array_column($rules->check($definition, $changed, 'fim:S00000096', '1.0'), 'code'));
      self::assertSame('integration.source_changed', $rules->compatibility($definition, $form, $changed)[0]['code']);
      foreach (['semantics' => ['concept' => 'synthetic.changed'], 'crm_key' => 'synthetic.changed'] as $property => $value) {
        $changed = $form;
        $changed['sections'][0]['fields'][0][$property] = $value;
        self::assertSame('integration.source_changed', $rules->compatibility($definition, $form, $changed)[0]['code']);
      }
      $invalid = $definition;
      $invalid['rules'][1]['valueMap'] = (object) ['0' => '001'];
      self::assertContains('integration.value_map_incomplete', array_column($rules->check($invalid, $form, 'fim:S00000096', '1.0'), 'code'));
      $invalid['rules'][1]['valueMap'] = (object) ['0' => '001', '1' => 'invalid'];
      self::assertContains('integration.value_map_invalid', array_column($rules->check($invalid, $form, 'fim:S00000096', '1.0'), 'code'));
      $invalid['rules'][1]['constant'] = 'Invented';
      $this->expectException(IntegrationException::class);
      $this->expectExceptionMessage('integration.rules_invalid');
      $rules->validate($invalid);
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
