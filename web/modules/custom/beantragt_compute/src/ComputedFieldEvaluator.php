<?php

declare(strict_types=1);

namespace Drupal\beantragt_compute;

use Drupal\beantragt_compute_api\ComputedFieldEvaluatorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Evaluates computed fields declared in a FormSchema definition.
 *
 * This service is the authoritative engine that derives server-side values
 * for fields declared with a `computed` spec. The hook_ENTITY_TYPE_presave
 * implementation writes the results back into field_data, overwriting any
 * client-supplied value for those field ids.
 */
class ComputedFieldEvaluator implements ComputedFieldEvaluatorInterface {

  /**
   * The compute function plugin manager.
   *
   * @var \Drupal\beantragt_compute\ComputeFunctionManager
   */
  private readonly ComputeFunctionManager $pluginManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a ComputedFieldEvaluator.
   *
   * @param \Drupal\beantragt_compute\ComputeFunctionManager $plugin_manager
   *   The compute function plugin manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    ComputeFunctionManager $plugin_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->pluginManager = $plugin_manager;
    $this->logger = $logger_factory->get('beantragt_compute');
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(array $formSchemaDefinition, array $submittedValues): array {
    // Build a set of all field ids that carry a `computed` spec so that the
    // arg-resolution step can detect and skip computed-on-computed references.
    $computedFieldIds = $this->collectComputedFieldIds($formSchemaDefinition);

    $results = [];

    $sections = $formSchemaDefinition['sections'] ?? [];
    if (!is_array($sections)) {
      return $results;
    }

    foreach ($sections as $section) {
      if (!is_array($section)) {
        continue;
      }

      $fields = $section['fields'] ?? [];
      if (!is_array($fields)) {
        continue;
      }

      foreach ($fields as $field) {
        if (!is_array($field)) {
          continue;
        }

        $fieldId = $field['id'] ?? NULL;
        if (!is_string($fieldId) || $fieldId === '') {
          continue;
        }

        $computed = $field['computed'] ?? NULL;
        if (!is_array($computed)) {
          // Not a computed field; skip.
          continue;
        }

        $fnId = $computed['fn'] ?? NULL;
        if (!is_string($fnId) || $fnId === '') {
          $this->logger->warning(
            'beantragt_compute: field "@field" has a computed spec with a missing or empty fn; skipping.',
            ['@field' => $fieldId],
          );
          continue;
        }

        // Fail-safe: unknown plugin id is skipped, never fatal.
        if (!$this->pluginManager->hasDefinition($fnId)) {
          $this->logger->warning(
            'beantragt_compute: field "@field" references unknown compute function "@fn"; skipping.',
            ['@field' => $fieldId, '@fn' => $fnId],
          );
          continue;
        }

        $argFieldIds = $computed['args'] ?? [];
        if (!is_array($argFieldIds)) {
          $argFieldIds = [];
        }

        // Resolve each arg: look up the submitted value for the referenced
        // field id. Computed-on-computed is forbidden in v1.
        $resolvedArgs = [];
        $skip = FALSE;
        foreach ($argFieldIds as $argFieldId) {
          if (!is_string($argFieldId)) {
            continue;
          }

          if (isset($computedFieldIds[$argFieldId])) {
            // v1 invariant: no chaining. Log and bail on this field.
            $this->logger->warning(
              'beantragt_compute: field "@field" arg "@arg" is itself a computed field; computed-on-computed chaining is not supported in v1. Skipping "@field".',
              ['@field' => $fieldId, '@arg' => $argFieldId],
            );
            $skip = TRUE;
            break;
          }

          // The arg value may legitimately be absent (e.g. optional field not
          // filled in). Pass NULL so the plugin can apply its own default.
          $resolvedArgs[] = $submittedValues[$argFieldId] ?? NULL;
        }

        if ($skip) {
          continue;
        }

        /** @var \Drupal\beantragt_compute\ComputeFunctionInterface $plugin */
        $plugin = $this->pluginManager->createInstance($fnId);
        $results[$fieldId] = $plugin->compute($resolvedArgs, []);
      }
    }

    return $results;
  }

  /**
   * Builds a set (keyed by field id) of all fields with a computed spec.
   *
   * @param array $formSchemaDefinition
   *   Decoded FormSchema definition array.
   *
   * @return array<string, true>
   *   Field ids of computed fields, keyed for O(1) lookup.
   */
  private function collectComputedFieldIds(array $formSchemaDefinition): array {
    $ids = [];

    $sections = $formSchemaDefinition['sections'] ?? [];
    if (!is_array($sections)) {
      return $ids;
    }

    foreach ($sections as $section) {
      if (!is_array($section)) {
        continue;
      }

      $fields = $section['fields'] ?? [];
      if (!is_array($fields)) {
        continue;
      }

      foreach ($fields as $field) {
        if (!is_array($field)) {
          continue;
        }

        $fieldId = $field['id'] ?? NULL;
        if (is_string($fieldId) && $fieldId !== '' && isset($field['computed'])) {
          $ids[$fieldId] = TRUE;
        }
      }
    }

    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function computedFieldIds(array $formSchemaDefinition): array {
    // collectComputedFieldIds() preserves declaration order and de-duplicates
    // (keyed set), so array_keys() yields the unique, ordered list.
    return array_keys($this->collectComputedFieldIds($formSchemaDefinition));
  }

}
