<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\beantragt_group\Service\NotificationTemplateCatalogInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;

/**
 * Resolves notification mail templates with jurisdiction-aware fallbacks.
 */
final class NotificationTemplateResolver {

  public function __construct(
    private readonly NotificationTemplateCatalogInterface $catalog,
    private readonly JurisdictionConfigServiceInterface $jurisdictionConfig,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly LanguageConfigFactoryOverrideInterface $languageConfigOverride,
  ) {}

  /**
   * Resolves one notification template for a jurisdiction and language.
   *
   * @return array{subject: string, body: string, enabled: bool}
   *   The resolved template text and enabled state.
   */
  public function resolve(?GroupInterface $jurisdiction, string $templateKey, string $langcode): array {
    $definition = $this->catalog->get($templateKey);
    if ($definition === NULL) {
      throw new \InvalidArgumentException(sprintf('Unknown notification template key: %s', $templateKey));
    }

    $jurisdiction_template = $this->jurisdictionTemplate($jurisdiction, $templateKey);
    $site_language_template = $this->siteLanguageTemplate($templateKey, $langcode);
    $site_default_template = $this->siteDefaultLanguageTemplate($templateKey);
    $site_base_template = $this->siteBaseTemplate($templateKey);

    return [
      'subject' => $this->resolveTextField(
        'subject',
        $this->jurisdictionLanguageTemplate($jurisdiction_template, $langcode),
        $this->jurisdictionDefaultTemplate($jurisdiction_template),
        $site_language_template,
        $site_default_template,
        $site_base_template,
        $definition,
      ),
      'body' => $this->resolveTextField(
        'body',
        $this->jurisdictionLanguageTemplate($jurisdiction_template, $langcode),
        $this->jurisdictionDefaultTemplate($jurisdiction_template),
        $site_language_template,
        $site_default_template,
        $site_base_template,
        $definition,
      ),
      'enabled' => $this->resolveEnabled($jurisdiction_template, $templateKey, $definition),
    ];
  }

  /**
   * Returns the stored jurisdiction template entry, if any.
   *
   * @return array<string, mixed>|null
   *   Stored template entry.
   */
  private function jurisdictionTemplate(?GroupInterface $jurisdiction, string $templateKey): ?array {
    if (!$jurisdiction instanceof GroupInterface) {
      return NULL;
    }

    $section = $this->jurisdictionConfig->getSection($jurisdiction, 'notification_mail');
    $templates = $section['templates'] ?? NULL;
    if (!is_array($templates)) {
      return NULL;
    }

    $template = $templates[$templateKey] ?? NULL;
    return is_array($template) ? $template : NULL;
  }

  /**
   * Returns the jurisdiction language-specific template entry, if any.
   *
   * @param array<string, mixed>|null $template
   *   Stored jurisdiction template entry.
   * @param string $langcode
   *   Requested language code.
   *
   * @return array<string, mixed>|null
   *   Language-specific text entry.
   */
  private function jurisdictionLanguageTemplate(?array $template, string $langcode): ?array {
    $translations = $template['translations'] ?? NULL;
    if (!is_array($translations)) {
      return NULL;
    }

    $translation = $translations[$langcode] ?? NULL;
    return is_array($translation) ? $translation : NULL;
  }

  /**
   * Returns the jurisdiction default template entry, if any.
   *
   * @param array<string, mixed>|null $template
   *   Stored jurisdiction template entry.
   *
   * @return array<string, mixed>|null
   *   Default text entry.
   */
  private function jurisdictionDefaultTemplate(?array $template): ?array {
    $default = $template['default'] ?? NULL;
    return is_array($default) ? $default : NULL;
  }

  /**
   * Returns the requested site language override for one template.
   *
   * @return array<string, mixed>|null
   *   Template text entry.
   */
  private function siteLanguageTemplate(string $templateKey, string $langcode): ?array {
    $override = $this->languageConfigOverride
      ->getOverride($langcode, 'beantragt_notification.mail')
      ->get($templateKey);

    return is_array($override) ? $override : NULL;
  }

  /**
   * Returns the site default language override for one template.
   *
   * @return array<string, mixed>|null
   *   Template text entry.
   */
  private function siteDefaultLanguageTemplate(string $templateKey): ?array {
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $override = $this->languageConfigOverride
      ->getOverride($langcode, 'beantragt_notification.mail')
      ->get($templateKey);

    return is_array($override) ? $override : NULL;
  }

  /**
   * Returns the site base config template without language overrides.
   *
   * @return array<string, mixed>|null
   *   Template text entry.
   */
  private function siteBaseTemplate(string $templateKey): ?array {
    $template = $this->configFactory
      ->getEditable('beantragt_notification.mail')
      ->get($templateKey);

    return is_array($template) ? $template : NULL;
  }

  /**
   * Resolves one text field through the configured fallback chain.
   *
   * @param string $field
   *   The text field to resolve.
   * @param array<string, mixed>|null ...$sources
   *   Candidate template entries.
   */
  private function resolveTextField(string $field, ?array ...$sources): string {
    foreach ($sources as $source) {
      if (is_array($source) && array_key_exists($field, $source) && is_string($source[$field]) && trim($source[$field]) !== '') {
        return $source[$field];
      }
    }
    return '';
  }

  /**
   * Resolves the enabled flag.
   *
   * @param array<string, mixed>|null $jurisdictionTemplate
   *   Stored jurisdiction template entry.
   * @param string $templateKey
   *   Notification template key.
   * @param array{subject: string, body: string, enabled: bool, requiredTokens: list<string>, lockedEnabled: bool} $definition
   *   Catalogue definition.
   */
  private function resolveEnabled(?array $jurisdictionTemplate, string $templateKey, array $definition): bool {
    if ($this->catalog->isLockedEnabled($templateKey)) {
      return TRUE;
    }

    if (is_array($jurisdictionTemplate) && is_bool($jurisdictionTemplate['enabled'] ?? NULL)) {
      return $jurisdictionTemplate['enabled'];
    }

    $site_enabled = $this->configFactory
      ->get('beantragt_notification.settings')
      ->get('enabled_templates.' . $templateKey);
    if ($site_enabled !== NULL) {
      return (bool) $site_enabled;
    }

    return $definition['enabled'];
  }

}
