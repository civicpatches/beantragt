<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

/**
 * Validates the complete jurisdiction configuration stored by Drupal admins.
 *
 * The Settings API validates one section at a time. The native Drupal
 * backstop exposes the persisted JSON field, so it needs the same allowlists
 * and value checks at the entity boundary.
 */
final class JurisdictionConfigBlobValidator {

  /**
   * Maximum complete JSON payload, including the boundary and inline logo.
   */
  private const MAX_BYTES = 3 * 1024 * 1024;

  /**
   * Allowed top-level storage sections and their keys.
   */
  private const SECTION_KEYS = [
    'general' => ['contactEmail', 'website', 'phone'],
    'theme' => ['primary', 'secondary', 'neutral', 'logoDataUrl', 'logoDarkDataUrl', 'logoAlt'],
    'languages' => ['default', 'available'],
    'retention' => ['dataRetentionDays'],
    'resubmission' => ['reminderDays', 'reminderScope'],
    'delegation' => ['noteRequired', 'personalAssignment'],
    'applicant_profile' => ['enabled', 'scope', 'bankConsent'],
    'embed' => ['defaultTheme', 'defaultWidth', 'defaultHeight', 'showBorder'],
    'uploads' => ['maxStorageBytes', 'warningThresholdPercent'],
    'notification_mail' => ['templates'],
    'boundary' => ['geojson'],
    'assistant' => ['tone', 'verbosity', 'acknowledgementStyle', 'additionalInstructions'],
  ];

  /**
   * Optional module-owned section validators, keyed by section name.
   *
   * @var \Drupal\beantragt_group\Service\JurisdictionConfigSectionValidatorInterface[]
   */
  private array $sectionValidators = [];

  /**
   * Registers an optional module's validator via the service collector.
   */
  public function addSectionValidator(JurisdictionConfigSectionValidatorInterface $validator): void {
    $this->sectionValidators[$validator->sectionName()] = $validator;
  }

  public function __construct(
    private readonly JurisdictionConfigValidator $valueValidator,
    private readonly ?NotificationTemplateCatalogInterface $notificationCatalog = NULL,
  ) {}

  /**
   * Validates a raw JSON field value.
   *
   * @return list<string>
   *   Validation errors. An empty list means the value is valid.
   */
  public function validateRaw(mixed $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    if (!is_string($raw)) {
      return ['The jurisdiction configuration must be a JSON object.'];
    }
    if (strlen($raw) > self::MAX_BYTES) {
      return ['The jurisdiction configuration exceeds the 3 MB limit.'];
    }
    if (!str_starts_with(ltrim($raw), '{')) {
      return ['The jurisdiction configuration must be a JSON object, not an array or scalar.'];
    }

    try {
      $config = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      return ['Invalid JSON: ' . $exception->getMessage()];
    }

    if (!is_array($config)) {
      return ['The jurisdiction configuration must be a JSON object.'];
    }

    return $this->validateConfig($config);
  }

  /**
   * Validates a decoded complete configuration object.
   *
   * @param array<string, mixed> $config
   *   Complete decoded configuration.
   *
   * @return list<string>
   *   Validation errors.
   */
  private function validateConfig(array $config): array {
    $errors = [];
    foreach ($config as $sectionName => $section) {
      $sectionName = (string) $sectionName;
      if (isset($this->sectionValidators[$sectionName])) {
        $errors = array_merge($errors, is_array($section)
          ? $this->sectionValidators[$sectionName]->validateSection($section)
          : [$sectionName . ' must be a JSON object.']);
        continue;
      }
      if (!array_key_exists($sectionName, self::SECTION_KEYS)) {
        $errors[] = 'Unknown section: ' . $sectionName . '.';
        continue;
      }
      if (!is_array($section)) {
        $errors[] = $sectionName . ' must be a JSON object.';
        continue;
      }

      $unknown_key_errors = $this->unknownKeyErrors($sectionName, $section);
      $errors = array_merge($errors, $unknown_key_errors);
      if ($unknown_key_errors !== []) {
        // Keep collecting section-independent errors, but never pass unknown
        // values into a validator that assumes the allowlisted shape.
        $known = array_intersect_key($section, array_flip(self::SECTION_KEYS[$sectionName]));
      }
      else {
        $known = $section;
      }

      $errors = array_merge($errors, match ($sectionName) {
        'general' => $this->validateGeneral($known),
        'theme' => $this->validateTheme($known),
        'languages' => $this->validateLanguages($known),
        'retention' => $this->validateValues($sectionName, $known, $this->valueValidator->validateRetentionValue(...)),
        'resubmission' => $this->validateValues($sectionName, $known, $this->valueValidator->validateResubmissionValue(...)),
        'delegation' => $this->validateValues($sectionName, $known, $this->valueValidator->validateDelegationValue(...)),
        'applicant_profile' => $this->validateValues($sectionName, $known, $this->valueValidator->validateApplicantProfileValue(...)),
        'embed' => $this->validateValues($sectionName, $known, $this->valueValidator->validateEmbedValue(...)),
        'uploads' => $this->validateValues($sectionName, $known, $this->valueValidator->validateUploadsValue(...)),
        'assistant' => $this->validateValues($sectionName, $known, $this->valueValidator->validateAssistantValue(...)),
        'boundary' => $this->validateBoundary($known),
        'notification_mail' => $this->validateNotificationMail($known),
      });
    }

    return array_values(array_unique($errors));
  }

