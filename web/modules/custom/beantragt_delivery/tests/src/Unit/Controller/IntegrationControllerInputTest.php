<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Controller;

use Drupal\beantragt_delivery\Controller\IntegrationController;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\IntegrationRules;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Protects strict JSON decoding and numeric codelist object keys.
 */
#[Group('beantragt_delivery')]
final class IntegrationControllerInputTest extends UnitTestCase {

  /**
   * Numeric source codes retain their object shape through the HTTP boundary.
   */
  public function testNumericCodeMapKeepsJsonObjectShape(): void {
    $input = '{"data":{"rules":{"version":1,"rules":[{"source":"synthetic_code","target":"/code","valueMap":{"0":"001","1":"002"}}]}}}';
    $data = $this->decode($input);
    self::assertInstanceOf(\stdClass::class, $data['rules']['rules'][0]['valueMap']);
    self::assertStringContainsString('"valueMap":{"0":"001","1":"002"}', IntegrationRules::canonical($data));
    $this->expectException(IntegrationException::class);
    $this->expectExceptionMessage('integration.rules_invalid');
    $this->decode(str_replace('{"0":"001","1":"002"}', '["001","002"]', $input));
  }

  /**
   * Rejects malformed envelopes, caller-controlled scope and excess input.
   */
  public function testStrictEnvelopes(): void {
    foreach ([
      ['[]', 'integration.invalid_input'],
      ['{"data":[]}', 'integration.invalid_input'],
      ['{"data":{},"extra":true}', 'integration.invalid_input'],
      ['{"data":{"scope_group_id":42}}', 'integration.unknown_key'],
      ['{', 'integration.invalid_json'],
      ['{"data":{"compatible_revisions":{"0":1}}}', 'integration.input_invalid'],
      ['{"data":{"rules":{"version":1,"rules":{"0":{"source":"a","target":"/a"}}}}}', 'integration.rules_invalid'],
      ['{"data":{"rules":{"version":1,"rules":[],"occurrences":{}}}}', 'integration.rules_invalid'],
      [str_repeat(' ', 131073), 'integration.input_too_large'],
    ] as [$input, $code]) {
      try {
        $this->decode($input);
        self::fail('Unsafe envelope was accepted.');
      }
      catch (IntegrationException $exception) {
        self::assertSame($code, $exception->errorCode);
      }
    }
  }

  /**
   * A newline-terminated scope never becomes a valid integer tenant ID.
   */
  public function testScopeRejectsTrailingNewline(): void {
    $reflection = new \ReflectionClass(IntegrationController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $request = Request::create('/api/admin/integrations/connections');
    $request->query->set('scope', "42\n");
    $this->expectException(IntegrationException::class);
    $this->expectExceptionMessage('integration.scope_required');
    $reflection->getMethod('scope')->invoke($controller, $request);
  }

  /**
   * Exercises the actual boundary parser without resolving unrelated services.
   */
  private function decode(string $json): array {
    $reflection = new \ReflectionClass(IntegrationController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $request = Request::create('/api/admin/integrations/mappings', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
    return $reflection->getMethod('body')->invoke($controller, $request);
  }

}
