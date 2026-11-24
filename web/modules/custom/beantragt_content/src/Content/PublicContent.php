<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Content;

use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared projection for public documents, sections, and pages.
 */
final class PublicContent {

  /**
   * Public languages considered in locale overrides and translated slugs.
   */
  public const SUPPORTED_LOCALES = ['de' => TRUE, 'en' => TRUE];

  /**
   * Accepts a language override for the supported public locales.
   */
  public static function requestedLangcode(Request $request): ?string {
    $langcode = strtolower(trim((string) $request->query->get('lang', '')));
    return isset(self::SUPPORTED_LOCALES[$langcode]) ? $langcode : NULL;
  }

  /**
   * Falls back to the original node when a translation is unpublished.
   */
  public static function publishedTranslation(NodeInterface $node, ?string $langcode): NodeInterface {
    if ($langcode && $node->hasTranslation($langcode)) {
      $translation = $node->getTranslation($langcode);
      if ($translation->isPublished()) {
        return $translation;
      }
    }

    return $node;
  }

  /**
   * Exposes filtered basic HTML while keeping the raw editor value private.
   */
  public static function bodyPayload(NodeInterface $node): array {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return [
        'value' => '',
        'processed' => '',
        'format' => NULL,
      ];
    }

    $body = $node->get('body')->first();
    $format = $body->format ?? NULL;
    if ($format !== 'basic_html') {
      return [
        'value' => '',
        'processed' => '',
        'format' => $format,
      ];
    }

    return [
      'value' => '',
      'processed' => (string) ($body->processed ?? ''),
      'format' => $format,
    ];
  }

}
