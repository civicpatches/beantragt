<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Component\Utility\EmailValidatorInterface;

/**
 * Validates jurisdiction settings values for the config-as-content API.
 *
 * Validators are ported from the Mark-a-Spot TenantSettingsController
 * (validateFieldValue, validateColorValue, validateCustomCss) and adapted to
 * the beantragt settings sections. Each method returns a human-readable error
 * message string, or NULL when the value is valid.
 */
class JurisdictionConfigValidator {

  /**
   * Valid Tailwind CSS color palette names.
   *
   * Used to validate theme color values that are not HEX codes.
   */
  public const VALID_TAILWIND_PALETTES = [
    'slate', 'gray', 'zinc', 'neutral', 'stone',
    'mauve', 'olive', 'mist', 'taupe',
    'red', 'orange', 'amber', 'yellow', 'lime',
    'green', 'emerald', 'teal', 'cyan', 'sky',
    'blue', 'indigo', 'violet', 'purple', 'fuchsia',
    'pink', 'rose',
  ];

  /**
   * Locale codes supported by the beantragt frontend.
   */
  public const SUPPORTED_LANGUAGES = ['de', 'en'];

  /**
   * Maximum accepted retention window in days.
   */
  public const MAX_RETENTION_DAYS = 36500;

  /**
   * Maximum accepted tenant upload storage quota in bytes.
   */
  public const MAX_UPLOAD_STORAGE_BYTES = 1099511627776;

  /**
   * Maximum accepted decoded logo payload size.
   */
  public const MAX_LOGO_BYTES = 512000;

  /**
   * Maximum accepted logo data URL length.
   */
  public const MAX_LOGO_DATA_URL_LENGTH = 700000;

  /**
   * Embed themes supported by the public form route.
   */
  public const SUPPORTED_EMBED_THEMES = ['auto', 'light', 'dark'];

  /**
   * Supported citizen assistant presentation policies.
   */
  public const SUPPORTED_ASSISTANT_TONES = ['neutral', 'friendly', 'empathetic', 'formal'];
  public const SUPPORTED_ASSISTANT_VERBOSITY = ['concise', 'balanced', 'conversational'];
  public const SUPPORTED_ASSISTANT_ACKNOWLEDGEMENTS = ['off', 'brief', 'contextual'];

  /**
   * Supported reach of a reusable applicant profile.
   *
   * Only "organisation" has runtime effect; "jurisdiction" is stored for the
   * jurisdiction-wide profile that follows later.
   */
  public const SUPPORTED_APPLICANT_PROFILE_SCOPES = ['organisation', 'jurisdiction'];

  public function __construct(
    protected EmailValidatorInterface $emailValidator,
  ) {}

  /**
   * Validates a single general-section value.
   *
   * @param string $key
   *   The general settings key (camelCase API name).
   * @param mixed $value
   *   The value to validate.
   *
   * @return string|null
   *   An error message if validation fails, NULL if the value is valid.
   */
  public function validateGeneralValue(string $key, mixed $value): ?string {
    switch ($key) {
      case 'platformName':
        return $this->validatePlainString($key, $value, 100);

      case 'emailFooter':
        return $this->validatePlainString($key, $value, 2000);

      case 'legalNotice':
      case 'privacyPolicy':
        if (!is_string($value)) {
          return "$key must be a string.";
        }
        // HTML content fields: allow markup but cap at a reasonable size.
        // XSS is handled by sanitize-on-write in the controller
        // (Xss::filterAdmin), not by an equality reject here — the filter
        // normalizes harmless input (bare "&" to "&amp;", "<br/>" to
        // "<br />"), so a reject would false-positive on legitimate content.
        if (mb_strlen($value) > 50000) {
          return "$key must not exceed 50000 characters.";
        }
        return NULL;

      case 'contactEmail':
        if (!is_string($value)) {
          return 'contactEmail must be a string.';
        }
        if ($value !== '' && !$this->emailValidator->isValid($value)) {
          return 'contactEmail must be a valid email address.';
        }
        return NULL;

      case 'website':
        if (!is_string($value)) {
          return 'website must be a string.';
        }
        if ($value === '') {
          return NULL;
        }
        if (mb_strlen($value) > 255) {
          return 'website must not exceed 255 characters.';
        }
        if (!preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === FALSE) {
          return 'website must be a valid http(s) URL.';
        }
        return NULL;

      case 'phone':
        if (!is_string($value)) {
          return 'phone must be a string.';
        }
        if (mb_strlen($value) > 50) {
          return 'phone must not exceed 50 characters.';
        }
        if (!preg_match('/^[0-9+\- ()]*$/', $value)) {
          return 'phone may only contain digits, +, -, spaces, and parentheses.';
        }
        return NULL;

      default:
        return "Unknown field: $key.";
    }
  }

