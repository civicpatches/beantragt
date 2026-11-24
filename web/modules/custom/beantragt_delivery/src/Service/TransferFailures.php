<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

/**
 * Central, exhaustive recovery policy for protected transfers.
 */
final class TransferFailures {

  public const PERMANENT = [
    'fit_connect.api_options_not_allowed',
    'fit_connect.api_path_not_allowed',
    'fit_connect.application_changed',
    'fit_connect.application_unavailable',
    'fit_connect.attachment_changed',
    'fit_connect.attachment_unavailable',
    'fit_connect.encryption_failed',
    'fit_connect.encryption_key_too_small',
    'fit_connect.encryption_not_implemented',
    'fit_connect.events_invalid_response',
    'fit_connect.invalid_data',
    'fit_connect.invalid_encryption_key',
    'fit_connect.invalid_key',
    'fit_connect.invalid_submission_response',
    'fit_connect.log_context_required',
    'fit_connect.metadata_version_unsupported',
    'fit_connect.not_implemented',
    'fit_connect.package_failed',
    'fit_connect.reply_api_invalid',
    'fit_connect.reply_binding_mismatch',
    'fit_connect.reply_channel_unsupported',
    'fit_connect.reply_scope_missing',
    'fit_connect.reply_verification_failed',
    'fit_connect.submission_already_started',
    'fit_connect.token_invalid_response',
    'fit_connect.unsupported_reply_standard',
    'integration.access_denied',
    'integration.already_submitted',
    'integration.application_anonymised',
    'integration.application_changed',
    'integration.application_unavailable',
    'integration.assignment_ambiguous',
    'integration.assignment_conflict',
    'integration.assignment_controls_delivery',
    'integration.assignment_not_found',
    'integration.assignment_unavailable',
    'integration.attachment_bounds_exceeded',
    'integration.attachment_changed',
    'integration.attachment_invalid',
    'integration.attachment_provenance_invalid',
    'integration.bad_request',
    'integration.binding_changed',
    'integration.binding_invalid',
    'integration.connection_invalid',
    'integration.connection_not_found',
    'integration.contract_checksum_mismatch',
    'integration.contract_invalid',
    'integration.contract_mismatch',
    'integration.contract_not_found',
    'integration.contract_reference_unresolved',
    'integration.contract_validation_failed',
    'integration.csrf_invalid',
    'integration.definition_immutable',
    'integration.destination_invalid',
    'integration.dispatch_approval_required',
    'integration.dispatch_not_approvable',
    'integration.duplicate_target',
    'integration.form_schema_invalid',
    'integration.form_schema_not_found',
    'integration.in_use',
    'integration.input_invalid',
    'integration.input_too_large',
    'integration.internal_error',
    'integration.invalid_input',
    'integration.invalid_json',
    'integration.json_required',
    'integration.legacy_delivery_forbidden',
    'integration.legacy_policy_without_assignment',
    'integration.mapped_string_too_large',
    'integration.mapping_checksum_mismatch',
    'integration.mapping_incomplete',
    'integration.mapping_not_found',
    'integration.mapping_version_immutable',
    'integration.mapping_version_not_found',
    'integration.method_not_allowed',
    'integration.occurrence_duplicate',
    'integration.occurrence_max_items',
    'integration.occurrence_min_items',
    'integration.occurrence_target_invalid',
    'integration.payload_encoding_invalid',
    'integration.payload_integrity_failed',
    'integration.payload_invalid',
    'integration.payload_too_large',
    'integration.profile_changed',
    'integration.quota_exceeded',
    'integration.rate_limited',
    'integration.reference_not_found',
    'integration.repeating_source_unsupported',
    'integration.replan_not_allowed',
    'integration.request_invalid',
    'integration.required_source_unguaranteed',
    'integration.required_target_missing',
    'integration.resource_unknown',
    'integration.resume_not_allowed',
    'integration.retry_not_allowed',
    'integration.revision_already_covered',
    'integration.revision_incompatible',
    'integration.route_not_found',
    'integration.rules_invalid',
    'integration.scope_mismatch',
    'integration.scope_not_found',
    'integration.scope_required',
    'integration.source_ambiguous',
    'integration.source_changed',
    'integration.source_fields_limit',
    'integration.source_missing',
    'integration.target_overlap',
    'integration.target_unknown',
    'integration.target_unsupported',
    'integration.transfer_already_live',
    'integration.transfer_not_found',
    'integration.transition_invalid',
    'integration.type_incompatible',
    'integration.unknown_key',
    'integration.validation.additional_properties',
    'integration.validation.all_of',
    'integration.validation.any_of',
    'integration.validation.const',
    'integration.validation.contains',
    'integration.validation.date_invalid',
    'integration.validation.dependent_required',
    'integration.validation.enum',
    'integration.validation.exclusive_maximum',
    'integration.validation.exclusive_minimum',
    'integration.validation.format',
    'integration.validation.integer_invalid',
    'integration.validation.invalid',
    'integration.validation.items',
    'integration.validation.max_contains',
    'integration.validation.max_items',
    'integration.validation.max_length',
    'integration.validation.max_properties',
    'integration.validation.maximum',
    'integration.validation.min_contains',
    'integration.validation.min_items',
    'integration.validation.min_length',
    'integration.validation.min_properties',
    'integration.validation.minimum',
    'integration.validation.multiple_of',
    'integration.validation.not',
    'integration.validation.number_invalid',
    'integration.validation.one_of',
    'integration.validation.pattern',
    'integration.validation.prefix_items',
    'integration.validation.property_names',
    'integration.validation.required',
    'integration.validation.type',
    'integration.validation.unevaluated_properties',
    'integration.validation.unique_items',
    'integration.value_map_incomplete',
    'integration.value_map_invalid',
    'integration.value_unmapped',
  ];

