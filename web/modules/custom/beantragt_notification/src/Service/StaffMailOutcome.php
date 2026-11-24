<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

/**
 * Staff mail outcomes distinguish a suppressed notification from a retry.
 */
enum StaffMailOutcome {

  case Sent;
  case Disabled;
  case NoRecipient;
  case TransportError;

}
