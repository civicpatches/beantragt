<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Exception;

use Drupal\Core\Entity\EntityStorageException;

/**
 * Signals that application deletion conflicts with active delivery work.
 */
final class ApplicationDeletionLockedException extends EntityStorageException {}
