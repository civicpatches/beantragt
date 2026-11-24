<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen;

/**
 * What an appendix prepared for one request letter draft.
 */
final class DocgenAppendix {

  /**
   * Constructs the value object.
   *
   * @param string|null $coverDocx
   *   Standalone cover sheet DOCX (body only, letterhead placeholders in the
   *   header after rendering), NULL without a cover sheet.
   * @param array<string, mixed> $payload
   *   Opaque data for DocgenAppendixInterface::commit(); never holds the
   *   identifier in clear.
   */
  public function __construct(
    public readonly ?string $coverDocx,
    public readonly array $payload,
  ) {}

}
