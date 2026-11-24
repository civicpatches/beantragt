<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\DocgenCaseExtensionInterface;
use Drupal\beantragt_docgen\Service\DocgenCaseExtensions;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Keeps core placeholders owned and release observers independent.
 */
#[Group('beantragt_docgen')]
final class DocgenCaseExtensionsTest extends UnitTestCase {

  /**
   * Merges blockers, bounds provided values and isolates release observers.
   */
  public function testComposite(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $account = $this->createMock(AccountInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects(self::once())->method('error')->with(self::anything(), ['@class' => \RuntimeException::class]);
    $extensions = new DocgenCaseExtensions($logger);
    self::assertSame([], $extensions->systemValues($application, $account));
    self::assertSame([], $extensions->releaseBlockers([]));
    $first = $this->createMock(DocgenCaseExtensionInterface::class);
    $first->method('systemValues')->willReturn(['heute' => 'forged', 'vorgang.fehlende_nachweise' => 'Mietvertrag']);
    $first->method('releaseBlockers')->willReturn(['docgen.agents_stopped']);
    $first->method('released')->willThrowException(new \RuntimeException('private data'));
    $second = $this->createMock(DocgenCaseExtensionInterface::class);
    $second->method('systemValues')->willReturn(['vorgang.fehlende_nachweise' => 'later']);
    $second->method('releaseBlockers')->willReturn(['docgen.agents_stopped', 'docgen.preview_missing']);
    $second->expects(self::once())->method('released')->with($application, [['released_by' => 9]], $account);
    $extensions->addExtension($first);
    $extensions->addExtension($second);
    self::assertSame(['vorgang.fehlende_nachweise' => 'Mietvertrag'], $extensions->systemValues($application, $account));
    self::assertSame(['docgen.agents_stopped', 'docgen.preview_missing'], $extensions->releaseBlockers([]));
    $extensions->released($application, [['released_by' => 9]], $account);
  }

  /**
   * Invalid values are omitted without exposing them in logs or casting them.
   */
  public function testNonStringValuesAreDroppedWithClassOnlyWarning(): void {
    foreach ([NULL, FALSE, 12, 2.5, ['private'], new \stdClass()] as $value) {
      $extension = $this->createMock(DocgenCaseExtensionInterface::class);
      $extension->method('systemValues')->willReturn(['vorgang.fehlende_nachweise' => $value]);
      $logger = $this->createMock(LoggerInterface::class);
      $logger->expects(self::once())->method('warning')->with(self::anything(), ['@class' => $extension::class]);
      $collector = new DocgenCaseExtensions($logger);
      $collector->addExtension($extension);
      $valid = $this->createMock(DocgenCaseExtensionInterface::class);
      $valid->method('systemValues')->willReturn(['vorgang.fehlende_nachweise' => 'Mietvertrag']);
      $collector->addExtension($valid);
      self::assertSame(['vorgang.fehlende_nachweise' => 'Mietvertrag'], $collector->systemValues(
        $this->createMock(ApplicationInterface::class), $this->createMock(AccountInterface::class),
      ));
    }
  }

}
