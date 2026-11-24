<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Service;

use Drupal\beantragt_application\Exception\AttachmentAnalysisSourceException;
use Drupal\beantragt_application\Service\AttachmentAnalysisSourcePreparer;
use Drupal\beantragt_application\Service\DocumentGuardClientInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the source choice of sovereign attachment analysis runs.
 *
 * @coversDefaultClass \Drupal\beantragt_application\Service\AttachmentAnalysisSourcePreparer
 */
final class AttachmentAnalysisSourcePreparerTest extends UnitTestCase {

  private const ORIGINAL = '%PDF-1.7 original payslip';
  private const DERIVATIVE = '%PDF-1.7 sovereign derivative';
  private const JPEG = "\xFF\xD8\xFF\xE0page";

  private const PROFILE = [
    'enabled' => TRUE,
    'egressPolicy' => 'sovereign_only',
    'modelClass' => 'openai_compatible',
  ];

  /**
   * Staff may review original scan pages with the unredacted marker.
   *
   * The reasons are the ones the guard reports for a scanned PDF.
   *
   * @covers ::prepareSovereign
   */
  public function testStaffScanSendsOriginalPagesWithReviewMarker(): void {
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())
      ->method('createDerivative')
      ->with(self::ORIGINAL, 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN)
      ->willReturn($this->guardResult('needs_review', NULL, ['embedded_image_without_ocr', 'empty_text_extraction'], FALSE));
    $guard->expects($this->once())
      ->method('renderPages')
      ->with(self::ORIGINAL)
      ->willReturn($this->rendered(1));

    $source = (new AttachmentAnalysisSourcePreparer($guard))
      ->prepareSovereign(self::PROFILE + ['redaction' => 'when_possible'], self::ORIGINAL, 'application/pdf', 'staff');

