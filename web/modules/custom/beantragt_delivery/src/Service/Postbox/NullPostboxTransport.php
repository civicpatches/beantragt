<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service\Postbox;

use Drupal\group\Entity\GroupInterface;

/**
 * No BundID postbox is connected yet.
 */
final class NullPostboxTransport implements PostboxTransportInterface {

  /**
   * {@inheritdoc}
   */
  public function available(?GroupInterface $scope): bool {
    return FALSE;
  }

}
