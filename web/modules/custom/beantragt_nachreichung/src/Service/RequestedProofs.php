<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_nachreichung\RequestedProofsProviderInterface;

/**
 * Collects the requested-proofs providers.
 *
 * Tag: beantragt_nachreichung_requested_proofs.
 */
class RequestedProofs {

  /**
   * Constructs the collector.
   */
  public function __construct(private readonly ?RequestAgainProofs $requestAgain = NULL) {}

  /**
   * Providers in collector order.
   *
   * @var \Drupal\beantragt_nachreichung\RequestedProofsProviderInterface[]
   */
  private array $providers = [];

  /**
   * Registers a provider.
   */
  public function addProvider(RequestedProofsProviderInterface $provider): void {
    $this->providers[] = $provider;
  }

  /**
   * The first provider's answer that knows the application, NULL otherwise.
   *
   * Entries are bounded to the documented keys and plain values.
   *
   * @return array<int, array{key: string, fieldId: string, personBlock: ?string, label: string, reason: string, criteria: list<string>}>|null
   *   Requested proofs in stored order.
   */
  public function requested(ApplicationInterface $application): ?array {
    // After "Erneut nachfordern" the next letter asks only for what was
    // insufficient or still missing (D18).
    $again = $this->requestAgain?->forApplication($application);
    if ($again !== NULL) {
      return $again;
    }
    foreach ($this->providers as $provider) {
      /** @var mixed $answer */
      $answer = $provider->requested($application);
      if ($answer === NULL) {
        continue;
      }
      $entries = [];
      // Providers are other modules: their answer is bounded here, whatever
      // their declared type says.
      foreach ((array) $answer as $entry) {
        $entry = (array) $entry;
        $field = $entry['fieldId'] ?? NULL;
        $label = $entry['label'] ?? NULL;
        if (!is_string($field) || !is_string($label)) {
          continue;
        }
        $key = $entry['key'] ?? NULL;
        $block = $entry['personBlock'] ?? NULL;
        $criteria = $entry['criteria'] ?? NULL;
        $entries[] = [
          'key' => is_string($key) ? $key : $field . '#',
          'fieldId' => $field,
          'personBlock' => is_string($block) ? $block : NULL,
          'label' => mb_substr($label, 0, 255),
          'reason' => in_array($entry['reason'] ?? NULL, [
            'missing',
            'person_unmatched',
            'requirement_not_met',
            'illegible',
          ], TRUE) ? (string) $entry['reason'] : 'missing',
          'criteria' => array_values(array_filter(is_array($criteria) ? $criteria : [], 'is_string')),
        ];
      }
      return $entries;
    }
    return NULL;
  }

}
