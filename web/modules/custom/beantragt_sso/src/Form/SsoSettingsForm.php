<?php

declare(strict_types=1);

namespace Drupal\beantragt_sso\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures the default SSO provider.
 */
final class SsoSettingsForm extends ConfigFormBase {

  /**
   * Attribute defaults for newly created providers.
   */
  private const DEFAULT_ATTRIBUTE_MAP = [
    'email' => [
      'mail',
      'email',
      'urn:oid:0.9.2342.19200300.100.1.3',
    ],
    'first_name' => [
      'givenName',
      'urn:oid:2.5.4.42',
    ],
    'last_name' => [
      'sn',
      'surname',
      'urn:oid:2.5.4.4',
    ],
    'full_name' => [
      'cn',
      'displayName',
      'urn:oid:2.5.4.3',
    ],
    'birthdate' => [
      'dateOfBirth',
      'birthdate',
    ],
    'assurance_level' => [
      'assuranceLevel',
      'loa',
      'acr',
    ],
  ];

  /**
   * Security defaults for newly created providers.
   */
  private const DEFAULT_SECURITY = [
    'authn_requests_signed' => TRUE,
    'want_assertions_signed' => TRUE,
    'want_messages_signed' => FALSE,
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'beantragt_sso_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['beantragt_sso.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('beantragt_sso.settings');
    $provider_id = (string) ($config->get('default_provider') ?: 'bundid');
    $provider = $config->get("providers.$provider_id");
    $provider = is_array($provider) ? $provider : [];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $provider['label'] ?? 'BundID',
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    $form['default_provider'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Provider ID'),
      '#default_value' => $provider_id,
      '#machine_name' => [
        'exists' => [$this, 'providerExists'],
        'source' => ['label'],
      ],
      '#description' => $this->t('Machine name for this provider, for example bundid or adfs. This form edits the default provider; additional providers are managed in configuration.'),
      '#required' => TRUE,
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable provider'),
      '#default_value' => (bool) ($provider['enabled'] ?? FALSE),
    ];

    $form['profile'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider profile'),
      '#options' => [
        'bundid' => $this->t('BundID'),
        'bundid_mock' => $this->t('BundID demo mock'),
        'adfs' => $this->t('ADFS'),
        'generic' => $this->t('Generic SAML'),
      ],
      '#default_value' => $provider['profile'] ?? 'bundid',
    ];

    $form['account'] = [
      '#type' => 'details',
      '#title' => $this->t('Account linking'),
      '#open' => TRUE,
    ];
    $form['account']['create_users'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create users automatically'),
      '#default_value' => (bool) ($provider['create_users'] ?? TRUE),
    ];
    $form['account']['link_by_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Link existing users by verified email'),
      '#default_value' => (bool) ($provider['link_by_email'] ?? FALSE),
      '#description' => $this->t('Only enable this when the IdP is authoritative for the submitted email address.'),
    ];
    $form['account']['email_verified'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Provider verifies the email address'),
      '#default_value' => (bool) ($provider['email_verified'] ?? FALSE),
      '#description' => $this->t('Only enable this when the IdP has verified the mailbox of the released email address (for example BundID). Applicant profiles under that address are then matched and taken over on login.'),
    ];

