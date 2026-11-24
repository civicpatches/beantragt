<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Exception;

/**
 * Signals that a queued delivery log was deleted before processing.
 */
final class DeliveryLogNotFoundException extends \RuntimeException {}
