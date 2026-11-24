<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_delivery\Service\TransferFailures;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Makes omitted, overlapping or dynamically invented failure codes a gate.
 */
#[CoversClass(TransferFailures::class)]
#[Group('beantragt_delivery')]
final class TransferFailuresTest extends UnitTestCase {

  /**
   * Every emitted integration or FIT code belongs to exactly one class.
   */
  public function testClassificationIsDisjointAndExhaustive(): void {
    self::assertSame([], array_intersect(TransferFailures::PERMANENT, TransferFailures::RECOVERABLE));
    self::assertSame(array_unique(TransferFailures::PERMANENT), TransferFailures::PERMANENT);
    self::assertSame(array_unique(TransferFailures::RECOVERABLE), TransferFailures::RECOVERABLE);
    self::assertSame([], array_diff(TransferFailures::TRANSPORT, TransferFailures::RECOVERABLE));
    $module = dirname(__DIR__, 3);
    $files = [$module . '/beantragt_delivery.module', $module . '/beantragt_delivery.install'];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module . '/src'));
    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
      }
    }
    $unclassified = [];
    foreach ($files as $file) {
      $previous = [];
      foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
          continue;
        }
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
          $literal = substr($token[1], 1, -1);
          // Config get/set arguments are names, not emitted failure codes.
          $configuration = end($previous) === '('
            && in_array($previous[count($previous) - 2] ?? '', ['get', 'set', 'clear'], TRUE);
          if (!$configuration && preg_match('/^(integration|fit_connect)\.[a-z_]+(?:\.[a-z_]+)?$/D', $literal)) {
            $count = (int) in_array($literal, TransferFailures::PERMANENT, TRUE) + (int) in_array($literal, TransferFailures::RECOVERABLE, TRUE);
            if ($count !== 1) {
              $unclassified[$literal][] = basename($file) . ':' . $token[2];
            }
          }
          if (!$configuration && in_array($literal, ['integration.', 'fit_connect.', 'integration.validation.'], TRUE)) {
            $unclassified[$literal][] = basename($file) . ':' . $token[2] . ' dynamically constructs a failure code';
          }
        }
        $previous[] = is_array($token) ? $token[1] : $token;
        $previous = array_slice($previous, -2);
      }
    }
    self::assertSame([], $unclassified, json_encode($unclassified, JSON_PRETTY_PRINT));
    self::assertSame('recoverable', TransferFailures::classify('integration.four_eyes_required'));
    self::assertTrue(TransferFailures::recoverable('integration.four_eyes_required'));
    self::assertSame('unknown', TransferFailures::classify('future.unregistered'));
    self::assertFalse(TransferFailures::recoverable('future.unregistered'));
  }

  /**
   * Resource and validator fallbacks always remain within the closed taxonomy.
   */
  public function testClosedDynamicVocabularies(): void {
    foreach (['assignment', 'connection', 'mapping', 'version', 'mapping_version', 'unknown'] as $resource) {
      self::assertSame('permanent', TransferFailures::classify(TransferFailures::resourceNotFound($resource)));
    }
    self::assertSame('integration.resource_unknown', TransferFailures::resourceNotFound('unknown'));
    self::assertSame('integration.validation.invalid', TransferFailures::validationCode('unknownKeyword'));
    self::assertSame('integration.validation.required', TransferFailures::validationCode('required'));
    self::assertSame('integration.payload_invalid', TransferFailures::code(['lastError' => 'integration.payload_invalid: synthetic detail']));
  }

}
