<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

/**
 * Creates privacy-preserving derivatives through the local document guard.
 */
interface DocumentGuardClientInterface {

  /**
   * Analyzer entity set that redacts every supported category.
   */
  public const ENTITY_PROFILE_FULL = 'full';

  /**
   * Transient sovereign analysis profile that keeps dates readable.
   *
   * A derivative created with this profile must never be persisted in
   * field_guard_* and must never reach an external provider.
   */
  public const ENTITY_PROFILE_ANALYSIS_SOVEREIGN = 'analysis_sovereign';

  /**
   * Guards source bytes and returns the validated response projection.
   *
   * @param string $document
   *   Source bytes.
   * @param string $media_type
   *   Source media type.
   * @param string $entity_profile
   *   One of the ENTITY_PROFILE_* constants; the full set by default.
   *
   * @return array{status: string, reportJson: string, derivative: string|null, mediaType: string|null, entityProfile: string}
   *   Validated guard status, unchanged report data, optional derivative and
   *   the entity profile it was produced with. The report itself carries no
   *   profile, so a caller that persists the result must check that the
   *   profile is ENTITY_PROFILE_FULL; a reduced derivative is never stored.
   */
  public function createDerivative(string $document, string $media_type, string $entity_profile = self::ENTITY_PROFILE_FULL): array;

  /**
   * Renders the first pages of a PDF as images through the isolated extractor.
   *
   * Nothing is persisted on either side. Rendering is limited server-side
   * (at most eight pages within the byte budget); a longer document returns
   * the first pages with truncated set to TRUE.
   *
   * @param string $document
   *   PDF bytes, at most 10 MiB.
   * @param string $format
   *   Either "jpeg" or "png".
   * @param int $max_pages
   *   Requested leading pages, from one to eight.
   *
   * @return array{pages: list<array{page: int, mediaType: string, data: string}>, pageCount: int, truncated: bool}
   *   Decoded and verified page images in page order.
   *
   * @throws \RuntimeException
   *   With a stable code: document_guard.source_invalid,
   *   document_guard.invalid_pdf, document_guard.encrypted_pdf,
   *   document_guard.too_large, document_guard.render_output_limit,
   *   document_guard.render_failed, document_guard.unavailable or
   *   document_guard.invalid_envelope.
   */
  public function renderPages(string $document, string $format = 'jpeg', int $max_pages = AttachmentAnalysisSourcePreparer::PAGE_LIMIT): array;

  /**
   * Reads the cover sheet codes of an envelope file, page by page.
   *
   * Only payloads of our own identifier pattern come back; nothing is kept.
   *
   * @param string $document
   *   PDF, PNG or JPEG bytes, at most 10 MiB.
   * @param string $media_type
   *   One of application/pdf, image/png or image/jpeg.
   *
   * @return array{pageCount: int, tooManyPages: bool, codes: list<array{page: int, payload: string}>}
   *   Page count, the 30-page flag and the codes per page.
   *
   * @throws \RuntimeException
   *   With a stable code: document_guard.source_invalid,
   *   document_guard.too_large, document_guard.invalid_format,
   *   document_guard.invalid_pdf, document_guard.encrypted_pdf,
   *   document_guard.invalid_image, document_guard.unavailable (also for
   *   503 and 504, retryable) or document_guard.invalid_envelope.
   */
  public function readCodes(string $document, string $media_type): array;

  /**
   * Cuts a PDF into parts by page ranges.
   *
   * @param string $document
   *   PDF bytes, at most 10 MiB.
   * @param list<array{0: int, 1: int}> $ranges
   *   Between 1 and 30 ranges [from, to], not overlapping, in part order.
   *
   * @return list<array{from: int, to: int, pageCount: int, sha256: string, data: string}>
   *   Decoded parts in request order, each checked against its SHA-256.
   *
   * @throws \RuntimeException
   *   With a stable code: document_guard.source_invalid,
   *   document_guard.too_large, document_guard.split_output_limit,
   *   document_guard.invalid_ranges, document_guard.invalid_pdf,
   *   document_guard.encrypted_pdf, document_guard.too_many_pages,
   *   document_guard.unavailable or document_guard.invalid_envelope.
   */
  public function splitPdf(string $document, array $ranges): array;

}
