<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for generating unique reference numbers for applications.
 *
 * Supports configurable patterns per organization group with tokens:
 * - {ORG}: The org's reference prefix (field_reference_prefix).
 * - {YEAR}: Current four-digit year.
 * - {SEQ}: Sequential number (auto-incremented per org per year).
 * - {SEQ:N}: Zero-padded sequential number (N = total digits).
 * - {FORM_CODE}: Short form identifier from the schema.
 * - {UUID_SHORT}: First 8 characters of the application UUID.
 *
 * Example patterns:
 *   "{ORG}-{YEAR}-{SEQ:5}" => "FRE-2026-00001"
 *   "{ORG}-{FORM_CODE}-{YEAR}-{SEQ:3}" => "ST-HND-2026-001"
 *
 * Pattern resolution order (most specific wins):
 *   1. Per-form pattern override (form_schema field_reference_pattern).
 *   2. Per-org pattern (group field_reference_pattern).
 *   3. DEFAULT_PATTERN.
 * The {ORG} prefix token is always resolved from the organisation.
 *
 * Race conditions are prevented by using an atomic database INSERT/UPDATE on a
 * dedicated sequence table with a unique key on (org_id, scope_id, year).
 * The scope_id dimension lets a form with its own pattern keep an independent
 * sequence (scope_id = form id, starts at 1); forms without a per-form pattern
 * share the organisation sequence (scope_id = 0). Per-form sequences are only
 * collision-safe because such forms carry a distinct pattern: two forms sharing
 * the org default pattern MUST keep sharing one sequence, otherwise they would
 * both emit "{ORG}-{YEAR}-00001".
 */
class ReferenceNumberGenerator {

  /**
   * The default pattern when no org-specific pattern is configured.
   */
  public const DEFAULT_PATTERN = '{ORG}-{YEAR}-{SEQ:5}';

  /**
   * The default org prefix when no org is associated.
   */
  public const DEFAULT_PREFIX = 'BEA';

  /**
   * Hard cap for {SEQ:N} zero-padding width.
   *
   * Well inside the 64-char field_reference_number column; prevents an
   * accidental or malicious {SEQ:huge} from overflowing/truncating the value.
   */
  public const MAX_SEQ_PADDING = 20;

  /**
   * Constructs a ReferenceNumberGenerator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly Connection $database,
  ) {}

  /**
   * Generates a unique reference number for an application.
   *
   * @param int|null $org_id
   *   The group ID of the organisation, or NULL for unaffiliated applications.
   * @param string|null $form_code
   *   Optional short form identifier (e.g., "HND" for Hundesteuer).
   * @param string|null $uuid
   *   The application UUID (used for {UUID_SHORT} token).
   * @param string|null $patternOverride
   *   Optional per-form pattern that wins over the org and default patterns.
   *   When provided (non-empty), the form gets its own sequence scope.
   * @param int|null $formScopeId
   *   The form's entity id, used as the sequence scope when a per-form pattern
   *   is in effect so the form numbers independently from 1. Ignored unless a
   *   non-empty $patternOverride is supplied.
   *
   * @return string
   *   The generated reference number.
   */
  public function generate(?int $org_id = NULL, ?string $form_code = NULL, ?string $uuid = NULL, ?string $patternOverride = NULL, ?int $formScopeId = NULL): string {
    $year = date('Y');
    [$orgPattern, $prefix] = $this->getOrgConfig($org_id);

    // A per-form pattern wins over the org/default pattern (the {ORG} prefix
    // token is still resolved from the organisation). It only takes effect, and
    // only gets its own sequence scope, when it is non-empty AND differs from
    // the org/default pattern: an identical override would share the number
    // namespace but use a separate counter, producing collisions.
    $override = $patternOverride !== NULL ? trim($patternOverride) : '';
    $hasFormPattern = $override !== '' && $override !== trim($orgPattern);
    $pattern = $hasFormPattern ? $override : $orgPattern;

    // Determine the effective org key for the sequence counter.
    // Unaffiliated applications share a global sequence keyed by org_id = 0.
    $sequence_org_id = $org_id ?? 0;

    // Determine the sequence scope. Forms with their own pattern number
    // independently (scope = form id); all others share the org sequence
    // (scope = 0). Per-form scoping is only safe because such forms carry a
    // distinct pattern — never scope a form that uses the org/default pattern.
    $scope_id = ($hasFormPattern && $formScopeId !== NULL && $formScopeId > 0) ? $formScopeId : 0;

    $tokens = [
      '{ORG}' => $prefix,
      '{YEAR}' => $year,
      '{FORM_CODE}' => $form_code ?? '',
      '{UUID_SHORT}' => $uuid ? substr($uuid, 0, 8) : '',
    ];

    // Handle {SEQ} and {SEQ:N} tokens. Only fetch a sequence number
    // if the pattern actually uses it.
    if (str_contains($pattern, '{SEQ')) {
      $sequence = $this->nextSequence($sequence_org_id, $scope_id, (int) $year);
      $pattern = $this->replaceSequenceTokens($pattern, $sequence);
    }

    // Replace simple tokens.
    $reference = str_replace(array_keys($tokens), array_values($tokens), $pattern);

    // Clean up any double dashes from empty tokens.
    $reference = preg_replace('/-{2,}/', '-', $reference);
    $reference = trim($reference, '-');

    return $reference;
  }

