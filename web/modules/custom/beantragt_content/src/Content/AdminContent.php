<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Content;

use Drupal\node\NodeInterface;

/**
 * Body projection for editorial responses.
 */
final class AdminContent {

  /**
   * Keeps raw text for editing and uses it when filtered text is unavailable.
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

    return [
      'value' => (string) ($body->value ?? ''),
      'processed' => (string) ($body->processed ?? $body->value ?? ''),
      'format' => $body->format ?? NULL,
    ];
  }

}
