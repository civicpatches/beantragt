<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Service\IntegrationCredentialProfiles;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Protects operator credential references and the TEST-only transport boundary.
 */
#[Group('beantragt_delivery')]
final class IntegrationCredentialProfilesTest extends UnitTestCase {

  /**
   * Tenant projections never reveal operator credential settings.
   */
  public function testProjectionAndLocalChecks(): void {
    $profiles = new IntegrationCredentialProfiles($this->getConfigFactoryStub([
      'beantragt_delivery.settings' => ['credential_profiles' => ['synthetic' => $this->profile()]],
    ]));
    self::assertSame([['key' => 'synthetic', 'label' => 'Synthetic profile']], $profiles->options(7));
    self::assertSame([], $profiles->check('synthetic', 7));
    self::assertSame([], $profiles->options(8));
    foreach ([['synthetic', 8], ['foreign', 7], ['synthetic', 0]] as [$key, $scope]) {
      try {
        $profiles->check($key, $scope);
        self::fail('Foreign and unknown profiles must be indistinguishable.');
      }
      catch (IntegrationException $exception) {
        self::assertSame('integration.credential_profile_not_found', $exception->errorCode);
        self::assertSame(404, $exception->status);
        self::assertSame([], $exception->errors);
      }
    }
  }

  /**
   * New or malformed scope grants fail closed; only explicit grants are listed.
   */
  public function testScopeBindingsAreExplicitAndStrict(): void {
    $profile = $this->profile();
    unset($profile['allowed_scopes'], $profile['all_scopes']);
    $definitions = [
      'legacy_unmigrated' => $profile,
      'closed' => $profile + ['allowed_scopes' => [], 'all_scopes' => FALSE],
      'string_scope' => $profile + ['allowed_scopes' => ['7'], 'all_scopes' => FALSE],
      'truthy_flag' => $profile + ['allowed_scopes' => [], 'all_scopes' => 'true'],
      'foreign' => $profile + ['allowed_scopes' => [8], 'all_scopes' => FALSE],
      'own' => $profile + ['allowed_scopes' => [7], 'all_scopes' => FALSE],
      'global' => $profile + ['allowed_scopes' => [], 'all_scopes' => TRUE],
    ];
    $profiles = new IntegrationCredentialProfiles($this->getConfigFactoryStub([
      'beantragt_delivery.settings' => ['credential_profiles' => $definitions],
    ]));
    self::assertSame(['own', 'global'], array_column($profiles->options(7), 'key'));
    self::assertSame(['foreign', 'global'], array_column($profiles->options(8), 'key'));
    self::assertSame([], $profiles->options(0));
    self::assertSame([], $profiles->check('global', 999));
    foreach (['legacy_unmigrated', 'closed', 'string_scope', 'truthy_flag', 'foreign'] as $key) {
      try {
        $profiles->check($key, 7);
        self::fail('A profile without an explicit tenant grant was accepted.');
      }
      catch (IntegrationException $exception) {
        self::assertSame(404, $exception->status);
        self::assertSame('integration.credential_profile_not_found', $exception->errorCode);
      }
    }
  }

  /**
   * Operator configuration cannot relax the existing TEST transport guardrail.
   */
  public function testUnsafeAndIncompleteProfilesFailClosed(): void {
    foreach ([
      ['token_url', 'https://synthetic-attacker.invalid/token', 'fit_connect.test_only'],
      ['submission_api_base', 'file:///synthetic-secret', 'fit_connect.test_only'],
      ['metadata_schema_version', '99', 'fit_connect.unsupported_contract'],
      ['client_secret_env', 'invalid.env', 'integration.profile_incomplete'],
      ['client_id', '', 'integration.profile_incomplete'],
    ] as [$key, $value, $code]) {
      $profile = $this->profile();
      $profile[$key] = $value;
      $profiles = new IntegrationCredentialProfiles($this->getConfigFactoryStub([
        'beantragt_delivery.settings' => ['credential_profiles' => ['synthetic' => $profile]],
      ]));
      self::assertSame($code, $profiles->check('synthetic', 7)[0]['code']);
    }
  }

