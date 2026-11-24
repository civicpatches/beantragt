<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Plugin\Mail;

use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\Plugin\Mail\PhpMail;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Sends branded multipart mail through Drupal's native mail() transport.
 */
#[Mail(
  id: 'beantragt_html_mail',
  label: new TranslatableMarkup('Beantragt HTML mail'),
  description: new TranslatableMarkup('Builds multipart HTML mail and delivers it through the PHP mail transport.'),
)]
final class BrandedHtmlMail extends PhpMail {

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];
    $html = $params['_html_body'] ?? NULL;
    $plain = $params['_plain_alt'] ?? NULL;
    if (!is_string($html) || trim($html) === '' || !is_string($plain)) {
      return parent::format($message);
    }

    $alternative_boundary = 'beantragt-alt-' . bin2hex(random_bytes(12));
    $related_boundary = 'beantragt-rel-' . bin2hex(random_bytes(12));
    $inline_logo = $this->validInlineLogo($params['_inline_logo'] ?? NULL);

    foreach (array_keys((array) ($message['headers'] ?? [])) as $header) {
      if (in_array(strtolower((string) $header), ['content-type', 'content-transfer-encoding'], TRUE)) {
        unset($message['headers'][$header]);
      }
    }
    $message['headers']['Content-Type'] = 'multipart/alternative; boundary="' . $alternative_boundary . '"';

    $parts = [
      '--' . $alternative_boundary,
      'Content-Type: text/plain; charset=UTF-8',
      'Content-Transfer-Encoding: quoted-printable',
      '',
      $this->quotedPrintable($plain),
      '--' . $alternative_boundary,
    ];

    if ($inline_logo !== NULL) {
      $parts[] = 'Content-Type: multipart/related; boundary="' . $related_boundary . '"';
      $parts[] = '';
      $parts[] = '--' . $related_boundary;
    }
    $parts[] = 'Content-Type: text/html; charset=UTF-8';
    $parts[] = 'Content-Transfer-Encoding: quoted-printable';
    $parts[] = '';
    $parts[] = $this->quotedPrintable($html);

    if ($inline_logo !== NULL) {
      $parts[] = '--' . $related_boundary;
      $parts[] = 'Content-Type: ' . $inline_logo['mime'];
      $parts[] = 'Content-Transfer-Encoding: base64';
      $parts[] = 'Content-ID: <' . $inline_logo['cid'] . '>';
      $parts[] = 'Content-Disposition: inline; filename="tenant-logo.' . ($inline_logo['mime'] === 'image/png' ? 'png' : 'jpg') . '"';
      $parts[] = '';
      $parts[] = rtrim(chunk_split(base64_encode($inline_logo['content']), 76, "\r\n"));
      $parts[] = '--' . $related_boundary . '--';
    }
    $parts[] = '--' . $alternative_boundary . '--';

    $message['body'] = implode("\r\n", $parts) . "\r\n";
    return $message;
  }

  /**
   * Encodes a MIME text part using canonical CRLF line endings.
   */
  private function quotedPrintable(string $value): string {
    $value = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $value);
    $encoded = quoted_printable_encode($value);
    $encoded = str_replace(["\r\n", "\r"], ["\n", "\n"], $encoded);
    return str_replace("\n", "\r\n", $encoded);
  }

  /**
   * Validates the internal logo attachment immediately before MIME assembly.
   *
   * @return array{mime: string, content: string, cid: string}|null
   *   Valid attachment or NULL.
   */
  private function validInlineLogo(mixed $value): ?array {
    if (!is_array($value)
      || !in_array($value['mime'] ?? NULL, ['image/png', 'image/jpeg'], TRUE)
      || !isset($value['content'], $value['cid'])
      || !is_string($value['content'])
      || !is_string($value['cid'])
      || $value['content'] === ''
      || strlen($value['content']) > 524288
      || preg_match('/^[A-Za-z0-9._@-]+$/', $value['cid']) !== 1) {
      return NULL;
    }
    $valid_magic = $value['mime'] === 'image/png'
      ? str_starts_with($value['content'], "\x89PNG\r\n\x1a\n")
      : str_starts_with($value['content'], "\xff\xd8\xff");
    if (!$valid_magic) {
      return NULL;
    }
    return [
      'mime' => $value['mime'],
      'content' => $value['content'],
      'cid' => $value['cid'],
    ];
  }

}