  /**
   * Validates a color value as a Tailwind palette name or HEX code.
   *
   * @param mixed $value
   *   The color value to validate.
   *
   * @return string|null
   *   An error message if invalid, NULL if valid.
   */
  public function validateColorValue(mixed $value): ?string {
    if (!is_string($value)) {
      return 'Color must be a string.';
    }

    // Allow Tailwind palette names.
    if (in_array($value, self::VALID_TAILWIND_PALETTES, TRUE)) {
      return NULL;
    }

    // Allow HEX color codes (#RGB or #RRGGBB).
    if (preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $value)) {
      return NULL;
    }

    return 'Must be a valid Tailwind palette name (' . implode(', ', self::VALID_TAILWIND_PALETTES) . ') or a HEX color code (e.g. #FF5733).';
  }

  /**
   * Validates a persisted logo data URL.
   *
   * Also used for the dark logo (theme.logoDarkDataUrl): same types, sizes
   * and SVG rules; only the field name of the message differs.
   *
   * @param mixed $value
   *   The logo data URL or an empty string to remove the logo.
   * @param string $field
   *   The field the message names: logoDataUrl or logoDarkDataUrl.
   *
   * @return string|null
   *   An error message if invalid, NULL if valid.
   */
  public function validateLogoDataUrl(mixed $value, string $field = 'logoDataUrl'): ?string {
    if (!is_string($value)) {
      return $field . ' must be a string.';
    }
    if ($value === '') {
      return NULL;
    }
    if (strlen($value) > self::MAX_LOGO_DATA_URL_LENGTH) {
      return $field . ' is too large.';
    }
    if (preg_match('#^data:image/(png|jpeg|svg\+xml);base64,([A-Za-z0-9+/]+={0,2})$#', $value, $matches) !== 1) {
      return $field . ' must be a PNG, JPEG, or SVG data URL.';
    }

    $mime = $matches[1];
    $decoded = base64_decode($matches[2], TRUE);
    if ($decoded === FALSE) {
      return $field . ' must contain valid base64 data.';
    }
    if (strlen($decoded) > self::MAX_LOGO_BYTES) {
      return $field . ' payload is too large.';
    }

    if ($mime === 'png') {
      return str_starts_with($decoded, "\x89PNG\r\n\x1A\n")
        ? NULL
        : $field . ' does not contain a valid PNG image.';
    }
    if ($mime === 'jpeg') {
      return str_starts_with($decoded, "\xFF\xD8\xFF")
        ? NULL
        : $field . ' does not contain a valid JPEG image.';
    }
    return $this->validateSvgLogo($decoded, $field);
  }

  /**
   * Validates persisted logo alternative text.
   *
   * @param mixed $value
   *   The alternative text.
   *
   * @return string|null
   *   An error message if invalid, NULL if valid.
   */
  public function validateLogoAlt(mixed $value): ?string {
    return $this->validatePlainString('logoAlt', $value, 160);
  }

  /**
   * Validates custom CSS for dangerous patterns.
   *
   * Blocks known CSS injection vectors such as @import, javascript: URIs,
   * expression(), behavior:, -moz-binding, script tags, and external url().
   *
   * @param mixed $css
   *   The CSS string to validate.
   *
   * @return string|null
   *   An error message if dangerous content is found, NULL if safe.
   */
  public function validateCustomCss(mixed $css): ?string {
    if (!is_string($css)) {
      return 'customCss must be a string.';
    }
    if (mb_strlen($css) > 50000) {
      return 'customCss must not exceed 50000 characters.';
    }

    $lower = strtolower($css);

    if (str_contains($lower, '@import')) {
      return 'CSS must not contain @import directives.';
    }
    if (str_contains($lower, 'javascript:')) {
      return 'CSS must not contain javascript: URIs.';
    }
    if (str_contains($lower, 'expression(')) {
      return 'CSS must not contain expression() functions.';
    }
    if (str_contains($lower, 'behavior:')) {
      return 'CSS must not contain behavior: properties.';
    }
    if (str_contains($lower, '-moz-binding')) {
      return 'CSS must not contain -moz-binding properties.';
    }
    if (preg_match('/<\s*script/i', $css)) {
      return 'CSS must not contain script tags.';
    }
    if (preg_match('/url\s*\(\s*["\']?\s*(https?:|\/\/|data:|blob:|vbscript:)/i', $css)) {
      return 'CSS must not contain external, protocol-relative, or data/blob/vbscript url() references.';
    }

    return NULL;
  }

  /**
   * Validates a decoded SVG logo payload.
   */
  private function validateSvgLogo(string $svg, string $field = 'logoDataUrl'): ?string {
    if (!mb_check_encoding($svg, 'UTF-8')) {
      return $field . ' SVG must be valid UTF-8.';
    }

    $lower = strtolower($svg);
    foreach (['<script', '<foreignobject', 'javascript:', 'vbscript:'] as $blocked) {
      if (str_contains($lower, $blocked)) {
        return $field . ' SVG contains blocked active or external content.';
      }
    }
    if (preg_match('/\son[a-z]+\s*=/i', $svg) === 1) {
      return $field . ' SVG contains blocked event handlers.';
    }
    if (preg_match('/\b(?:xlink:)?href\s*=\s*["\']?\s*(?:https?:|\/\/|data:|javascript:|vbscript:)/i', $svg) === 1) {
      return $field . ' SVG contains blocked active or external content.';
    }
    if (preg_match('/url\s*\(\s*["\']?\s*(?:https?:|\/\/|data:|javascript:|vbscript:)/i', $svg) === 1) {
      return $field . ' SVG contains blocked active or external content.';
    }
    if (preg_match('/<\s*svg[\s>]/i', $svg) !== 1) {
      return $field . ' SVG must contain an SVG root element.';
    }

    return NULL;
  }

  /**
   * Validates a complete languages configuration.
   *
   * @param array $languages
   *   The languages config with "default" and "available" keys.
   *
   * @return string|null
   *   An error message if invalid, NULL if valid.
   */
  public function validateLanguages(array $languages): ?string {
    if (!isset($languages['default']) || !is_string($languages['default'])) {
      return 'default must be a string locale code.';
    }
    if (!in_array($languages['default'], self::SUPPORTED_LANGUAGES, TRUE)) {
      return 'default must be one of: ' . implode(', ', self::SUPPORTED_LANGUAGES) . '.';
    }

    if (!isset($languages['available']) || !is_array($languages['available']) || $languages['available'] === []) {
      return 'available must be a non-empty array of locale codes.';
    }
    foreach ($languages['available'] as $code) {
      if (!is_string($code) || !in_array($code, self::SUPPORTED_LANGUAGES, TRUE)) {
        return 'available may only contain: ' . implode(', ', self::SUPPORTED_LANGUAGES) . '.';
      }
    }

    if (!in_array($languages['default'], $languages['available'], TRUE)) {
      return 'default locale must be present in the available list.';
    }

    return NULL;
  }

  /**
   * Validates a single retention-section value.
   *
   * @param string $key
   *   The retention settings key.
   * @param mixed $value
   *   The value to validate.
   *
   * @return string|null
   *   An error message if validation fails, NULL if the value is valid.
   */
  public function validateRetentionValue(string $key, mixed $value): ?string {
    if ($key !== 'dataRetentionDays') {
      return "Unknown field: $key.";
    }

    if (is_int($value)) {
      $days = $value;
    }
    elseif (is_string($value)) {
      $value = trim($value);
      if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return 'dataRetentionDays must be an integer between 0 and 36500.';
      }
      $days = (int) $value;
    }
    else {
      return 'dataRetentionDays must be an integer between 0 and 36500.';
    }

    if ($days < 0 || $days > self::MAX_RETENTION_DAYS) {
      return 'dataRetentionDays must be an integer between 0 and 36500.';
    }

    return NULL;
  }

  /**
   * Validates a single resubmission-section value.
   *
   * @param string $key
   *   The resubmission settings key.
   * @param mixed $value
   *   The value to validate.
   *
   * @return string|null
   *   An error message if validation fails, NULL if the value is valid.
   */
  public function validateResubmissionValue(string $key, mixed $value): ?string {
    if ($key === 'reminderScope') {
      if (is_string($value) && in_array($value, ['org', 'user'], TRUE)) {
        return NULL;
      }
      return 'reminderScope must be one of: org, user.';
    }

    if ($key !== 'reminderDays') {
      return "Unknown field: $key.";
    }

    if (is_int($value)) {
      $days = $value;
    }
    elseif (is_string($value)) {
      $value = trim($value);
      if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return 'reminderDays must be an integer between 1 and 365.';
      }
      $days = (int) $value;
    }
    else {
      return 'reminderDays must be an integer between 1 and 365.';
    }

    if ($days < 1 || $days > 365) {
      return 'reminderDays must be an integer between 1 and 365.';
    }

    return NULL;
  }

  /**
   * Validates a single delegation-section value.
   *
   * @param string $key
   *   The delegation settings key.
   * @param mixed $value
   *   The value to validate.
   *
   * @return string|null
   *   An error message if validation fails, NULL if the value is valid.
   */
  public function validateDelegationValue(string $key, mixed $value): ?string {
    if (!in_array($key, ['noteRequired', 'personalAssignment'], TRUE)) {
      return "Unknown field: $key.";
    }

    return is_bool($value) ? NULL : $key . ' must be a boolean.';
  }

  /**
   * Validates a single applicant-profile-section value.
   *
   * @param string $key
   *   The applicant profile settings key.
   * @param mixed $value
   *   The value to validate.
   *
   * @return string|null
   *   An error message if validation fails, NULL if the value is valid.
   */
  public function validateApplicantProfileValue(string $key, mixed $value): ?string {
    return match ($key) {
      'enabled' => is_bool($value) ? NULL : 'enabled must be a boolean.',
      'bankConsent' => is_bool($value) ? NULL : 'bankConsent must be a boolean.',
      'scope' => is_string($value) && in_array($value, self::SUPPORTED_APPLICANT_PROFILE_SCOPES, TRUE)
        ? NULL
        : 'scope must be one of: ' . implode(', ', self::SUPPORTED_APPLICANT_PROFILE_SCOPES) . '.',
      default => "Unknown field: $key.",
    };
  }

  /**
   * Validates one jurisdiction-level citizen assistant setting.
   */
  public function validateAssistantValue(string $key, mixed $value): ?string {
    return match ($key) {
      'tone' => is_string($value) && in_array($value, self::SUPPORTED_ASSISTANT_TONES, TRUE)
        ? NULL
        : 'tone must be one of: ' . implode(', ', self::SUPPORTED_ASSISTANT_TONES) . '.',
      'verbosity' => is_string($value) && in_array($value, self::SUPPORTED_ASSISTANT_VERBOSITY, TRUE)
        ? NULL
        : 'verbosity must be one of: ' . implode(', ', self::SUPPORTED_ASSISTANT_VERBOSITY) . '.',
      'acknowledgementStyle' => is_string($value) && in_array($value, self::SUPPORTED_ASSISTANT_ACKNOWLEDGEMENTS, TRUE)
        ? NULL
        : 'acknowledgementStyle must be one of: ' . implode(', ', self::SUPPORTED_ASSISTANT_ACKNOWLEDGEMENTS) . '.',
      'additionalInstructions' => $this->validatePlainString($key, $value, 2000),
      default => "Unknown field: $key.",
    };
  }

  /**
   * Validates a single uploads-section value.
   *
   * @param string $key
   *   The uploads settings key.
   * @param mixed $value
   *   The value to validate.
   *
   * @return string|null
   *   An error message if validation fails, NULL if the value is valid.
   */
  public function validateUploadsValue(string $key, mixed $value): ?string {
    switch ($key) {
      case 'maxStorageBytes':
        if (is_int($value)) {
          $bytes = $value;
        }
        elseif (is_string($value)) {
          $value = trim($value);
          if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return 'maxStorageBytes must be an integer between 0 and 1099511627776.';
          }
          $bytes = (int) $value;
        }
        else {
          return 'maxStorageBytes must be an integer between 0 and 1099511627776.';
        }
        if ($bytes < 0 || $bytes > self::MAX_UPLOAD_STORAGE_BYTES) {
          return 'maxStorageBytes must be an integer between 0 and 1099511627776.';
        }
        return NULL;

      case 'warningThresholdPercent':
        if (is_int($value)) {
          $percent = $value;
        }
        elseif (is_string($value)) {
          $value = trim($value);
          if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return 'warningThresholdPercent must be an integer between 1 and 100.';
          }
          $percent = (int) $value;
        }
        else {
          return 'warningThresholdPercent must be an integer between 1 and 100.';
        }
        if ($percent < 1 || $percent > 100) {
          return 'warningThresholdPercent must be an integer between 1 and 100.';
        }
        return NULL;

      default:
        return "Unknown field: $key.";
    }
  }

  /**
   * Validates a single embed-section value.
   *
   * @param string $key
   *   The embed settings key.
   * @param mixed $value
   *   The value to validate.
   *
   * @return string|null
   *   An error message if validation fails, NULL if the value is valid.
   */
  public function validateEmbedValue(string $key, mixed $value): ?string {
    switch ($key) {
      case 'defaultTheme':
        if (!is_string($value) || !in_array($value, self::SUPPORTED_EMBED_THEMES, TRUE)) {
          return 'defaultTheme must be one of: ' . implode(', ', self::SUPPORTED_EMBED_THEMES) . '.';
        }
        return NULL;

      case 'defaultWidth':
      case 'defaultHeight':
        if (!is_string($value)) {
          return "$key must be a string.";
        }
        if (preg_match('/^(?:[1-9][0-9]{0,3}|10000)(?:px|%|rem|em|vh|vw)$/', $value) !== 1) {
          return "$key must be a dimension like 100% or 800px.";
        }
        return NULL;

      case 'showBorder':
        return is_bool($value) ? NULL : 'showBorder must be a boolean.';

      default:
        return "Unknown field: $key.";
    }
  }

  /**
   * Validates a plain-text string value (no HTML, length-capped).
   *
   * @param string $key
   *   The settings key for error messages.
   * @param mixed $value
   *   The value to validate.
   * @param int $maxLength
   *   The maximum allowed character count.
   *
   * @return string|null
   *   An error message if invalid, NULL if valid.
   */
  protected function validatePlainString(string $key, mixed $value, int $maxLength): ?string {
    if (!is_string($value)) {
      return "$key must be a string.";
    }
    if ($value !== strip_tags($value)) {
      return "$key must not contain HTML tags.";
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u', $value) === 1) {
      return "$key must not contain control characters.";
    }
    if (mb_strlen($value) > $maxLength) {
      return "$key must not exceed $maxLength characters.";
    }

    return NULL;
  }

}