  public const RECOVERABLE = [
    'fit_connect.api_invalid_response',
    'fit_connect.api_rejected',
    'fit_connect.api_request_failed',
    'fit_connect.destination_inactive',
    'fit_connect.missing_configuration',
    'fit_connect.reply_api_failed',
    'fit_connect.reply_api_rejected',
    'fit_connect.reply_storage_unavailable',
    'fit_connect.test_only',
    'fit_connect.token_rejected',
    'fit_connect.token_request_failed',
    'fit_connect.token_url_not_allowed',
    'fit_connect.unsupported_contract',
    // Not a transfer failure: an accepted transfer that left the case in its
    // start status (SemanticStatusChanger::ACCEPTED_UNCHANGED_REASONS). A
    // person sets the status; nothing retries it.
    'integration.accepted_status_unchanged',
    'integration.assignment_inactive',
    'integration.attachment_not_clean',
    'integration.confirmation_required',
    'integration.connection_disabled',
    'integration.contract_unavailable',
    // A status trigger without a status carrying the trigger flag: staff add
    // the status in the workflow settings.
    'integration.coupling_status_missing',
    'integration.credential_profile_not_found',
    'integration.destination_contract_mismatch',
    'integration.destination_service_mismatch',
    'integration.destination_unconfirmed',
    'integration.dispatch_approver_unauthorized',
    'integration.four_eyes_required',
    // Bulk planning refusals: no row is written, the fix is a status or rule.
    'integration.handover_approval_mode_required',
    'integration.handover_status_required',
    'integration.mapping_inactive',
    'integration.payload_protection_failed',
    'integration.planning_failed',
    'integration.prerequisite_inactive',
    'integration.profile_incomplete',
    'integration.queue_backend_unsupported',
    'integration.revision_not_covered',
    'integration.test_only',
    'integration.transfer_busy',
    // Not a failure: the rule plans on a status or only by staff.
    'integration.trigger_deferred',
    // Not a failure: the trigger status was left before planning ran.
    'integration.trigger_reverted',
  ];

  public const TRANSPORT = [
    'fit_connect.token_request_failed',
    'fit_connect.api_request_failed',
    'fit_connect.api_rejected',
    'fit_connect.api_invalid_response',
  ];

  public const MANUAL_LIMIT = 10;

  /**
   * Rowless infrastructure failures may repeat the original planning intent.
   */
  public const PLANNING_RETRY = [
    'integration.transfer_busy',
    'integration.queue_backend_unsupported',
    'integration.planning_failed',
  ];

  /**
   * Extracts only the stable identifier from a stored error.
   */
  public static function code(array $row): string {
    return explode(':', (string) ($row['lastError'] ?? ''), 2)[0];
  }

  /**
   * Unknown errors fail closed and never gain an implicit retry privilege.
   */
  public static function recoverable(string $code): bool {
    return self::classify($code) === 'recoverable';
  }

  /**
   * Classifies registered codes; unknown codes fail closed with no privileges.
   */
  public static function classify(string $code): string {
    if (in_array($code, self::PERMANENT, TRUE)) {
      return 'permanent';
    }
    return in_array($code, self::RECOVERABLE, TRUE) ? 'recoverable' : 'unknown';
  }

  /**
   * Closed management resource vocabulary without dynamic error construction.
   */
  public static function resourceNotFound(string $resource): string {
    return match ($resource) {
      'assignment' => 'integration.assignment_not_found',
      'connection' => 'integration.connection_not_found',
      'mapping' => 'integration.mapping_not_found',
      'version', 'mapping_version' => 'integration.mapping_version_not_found',
      default => 'integration.resource_unknown',
    };
  }

  /**
   * Closed schema-keyword vocabulary, never a dynamically invented code.
   */
  public static function validationCode(string $keyword): string {
    return match ($keyword) {
      'required' => 'integration.validation.required',
      'type' => 'integration.validation.type',
      'enum' => 'integration.validation.enum',
      'const' => 'integration.validation.const',
      'format' => 'integration.validation.format',
      'pattern' => 'integration.validation.pattern',
      'minLength' => 'integration.validation.min_length',
      'maxLength' => 'integration.validation.max_length',
      'minimum' => 'integration.validation.minimum',
      'maximum' => 'integration.validation.maximum',
      'exclusiveMinimum' => 'integration.validation.exclusive_minimum',
      'exclusiveMaximum' => 'integration.validation.exclusive_maximum',
      'multipleOf' => 'integration.validation.multiple_of',
      'minItems' => 'integration.validation.min_items',
      'maxItems' => 'integration.validation.max_items',
      'uniqueItems' => 'integration.validation.unique_items',
      'items' => 'integration.validation.items',
      'prefixItems' => 'integration.validation.prefix_items',
      'additionalProperties' => 'integration.validation.additional_properties',
      'unevaluatedProperties' => 'integration.validation.unevaluated_properties',
      'minProperties' => 'integration.validation.min_properties',
      'maxProperties' => 'integration.validation.max_properties',
      'dependentRequired' => 'integration.validation.dependent_required',
      'oneOf' => 'integration.validation.one_of',
      'anyOf' => 'integration.validation.any_of',
      'allOf' => 'integration.validation.all_of',
      'not' => 'integration.validation.not',
      'contains' => 'integration.validation.contains',
      'minContains' => 'integration.validation.min_contains',
      'maxContains' => 'integration.validation.max_contains',
      'propertyNames' => 'integration.validation.property_names',
      'invalid' => 'integration.validation.invalid',
      default => 'integration.validation.invalid',
    };
  }

}
