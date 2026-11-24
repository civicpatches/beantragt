<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Header\MailboxHeader;

/**
 * Promotes allowlisted beantragt.ai mails to branded multipart messages.
 */
final class BrandedMailComposer {

  /**
   * Mail pairs owned by beantragt.ai and safe for the internal renderer.
   */
  public const SUPPORTED = [
    'beantragt_notification' => [
      'application_confirmation_request',
      'application_confirmation',
      'status_change',
      'approval',
      'rejection',
      'new_application_alert',
      'assignment_alert',
      'delegation_alert',
      'escalation_alert',
      'deadline_alert',
      'resubmission_reminder',
      'application_comment_staff',
      'application_comment_citizen',
      'external_response_available',
    ],
    'beantragt_passwordless' => ['verification_code'],
    'beantragt_status' => ['status_lookup_code'],
    'beantragt_group' => ['workspace_invitation'],
    'beantragt_maintenance' => ['break_glass_code'],
  ];

  public function __construct(
    private readonly TenantMailBranding $branding,
    private readonly TenantMailHtmlRenderer $renderer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns whether a module/key pair belongs to the internal mail system.
   */
  public static function supports(string $module, string $key): bool {
    return isset(self::SUPPORTED[$module]) && in_array($key, self::SUPPORTED[$module], TRUE);
  }

  /**
   * Adds renderer output and safe reply-to metadata to a mail message.
   *
   * @param array<string, mixed> $message
   *   Drupal mail message, altered by reference.
   */
  public function compose(array &$message): void {
    $module = (string) ($message['module'] ?? '');
    $key = (string) ($message['key'] ?? '');
    if (!self::supports($module, $key) || empty($message['send'])) {
      return;
    }

    $message['subject'] = $this->sanitizeHeader((string) ($message['subject'] ?? ''));
    $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];
    $branding = $this->branding->resolve($params, (string) ($message['langcode'] ?? 'de'));
    $this->applyTenantIdentity($message, $branding, $params);
    $rendered = $this->renderer->render($branding, $message);

    $message['body'] = [$rendered['html']];
    $message['params']['_html_body'] = $rendered['html'];
    $message['params']['_plain_alt'] = $rendered['plain'];
    unset($message['params']['_inline_logo']);
    if (is_array($branding['inline_logo'] ?? NULL)) {
      $message['params']['_inline_logo'] = $branding['inline_logo'];
    }

    $reply_to = (string) ($branding['reply_to'] ?? '');
    if ($reply_to !== '') {
      foreach (array_keys((array) ($message['headers'] ?? [])) as $header) {
        if (strcasecmp((string) $header, 'Reply-To') === 0) {
          unset($message['headers'][$header]);
        }
      }
      $message['headers']['Reply-To'] = $reply_to;
      $message['reply-to'] = $reply_to;
    }

    $this->logger->debug('Composed branded multipart mail for @module:@key using tenant @tenant.', [
      '@module' => $module,
      '@key' => $key,
      '@tenant' => $branding['tenant_id'] ?? 'platform',
    ]);
  }

  /**
   * Removes header-breaking control bytes.
   */
  private function sanitizeHeader(string $value): string {
    return str_replace(["\r", "\n", "\0"], '', $value);
  }

  /**
   * Applies the tenant display identity without changing the envelope sender.
   *
   * @param array<string, mixed> $message
   *   Drupal mail message.
   * @param array<string, mixed> $branding
   *   Resolved branding package.
   * @param array<string, mixed> $params
   *   Original mail parameters.
   */
  private function applyTenantIdentity(array &$message, array $branding, array $params): void {
    if (empty($branding['tenant_id'])) {
      return;
    }

    $tenant_name = trim((string) ($branding['name'] ?? ''));
    $platform_name = isset($params['platform_name']) && is_scalar($params['platform_name'])
      ? trim((string) $params['platform_name'])
      : '';
    if ($tenant_name !== '' && $platform_name !== '' && $tenant_name !== $platform_name) {
      $message['subject'] = str_replace($platform_name, $tenant_name, (string) ($message['subject'] ?? ''));
      foreach ((array) ($message['body'] ?? []) as $index => $body) {
        $message['body'][$index] = str_replace($platform_name, $tenant_name, (string) $body);
      }
    }

    $from = trim((string) ($message['from'] ?? ''));
    if ($tenant_name !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== FALSE) {
      $message['headers']['From'] = (new MailboxHeader(
        'From',
        new Address($from, $tenant_name),
      ))->getBodyAsString();
    }
  }

}
