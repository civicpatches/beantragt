<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rejects SSO config imports that would trust unverified mailboxes.
 */
final class SsoConfigImportValidator implements EventSubscriberInterface {

  /**
   * Rejects unsafe account-linking provider configuration before import.
   */
  public function validate(ConfigImporterEvent $event): void {
    $source = $event->getConfigImporter()->getStorageComparer()->getSourceStorage();
    $settings = $source->read('beantragt_sso.settings');
    if (!is_array($settings)) {
      return;
    }
    foreach ($settings['providers'] ?? [] as $provider_id => $provider) {
      if (is_array($provider)
        && !empty($provider['link_by_email'])
        && ($provider['email_verified'] ?? FALSE) !== TRUE) {
        $event->getConfigImporter()->logError(sprintf(
          'SSO provider "%s" cannot link users by email without verified email claims.',
          (string) $provider_id,
        ));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT_VALIDATE => 'validate'];
  }

}
