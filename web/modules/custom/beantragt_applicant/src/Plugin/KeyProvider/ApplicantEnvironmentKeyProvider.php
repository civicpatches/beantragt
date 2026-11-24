<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant\Plugin\KeyProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyProviderBase;

/**
 * Derives the applicant field-encryption key from deployment key material.
 *
 * @KeyProvider(
 *   id = "beantragt_applicant_env",
 *   label = @Translation("Beantragt applicant environment"),
 *   description = @Translation("Derives a dedicated applicant field-encryption key from an environment variable."),
 *   tags = {
 *     "env",
 *     "beantragt",
 *   },
 *   key_value = {
 *     "accepted" = FALSE,
 *     "required" = FALSE
 *   }
 * )
 */
final class ApplicantEnvironmentKeyProvider extends KeyProviderBase implements KeyPluginFormInterface {

  private const DEFAULT_ENV_VARIABLE = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';
  private const DERIVATION_CONTEXT = 'beantragt_applicant_field_encrypt_v1';
  private const MINIMUM_KEY_MATERIAL_BYTES = 32;
  private const DERIVED_KEY_BYTES = 32;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'env_variable' => self::DEFAULT_ENV_VARIABLE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['env_variable'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Environment variable'),
      '#description' => $this->t('Name of the environment variable containing at least 32 bytes of deployment key material. The value itself is never stored in Drupal configuration.'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['env_variable'] ?? self::DEFAULT_ENV_VARIABLE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $env_variable = trim((string) $form_state->getValue('env_variable'));
    $raw_key = getenv($env_variable);
    if (!is_string($raw_key) || strlen($raw_key) < self::MINIMUM_KEY_MATERIAL_BYTES) {
      $form_state->setErrorByName('env_variable', $this->t('The environment variable must contain at least 32 bytes of key material.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->setConfiguration([
      'env_variable' => trim((string) $form_state->getValue('env_variable')),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key): ?string {
    $env_variable = $this->configuration['env_variable'] ?? self::DEFAULT_ENV_VARIABLE;
    $raw_key = getenv($env_variable);
    if (!is_string($raw_key) || strlen($raw_key) < self::MINIMUM_KEY_MATERIAL_BYTES) {
      return NULL;
    }

    return hash_hkdf(
      'sha256',
      $raw_key,
      self::DERIVED_KEY_BYTES,
      self::DERIVATION_CONTEXT,
    );
  }

}
