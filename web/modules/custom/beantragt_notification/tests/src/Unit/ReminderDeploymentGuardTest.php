<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

require_once dirname(__DIR__, 3) . '/beantragt_notification.module';

/**
 * Updated hooks tolerate the code-before-container-rebuild deployment window.
 */
#[Group('beantragt_notification')]
final class ReminderDeploymentGuardTest extends UnitTestCase {

  /**
   * Cron and deletion are safe before the new scheduling service is registered.
   */
  public function testMissingSchedulingService(): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturn(FALSE);
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->method('get')->willReturn($settings);
    $container = new ContainerBuilder();
    $container->set('config.factory', $config);
    \Drupal::setContainer($container);
    $application = $this->createMock(ApplicationInterface::class);
    $application->expects($this->never())->method('id');
    beantragt_notification_application_delete($application);
    beantragt_notification_cron();
    self::assertFalse($container->has('beantragt_notification.reminder_schedule'));
  }

}
