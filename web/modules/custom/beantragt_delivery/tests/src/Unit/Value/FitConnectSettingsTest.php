<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Value;

use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;

/**
 * Tests FIT-Connect settings normalization.
 *
 * @group beantragt_delivery
 */
final class FitConnectSettingsTest extends UnitTestCase {

  /**
   * Environment fallbacks make TEST settings complete without storing secrets.
   */
  public function testEnvironmentFallbacksCompleteSettings(): void {
    $env_snapshot = $this->snapshotFitConnectEnv();
    $this->clearFitConnectEnv();
    putenv('FIT_CONNECT_CLIENT_ID=sender-client');
    putenv('FIT_CONNECT_CLIENT_SECRET=super-secret');
    putenv('FIT_CONNECT_DESTINATION_ID=destination-123');
    putenv('FIT_CONNECT_SERVICE_URN=urn:de:fim:leika:leistung:512431');
    putenv('FIT_CONNECT_ARS=000000000000');
    putenv('FIT_CONNECT_SCHEMA_URI=urn:beantragt:dev:musterantrag-bescheid:v1');

    try {
      $settings = FitConnectSettings::fromConfig($this->configFactory([
        'environment' => 'TEST',
        'client_secret_env' => 'FIT_CONNECT_CLIENT_SECRET',
      ]));

      $this->assertSame([], $settings->missingForSubmission());
      $this->assertSame('sender-client', $settings->clientId);
      $this->assertSame('super-secret', $settings->clientSecret);
      $this->assertSame(FitConnectSettings::DEFAULT_TEST_TOKEN_URL, $settings->tokenUrl);
      $this->assertSame(FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE, $settings->submissionApiBase);

      $safe = $settings->toSafeMetadata();
      $this->assertTrue($safe['clientConfigured']);
      $this->assertTrue($safe['clientSecretConfigured']);
      $this->assertTrue($safe['clientSecretEnvConfigured']);
      $this->assertArrayNotHasKey('clientSecret', $safe);
      $this->assertArrayNotHasKey('clientSecretEnv', $safe);
      $this->assertNotContains('super-secret', $safe);
    }
    finally {
      $this->restoreFitConnectEnv($env_snapshot);
    }
  }

  /**
   * Pasted secrets in the env-name config are not reflected in safe metadata.
   */
  public function testInvalidClientSecretEnvDoesNotLeak(): void {
    $env_snapshot = $this->snapshotFitConnectEnv();
    $this->clearFitConnectEnv();

    try {
      $settings = FitConnectSettings::fromConfig($this->configFactory([
        'client_secret_env' => 'pasted-secret-value',
      ]));

      $this->assertSame('FIT_CONNECT_CLIENT_SECRET', $settings->clientSecretEnv);
      $safe = $settings->toSafeMetadata();
      $this->assertArrayNotHasKey('clientSecretEnv', $safe);
      $this->assertNotContains('pasted-secret-value', $safe);
    }
    finally {
      $this->restoreFitConnectEnv($env_snapshot);
    }
  }

  /**
   * Missing transport values are explicit and secret-safe.
   */
  public function testMissingValuesAreExplicit(): void {
    $env_snapshot = $this->snapshotFitConnectEnv();
    $this->clearFitConnectEnv();

    try {
      $settings = FitConnectSettings::fromConfig($this->configFactory([]));

      $this->assertSame([
        'client_id',
        'client_secret',
        'service_urn',
        'ars',
        'schema_uri',
        'destination_id',
      ], $settings->missingForSubmission());
      $this->assertSame('FIT_CONNECT_CLIENT_SECRET', $settings->clientSecretEnv);
    }
    finally {
      $this->restoreFitConnectEnv($env_snapshot);
    }
  }

  /**
   * Builds a config factory mock.
   *
   * @param array<string, mixed> $fit_connect
   *   FIT-Connect config.
   */
  private function configFactory(array $fit_connect): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn(string $key): mixed => $key === 'fit_connect' ? $fit_connect : NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('beantragt_delivery.settings')->willReturn($config);
    return $factory;
  }

  /**
   * Clears FIT-Connect env vars used by these tests.
   */
  private function clearFitConnectEnv(): void {
    foreach ($this->fitConnectEnvNames() as $name) {
      putenv($name);
    }
  }

  /**
   * Returns FIT-Connect env names used by settings.
   *
   * @return string[]
   *   Environment variable names.
   */
  private function fitConnectEnvNames(): array {
    return [
      'FIT_CONNECT_CLIENT_ID',
      'FIT_CONNECT_CLIENT_SECRET',
      'FIT_CONNECT_DESTINATION_ID',
      'FIT_CONNECT_SERVICE_URN',
      'FIT_CONNECT_ARS',
      'FIT_CONNECT_SCHEMA_URI',
    ];
  }

  /**
   * Captures current FIT-Connect environment values.
   *
   * @return array<string, string|false>
   *   Environment snapshot.
   */
  private function snapshotFitConnectEnv(): array {
    $snapshot = [];
    foreach ($this->fitConnectEnvNames() as $name) {
      $snapshot[$name] = getenv($name);
    }
    return $snapshot;
  }

  /**
   * Restores captured FIT-Connect environment values.
   *
   * @param array<string, string|false> $snapshot
   *   Environment snapshot.
   */
  private function restoreFitConnectEnv(array $snapshot): void {
    foreach ($snapshot as $name => $value) {
      if ($value === FALSE) {
        putenv($name);
        continue;
      }
      putenv($name . '=' . $value);
    }
  }

}
