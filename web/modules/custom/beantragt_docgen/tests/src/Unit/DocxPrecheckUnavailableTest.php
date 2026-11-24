<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves fail-closed validation even when the test runtime has ext-zip.
 */
#[Group('beantragt_docgen')]
final class DocxPrecheckUnavailableTest extends UnitTestCase {

  /**
   * A ZIP signature alone never authorizes storage of an uploaded DOCX.
   */
  public function testMissingZipRejectsUpload(): void {
    $validator = new class() extends DocxPrecheck {

      /**
       * {@inheritdoc}
       */
      protected static function zipAvailable(): bool {
        return FALSE;
      }

    };
    try {
      $validator::assertDocx("PK\x03\x04" . str_repeat('x', 40));
      self::fail('Upload accepted without structural validation.');
    }
    catch (DocgenApiException $exception) {
      self::assertSame(503, $exception->status);
      self::assertSame('docgen.validation_unavailable', $exception->errorCode);
      self::assertSame('structural_validation_unavailable', $exception->details['reason']);
    }
  }

}
