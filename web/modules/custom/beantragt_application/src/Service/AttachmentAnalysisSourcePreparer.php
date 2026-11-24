<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Exception\AttachmentAnalysisSourceException;

/**
 * Chooses and prepares what a sovereign or local analysis run may see.
 *
 * PDFs leave as rendered pages, capped at PAGE_LIMIT. With when_possible
 * the guard creates a transient analysis_sovereign derivative. Citizen and
 * legacy runs require that derivative. Only a configured staff run may use
 * original images or the pages of an image-only scan, marked for review.
 * With redaction set to none, runs use the original image or rendered pages.
 *
 * External policies never come here: they keep the persisted full-profile
 * guard derivative of the dispatcher and never fall back to the original.
 */
final class AttachmentAnalysisSourcePreparer {

  /**
   * Pages per document that reach the model (product decision).
   */
  public const PAGE_LIMIT = 8;

  /**
   * Leading pages for one retry after a quantity-related classify failure.
   */
  public const FALLBACK_PAGE_LIMIT = 3;

  public const RENDERED_FROM_DERIVATIVE = 'derivative';
  public const RENDERED_FROM_ORIGINAL = 'original';

  public const REDACTION_REDACTED = 'redacted';
  public const REDACTION_UNREDACTED_NEEDS_REVIEW = 'unredacted_needs_review';
  public const REDACTION_NONE = 'none';

  public const REDACTION_NOT_POSSIBLE = 'redaction_not_possible';

  /**
   * Policies whose runs may see original bytes (sovereign or local service).
   */
  public const SOVEREIGN_POLICIES = ['sovereign_only', 'local_only'];

  private const MAX_DOCUMENT_BYTES = 10485760;

  /**
   * Guard reasons of a scan: the pages carry no text the guard could redact.
   *
   * A phone scan reports embedded_image_without_ocr next to
   * empty_text_extraction (measured with the guard on scanned PDFs).
   */
  private const SCAN_REASONS = [
    'empty_text_extraction',
    'embedded_image_without_ocr',
    'image_without_ocr',
  ];

  /**
   * Guard reasons of a transient outage: the run is retried, never sent.
   */
  private const OUTAGE_REASONS = [
    'guard_analyzer_unavailable',
    'guard_redactor_unavailable',
  ];

  /**
   * Render errors worth a queue retry; everything else is final.
   */
  private const RETRYABLE_RENDER_ERRORS = [
    'document_guard.unavailable',
    'document_guard.render_failed',
    'document_guard.invalid_envelope',
  ];

  public function __construct(
    private readonly ?DocumentGuardClientInterface $documentGuardClient = NULL,
  ) {}

  /**
   * Returns TRUE when the profile's runs go through this preparer.
   *
   * @param array<string, mixed> $profile
   *   Field analysis profile.
   */
  public static function handlesProfile(array $profile): bool {
    return in_array($profile['egressPolicy'] ?? NULL, self::SOVEREIGN_POLICIES, TRUE);
  }

  /**
   * Returns whether a citizen or legacy run needs a sovereign derivative.
   *
   * @param array<string, mixed> $profile
   *   Server-owned field analysis profile.
   * @param string $story
   *   Server-selected analysis story.
   */
  public static function requiresSovereignDerivative(array $profile, string $story): bool {
    return self::handlesProfile($profile)
      && ($profile['redaction'] ?? NULL) === 'when_possible'
      && $story !== AttachmentAnalysisMetadataWriter::STORY_STAFF;
  }

