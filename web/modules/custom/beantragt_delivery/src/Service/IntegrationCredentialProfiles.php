<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Operator-owned profiles, with a secret-free tenant projection.
 */
final class IntegrationCredentialProfiles {

  /**
   * Constructs the profile reader.
   */
  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * Returns only profile keys and human labels.
   */
  public function options(int $scope): array {
    $profiles = $this->configFactory->get('beantragt_delivery.settings')->get('credential_profiles') ?? [];
    $options = [];
    foreach (is_array($profiles) ? $profiles : [] as $key => $profile) {
      if (!is_array($profile) || !$this->allows($profile, $scope)) {
        continue;
      }
      $options[] = ['key' => (string) $key, 'label' => (string) ($profile['label'] ?? $key)];
    }
    return $options;
  }

  /**
   * Performs local checks without reading secrets or calling a remote service.
   */
  public function check(string $key, int $scope): array {
    $profiles = $this->configFactory->get('beantragt_delivery.settings')->get('credential_profiles') ?? [];
    $profile = is_array($profiles) ? ($profiles[$key] ?? NULL) : NULL;
    if (!is_array($profile) || !$this->allows($profile, $scope)) {
      throw new IntegrationException('integration.credential_profile_not_found', 404);
    }
    if (($profile['token_url'] ?? '') !== FitConnectSettings::DEFAULT_TEST_TOKEN_URL
      || ($profile['submission_api_base'] ?? '') !== FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE) {
      return [['pointer' => '/credential_profile', 'code' => 'fit_connect.test_only']];
    }
    if (($profile['metadata_schema_version'] ?? '') !== '2.1.0') {
      return [['pointer' => '/credential_profile', 'code' => 'fit_connect.unsupported_contract']];
    }
    if (!is_string($profile['client_secret_env'] ?? NULL)
      || !preg_match('/^[A-Z_][A-Z0-9_]{0,127}$/', $profile['client_secret_env'])
      || !is_string($profile['client_id'] ?? NULL) || $profile['client_id'] === '') {
      return [['pointer' => '/credential_profile', 'code' => 'integration.profile_incomplete']];
    }
    return [];
  }

  /**
   * Resolves only operator credentials and explicitly confirmed destinations.
   */
  public function settings(array $binding): FitConnectSettings {
    // Queue workers must observe operator revocation between calls.
    $this->configFactory->reset('beantragt_delivery.settings');
    $snapshot = $binding['connection_snapshot'];
    $key = $snapshot['credential_profile'];
    $scope = (int) $binding['scope_group_id'];
    $errors = $this->check($key, $scope);
    if ($errors !== []) {
      throw new IntegrationException($errors[0]['code']);
    }
    $profile = $this->configFactory->get('beantragt_delivery.settings')->get('credential_profiles')[$key];
    // Operator configuration confirms the exact tenant/destination pair.
    $confirmed = FALSE;
    foreach (($profile['confirmed_destinations'] ?? []) as $grant) {
      if (is_array($grant) && ($grant['scope_group_id'] ?? NULL) === $scope
        && ($grant['destination_id'] ?? NULL) === $snapshot['destination_id']
        && ($grant['service_urn'] ?? NULL) === $binding['service_urn']
        && ($grant['contract_key'] ?? NULL) === $binding['contract_key']
        && ($grant['contract_version'] ?? NULL) === $binding['contract_version']) {
        $confirmed = TRUE;
      }
    }
    if (!$confirmed) {
      throw new IntegrationException('integration.destination_unconfirmed');
    }
    $secret = getenv($profile['client_secret_env']);
    $settings = new FitConnectSettings(
      strtoupper($snapshot['environment']),
      $profile['client_id'],
      $profile['client_secret_env'],
      is_string($secret) ? $secret : '',
      $profile['token_url'],
      $profile['submission_api_base'],
      FitConnectSettings::DEFAULT_TEST_DESTINATION_API_BASE,
      FitConnectSettings::DEFAULT_TEST_ROUTING_API_BASE,
      $snapshot['destination_id'],
      $binding['service_urn'],
      '',
      $snapshot['schema_uri'],
      'application/json',
      $profile['metadata_schema_version'],
      FALSE,
      ($snapshot['reply_process_standard'] ?? '') !== '',
      $snapshot['reply_process_standard'] ?? '',
      FALSE,
    );
    $settings->assertTestTransportAllowed();
    if ($settings->missingForTokenFetch() !== []) {
      throw new IntegrationException('integration.profile_incomplete');
    }
    return $settings;
  }

  /**
   * Requires an explicit operator grant; missing scope configuration is closed.
   */
  private function allows(array $profile, int $scope): bool {
    if ($scope < 1) {
      return FALSE;
    }
    if (($profile['all_scopes'] ?? FALSE) === TRUE) {
      return TRUE;
    }
    $scopes = $profile['allowed_scopes'] ?? [];
    return is_array($scopes) && array_is_list($scopes) && in_array($scope, $scopes, TRUE);
  }

}
