<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;

/**
 * Projects the staff run for an already authorized and audited case view.
 */
final class StaffAttachmentAnalysis {

  // Preserve the existing staff comparison tolerance, in the field's unit.
  private const MATCH_TOLERANCE = 1;

  /**
   * Constructs the authorized projection.
   */
  public function __construct(
    private readonly ApplicationDataProtectionService $dataProtection,
    private readonly FormValueParser $parser,
  ) {}

  /**
   * Builds the staff-only status and configured comparisons for one attachment.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The viewed application.
   * @param \Drupal\beantragt_application\Entity\ApplicationAttachmentInterface $attachment
   *   The listed attachment, including the form configuration.
   * @param array<string, mixed> $metadata
   *   Sanitized metadata with authorized clear values.
   * @param array<string, array<string, mixed>> $fields
   *   The application's schema fields keyed by field id.
   * @param string $scan_status
   *   The file's scan status.
   * @param int $now
   *   The current request time.
   *
   * @return array<string, mixed>|null
   *   The staff projection, or NULL for an attachment without story mode.
   */
  public function view(ApplicationInterface $application, ApplicationAttachmentInterface $attachment, array $metadata, array $fields, string $scan_status, int $now): ?array {
    // Empty provenance covers citizen uploads predating the provenance field.
    $provenance = (string) ($attachment->get('field_provenance')->value ?? '');
    $citizen = AttachmentAnalysisMetadataWriter::storyRecord($metadata, AttachmentAnalysisMetadataWriter::STORY_CITIZEN);
    if (!in_array($provenance, ['', 'submission'], TRUE) || ($citizen['code'] ?? NULL) === 'bulk_classified') {
      return NULL;
    }
    $story = AttachmentAnalysisMetadataWriter::STORY_STAFF;
    $profile = AttachmentAnalysisMetadataWriter::fieldAnalysisProfile($attachment);
    $record = AttachmentAnalysisMetadataWriter::storyRecord($metadata, $story);
    if (!AttachmentAnalysisMetadataWriter::isStoryProfile($profile) && $record === []) {
      return NULL;
    }

    $definitions = AttachmentAnalysisMetadataWriter::staffFactDefinitions($profile ?? []);
    $configured = ($profile['enabled'] ?? FALSE) === TRUE && $definitions !== [];
    $supported = AttachmentAnalysisMetadataWriter::rerunStoryAllowed($profile, $story);
    $legacy = AttachmentAnalysisMetadataWriter::holdsLegacyRun($metadata);
    $gate = $legacy ? $metadata : $record;
    if ($legacy && ($metadata['status'] ?? NULL) === 'pending') {
      $gate['analysisStartedAt'] = max((int) ($gate['analysisStartedAt'] ?? 0), (int) $attachment->getChangedTime());
    }
    $lease = AttachmentAnalysisDispatcher::PENDING_DISPATCH_LEASE_SECONDS;
    $active = AttachmentAnalysisMetadataWriter::hasActiveLease($gate, $now, $lease);
    $blocked = ($gate['code'] ?? NULL) === 'guard_blocked';
    $status = $record['status'] ?? 'not_requested';
    $result = $record['result'] ?? [];
    $clean = $scan_status === AttachmentScanResult::STATUS_CLEAN;

    $view = [
      'state' => 'unavailable',
      'reason' => NULL,
      'canRun' => $supported && $clean && !$active && !$blocked,
      'pollUntil' => NULL,
      'checks' => ['party' => 'not_configured', 'validity' => 'not_configured'],
      'facts' => [],
      'comparisons' => [],
    ];
    if (!$configured) {
      $view['state'] = 'not_configured';
    }
    elseif (!$clean || !$supported || $blocked) {
      $view['reason'] = !$clean ? 'file_unavailable' : ($blocked ? 'source_unavailable' : 'configuration_unavailable');
    }
    elseif ($active) {
      $view['state'] = 'pending';
      $view['reason'] = $legacy ? 'previous_run' : (isset($record['worker']) ? 'processing' : 'queued');
      $view['pollUntil'] = $gate['analysisStartedAt'] + $lease;
    }
    elseif ($status === 'pending') {
      $view['reason'] = 'result_not_received';
    }
    elseif ($status === 'failed') {
      $view['reason'] = $this->failureReason($record['code'] ?? '');
    }
    elseif ($status !== 'complete') {
      $view['state'] = 'not_requested';
    }
    elseif (($record['extractedUnavailable'] ?? FALSE) === TRUE) {
      $view['reason'] = 'values_unavailable';
    }
    else {
      $this->addResult($view, $application, $definitions, $result, $fields);
    }
    return $view;
  }

