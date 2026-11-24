<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;

/**
 * Renders email-client-safe tenant HTML around existing plain templates.
 */
final class TenantMailHtmlRenderer {

  public function __construct(
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * Renders the HTML part while preserving the original text as fallback.
   *
   * @param array<string, mixed> $branding
   *   Sanitized tenant branding.
   * @param array<string, mixed> $message
   *   Drupal mail message.
   *
   * @return array{html: string, plain: string}
   *   Multipart content.
   */
  public function render(array $branding, array $message): array {
    $plain = $this->normalizePlain(implode("\n\n", array_map('strval', (array) ($message['body'] ?? []))));
    $body = $this->bodyWithoutDuplicatedFooter($plain, (string) ($branding['footer'] ?? ''));
    $code = $this->verificationCode((array) ($message['params'] ?? []));
    if ($code !== '') {
      $body = trim(str_replace($code, '', $body));
    }
    $cta_url = $this->safeCtaUrl((array) ($message['params'] ?? []), $body);
    if ($cta_url !== '') {
      $body = $this->bodyWithoutCtaUrl($body, $cta_url);
    }
    $langcode = (string) ($message['langcode'] ?? 'de');

    $build = [
      '#theme' => 'beantragt_notification_mail',
      '#langcode' => $langcode,
      '#branding' => $branding,
      '#subject' => (string) ($message['subject'] ?? ''),
      '#body' => Markup::create(nl2br(Html::escape($body))),
      '#code' => $code,
      '#cta_url' => $cta_url,
      '#cta_label' => $langcode === 'de' ? 'Link öffnen' : 'Open link',
    ];
    $html = (string) $this->renderer->renderInIsolation($build);

    if (trim((string) ($branding['footer'] ?? '')) !== '' && !str_contains($plain, (string) $branding['footer'])) {
      $plain = rtrim($plain) . "\n\n-- \n" . trim((string) $branding['footer']) . "\n";
    }

    return ['html' => $html, 'plain' => $plain];
  }

  /**
   * Normalizes text-part line endings and control bytes.
   */
  private function normalizePlain(string $plain): string {
    $plain = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $plain);
    return rtrim($plain) . "\n";
  }

  /**
   * Removes a footer that the legacy plain hook already appended.
   */
  private function bodyWithoutDuplicatedFooter(string $body, string $footer): string {
    $footer = trim($footer);
    if ($footer === '') {
      return trim($body);
    }
    $suffix = "\n\n-- \n" . $footer;
    return str_ends_with(rtrim($body), $suffix)
      ? trim(substr(rtrim($body), 0, -strlen($suffix)))
      : trim($body);
  }

  /**
   * Reads only explicitly supported OTP parameters.
   */
  private function verificationCode(array $params): string {
    $code = isset($params['code']) && is_scalar($params['code']) ? trim((string) $params['code']) : '';
    return preg_match('/^[0-9]{4,9}$/', $code) === 1 ? $code : '';
  }

  /**
   * Selects a public HTTP(S) action link from known mail parameters.
   */
  private function safeCtaUrl(array $params, string $body): string {
    $candidates = [
      $params['claim_url'] ?? NULL,
      $params['replacements']['@confirmation_url'] ?? NULL,
      $params['replacements']['@citizen_application_url'] ?? NULL,
      $params['replacements']['@admin_application_url'] ?? NULL,
    ];
    if (preg_match('#https?://[^\s<>]+#i', $body, $matches) === 1) {
      $candidates[] = rtrim($matches[0], '.,;:)');
    }
    foreach ($candidates as $candidate) {
      if (!is_string($candidate) || filter_var($candidate, FILTER_VALIDATE_URL) === FALSE) {
        continue;
      }
      $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
      if (in_array($scheme, ['http', 'https'], TRUE)) {
        return $candidate;
      }
    }
    return '';
  }

  /**
   * Removes the button target from HTML while retaining it in plain text.
   */
  private function bodyWithoutCtaUrl(string $body, string $ctaUrl): string {
    $lines = preg_split('/\R/', $body) ?: [$body];
    $lines = array_filter(
      $lines,
      static fn (string $line): bool => !str_contains($line, $ctaUrl),
    );
    return trim(implode("\n", $lines));
  }

}
