<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\ClamAvAttachmentScanService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the ClamAV adapter's local fail-closed edges.
 */
final class ClamAvAttachmentScanServiceTest extends TestCase {

  /**
   * MIME-wrapped EICAR content stays quarantined before clamd is called.
   */
  public function testEicarPrecheckQuarantinesMimeWrappedPayload(): void {
    $scanner = new ClamAvAttachmentScanService('127.0.0.1', 1, 0.01);

    $result = $scanner->scan(
      "%PDF-1.4\n" . $this->eicarSignature(),
      'eicar.pdf',
      'application/pdf',
    );

    $this->assertSame(AttachmentScanResult::STATUS_QUARANTINED, $result->status());
    $this->assertSame('EICAR test signature detected.', $result->message());
  }

  /**
   * Strict ClamAV mode blocks when clamd is unavailable.
   */
  public function testUnavailableClamAvQuarantinesFailClosed(): void {
    $scanner = new ClamAvAttachmentScanService('127.0.0.1', 1, 0.01);

    $result = $scanner->scan('%PDF-1.4 clean', 'clean.pdf', 'application/pdf');

    $this->assertSame(AttachmentScanResult::STATUS_QUARANTINED, $result->status());
    $this->assertSame('Attachment scanner unavailable.', $result->message());
    $this->assertFalse($result->isVerifiedClean());
  }

  /**
   * Clamd OK responses map to clean.
   */
  public function testOkResponseMapsToClean(): void {
    $result = $this->resultFromResponse("stream: OK\0");

    $this->assertSame(AttachmentScanResult::STATUS_CLEAN, $result->status());
    $this->assertSame('', $result->message());
    $this->assertTrue($result->isVerifiedClean());
    $this->assertFalse((new AttachmentScanResult(AttachmentScanResult::STATUS_CLEAN))->isVerifiedClean());
  }

  /**
   * Clamd FOUND responses map to quarantined with the signature name.
   */
  public function testFoundResponseMapsToQuarantine(): void {
    $result = $this->resultFromResponse("stream: Eicar-Test-Signature FOUND\0");

    $this->assertSame(AttachmentScanResult::STATUS_QUARANTINED, $result->status());
    $this->assertSame('ClamAV detected malware: Eicar-Test-Signature.', $result->message());
  }

  /**
   * Unknown clamd responses fail closed.
   */
  public function testUnknownResponseMapsToUnavailableQuarantine(): void {
    $result = $this->resultFromResponse("stream: ERROR\0");

    $this->assertSame(AttachmentScanResult::STATUS_QUARANTINED, $result->status());
    $this->assertSame('Attachment scanner unavailable.', $result->message());
    $this->assertFalse($result->isVerifiedClean());
  }

  /**
   * Returns the standard EICAR test signature.
   */
  private function eicarSignature(): string {
    return 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
  }

  /**
   * Calls the private clamd response parser.
   */
  private function resultFromResponse(string $response): AttachmentScanResult {
    $method = new \ReflectionMethod(ClamAvAttachmentScanService::class, 'resultFromResponse');
    return $method->invoke(new ClamAvAttachmentScanService(), $response);
  }

}
