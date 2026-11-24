<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema;

/**
 * Recognizes the provenance already written by the form compiler.
 */
final class FormCompilerOrigin {

  /**
   * Checks the source signature in the stored form definition.
   */
  public static function matches(mixed $schema, ?string $source_filename): bool {
    $source = is_array($schema) ? ($schema['source'] ?? NULL) : NULL;
    $source_type = is_array($source) ? ($source['type'] ?? NULL) : NULL;
    $source_filename = trim((string) $source_filename);
    return in_array($source_type, ['pdf', 'image', 'text'], TRUE)
      && $source_filename !== ''
      && !str_starts_with($source_filename, 'seed:');
  }

}
