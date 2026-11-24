<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\Core\Session\AccountInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guards the document trust boundary before file storage and portal release.
 */
#[Group('beantragt_delivery')]
final class ExternalResponseServiceTest extends UnitTestCase {

  /**
   * Matching raw mail grants no applicant identity before verification.
   */
  public function testApplicantIdentityRequiresVerifiedCurrentMail(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('getOwnerId')->willReturn(99);
    $reader = $this->createMock(AccountInterface::class);
    $reader->method('id')->willReturn(42);
    $mailbox = $this->createMock(ConfirmedApplicationMailbox::class);
    $mailbox->expects($this->exactly(2))->method('allowsRead')->with($application, $reader)->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $service = (new \ReflectionClass(ExternalResponseService::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(ExternalResponseService::class, 'confirmedMailbox'))->setValue($service, $mailbox);
    $method = new \ReflectionMethod(ExternalResponseService::class, 'readsAsApplicant');
    $this->assertFalse($method->invoke($service, $application, $reader));
    $this->assertTrue($method->invoke($service, $application, $reader));
    $owner = $this->createMock(AccountInterface::class);
    $owner->method('id')->willReturn(99);
    $this->assertTrue($method->invoke($service, $application, $owner));
  }

  /**
   * Tests that untrusted document payloads are rejected before storage.
   */
  #[DataProvider('invalidDocuments')]
  public function testRejectsUntrustedDocuments(array $documents): void {
    $service = (new \ReflectionClass(ExternalResponseService::class))->newInstanceWithoutConstructor();
    $this->expectException(\InvalidArgumentException::class);
    $service->validateDocuments($documents);
  }

  /**
   * Tests that a verified PDF document is accepted.
   */
  public function testAcceptsVerifiedPdf(): void {
    $service = (new \ReflectionClass(ExternalResponseService::class))->newInstanceWithoutConstructor();
    $service->validateDocuments([self::document()]);
    $this->addToAssertionCount(1);
  }

  /**
   * Provides document payloads that the service must reject.
   */
  public static function invalidDocuments(): array {
    $valid = self::document();
    return [
      'empty' => [[]],
      'too many' => [array_fill(0, 11, $valid)],
      'missing field' => [[['bytes' => '%PDF-1.7']]],
      'bad hash' => [[array_replace($valid, ['sha512' => str_repeat('f', 128)])]],
      'bad declared MIME' => [[array_replace($valid, ['mimeType' => 'text/html'])]],
      'path traversal' => [[array_replace($valid, ['filename' => '../bescheid.pdf'])]],
      'windows path' => [[array_replace($valid, ['filename' => '..\\bescheid.pdf'])]],
      'control char' => [[array_replace($valid, ['filename' => "bescheid\r.pdf"])]],
      'wrong extension' => [[array_replace($valid, ['filename' => 'bescheid.html'])]],
      'wrong bytes' => [[array_replace($valid, ['bytes' => '<html>bad</html>'])]],
      'oversized' => [[array_replace($valid, ['bytes' => str_repeat('x', ExternalResponseService::MAX_DOCUMENT_BYTES + 1)])]],
    ];
  }

  /**
   * Returns a valid PDF document payload.
   */
  private static function document(): array {
    $bytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
    return [
      'bytes' => $bytes,
      'filename' => 'bescheid.pdf',
      'mimeType' => 'application/pdf',
      'sha512' => hash('sha512', $bytes),
    ];
  }

}
