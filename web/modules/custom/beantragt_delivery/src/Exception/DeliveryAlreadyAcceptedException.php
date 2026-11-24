<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Exception;

/**
 * Raised when a delivery log has already reached a terminal accepted state.
 */
final class DeliveryAlreadyAcceptedException extends \RuntimeException {
}
