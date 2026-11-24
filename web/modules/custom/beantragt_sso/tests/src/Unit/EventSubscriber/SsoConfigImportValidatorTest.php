<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Unit\EventSubscriber;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\StorageComparerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\beantragt_sso\EventSubscriber\SsoConfigImportValidator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests import-time SSO mailbox-provenance validation.
 *
 * @group beantragt_sso
 */
final class SsoConfigImportValidatorTest extends UnitTestCase {

  /**
   * Imports cannot re-enable address linking without verified claims.
   */
  public function testUnsafeEmailLinkingImportIsRejected(): void {
    $source = $this->createMock(StorageInterface::class);
    $source->method('read')->with('beantragt_sso.settings')->willReturn([
      'providers' => [
        'unsafe' => [
          'link_by_email' => TRUE,
          'email_verified' => FALSE,
        ],
      ],
    ]);
    $comparer = $this->createMock(StorageComparerInterface::class);
    $comparer->method('getSourceStorage')->willReturn($source);
    $importer = $this->getMockBuilder(ConfigImporter::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getStorageComparer', 'logError'])
      ->getMock();
    $importer->method('getStorageComparer')->willReturn($comparer);

    $importer->expects($this->once())->method('logError')->with(
      'SSO provider "unsafe" cannot link users by email without verified email claims.',
    );
    (new SsoConfigImportValidator())->validate(new ConfigImporterEvent($importer));
  }

}
