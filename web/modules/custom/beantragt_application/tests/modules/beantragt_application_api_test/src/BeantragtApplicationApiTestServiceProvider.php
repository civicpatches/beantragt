<?php

declare(strict_types=1);

namespace Drupal\beantragt_application_api_test;

use Drupal\beantragt_application\Service\AttachmentScanService;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Mail\MailManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Supplies local services for application API tests.
 */
final class BeantragtApplicationApiTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    // Production pins PhpMail. Tests use Drupal's test_mail_collector instead.
    // CI must not depend on an external transport.
    $definition = clone $container->getDefinition('plugin.manager.mail');
    $definition->setClass(MailManager::class);
    $definition->setDecoratedService('plugin.manager.mail');
    $container->setDefinition('beantragt_application_api_test.mail_manager', $definition);

    // Use the deterministic scanner so test uploads need no ClamAV service.
    $container->register('beantragt_application.attachment_scanner', AttachmentScanService::class);
  }

}
