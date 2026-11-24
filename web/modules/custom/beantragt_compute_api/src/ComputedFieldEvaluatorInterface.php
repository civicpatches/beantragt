<?php

declare(strict_types=1);

namespace Drupal\beantragt_compute_api;

/**
 * Evaluates computed fields declared in a FormSchema definition.
 */
interface ComputedFieldEvaluatorInterface {

  /**
   * Evaluates all computed fields declared in the given schema definition.
   *
   * For each field carrying a `computed` spec ({fn, args}), the evaluator:
   *  - resolves each arg as a field id, looks up its value in $submittedValues
   *  - loads the ComputeFunction plugin by id
   *  - calls compute() and collects the result.
   *
   * Computed-on-computed chaining is forbidden in v1: if an arg references
   * another field that is itself declared as computed, that field is skipped
   * and a warning is logged. This prevents ordering ambiguity and circular
   * dependency risks.
   *
   * Unknown function ids are skipped with a warning (fail-safe, never fatal).
   *
   * @param array $formSchemaDefinition
   *   Decoded FormSchema definition array (the value of field_schema JSON).
   * @param array $submittedValues
   *   Map of field_id => submitted value (decoded from field_data JSON).
   *
   * @return array
   *   Map of computed field_id => authoritative computed value. Only fields
   *   that were successfully evaluated are included. Skipped fields (unknown
   *   fn, empty fn, computed-on-computed) are deliberately absent: callers MUST
   *   still strip the client-supplied value for those ids using
   *   computedFieldIds(), so a skipped field never keeps a client value.
   */
  public function evaluate(array $formSchemaDefinition, array $submittedValues): array;

  /**
   * Returns every field id declared as computed in the schema definition.
   *
   * This is the FULL set of declared computed fields, regardless of whether
   * they evaluate successfully. The presave hook uses it to enforce the
   * security invariant over all declared computed fields: for each id, the
   * stored value is set to the server result, or NULL when the field was
   * skipped, but NEVER the client-supplied value.
   *
   * @param array $formSchemaDefinition
   *   Decoded FormSchema definition array (the value of field_schema JSON).
   *
   * @return string[]
   *   List of declared computed field ids (unique, in declaration order).
   */
  public function computedFieldIds(array $formSchemaDefinition): array;

}
