<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\EventSubscriber;

use Drupal\beantragt_notification\Service\ReminderSchedule;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A policy edit schedules a bounded refresh of existing application due dates.
 */
final class ReminderPolicySubscriber implements EventSubscriberInterface {

  public function __construct(private readonly ReminderSchedule $schedule) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  /**
   * Invalidates due dates only when relevant policy values change.
   */
  public function onSave(ConfigCrudEvent $event): void {
    $keys = match ($event->getConfig()->getName()) {
      'beantragt_notification.settings' => [
        'enabled_templates.escalation_alert', 'enabled_templates.resubmission_reminder',
      ],
      'beantragt_workflow.settings' => [
        'escalation_days', 'resubmission_days', 'resubmission_scope',
        'resubmission_interval_days', 'resubmission_max_reminders',
      ],
      default => [],
    };
    foreach ($keys as $key) {
      if (!$event->isChanged($key)) {
        continue;
      }
      $this->schedule->invalidate();
      break;
    }
  }

}
