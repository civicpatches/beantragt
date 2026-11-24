<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_delivery\Service\DataContractValidator;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests draft 2020-12 validation using exclusively synthetic payloads.
 */
#[CoversClass(DataContractValidator::class)]
#[Group('beantragt_delivery')]
final class DataContractValidatorTest extends UnitTestCase {

  /**
   * Creates the offline validator.
   */
  private function validator(?string $root = NULL, ?array $manifest = NULL): DataContractValidator {
    $registry = new SyntheticDataContractRegistry(contractDirectory: $root ?? dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
    if ($manifest !== NULL) {
      $registry->pinFixture($manifest);
    }
    return new DataContractValidator($registry);
  }

  /**
   * Loads an explicitly fake, minimal complete FIM payload.
   */
  private function fixture(): array {
    $data = json_decode(file_get_contents(__DIR__ . '/DataContractSyntheticFixture.json'), TRUE, 128, JSON_THROW_ON_ERROR);
    $data['GSynthetic069']['GSynthetic084'] = new \stdClass();
    $data['GSynthetic069']['GSynthetic093'] = new \stdClass();
    return $data;
  }

  /**
   * The published draft 2020-12 schema accepts a complete synthetic fixture.
   */
  public function testSyntheticFixturePasses(): void {
    $this->assertSame([], $this->validator()->validate($this->fixture(), 'fim:S00000096', '1.0'));
  }

  /**
   * Required fields, primitive types, enum codes and arrays fail explicitly.
   */
  public function testInvalidPayloadsHaveSafeStructuredErrors(): void {
    $valid = $this->fixture();
    $missing = $valid;
    unset($missing['GSynthetic069']);
    $this->assertViolation($missing, '/GSynthetic069', 'required');
    $type = $valid;
    $type['GSynthetic069']['FSynthetic068'] = 123;
    $this->assertViolation($type, '/GSynthetic069/FSynthetic068', 'type');
    $array = $valid;
    $array['GSynthetic078'] = [];
    $this->assertViolation($array, '/GSynthetic078', 'minItems', 'min_items');
    $nested = $valid;
    $nested['GSynthetic078'][0] = new \stdClass();
    $this->assertViolation($nested, '/GSynthetic078/0/GSynthetic070', 'required');
    $enum = $valid;
    $enum['GSynthetic078'][0]['GSynthetic070']['FSynthetic003'] = 'INVALID SYNTHETIC SECRET';
    $this->assertViolation($enum, '/GSynthetic078/0/GSynthetic070/FSynthetic003', 'enum');
  }

  /**
   * Limits diagnostics even for many invalid repeated records.
   */
  public function testErrorsAreBounded(): void {
    $data = $this->fixture();
    $data['GSynthetic078'] = array_fill(0, 100, new \stdClass());
    $errors = $this->validator()->validate($data, 'fim:S00000096', '1.0');
    $this->assertCount(50, $errors);
  }

  /**
   * Checks exact diagnostics without reflecting personal or other field values.
   */
  private function assertViolation(array $data, string $pointer, string $keyword, ?string $code = NULL): void {
    $errors = $this->validator()->validate($data, 'fim:S00000096', '1.0');
    $this->assertContains([
      'pointer' => $pointer,
      'keyword' => $keyword,
      'code' => 'integration.validation.' . ($code ?? $keyword),
    ], $errors);
    $this->assertStringNotContainsString('INVALID SYNTHETIC SECRET', json_encode($errors));
    $this->assertLessThanOrEqual(50, count($errors));
    foreach ($errors as $error) {
      $this->assertSame(['pointer', 'keyword', 'code'], array_keys($error));
    }
  }

  /**
   * Unexpected failures have no misleading keyword and log only their class.
   */
  public function testInvalidPayloadEncodingHasDistinctSafeFailure(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')->with('Contract validation failed ({class}).', ['class' => \JsonException::class]);
    $validator = new DataContractValidator(new SyntheticDataContractRegistry(contractDirectory: dirname(__DIR__, 2) . '/fixtures/synthetic-contracts'), $logger);
    $this->assertSame([[
      'pointer' => '',
      'keyword' => NULL,
      'code' => 'integration.contract_validation_failed',
    ],
    ], $validator->validate(['synthetic' => "\xFF"], 'fim:S00000096', '1.0'));
  }

  /**
   * Remote and file references fail without invoking any stream wrapper.
   */
  public function testExternalReferencesAreNeverFetched(): void {
    $root = sys_get_temp_dir() . '/beantragt-contract-' . bin2hex(random_bytes(8));
    $dir = $root . '/fim/S00000096/1.0';
    mkdir($dir, 0700, TRUE);
    $source = dirname(__DIR__, 2) . '/fixtures/synthetic-contracts/fim/S00000096/1.0';
    $manifest = json_decode(file_get_contents($source . '/manifest.json'), TRUE, 32, JSON_THROW_ON_ERROR);
    $wrapper = new class() {
      /**
       * Number of attempted external stream opens.
       */
      public static int $calls = 0;

      /**
       * Stream context provided by PHP.
       */
      public mixed $context;

      /**
       * Rejects attempts to open HTTPS streams.
       */
      // phpcs:ignore Drupal.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- PHP stream wrapper API.
      public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        self::$calls++;
        throw new \RuntimeException('No external stream may be opened.');
      }

    };
    stream_wrapper_unregister('https');
    stream_wrapper_register('https', $wrapper::class);
    $attempted = [];
    set_error_handler(static function (int $severity, string $message) use (&$attempted): bool {
      $attempted[] = $message;
      return TRUE;
    });
    try {
      foreach (['https://127.0.0.1:1/never-fetch.json', 'file:///definitely-missing-never-read.json'] as $reference) {
        $schema = json_encode([
          '$schema' => 'https://json-schema.org/draft/2020-12/schema',
          '$id' => $manifest['sourceUri'],
          '$ref' => $reference,
        ], JSON_THROW_ON_ERROR);
        file_put_contents($dir . '/schema.json', $schema);
        $manifest['sha256'] = hash('sha256', $schema);
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->assertSame([[
          'pointer' => '',
          'keyword' => '$ref',
          'code' => 'integration.contract_reference_unresolved',
        ],
        ], $this->validator($root, $manifest)->validate(new \stdClass(), 'fim:S00000096', '1.0'));
      }
      $this->assertSame([], $attempted);
      $this->assertSame(0, $wrapper::$calls);
    }
    finally {
      restore_error_handler();
      stream_wrapper_restore('https');
      unlink($dir . '/schema.json');
      unlink($dir . '/manifest.json');
      rmdir($dir);
      rmdir(dirname($dir));
      rmdir(dirname($dir, 2));
      rmdir($root);
    }
  }

}