  /**
   * Maps worker codes without exposing provider messages or document content.
   */
  private function failureReason(string $code): string {
    return match ($code) {
      'provider_not_configured', 'staff_story_not_allowed', 'local_worker_required', 'submission_worker_required' => 'configuration_unavailable',
      'guard_blocked', 'guard_retry_pending', 'guard_unavailable', 'unsupported_media_type', 'page_rendering_unavailable' => 'source_unavailable',
      default => 'run_failed',
    };
  }

  /**
   * Compares approved numbers using the same parser as online submission.
   */
  private function addResult(array &$view, ApplicationInterface $application, array $definitions, array $result, array $fields): void {
    $values = [];
    foreach ($result['extracted'] ?? [] as $item) {
      $key = $item['key'];
      $type = $definitions[$key]['type'] ?? NULL;
      $value = $item['value'];
      $valid = match ($type) {
        'boolean' => is_bool($value),
        'integer' => is_int($value),
        'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
        default => FALSE,
      };
      // Multiple readings of a single fact cannot justify a comparison.
      $values[$key] = $valid && !array_key_exists($key, $values) ? $value : NULL;
    }

    $data = AttachmentAnalysisMetadataWriter::decode($application->getData() ?? '');
    $view['state'] = 'complete';
    foreach ($definitions as $key => $definition) {
      $value = $values[$key] ?? NULL;
      $view['facts'][] = ['key' => $key, 'type' => $definition['type'], 'value' => $value];
      if ($value === NULL) {
        $view['state'] = 'needs_review';
        $view['reason'] ??= 'incomplete_evidence';
      }
      $field_id = $definition['compareWith'] ?? NULL;
      if (!is_string($field_id) || !in_array($definition['type'], ['number', 'integer'], TRUE)) {
        continue;
      }
      $field = $fields[$field_id] ?? [];
      $comparison = $this->compare(is_int($value) || is_float($value) ? $value : NULL, $field_id, $field, $data);
      $view['comparisons'][] = ['key' => $key, 'fieldId' => $field_id] + $comparison;
      if ($comparison['state'] !== 'match') {
        $view['state'] = 'needs_review';
        $view['reason'] = 'check_comparisons';
      }
    }
    // The extraction worker has no staff pass/fail verdict. Source limitations
    // are still relevant to the review.
    if (!empty($result['notices'])) {
      $view['state'] = 'needs_review';
      $view['reason'] ??= 'review_evidence';
    }
  }

  /**
   * Reads only the configured numeric answer, including encrypted answers.
   *
   * @return array{state: string, extracted: int|float|null, reported: int|float|null}
   *   A comparison with both numeric readings when available.
   */
  private function compare(int|float|null $extracted, string $id, array $field, array $data): array {
    $comparison = ['state' => 'rule_unavailable', 'extracted' => $extracted, 'reported' => NULL];
    if (!in_array($field['type'] ?? NULL, ['number', 'integer'], TRUE)) {
      return $comparison;
    }
    try {
      $answer = $this->dataProtection->revealData([$id => $data[$id] ?? NULL])[$id];
    }
    catch (\RuntimeException) {
      $comparison['state'] = 'answer_unavailable';
      return $comparison;
    }
    $parsed = $this->parser->parse($field, $answer);
    $reported = $parsed['issue'] === NULL ? $parsed['number'] : NULL;
    $comparison['reported'] = $reported;
    $comparison['state'] = match (TRUE) {
      $extracted === NULL => 'no_value',
      $reported === NULL => 'no_answer',
      abs($extracted - $reported) <= self::MATCH_TOLERANCE => 'match',
      default => 'mismatch',
    };
    return $comparison;
  }

}
