<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\AttachmentProofProviderInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Reads the proof assignments recorded on a specific application revision.
 */
final class ApplicationProofAssignments {

  public const FIELD = 'field_proof_assignments';

  /**
   * Returns the recorded projection, including explicitly unassigned files.
   *
   * @return array<string, array{fieldId: ?string, personBlock: ?string}>
   *   The proof targets keyed by attachment UUID.
   */
  public static function read(ApplicationInterface $application): array {
    if (!$application->hasField(self::FIELD) || $application->get(self::FIELD)->isEmpty()) {
      return [];
    }
    return json_decode($application->get(self::FIELD)->getString(), TRUE, 16, JSON_THROW_ON_ERROR);
  }

  /**
   * Returns the field used for access checks, or NULL for an untouched file.
   */
  public static function target(array $assignments, ?string $uuid): ?string {
    if ($uuid === NULL || !isset($assignments[$uuid])) {
      return NULL;
    }
    return $assignments[$uuid]['fieldId'] ?? AttachmentProofProviderInterface::UNASSIGNED_FIELD_ID;
  }

}
