<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

use Drupal\beantragt_group\Service\NotificationTemplateCatalogInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Catalogue of valid notification mail templates and code defaults.
 */
final class NotificationTemplateCatalog implements NotificationTemplateCatalogInterface {

  /**
   * Templates that must stay enabled.
   */
  private const LOCKED_ENABLED = ['application_confirmation_request'];

  /**
   * Cached catalogue definitions.
   *
   * @var array<string, array{subject: string, body: string, enabled: bool, requiredTokens: list<string>, lockedEnabled: bool}>|null
   */
  private ?array $definitions = NULL;

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    if ($this->definitions !== NULL) {
      return $this->definitions;
    }

    $mail = $this->readInstallConfig('beantragt_notification.mail.yml');
    $settings = $this->readInstallConfig('beantragt_notification.settings.yml');
    $enabled_templates = is_array($settings['enabled_templates'] ?? NULL)
      ? $settings['enabled_templates']
      : [];

    $definitions = [];
    foreach ($mail as $key => $template) {
      if (!is_array($template)) {
        continue;
      }

      $subject = (string) ($template['subject'] ?? '');
      $body = (string) ($template['body'] ?? '');
      $locked_enabled = in_array($key, self::LOCKED_ENABLED, TRUE);
      $definitions[$key] = [
        'subject' => $subject,
        'body' => $body,
        'enabled' => $locked_enabled || !empty($enabled_templates[$key]),
        'requiredTokens' => $this->extractTokens($subject . "\n" . $body),
        'lockedEnabled' => $locked_enabled,
      ];
    }

    return $this->definitions = $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function keys(): array {
    return array_keys($this->all());
  }

  /**
   * {@inheritdoc}
   */
  public function has(string $templateKey): bool {
    return array_key_exists($templateKey, $this->all());
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $templateKey): ?array {
    $definitions = $this->all();
    return $definitions[$templateKey] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function requiredTokens(string $templateKey): array {
    return $this->get($templateKey)['requiredTokens'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function isLockedEnabled(string $templateKey): bool {
    return (bool) ($this->get($templateKey)['lockedEnabled'] ?? FALSE);
  }

  /**
   * Reads one install config YAML file from this module.
   *
   * @return array<string, mixed>
   *   Decoded config data.
   */
  private function readInstallConfig(string $filename): array {
    $path = DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('beantragt_notification') . '/config/install/' . $filename;
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException(sprintf('Unable to read notification install config: %s', $filename));
    }

    $decoded = Yaml::decode($contents);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Extracts token placeholders from template text.
   *
   * @return list<string>
   *   Unique tokens in first-seen order.
   */
  private function extractTokens(string $text): array {
    preg_match_all('/@[a-z][a-z0-9_]*/i', $text, $matches);
    $tokens = [];
    foreach ($matches[0] as $token) {
      if (!in_array($token, $tokens, TRUE)) {
        $tokens[] = $token;
      }
    }
    return $tokens;
  }

}