  /**
   * Prepares the source of a sovereign or local run from the original bytes.
   *
   * @param array<string, mixed> $profile
   *   Field analysis profile (sovereign_only or local_only).
   * @param string $original
   *   Original attachment bytes.
   * @param string $media_type
   *   Original media type.
   * @param string $story
   *   Server-selected analysis story. Only staff may use a scan fallback.
   *
   * @return array<string, mixed>
   *   Either a file source {kind: 'file', data, mediaType, redaction} or a
   *   page source {kind: 'pages', pages: list<{mediaType, data}>, pageCount,
   *   truncated, renderedFrom, redaction}. Page data are raw bytes.
   *
   * @throws \Drupal\beantragt_application\Exception\AttachmentAnalysisSourceException
   *   With a bounded reason and the retry policy for this failure.
   */
  public function prepareSovereign(array $profile, string $original, string $media_type, string $story): array {
    if (!self::handlesProfile($profile)) {
      throw new \InvalidArgumentException('Only sovereign or local analysis runs are prepared here.');
    }
    $redaction_wanted = ($profile['redaction'] ?? NULL) === 'when_possible';
    $derivative_required = self::requiresSovereignDerivative($profile, $story);

    if ($media_type !== 'application/pdf') {
      if ($derivative_required) {
        throw new AttachmentAnalysisSourceException(self::REDACTION_NOT_POSSIBLE, FALSE);
      }
      return [
        'kind' => 'file',
        'data' => $original,
        'mediaType' => $media_type,
        'redaction' => $redaction_wanted ? self::REDACTION_UNREDACTED_NEEDS_REVIEW : self::REDACTION_NONE,
      ];
    }

    if (!$redaction_wanted) {
      return $this->pages($original, self::RENDERED_FROM_ORIGINAL, self::REDACTION_NONE);
    }

    $guard = $this->guardClient();
    try {
      $result = $guard->createDerivative(
        $original,
        'application/pdf',
        DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN,
      );
    }
    catch (\Throwable $exception) {
      throw new AttachmentAnalysisSourceException('guard_unavailable', TRUE, $exception);
    }
    if ($result['entityProfile'] !== DocumentGuardClientInterface::ENTITY_PROFILE_ANALYSIS_SOVEREIGN) {
      throw new AttachmentAnalysisSourceException('guard_unavailable', FALSE);
    }

    $status = $result['status'];
    if ($status === 'rejected') {
      throw new AttachmentAnalysisSourceException('guard_blocked', FALSE);
    }
    if ($status === 'needs_review') {
      $this->assertScan($result['reportJson']);
      if ($derivative_required) {
        throw new AttachmentAnalysisSourceException(self::REDACTION_NOT_POSSIBLE, FALSE);
      }
      return $this->pages($original, self::RENDERED_FROM_ORIGINAL, self::REDACTION_UNREDACTED_NEEDS_REVIEW);
    }
    if (!in_array($status, ['clean', 'redacted'], TRUE)) {
      throw new AttachmentAnalysisSourceException('guard_unavailable', FALSE);
    }

    $derivative = $result['derivative'];
    if (!is_string($derivative)
      || $derivative === ''
      || strlen($derivative) > self::MAX_DOCUMENT_BYTES
      || !str_starts_with($derivative, '%PDF-')
      || $result['mediaType'] !== 'application/pdf') {
      throw new AttachmentAnalysisSourceException('guard_unavailable', FALSE);
    }
    return $this->pages($derivative, self::RENDERED_FROM_DERIVATIVE, self::REDACTION_REDACTED);
  }

  /**
   * Lets a needs_review verdict through only when the document is a scan.
   *
   * The original pages are the fallback of a document the guard cannot read,
   * never of one it read and refused to redact. The report decides: no text
   * layer at all and only scan reasons. An unreadable report fails closed.
   *
   * @param mixed $report_json
   *   The PII-free guard report as JSON.
   *
   * @throws \Drupal\beantragt_application\Exception\AttachmentAnalysisSourceException
   *   With guard_unavailable (retryable for an analyzer or redactor outage)
   *   or guard_blocked.
   */
  private function assertScan(mixed $report_json): void {
    $report = is_string($report_json) ? json_decode($report_json, TRUE) : NULL;
    $reasons = is_array($report) ? ($report['reasons'] ?? NULL) : NULL;
    $has_text_layer = is_array($report) && is_array($report['extraction'] ?? NULL)
      ? ($report['extraction']['hasTextLayer'] ?? NULL)
      : NULL;
    if (!is_array($reasons)
      || !array_is_list($reasons)
      || !is_bool($has_text_layer)
      || array_filter($reasons, 'is_string') !== $reasons) {
      throw new AttachmentAnalysisSourceException('guard_unavailable', FALSE);
    }
    if (array_intersect($reasons, self::OUTAGE_REASONS) !== []) {
      throw new AttachmentAnalysisSourceException('guard_unavailable', TRUE);
    }
    if ($has_text_layer
      || $reasons === []
      || array_diff($reasons, self::SCAN_REASONS) !== []) {
      throw new AttachmentAnalysisSourceException('guard_blocked', FALSE);
    }
  }

  /**
   * Renders the first pages of a PDF.
   *
   * @return array<string, mixed>
   *   Page source.
   */
  private function pages(string $document, string $rendered_from, string $redaction): array {
    try {
      $rendered = $this->guardClient()->renderPages($document, 'jpeg', self::PAGE_LIMIT);
    }
    catch (\Throwable $exception) {
      throw new AttachmentAnalysisSourceException(
        'page_rendering_unavailable',
        in_array($exception->getMessage(), self::RETRYABLE_RENDER_ERRORS, TRUE),
        $exception,
      );
    }

    $pages = [];
    foreach (array_slice($rendered['pages'], 0, self::PAGE_LIMIT) as $page) {
      $pages[] = [
        'mediaType' => $page['mediaType'],
        'data' => $page['data'],
      ];
    }
    if ($pages === []) {
      throw new AttachmentAnalysisSourceException('page_rendering_unavailable', FALSE);
    }
    $page_count = max((int) $rendered['pageCount'], count($pages));

    return [
      'kind' => 'pages',
      'pages' => $pages,
      'pageCount' => $page_count,
      'truncated' => (bool) $rendered['truncated'] || $page_count > count($pages),
      'renderedFrom' => $rendered_from,
      'redaction' => $redaction,
    ];
  }

  /**
   * Returns the configured guard client.
   */
  private function guardClient(): DocumentGuardClientInterface {
    if (!$this->documentGuardClient instanceof DocumentGuardClientInterface) {
      throw new AttachmentAnalysisSourceException('page_rendering_unavailable', FALSE);
    }
    return $this->documentGuardClient;
  }

}
