<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification;

use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Pins Drupal mail-plugin resolution to the container-owned msmtp transport.
 */
final class BeantragtNotificationServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container
      ->getDefinition('plugin.manager.mail')
      ->setClass(ForcedPhpMailManager::class)
      ->replaceArgument(4, new Reference('beantragt_notification.mail_logger_factory'));
  }

}