  /**
   * Confirmation grants bind every part of the tenant destination contract.
   */
  public function testDestinationConfirmationRequiresExactBinding(): void {
    $binding = $this->binding();
    $grant = $this->grant();
    foreach ([
      ['scope_group_id', 8],
      ['scope_group_id', '7'],
      ['destination_id', '22222222-2222-4222-8222-222222222222'],
      ['service_urn', 'urn:synthetic:other'],
      ['contract_key', 'synthetic-other'],
      ['contract_version', '2.0'],
    ] as [$key, $value]) {
      $profile = $this->profile();
      $profile['confirmed_destinations'] = [array_replace($grant, [$key => $value])];
      $profiles = new IntegrationCredentialProfiles($this->getConfigFactoryStub([
        'beantragt_delivery.settings' => ['credential_profiles' => ['synthetic' => $profile]],
      ]));
      try {
        $profiles->settings($binding);
        self::fail('A destination grant for a different binding was accepted.');
      }
      catch (IntegrationException $exception) {
        self::assertSame('integration.destination_unconfirmed', $exception->errorCode);
      }
    }
    $profiles = new IntegrationCredentialProfiles($this->getConfigFactoryStub([
      'beantragt_delivery.settings' => ['credential_profiles' => ['synthetic' => $this->profile()]],
    ]));
    $this->expectException(IntegrationException::class);
    $this->expectExceptionMessage('integration.destination_unconfirmed');
    $profiles->settings($binding);
  }

  /**
   * Confirmed settings use only operator credentials and the frozen binding.
   */
  public function testBoundSettingsIgnoreGlobalDestinationAndGuardTestEnvironment(): void {
    $previous = getenv('SYNTHETIC_NONEXISTENT_SECRET');
    putenv('SYNTHETIC_NONEXISTENT_SECRET=synthetic-secret-only');
    try {
      $profile = $this->profile();
      $profile['confirmed_destinations'] = [$this->grant()];
      $profiles = new IntegrationCredentialProfiles($this->getConfigFactoryStub([
        'beantragt_delivery.settings' => [
          'fit_connect' => [
            'client_id' => 'global-client-must-not-be-used',
            'destination_id' => 'global-destination-must-not-be-used',
            'service_urn' => 'urn:global:must-not-be-used',
          ],
          'credential_profiles' => ['synthetic' => $profile],
        ],
      ]));
      $settings = $profiles->settings($this->binding());
      self::assertSame('synthetic-client', $settings->clientId);
      self::assertSame('synthetic-secret-only', $settings->clientSecret);
      self::assertSame($this->grant()['destination_id'], $settings->destinationId);
      self::assertSame('urn:synthetic:service', $settings->serviceUrn);
      self::assertSame('urn:synthetic:schema', $settings->schemaUri);
      self::assertSame(FitConnectSettings::DEFAULT_TEST_TOKEN_URL, $settings->tokenUrl);
      self::assertSame(FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE, $settings->submissionApiBase);
      $binding = $this->binding();
      $binding['connection_snapshot']['environment'] = 'production';
      try {
        $profiles->settings($binding);
        self::fail('A confirmed destination must still require TEST transport.');
      }
      catch (FitConnectTransportException $exception) {
        self::assertSame('fit_connect.test_only', $exception->errorCode);
      }
      $binding = $this->binding();
      $binding['scope_group_id'] = 8;
      try {
        $profiles->settings($binding);
        self::fail('A different tenant must not inherit profile access.');
      }
      catch (IntegrationException $exception) {
        self::assertSame('integration.credential_profile_not_found', $exception->errorCode);
      }
    }
    finally {
      putenv($previous === FALSE ? 'SYNTHETIC_NONEXISTENT_SECRET' : 'SYNTHETIC_NONEXISTENT_SECRET=' . $previous);
    }
  }

  /**
   * Builds a synthetic non-secret frozen binding.
   */
  private function binding(): array {
    return [
      'scope_group_id' => 7,
      'service_urn' => 'urn:synthetic:service',
      'contract_key' => 'synthetic',
      'contract_version' => '1.0',
      'connection_snapshot' => [
        'credential_profile' => 'synthetic',
        'destination_id' => '11111111-1111-4111-8111-111111111111',
        'environment' => 'test',
        'schema_uri' => 'urn:synthetic:schema',
      ],
    ];
  }

  /**
   * Builds an explicit operator confirmation without real transport access.
   */
  private function grant(): array {
    return [
      'scope_group_id' => 7,
      'destination_id' => '11111111-1111-4111-8111-111111111111',
      'service_urn' => 'urn:synthetic:service',
      'contract_key' => 'synthetic',
      'contract_version' => '1.0',
    ];
  }

  /**
   * Contains only synthetic references; the service must never read the secret.
   */
  private function profile(): array {
    return [
      'label' => 'Synthetic profile',
      'allowed_scopes' => [7],
      'all_scopes' => FALSE,
      'client_id' => 'synthetic-client',
      'client_secret_env' => 'SYNTHETIC_NONEXISTENT_SECRET',
      'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
      'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      'metadata_schema_version' => '2.1.0',
    ];
  }

}
