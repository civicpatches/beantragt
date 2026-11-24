<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Exception;

/**
 * Signals that the attachment claim lock is held by another writer.
 *
 * Nothing was written. Queue callers retry later, HTTP callers answer with
 * the existing claim-in-progress conflict.
 */
final class AttachmentAnalysisMetadataLockedException extends \RuntimeException {

}
