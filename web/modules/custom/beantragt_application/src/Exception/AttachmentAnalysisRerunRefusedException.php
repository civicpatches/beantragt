<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Exception;

/**
 * Signals that a staff rerun of one analysis story is denied.
 *
 * The message is a stable API error code such as attachment.analysis_running
 * or attachment.guard_blocked. Nothing was written.
 */
final class AttachmentAnalysisRerunRefusedException extends \RuntimeException {

}
