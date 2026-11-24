<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\AttachmentPrecheckProviderInterface;
use Drupal\beantragt_application\AttachmentProofProviderInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Collects the attachment pre-check providers.
 *
 * Tag: beantragt_application_attachment_precheck. The first provider that
 * returns a block for an attachment wins. Provider exceptions propagate: the
 * attachment list catches them at its boundary.
 */
final class AttachmentPrecheck implements AttachmentPrecheckProviderInterface, AttachmentProofProviderInterface {

  /**
   * States a block may carry.
   */
  public const STATES = ['finding', 'clean', 'unavailable'];

  /**
   * Providers in collector order.
   *
   * @var \Drupal\beantragt_application\AttachmentPrecheckProviderInterface[]
   */
  private array $providers = [];

  /**
   * Registers a provider.
   */
  public function addProvider(AttachmentPrecheckProviderInterface $provider): void {
    $this->providers[] = $provider;
  }

  /**
   * {@inheritdoc}
   */
  public function proofsForApplication(ApplicationInterface $application, array $attachmentIds, callable $canViewField): ?array {
    foreach ($this->providers as $provider) {
      if ($provider instanceof AttachmentProofProviderInterface) {
        $projection = $provider->proofsForApplication($application, $attachmentIds, $canViewField);
        if ($projection !== NULL) {
          return $projection;
        }
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function forApplication(ApplicationInterface $application, array $attachment_ids): array {
    $requested = array_flip(array_map('intval', $attachment_ids));
    $blocks = [];
    if ($requested === []) {
      return $blocks;
    }
    foreach ($this->providers as $provider) {
      /** @var mixed $answer */
      $answer = $provider->forApplication($application, array_keys($requested));
      foreach (is_array($answer) ? $answer : [] as $id => $block) {
        if (!is_int($id) || !isset($requested[$id]) || isset($blocks[$id])) {
          continue;
        }
        $bounded = self::bound($block);
        if ($bounded !== NULL) {
          $blocks[$id] = $bounded;
        }
      }
    }
    return $blocks;
  }

  /**
   * Bounds one provider block to the contract keys, NULL when unusable.
   *
   * Providers are other modules, and this is the last boundary before the
   * response: id, state, top-level keys and the types of fieldId and label
   * are enforced here, whatever a provider returns. A block that cannot be
   * JSON-encoded is dropped, so a provider never turns the list into a 500.
   * Nested values are the provider's contract (BulkCrossStackContractTest).
   *
   * @return array<string, mixed>|null
   *   The block in wire order, or NULL to deliver no block.
   */
  private static function bound(mixed $block): ?array {
    if (!is_array($block) || !in_array($block['state'] ?? NULL, self::STATES, TRUE)
      || !is_string($block['fieldId'] ?? NULL)) {
      return NULL;
    }
    $keys = $block['state'] === 'unavailable'
      ? AttachmentPrecheckProviderInterface::UNAVAILABLE_KEYS
      : AttachmentPrecheckProviderInterface::KEYS;
    $bounded = [];
    foreach ($keys as $key) {
      if ($key === 'label') {
        $bounded['label'] = is_string($block['label'] ?? NULL) ? $block['label'] : NULL;
      }
      elseif (array_key_exists($key, $block)) {
        $bounded[$key] = $block[$key];
      }
    }
    try {
      // The contract shape nests 4 levels; a small depth keeps a deeper
      // block from passing here and failing inside the response.
      json_encode($bounded, JSON_THROW_ON_ERROR, 16);
    }
    catch (\JsonException) {
      return NULL;
    }
    return $bounded;
  }

}
