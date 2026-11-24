<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Psr\Log\LoggerInterface;

/**
 * Redacts mailboxes before the mail manager's failure logs reach any sink.
 */
final class MailFailureLoggerFactory implements LoggerChannelFactoryInterface {

  public function __construct(private readonly LoggerChannelFactoryInterface $inner) {}

  /**
   * {@inheritdoc}
   */
  public function get($channel): LoggerChannelInterface {
    $logger = $this->inner->get($channel);
    if ($channel !== 'mail') {
      return $logger;
    }

    return new class($logger) extends LoggerChannel {

      public function __construct(private readonly LoggerChannelInterface $inner) {
        parent::__construct('mail');
      }

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        // Core logs these placeholders before evaluating _error_message for
        // the UI. Keep the actual message and transport intact.
        foreach (['%from', '%to', '%reply'] as $placeholder) {
          if (is_string($context[$placeholder] ?? NULL)) {
            $context[$placeholder] = _beantragt_notification_mail_recipient_domain($context[$placeholder]);
          }
        }
        $this->inner->log($level, $message, $context);
      }

    };
  }

  /**
   * {@inheritdoc}
   */
  public function addLogger(LoggerInterface $logger, $priority = 0): void {
    $this->inner->addLogger($logger, $priority);
  }

}
