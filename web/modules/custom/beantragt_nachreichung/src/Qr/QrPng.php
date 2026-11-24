<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Qr;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Drupal\beantragt_nachreichung\Code\CoverCode;

/**
 * Renders the cover sheet QR as PNG: the only caller of chillerlan/php-qrcode.
 *
 * Version 2 (25 modules), error correction M, quiet zone of 4 modules,
 * 6 px per module: 198 x 198 px, opaque white background, no alpha channel.
 * Printed at 30 mm that is about 168 ppi, below Word's default compression
 * threshold. The output is deterministic for one payload.
 */
final class QrPng {

  public const SIZE_PX = 198;

  /**
   * Returns PNG bytes for a cover sheet payload (BA1- plus 17 characters).
   */
  public static function render(string $payload): string {
    if (!preg_match(CoverCode::PAYLOAD_PATTERN, $payload)) {
      throw new \InvalidArgumentException('Only cover sheet payloads are rendered.');
    }
    $options = new QROptions([
      'version' => 2,
      'eccLevel' => EccLevel::M,
      'scale' => 6,
      'addQuietzone' => TRUE,
      'quietzoneSize' => 4,
      'imageTransparent' => FALSE,
      'bgColor' => [255, 255, 255],
      'outputBase64' => FALSE,
      'returnResource' => FALSE,
    ]);
    // Alphanumeric segment (BA1-, digits, capitals); the PNG output class is
    // used directly: the output mode constants are deprecated in 5.0.
    $matrix = (new QRCode($options))->addAlphaNumSegment($payload)->getQRMatrix();
    // QRGdImagePNG is the supported child class; only its parent is marked
    // to become abstract in a later major version.
    // @phpstan-ignore method.deprecatedClass
    $png = (new QRGdImagePNG($options, $matrix))->dump();
    if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
      throw new \RuntimeException('The QR library returned no PNG.');
    }
    return $png;
  }

}