  /**
   * Returns errors for keys outside the Settings API storage contract.
   */
  private function unknownKeyErrors(string $sectionName, array $section): array {
    $errors = [];
    foreach (array_keys($section) as $key) {
      if (!in_array((string) $key, self::SECTION_KEYS[$sectionName], TRUE)) {
        $errors[] = 'Unknown key: ' . $sectionName . '.' . $key . '.';
      }
    }
    return $errors;
  }

  /**
   * Validates the general JSON subsection.
   */
  private function validateGeneral(array $section): array {
    return $this->validateValues(
      'general',
      $section,
      $this->valueValidator->validateGeneralValue(...),
    );
  }

  /**
   * Validates persisted theme data.
   */
  private function validateTheme(array $section): array {
    $errors = [];
    foreach (['primary', 'secondary', 'neutral'] as $key) {
      if (!array_key_exists($key, $section)) {
        continue;
      }
      $error = $this->valueValidator->validateColorValue($section[$key]);
      if ($error !== NULL) {
        $errors[] = 'theme.' . $key . ': ' . $error;
      }
    }
    if (array_key_exists('logoDataUrl', $section)) {
      $error = $this->valueValidator->validateLogoDataUrl($section['logoDataUrl']);
      if ($error !== NULL) {
        $errors[] = 'theme.logoDataUrl: ' . $error;
      }
    }
    if (array_key_exists('logoDarkDataUrl', $section)) {
      $error = $this->valueValidator->validateLogoDataUrl($section['logoDarkDataUrl'], 'logoDarkDataUrl');
      if ($error !== NULL) {
        $errors[] = 'theme.logoDarkDataUrl: ' . $error;
      }
    }
    if (array_key_exists('logoAlt', $section)) {
      $error = $this->valueValidator->validateLogoAlt($section['logoAlt']);
      if ($error !== NULL) {
        $errors[] = 'theme.logoAlt: ' . $error;
      }
    }
    return $errors;
  }

  /**
   * Validates complete language settings when present.
   */
  private function validateLanguages(array $section): array {
    if ($section === []) {
      return [];
    }
    $error = $this->valueValidator->validateLanguages($section);
    return $error === NULL ? [] : ['languages: ' . $error];
  }

  /**
   * Runs one of the scalar Settings API validators over a section.
   *
   * @param string $sectionName
   *   Persisted configuration section name used in validation errors.
   * @param array<string, mixed> $section
   *   Section values to validate.
   * @param callable(string, mixed): ?string $validator
   *   Scalar validator.
   */
  private function validateValues(string $sectionName, array $section, callable $validator): array {
    $errors = [];
    foreach ($section as $key => $value) {
      $error = $validator((string) $key, $value);
      if ($error !== NULL) {
        $errors[] = $sectionName . '.' . $key . ': ' . $error;
      }
    }
    return $errors;
  }

  /**
   * Validates the persisted boundary wrapper.
   */
  private function validateBoundary(array $section): array {
    $result = BoundaryGeoJsonValidator::validate($section['geojson'] ?? NULL);
    return $result['valid'] ? [] : ['boundary.geojson: ' . $result['error']];
  }

