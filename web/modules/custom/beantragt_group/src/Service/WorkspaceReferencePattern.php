<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\beantragt_application\Service\ReferenceNumberGenerator;

/**
 * Shared reference number rules for workspace settings and organisations.
 */
final class WorkspaceReferencePattern {

  /**
   * Org reference prefix shape.
   */
  private const REFERENCE_PREFIX_PATTERN = '/^[A-Za-z0-9_-]{0,32}$/';

  /**
   * Allows a blank prefix so reference generation can use the default.
   */
  public static function validatePrefix(mixed $value): ?string {
    if (!is_string($value)) {
      return 'referencePrefix must be a string.';
    }

    $trimmed = trim($value);
    if (preg_match(self::REFERENCE_PREFIX_PATTERN, $trimmed) !== 1) {
      return 'referencePrefix must contain up to 32 ASCII letters, numbers, underscores, or hyphens.';
    }
    return NULL;
  }

  /**
   * Uses the maximum token widths to test the rendered reference limit.
   */
  public static function renderWorstCase(string $pattern): string {
    $rendered = preg_replace_callback('/\{SEQ(?::(\d+))?\}/', static function (array $matches): string {
      $padding = isset($matches[1]) ? (int) $matches[1] : ReferenceNumberGenerator::MAX_SEQ_PADDING;
      return str_repeat('9', max(1, min($padding, ReferenceNumberGenerator::MAX_SEQ_PADDING)));
    }, $pattern) ?? $pattern;

    return str_replace(
      ['{ORG}', '{YEAR}', '{FORM_CODE}', '{UUID_SHORT}'],
      [str_repeat('O', 32), '9999', str_repeat('F', 12), 'abcdef12'],
      $rendered
    );
  }

}