    $this->assertSame('pages', $source['kind']);
    $this->assertSame('original', $source['renderedFrom']);
    $this->assertSame('unredacted_needs_review', $source['redaction']);
    $this->assertSame([['mediaType' => 'image/jpeg', 'data' => self::JPEG]], $source['pages']);
    $this->assertArrayNotHasKey('data', $source);
  }

  /**
   * When_possible renders the transient sovereign derivative, not the file.
   *
   * @covers ::prepareSovereign
   */
  public function testSovereignWhenPossibleRendersTransientDerivative(): void {
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->once())
      ->method('createDerivative')
      ->with(self::ORIGINAL, 'application/pdf', DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN)
      ->willReturn($this->guardResult('redacted', self::DERIVATIVE));
    $guard->expects($this->once())
      ->method('renderPages')
      ->with(self::DERIVATIVE)
      ->willReturn($this->rendered(2));

    $source = (new AttachmentAnalysisSourcePreparer($guard))
      ->prepareSovereign(self::PROFILE + ['redaction' => 'when_possible'], self::ORIGINAL, 'application/pdf', 'citizen');

    $this->assertSame('derivative', $source['renderedFrom']);
    $this->assertSame('redacted', $source['redaction']);
    $this->assertCount(2, $source['pages']);
    $this->assertSame(2, $source['pageCount']);
    $this->assertFalse($source['truncated']);
  }

  /**
   * An analyzer or redactor outage is retried and never sends the original.
   *
   * @covers ::prepareSovereign
   */
  public function testSovereignGuardOutageIsRetriedWithoutFallback(): void {
    foreach (['guard_analyzer_unavailable', 'guard_redactor_unavailable'] as $reason) {
      $guard = $this->createMock(DocumentGuardClientInterface::class);
      $guard->method('createDerivative')->willReturn($this->guardResult('needs_review', NULL, [$reason], TRUE));
      $guard->expects($this->never())->method('renderPages');

      try {
        (new AttachmentAnalysisSourcePreparer($guard))
          ->prepareSovereign(self::PROFILE + ['redaction' => 'when_possible'], self::ORIGINAL, 'application/pdf', 'citizen');
        $this->fail('A guard outage must not fall back to the original pages.');
      }
      catch (AttachmentAnalysisSourceException $e) {
        $this->assertSame('guard_unavailable', $e->analysisCode);
        $this->assertTrue($e->retryable, $reason);
      }
    }
  }

  /**
   * A needs_review verdict on readable text sends nothing.
   *
   * Covers a refused redaction, a text PDF with a large image, a text page
   * next to a scanned one, an empty reason list and an unreadable report.
   *
   * @covers ::prepareSovereign
   */
  public function testSovereignNeedsReviewWithTextIsBlocked(): void {
    $cases = [
      'density' => [['redaction_density_exceeded'], TRUE],
      'location' => [['redaction_location_failed'], TRUE],
      'verification' => [['redaction_verification_failed'], TRUE],
      'text with large image' => [['embedded_image_without_ocr'], TRUE],
      'text page next to a scan' => [['embedded_image_without_ocr', 'empty_text_extraction'], TRUE],
      'little text' => [['insufficient_text_extraction'], TRUE],
      'scan plus density' => [['empty_text_extraction', 'redaction_density_exceeded'], FALSE],
      'no reasons' => [[], FALSE],
    ];
    foreach ($cases as $label => [$reasons, $has_text_layer]) {
      $guard = $this->createMock(DocumentGuardClientInterface::class);
      $guard->method('createDerivative')->willReturn($this->guardResult('needs_review', NULL, $reasons, $has_text_layer));
      $guard->expects($this->never())->method('renderPages');

      try {
        (new AttachmentAnalysisSourcePreparer($guard))
          ->prepareSovereign(self::PROFILE + ['redaction' => 'when_possible'], self::ORIGINAL, 'application/pdf', 'citizen');
        $this->fail($label . ' must not fall back to the original pages.');
      }
      catch (AttachmentAnalysisSourceException $e) {
        $this->assertSame('guard_blocked', $e->analysisCode, $label);
        $this->assertFalse($e->retryable, $label);
      }
    }

    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->method('createDerivative')->willReturn(['reportJson' => '{"status":"needs_review"}'] + $this->guardResult('needs_review', NULL));
    $guard->expects($this->never())->method('renderPages');
    try {
      (new AttachmentAnalysisSourcePreparer($guard))
        ->prepareSovereign(self::PROFILE + ['redaction' => 'when_possible'], self::ORIGINAL, 'application/pdf', 'citizen');
      $this->fail('An unreadable report must fail closed.');
    }
    catch (AttachmentAnalysisSourceException $e) {
      $this->assertSame('guard_unavailable', $e->analysisCode);
      $this->assertFalse($e->retryable);
    }
  }

  /**
   * A rejected document sends nothing.
   *
   * @covers ::prepareSovereign
   */
  public function testSovereignRejectedSendsNothing(): void {
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->method('createDerivative')->willReturn($this->guardResult('rejected', NULL));
    $guard->expects($this->never())->method('renderPages');

    try {
      (new AttachmentAnalysisSourcePreparer($guard))
        ->prepareSovereign(self::PROFILE + ['redaction' => 'when_possible'], self::ORIGINAL, 'application/pdf', 'citizen');
      $this->fail('A rejected document must not be prepared.');
    }
    catch (AttachmentAnalysisSourceException $e) {
      $this->assertSame('guard_blocked', $e->analysisCode);
      $this->assertFalse($e->retryable);
    }
  }

  /**
   * Without redaction the original pages go; only the requested leading pages.
   *
   * @covers ::prepareSovereign
   */
  public function testNoRedactionRendersOriginalAndCapsAtPageLimit(): void {
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->never())->method('createDerivative');
    $guard->method('renderPages')->willReturn($this->rendered(9, 10));

    $source = (new AttachmentAnalysisSourcePreparer($guard))
      ->prepareSovereign(self::PROFILE + ['redaction' => 'none'], self::ORIGINAL, 'application/pdf', 'citizen');

    $this->assertSame('original', $source['renderedFrom']);
    $this->assertSame('none', $source['redaction']);
    $this->assertCount(AttachmentAnalysisSourcePreparer::PAGE_LIMIT, $source['pages']);
    $this->assertSame(10, $source['pageCount']);
    $this->assertTrue($source['truncated']);
  }

  /**
   * Staff may receive the original image with the review marker.
   *
   * @covers ::prepareSovereign
   */
  public function testStaffImagePassesThroughWithReviewMarker(): void {
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->expects($this->never())->method('createDerivative');
    $guard->expects($this->never())->method('renderPages');
    $preparer = new AttachmentAnalysisSourcePreparer($guard);

    $source = $preparer->prepareSovereign(self::PROFILE + ['redaction' => 'when_possible'], self::JPEG, 'image/jpeg', 'staff');
    $this->assertSame([
      'kind' => 'file',
      'data' => self::JPEG,
      'mediaType' => 'image/jpeg',
      'redaction' => 'unredacted_needs_review',
    ], $source);

    $source = $preparer->prepareSovereign(self::PROFILE, self::JPEG, 'image/jpeg', 'citizen');
    $this->assertSame('none', $source['redaction']);
  }

  /**
   * An unavailable renderer never falls back to the raw PDF.
   *
   * @covers ::prepareSovereign
   */
  public function testRenderingUnavailableFailsWithoutFallback(): void {
    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->method('renderPages')->willThrowException(new \RuntimeException('document_guard.unavailable'));

    try {
      (new AttachmentAnalysisSourcePreparer($guard))->prepareSovereign(self::PROFILE, self::ORIGINAL, 'application/pdf', 'citizen');
      $this->fail('Rendering failures must not fall back to the raw PDF.');
    }
    catch (AttachmentAnalysisSourceException $e) {
      $this->assertSame('page_rendering_unavailable', $e->analysisCode);
      $this->assertTrue($e->retryable);
    }

    $guard = $this->createMock(DocumentGuardClientInterface::class);
    $guard->method('renderPages')->willThrowException(new \RuntimeException('document_guard.encrypted_pdf'));
    try {
      (new AttachmentAnalysisSourcePreparer($guard))->prepareSovereign(self::PROFILE, self::ORIGINAL, 'application/pdf', 'citizen');
      $this->fail('An encrypted PDF must fail.');
    }
    catch (AttachmentAnalysisSourceException $e) {
      $this->assertFalse($e->retryable);
    }
  }

  /**
   * External policies never use this preparer.
   *
   * @covers ::prepareSovereign
   */
  public function testExternalPolicyIsNotPreparedHere(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new AttachmentAnalysisSourcePreparer($this->createMock(DocumentGuardClientInterface::class)))
      ->prepareSovereign(['egressPolicy' => 'external_redacted'] + self::PROFILE, self::ORIGINAL, 'application/pdf', 'citizen');
  }

  /**
   * Builds a guard result with a report in the shape of the guard.
   *
   * @param string $status
   *   Guard status.
   * @param string|null $derivative
   *   Derivative bytes.
   * @param list<string> $reasons
   *   Report reasons.
   * @param bool $has_text_layer
   *   Whether the source had any extractable text.
   *
   * @return array{status: string, reportJson: string, derivative: string|null, mediaType: string|null, entityProfile: string}
   *   Guard result.
   */
  private function guardResult(string $status, ?string $derivative, array $reasons = [], bool $has_text_layer = TRUE): array {
    return [
      'status' => $status,
      'reportJson' => json_encode([
        'advisories' => [],
        'derivative' => NULL,
        'extraction' => [
          'engine' => 'pdfminer.six',
          'hasTextLayer' => $has_text_layer,
          'textChars' => $has_text_layer ? 1200 : 0,
        ],
        'findings' => [],
        'guardVersion' => '1',
        'reasons' => $reasons,
        'redactionDensity' => 0,
        'source' => ['mediaType' => 'application/pdf', 'pages' => 1, 'size' => 2048],
        'status' => $status,
      ], JSON_THROW_ON_ERROR),
      'derivative' => $derivative,
      'mediaType' => $derivative === NULL ? NULL : 'application/pdf',
      'entityProfile' => DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
    ];
  }

  /**
   * Builds a render result.
   *
   * @return array{pages: list<array{page: int, mediaType: string, data: string}>, pageCount: int, truncated: bool}
   *   Render result.
   */
  private function rendered(int $pages, ?int $page_count = NULL): array {
    $page_count ??= $pages;
    return [
      'pages' => array_map(
        static fn (int $page): array => ['page' => $page, 'mediaType' => 'image/jpeg', 'data' => self::JPEG],
        range(1, $pages),
      ),
      'pageCount' => $page_count,
      'truncated' => $page_count > $pages,
    ];
  }

}
