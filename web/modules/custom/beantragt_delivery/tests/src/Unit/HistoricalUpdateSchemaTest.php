<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Keeps historical DDL reproducible when live repository schemas evolve.
 */
#[Group('beantragt_delivery')]
final class HistoricalUpdateSchemaTest extends UnitTestCase {

  /**
   * Historical update storage is immutable and independent of hook_schema.
   */
  public function testHistoricalDefinitionsRemainFrozen(): void {
    $module = dirname(__DIR__, 3);
    require_once $module . '/beantragt_delivery.install';
    $hashes = [
      10004 => 'c7990e4e58f9f7b7ba5f4418941a282f20dc02fa061d57d8b8acf35f9759c5fb',
      10005 => '6003b696fa461b64bb753a3bef069d66519d70a4dc097c83091683ec6f6e0e55',
      10006 => '6003b696fa461b64bb753a3bef069d66519d70a4dc097c83091683ec6f6e0e55',
      10007 => '051e8a6198bc8d4a19185f9346238fb67b14fe38ab960e4ae12bf16e448f4b3b',
      10008 => 'd18199a4a201f75c8d5357b20e30b30a6fae2b60a2fcf7c5352d756529f81fb4',
      10009 => '50c77473f07d5a839f918999cfc1c48cce305786d1b5b85fa3a7b5980e2180a4',
      10013 => '74a7141f1c8adc131aaf34c6bc0637a2798f63c805230ca896680aa800c81c82',
      10014 => 'a601ffa38865cab7cdd56b2b346ef98d2b480f4aa0e2d06ca569434aef54fe83',
      10015 => 'eff9b7b419ed75d337d770887d1aadc672b6142f0c1555e795b2afb8eeb38669',
      10016 => '6c87c960f0fba88ec6db31c944ccf27795211afd2ec255dd3edf192eda240b2d',
    ];
    $source = file($module . '/beantragt_delivery.install');
    foreach ($hashes as $update => $hash) {
      self::assertSame($hash, hash_file('sha256', $module . '/updates/schema-' . $update . '.json'), 'Add a new migration rather than modifying historical DDL.');
      self::assertNotEmpty(_beantragt_delivery_update_schema($update));
      $names = ['beantragt_delivery_update_' . $update];
      if ($update === 10006) {
        $names[] = '_beantragt_delivery_update_storage_10006';
      }
      foreach ($names as $name) {
        $function = new \ReflectionFunction($name);
        $body = implode('', array_slice($source, $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
        self::assertDoesNotMatchRegularExpression('/::(?:schema|bindingFields|operationalFields)\(|beantragt_delivery_schema\(|_beantragt_delivery_audit_schema\(/', $body);
      }
    }
    self::assertArrayNotHasKey('send_attachments', _beantragt_delivery_update_schema(10005)['beantragt_integration_assignment']['fields']);
    self::assertArrayHasKey('send_attachments', _beantragt_delivery_update_schema(10008));
    self::assertArrayNotHasKey('automatic_attempts', _beantragt_delivery_update_schema(10007)['binding_fields']);
    self::assertArrayHasKey('automatic_attempts', _beantragt_delivery_update_schema(10008)['operational_fields']);
    self::assertArrayHasKey('transfer_plan', _beantragt_delivery_update_schema(10009));
    self::assertSame('submission', _beantragt_delivery_update_schema(10013)['assignment_fields']['transfer_trigger']['default']);
    self::assertArrayHasKey('actor_uid', _beantragt_delivery_update_schema(10013)['transfer_plan_fields']);
    self::assertSame('decision', _beantragt_delivery_update_schema(10014)['external_response_fields']['kind']['default']);
    self::assertSame(64, _beantragt_delivery_update_schema(10015)['external_response_fields']['outcome_label']['length']);
    self::assertSame('', _beantragt_delivery_update_schema(10016)['external_response_fields']['delivery_route']['default']);
    self::assertSame(0, _beantragt_delivery_update_schema(10016)['external_response_fields']['is_decision']['default']);
  }

}
