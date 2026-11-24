<?php

/**
 * @file
 * Container runtime settings for beantragt.ai.
 */

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;

$app_root = dirname(dirname(__DIR__));
$site_path = 'sites/default';
$site_identifier = getenv('SITE_IDENTIFIER') ?: 'beantragt';
$environment = strtolower(trim((string) (getenv('BEANTRAGT_ENV') ?: 'production')));
$is_production = !in_array($environment, ['dev', 'local', 'ddev', 'development'], TRUE);

$hash_salt = getenv('DRUPAL_HASH_SALT');
if ($hash_salt === FALSE || trim($hash_salt) === '') {
  throw new RuntimeException('DRUPAL_HASH_SALT must be set for beantragt.ai container runtime.');
}

$settings['hash_salt'] = $hash_salt;
$settings['site_identifier'] = $site_identifier;
$settings['config_sync_directory'] = getenv('CONFIG_SYNC_DIR') ?: '../config/sync';
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = '../private/' . $site_identifier;
$settings['file_temp_path'] = '/tmp/drupal';

if (file_exists($app_root . '/' . $site_path . '/services.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
}

$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127\.0\.0\.1$',
  '^nginx$',
  '^.+-nginx-\d+$',
];

$plain_hostname_pattern = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/';
$add_trusted_hosts = static function (?string $hosts, string $env_name) use (&$settings, $plain_hostname_pattern): int {
  $added = 0;
  if (!$hosts) {
    return $added;
  }
  foreach (explode(',', $hosts) as $host) {
    $host = trim($host);
    if ($host !== '') {
      if (!preg_match($plain_hostname_pattern, $host)) {
        throw new RuntimeException(sprintf('%s contains invalid host "%s"; use plain hostnames without scheme, port, path, or wildcards.', $env_name, $host));
      }
      $settings['trusted_host_patterns'][] = '^' . preg_quote($host, '/') . '$';
      $added++;
    }
  }
  return $added;
};

$external_trusted_host_count = 0;
$external_trusted_host_count += $add_trusted_hosts(getenv('DRUPAL_TRUSTED_HOST') ?: NULL, 'DRUPAL_TRUSTED_HOST');
$external_trusted_host_count += $add_trusted_hosts(getenv('FRONTEND_HOSTS') ?: NULL, 'FRONTEND_HOSTS');
$trust_sslip = getenv('DRUPAL_TRUST_SSLIP') === 'true';
if ($is_production && $trust_sslip) {
  throw new RuntimeException('DRUPAL_TRUST_SSLIP must not be true in production.');
}
if ($trust_sslip) {
  $settings['trusted_host_patterns'][] = '\.sslip\.io$';
}
if ($is_production && $external_trusted_host_count === 0) {
  throw new RuntimeException('DRUPAL_TRUSTED_HOST or FRONTEND_HOSTS must be set for production.');
}

$databases['default']['default'] = [
  'database' => getenv('DB_NAME') ?: 'drupal',
  'username' => getenv('DB_USER') ?: 'drupal',
  'password' => getenv('DB_PASSWORD') ?: '',
  'host' => getenv('DB_HOST') ?: 'localhost',
  'port' => getenv('DB_PORT') ?: '3306',
  'driver' => 'mysql',
  'prefix' => '',
  'charset' => 'utf8mb4',
  'collation' => 'utf8mb4_unicode_ci',
];

$reverse_proxy_addresses = array_values(array_filter(array_map('trim', explode(',', getenv('DRUPAL_REVERSE_PROXY_ADDRESSES') ?: ''))));
if ($reverse_proxy_addresses !== []) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = $reverse_proxy_addresses;
  $settings['reverse_proxy_trusted_headers'] =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO;
}

if ($cookieDomain = getenv('COOKIE_DOMAIN')) {
  $settings['cookie_domain'] = $cookieDomain;
}

$error_level = getenv('DRUPAL_ERROR_LEVEL') ?: 'hide';
if ($is_production && $error_level !== 'hide') {
  throw new RuntimeException('DRUPAL_ERROR_LEVEL must be hide or unset in production.');
}
$config['system.logging']['error_level'] = $error_level;
$settings['update_free_access'] = FALSE;
$settings['rebuild_access'] = FALSE;
$settings['file_chmod_directory'] = 0755;
$settings['file_chmod_file'] = 0644;

$config['system.performance']['css']['preprocess'] = TRUE;
$config['system.performance']['js']['preprocess'] = TRUE;
$config['system.performance']['cache']['page']['max_age'] = 300;
// Cap page_cache lifetime of 4xx responses (Core default 3600 s).
$settings['cache_ttl_4xx'] = 60;

// Only operator-supplied plain hostnames may authorize webhook destinations.
$webhook_hosts = getenv('BEANTRAGT_WEBHOOK_ALLOWED_HOSTS');
if ($webhook_hosts !== FALSE) {
  $config['beantragt_delivery.settings']['webhook']['allowed_hosts'] = array_values(array_filter(
    array_map('trim', explode(',', $webhook_hosts)),
    static fn (string $host): bool => preg_match($plain_hostname_pattern, $host) === 1
      && filter_var($host, FILTER_VALIDATE_IP) === FALSE,
  ));
}

$smtp_from = trim((string) (getenv('SMTP_FROM') ?: getenv('DRUPAL_SITE_MAIL') ?: ''));
if ($is_production && $smtp_from === '') {
  throw new RuntimeException('SMTP_FROM or DRUPAL_SITE_MAIL must be set for production.');
}
if ($smtp_from !== '' && !filter_var($smtp_from, FILTER_VALIDATE_EMAIL)) {
  throw new RuntimeException('SMTP_FROM must be a valid email address when set.');
}
if ($smtp_from !== '') {
  $config['system.site']['mail'] = $smtp_from;
  $config['system.site']['mail_notification'] = $smtp_from;
}

// Mail delivery is intentionally delegated to PHP's sendmail path. The image
// configures that path through msmtp, which is Mailpit-only by default and
// permits SMTP solely through the explicit BEANTRAGT_MAIL_MODE=smtp contract.
// Do not allow an active Symfony Mailer DSN to bypass that container boundary.
$mail_mode = strtolower(trim((string) (getenv('BEANTRAGT_MAIL_MODE') ?: 'mailpit')));
if (!in_array($mail_mode, ['mailpit', 'smtp'], TRUE)) {
  throw new RuntimeException("BEANTRAGT_MAIL_MODE must be 'mailpit' or 'smtp'.");
}
$config['system.mail']['interface']['default'] = 'php_mail';
$config['system.mail']['mailer_dsn'] = [
  'scheme' => 'sendmail',
  'host' => 'default',
  'user' => NULL,
  'password' => NULL,
  'port' => NULL,
  'options' => [],
];

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('session.cookie_lifetime', '7200');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
