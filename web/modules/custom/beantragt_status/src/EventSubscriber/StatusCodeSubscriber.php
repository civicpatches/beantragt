<?php

declare(strict_types=1);

namespace Drupal\beantragt_status\EventSubscriber;

use Drupal\beantragt_status\Service\StatusBuilder;
use Drupal\beantragt_status\Service\StatusOtpService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Matches status lookup requests and sends codes after the response is sent.
 */
final class StatusCodeSubscriber implements EventSubscriberInterface {

  /**
   * Request-local pending lookups, never persisted or logged.
   *
   * @var array<array{reference: string, email: string, langcode: string}>
   */
  private array $pending = [];

  /**
   * Constructs the deferred status code sender.
   */
  public function __construct(
    private readonly StatusBuilder $statusBuilder,
    private readonly StatusOtpService $statusOtp,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Defers all existence checks, bcrypt and SMTP until kernel termination.
   */
  public function enqueue(string $reference, string $email, string $langcode): void {
    $this->pending[] = compact('reference', 'email', 'langcode');
  }

  /**
   * Processes each lookup once, after PHP-FPM has flushed the response.
   */
  public function onTerminate(): void {
    $pending = $this->pending;
    $this->pending = [];
    foreach ($pending as $lookup) {
      try {
        if ($this->statusBuilder->referenceMatchesApplicantEmail($lookup['reference'], $lookup['email'])) {
          $this->statusOtp->requestCode(
            $lookup['reference'],
            $lookup['email'],
            $lookup['langcode'],
            $this->statusBuilder->jurisdictionIdForReference($lookup['reference']),
          );
        }
      }
      catch (\Throwable) {
        // The response is already sent. Do not log lookup identities or codes.
        $this->logger->error('Deferred status code request failed.');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

}
