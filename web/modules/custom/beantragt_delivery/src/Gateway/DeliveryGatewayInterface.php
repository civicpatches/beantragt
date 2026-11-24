<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Gateway;

use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\DeliveryResult;

/**
 * Defines an outbound application delivery adapter.
 */
interface DeliveryGatewayInterface {

  /**
   * Returns the adapter machine name.
   */
  public function id(): string;

  /**
   * Delivers one application envelope.
   */
  public function deliver(DeliveryEnvelope $envelope): DeliveryResult;

}