  /**
   * Gets the reference pattern and prefix for an organisation.
   *
   * Loads the group entity once and extracts both values to avoid
   * redundant entity loads.
   *
   * @param int|null $org_id
   *   The group ID, or NULL.
   *
   * @return array{string, string}
   *   A tuple of [pattern, prefix].
   */
  protected function getOrgConfig(?int $org_id): array {
    if ($org_id === NULL) {
      return [self::DEFAULT_PATTERN, self::DEFAULT_PREFIX];
    }

    $group = $this->entityTypeManager->getStorage('group')->load($org_id);
    if (!$group) {
      return [self::DEFAULT_PATTERN, self::DEFAULT_PREFIX];
    }

    // Pattern: fall back to default if empty or missing.
    $pattern = self::DEFAULT_PATTERN;
    if ($group->hasField('field_reference_pattern') && !$group->get('field_reference_pattern')->isEmpty()) {
      $value = trim($group->get('field_reference_pattern')->value);
      if ($value !== '') {
        $pattern = $value;
      }
    }

    // Prefix.
    $prefix = self::DEFAULT_PREFIX;
    if ($group->hasField('field_reference_prefix') && !$group->get('field_reference_prefix')->isEmpty()) {
      $prefix = $group->get('field_reference_prefix')->value;
    }

    return [$pattern, $prefix];
  }

  /**
   * Atomically increments and returns the next sequence number.
   *
   * Uses INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID() for
   * truly atomic sequence generation. LAST_INSERT_ID() is connection-scoped
   * in MySQL, so concurrent requests each get their own unique value.
   *
   * Note: This uses MySQL-specific syntax (ON DUPLICATE KEY UPDATE,
   * LAST_INSERT_ID()). PostgreSQL would require INSERT ... ON CONFLICT
   * ... DO UPDATE ... RETURNING sequence.
   *
   * @param int $org_id
   *   The organisation ID (0 for global/unaffiliated).
   * @param int $scope_id
   *   The sequence scope: a form id for per-form sequences, 0 for the shared
   *   organisation sequence.
   * @param int $year
   *   The year for the sequence.
   *
   * @return int
   *   The next sequence number (starting at 1).
   */
  protected function nextSequence(int $org_id, int $scope_id, int $year): int {
    // Atomic upsert with LAST_INSERT_ID() to capture the assigned value.
    // On INSERT: LAST_INSERT_ID(1) sets the connection-local value to 1.
    // On UPDATE: LAST_INSERT_ID(sequence + 1) captures the incremented value.
    $this->database->query(
      'INSERT INTO {beantragt_ref_sequence} (org_id, scope_id, year, sequence) VALUES (:org_id, :scope_id, :year, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE sequence = LAST_INSERT_ID(sequence + 1)',
      [
        ':org_id' => $org_id,
        ':scope_id' => $scope_id,
        ':year' => $year,
      ]
    );

    // LAST_INSERT_ID() is connection-scoped: each concurrent request
    // gets its own unique value, no race condition possible.
    $sequence = (int) $this->database->query('SELECT LAST_INSERT_ID()')->fetchField();

    return $sequence;
  }

  /**
   * Replaces {SEQ} and {SEQ:N} tokens with the sequence number.
   *
   * @param string $pattern
   *   The pattern string.
   * @param int $sequence
   *   The sequence number.
   *
   * @return string
   *   The pattern with sequence tokens replaced.
   */
  protected function replaceSequenceTokens(string $pattern, int $sequence): string {
    // Replace {SEQ:N} with zero-padded number. Padding is hard-capped so a
    // pattern like {SEQ:9999999} cannot allocate a huge string or overflow the
    // 64-char field_reference_number column (which would truncate -> collide).
    $pattern = preg_replace_callback('/\{SEQ:(\d+)\}/', function (array $matches) use ($sequence): string {
      $padding = min((int) $matches[1], self::MAX_SEQ_PADDING);
      return str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT);
    }, $pattern) ?? $pattern;

    // Replace plain {SEQ} with unpadded number.
    $pattern = str_replace('{SEQ}', (string) $sequence, $pattern);

    return $pattern;
  }

}