    $form['sp'] = [
      '#type' => 'details',
      '#title' => $this->t('Service provider'),
      '#open' => TRUE,
    ];
    $form['sp']['sp_entity_id'] = [
      '#type' => 'url',
      '#title' => $this->t('SP entity ID'),
      '#default_value' => $provider['sp_entity_id'] ?? '',
      '#description' => $this->t('Leave empty to use the metadata URL.'),
    ];
    $form['sp']['sp_acs_url'] = [
      '#type' => 'url',
      '#title' => $this->t('ACS URL'),
      '#default_value' => $provider['sp_acs_url'] ?? '',
      '#description' => $this->t('Leave empty to use /auth/sso/{provider}/acs on this Drupal host. If you use the Nuxt origin, the edge proxy must route /auth/sso/{provider}/acs directly to Drupal.'),
    ];
    $form['sp']['sp_x509_cert'] = [
      '#type' => 'textarea',
      '#title' => $this->t('SP X.509 certificate'),
      '#default_value' => $provider['sp_x509_cert'] ?? '',
      '#rows' => 8,
      '#description' => $this->t('PEM certificate used for signed AuthnRequests and metadata.'),
    ];
    $form['sp']['sp_private_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SP private key path'),
      '#default_value' => $provider['sp_private_key_path'] ?? '',
      '#description' => $this->t('Absolute PEM key path, or BEANTRAGT_SSO_{PROVIDER}_SP_PRIVATE_KEY.'),
    ];

    $form['idp'] = [
      '#type' => 'details',
      '#title' => $this->t('Identity provider'),
      '#open' => TRUE,
    ];
    $form['idp']['idp_entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IdP entity ID'),
      '#default_value' => $provider['idp_entity_id'] ?? '',
      '#required' => FALSE,
    ];
    $form['idp']['idp_sso_url'] = [
      '#type' => 'url',
      '#title' => $this->t('IdP SSO URL'),
      '#default_value' => $provider['idp_sso_url'] ?? '',
      '#required' => FALSE,
    ];
    $form['idp']['idp_x509_cert'] = [
      '#type' => 'textarea',
      '#title' => $this->t('IdP X.509 certificate'),
      '#default_value' => $provider['idp_x509_cert'] ?? '',
      '#rows' => 8,
    ];
    $form['idp']['name_id_format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NameID format'),
      '#default_value' => $provider['name_id_format']
      ?? 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
      '#required' => TRUE,
    ];
    $form['idp']['minimum_assurance_level'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Minimum assurance level'),
      '#default_value' => $provider['minimum_assurance_level'] ?? '',
      '#description' => $this->t(
          'Optional. When set, login fails unless the mapped assurance_level claim meets or equals this value.'
      ),
    ];

    $form['relay'] = [
      '#type' => 'details',
      '#title' => $this->t('RelayState'),
      '#open' => FALSE,
    ];
    $form['relay']['default_relay_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default return path'),
      '#default_value' => $provider['default_relay_path'] ?? '/',
      '#required' => TRUE,
    ];
    $form['relay']['allowed_relay_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed return hosts'),
      '#default_value' => implode(
          "\n",
          array_filter(array_map('strval', $provider['allowed_relay_hosts'] ?? []))
      ),
      '#description' => $this->t('One host per line. Use host:port for a separate development frontend port.'),
      '#rows' => 4,
    ];

    $security = is_array($provider['security'] ?? NULL) ? $provider['security'] : [];
    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Security'),
      '#open' => FALSE,
    ];
    $form['security']['authn_requests_signed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sign AuthnRequests'),
      '#default_value' => (bool) ($security['authn_requests_signed'] ?? TRUE),
    ];
    $form['security']['want_assertions_signed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require signed assertions'),
      '#default_value' => (bool) ($security['want_assertions_signed'] ?? TRUE),
    ];
    // BSI TR-03130 requires signed SAML responses for the real BundID profile
    // ("hoch"), so default that profile to require signed messages. Generic and
    // mock profiles keep the relaxed default.
    $messages_signed_default = ($provider['profile'] ?? '') === 'bundid';
    $form['security']['want_messages_signed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require signed messages'),
      '#default_value' => (bool) ($security['want_messages_signed'] ?? $messages_signed_default),
      '#description' => $this->t('Required by BSI TR-03130 for BundID at assurance level "hoch".'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Machine-name callback. Existing provider IDs are editable here.
   */
  public function providerExists(string $value): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $path = (string) $form_state->getValue('default_relay_path');
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
      $form_state->setErrorByName(
            'default_relay_path',
            $this->t('The default return path must be a relative path starting with /.')
        );
    }

    $enabled = (bool) $form_state->getValue('enabled');
    if ((bool) $form_state->getValue('link_by_email') && !(bool) $form_state->getValue('email_verified')) {
      $form_state->setErrorByName(
        'link_by_email',
        $this->t('Linking existing users by email requires a provider that verifies the released email address.')
      );
    }
    $is_mock = $form_state->getValue('profile') === 'bundid_mock';
    foreach (['idp_entity_id', 'idp_sso_url', 'idp_x509_cert'] as $key) {
      if ($enabled && !$is_mock && trim((string) $form_state->getValue($key)) === '') {
        $form_state->setErrorByName(
              $key,
              $this->t('@field is required when the provider is enabled.', ['@field' => $key])
          );
      }
    }
    $requires_private_key = $enabled
          && !$is_mock
          && (bool) $form_state->getValue('authn_requests_signed')
          && trim((string) $form_state->getValue('sp_private_key_path')) === '';
    if ($requires_private_key) {
      $provider_id = (string) $form_state->getValue('default_provider');
      $env_suffix = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $provider_id) ?? $provider_id);
      $env_name = 'BEANTRAGT_SSO_' . $env_suffix . '_SP_PRIVATE_KEY';
      $env_value = getenv($env_name);
      if (!is_string($env_value) || trim($env_value) === '') {
        $form_state->setErrorByName(
              'sp_private_key_path',
              $this->t(
                  'SP private key path is required when the provider is enabled, unless @env is set.',
                  ['@env' => $env_name]
              )
          );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('beantragt_sso.settings');
    $provider_id = (string) $form_state->getValue('default_provider');
    $existing = $config->get("providers.$provider_id");
    $existing = is_array($existing) ? $existing : [];

    $hosts = array_values(array_filter(array_map(
          static fn(string $line): string => trim($line),
          preg_split('/\R/', (string) $form_state->getValue('allowed_relay_hosts')) ?: [],
      )));

    $provider = $existing + $this->providerDefaults();
    foreach (
          [
            'label',
            'profile',
            'sp_entity_id',
            'sp_acs_url',
            'sp_x509_cert',
            'sp_private_key_path',
            'idp_entity_id',
            'idp_sso_url',
            'idp_x509_cert',
            'name_id_format',
            'minimum_assurance_level',
            'default_relay_path',
          ] as $key
      ) {
      $provider[$key] = $form_state->getValue($key);
    }
    foreach (['enabled', 'create_users', 'link_by_email', 'email_verified'] as $key) {
      $provider[$key] = (bool) $form_state->getValue($key);
    }
    $provider['allowed_relay_hosts'] = $hosts;
    $provider['security'] = [
      'authn_requests_signed' => (bool) $form_state->getValue('authn_requests_signed'),
      'want_assertions_signed' => (bool) $form_state->getValue('want_assertions_signed'),
      'want_messages_signed' => (bool) $form_state->getValue('want_messages_signed'),
    ];

    $config
      ->set('default_provider', $provider_id)
      ->set("providers.$provider_id", $provider)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns safe local defaults for a new provider config entry.
   *
   * @return array<string, mixed>
   *   Provider defaults.
   */
  private function providerDefaults(): array {
    return [
      'attribute_map' => self::DEFAULT_ATTRIBUTE_MAP,
      'security' => self::DEFAULT_SECURITY,
    ];
  }

}
