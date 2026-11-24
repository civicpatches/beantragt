<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification;

use Drupal\beantragt_notification\Service\BrandedMailComposer;
use Drupal\Core\Mail\MailManager;

/**
 * Restricts every Drupal mail delivery to the image's msmtp transport.
 *
 * The container entrypoint owns the transport contract: Mailpit by default,
 * explicit SMTP only when configured at runtime. Drupal configuration must not
 * select a direct mail transport for a particular module or message key.
 */
final class ForcedPhpMailManager extends MailManager {

  /**
   * {@inheritdoc}
   */
  public function doMail($module, $key, $to, $langcode, $params = [], $reply = NULL, $send = TRUE) {
    $message = parent::doMail($module, $key, $to, $langcode, $params, $reply, $send);
    if (isset($message['beantragt_redirect_log'])) {
      $log_context = $message['beantragt_redirect_log'];
      unset($message['beantragt_redirect_log']);
      _beantragt_notification_log_mail_redirect($log_context[($message['result'] ?? NULL) === TRUE ? 'accepted' : 'domains']);
    }
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    if (BrandedMailComposer::supports(
      (string) ($options['module'] ?? ''),
      (string) ($options['key'] ?? ''),
    )) {
      if (!isset($this->instances['beantragt_html_mail'])) {
        $this->instances['beantragt_html_mail'] = parent::createInstance('beantragt_html_mail');
      }
      return $this->instances['beantragt_html_mail'];
    }
    if (!isset($this->instances['php_mail'])) {
      $this->instances['php_mail'] = parent::createInstance('php_mail');
    }
    return $this->instances['php_mail'];
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    if ($plugin_id === 'beantragt_html_mail') {
      return parent::createInstance('beantragt_html_mail', $configuration);
    }
    return parent::createInstance('php_mail', $configuration);
  }

}
