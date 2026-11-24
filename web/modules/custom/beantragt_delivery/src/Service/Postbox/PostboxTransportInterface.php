<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service\Postbox;

use Drupal\group\Entity\GroupInterface;

/**
 * Transport into the BundID postbox (OZG § 9).
 *
 * Stage 1 only asks whether a transport exists; sending is stage 2. Without
 * one, bundid is shown as not connected, never offered publicly and turned
 * into postal at provision (reason postbox_unavailable).
 */
interface PostboxTransportInterface {

  /**
   * Whether documents can be sent into the BundID postbox for a scope.
   *
   * @param \Drupal\group\Entity\GroupInterface|null $scope
   *   Organisation or jurisdiction of the form or application, if any.
   */
  public function available(?GroupInterface $scope): bool;

}