  /**
   * Validates the complete persisted notification template map.
   */
  private function validateNotificationMail(array $section): array {
    if ($section === []) {
      return [];
    }
    $templates = $section['templates'] ?? NULL;
    if (!is_array($templates)) {
      return ['notification_mail.templates must be a JSON object.'];
    }
    if ($templates !== [] && $this->notificationCatalog === NULL) {
      return ['notification_mail.templates cannot be validated while the notification template catalog is unavailable.'];
    }

    $errors = [];
    foreach ($templates as $templateKey => $template) {
      $templateKey = (string) $templateKey;
      $path = 'notification_mail.templates.' . $templateKey;
      if (!$this->notificationCatalog->has($templateKey)) {
        $errors[] = $path . ': unknown template.';
        continue;
      }
      if (!is_array($template)) {
        $errors[] = $path . ' must be a JSON object.';
        continue;
      }

      foreach (array_keys($template) as $key) {
        if (!in_array((string) $key, ['enabled', 'default', 'translations'], TRUE)) {
          $errors[] = $path . '.' . $key . ': unknown key.';
        }
      }
      if (array_key_exists('enabled', $template) && !is_bool($template['enabled'])) {
        $errors[] = $path . '.enabled must be a boolean.';
      }
      if (
        ($template['enabled'] ?? NULL) === FALSE
        && $this->notificationCatalog->isLockedEnabled($templateKey)
      ) {
        $errors[] = $path . '.enabled cannot disable a system template.';
      }

      $errors = array_merge($errors, $this->validateTemplateTextEntry($template['default'] ?? NULL, $path . '.default', TRUE));
      $translations = $template['translations'] ?? [];
      if (!is_array($translations)) {
        $errors[] = $path . '.translations must be a JSON object.';
        continue;
      }
      foreach ($translations as $langcode => $translation) {
        if (!in_array((string) $langcode, JurisdictionConfigValidator::SUPPORTED_LANGUAGES, TRUE)) {
          $errors[] = $path . '.translations.' . $langcode . ': unsupported language.';
          continue;
        }
        $errors = array_merge(
          $errors,
          $this->validateTemplateTextEntry($translation, $path . '.translations.' . $langcode, FALSE),
        );
      }

      $errors = array_merge($errors, $this->validateRequiredTemplateTokens($templateKey, $template));
    }

    return $errors;
  }

  /**
   * Validates one notification subject/body object.
   */
  private function validateTemplateTextEntry(mixed $entry, string $path, bool $optional): array {
    if ($entry === NULL && $optional) {
      return [];
    }
    if (!is_array($entry)) {
      return [$path . ' must be a JSON object.'];
    }

    $errors = [];
    foreach (array_keys($entry) as $key) {
      if (!in_array((string) $key, ['subject', 'body'], TRUE)) {
        $errors[] = $path . '.' . $key . ': unknown key.';
      }
    }
    foreach (['subject' => 500, 'body' => 10000] as $key => $maxLength) {
      if (!array_key_exists($key, $entry)) {
        continue;
      }
      if (!is_string($entry[$key])) {
        $errors[] = $path . '.' . $key . ' must be a string.';
        continue;
      }
      if (mb_strlen($entry[$key]) > $maxLength) {
        $errors[] = $path . '.' . $key . ' exceeds ' . $maxLength . ' characters.';
      }
      if ($entry[$key] !== strip_tags($entry[$key])) {
        $errors[] = $path . '.' . $key . ' must not contain HTML.';
      }
      if ($key === 'subject' && preg_match('/[\r\n]/', $entry[$key]) === 1) {
        $errors[] = $path . '.subject must not contain line breaks.';
      }
    }
    return $errors;
  }

  /**
   * Ensures custom notification text preserves required placeholders.
   */
  private function validateRequiredTemplateTokens(string $templateKey, array $template): array {
    $definition = $this->notificationCatalog?->get($templateKey);
    if ($definition === NULL || $definition['requiredTokens'] === []) {
      return [];
    }

    $default = is_array($template['default'] ?? NULL) ? $template['default'] : [];
    $defaultText = [
      'subject' => is_string($default['subject'] ?? NULL) ? $default['subject'] : $definition['subject'],
      'body' => is_string($default['body'] ?? NULL) ? $default['body'] : $definition['body'],
    ];
    $errors = $this->missingTokenErrors($templateKey, 'default', $defaultText, $definition['requiredTokens']);

    foreach (is_array($template['translations'] ?? NULL) ? $template['translations'] : [] as $langcode => $translation) {
      if (!is_array($translation)) {
        continue;
      }
      $text = [
        'subject' => is_string($translation['subject'] ?? NULL) ? $translation['subject'] : $defaultText['subject'],
        'body' => is_string($translation['body'] ?? NULL) ? $translation['body'] : $defaultText['body'],
      ];
      $errors = array_merge(
        $errors,
        $this->missingTokenErrors($templateKey, 'translations.' . $langcode, $text, $definition['requiredTokens']),
      );
    }
    return $errors;
  }

  /**
   * Returns missing required token errors for one effective template text.
   */
  private function missingTokenErrors(string $templateKey, string $path, array $text, array $tokens): array {
    $errors = [];
    foreach ($tokens as $token) {
      $bodyOnly = $templateKey === 'application_confirmation_request' && $token === '@confirmation_url';
      $haystack = $bodyOnly ? $text['body'] : $text['subject'] . "\n" . $text['body'];
      if (!str_contains($haystack, $token)) {
        $errors[] = 'notification_mail.templates.' . $templateKey . '.' . $path . ' must include ' . $token . '.';
      }
    }
    return $errors;
  }

}
