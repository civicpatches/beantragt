<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves safe mail branding from the canonical jurisdiction configuration.
 */
final class TenantMailBranding {

  private const DEFAULT_PRIMARY = '#16a34a';

  private const DEFAULT_BACKGROUND = '#f8fafc';

  /**
   * Request-local branding cache.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $cache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Resolves platform or tenant branding from scalar mail parameters.
   *
   * @param array<string, mixed> $params
   *   Mail parameters. jurisdiction_id and jurisdiction_slug are supported.
   * @param string $langcode
   *   Message language.
   *
   * @return array<string, mixed>
   *   Sanitized branding values for renderer and MIME plugin use.
   */
  public function resolve(array $params, string $langcode): array {
    $jurisdiction_id = isset($params['jurisdiction_id']) && is_numeric($params['jurisdiction_id'])
      ? (int) $params['jurisdiction_id']
      : NULL;
    $jurisdiction_slug = isset($params['jurisdiction_slug']) && is_string($params['jurisdiction_slug'])
      ? trim($params['jurisdiction_slug'])
      : '';
    $cache_key = ($jurisdiction_id ?? 0) . ':' . $jurisdiction_slug . ':' . $langcode;
    if (isset($this->cache[$cache_key])) {
      return $this->cache[$cache_key];
    }

    $site = $this->configFactory->get('system.site');
    $site_name = trim((string) ($site->get('name') ?: 'beantragt.ai'));
    $branding = [
      'tenant_id' => NULL,
      'name' => $site_name !== '' ? $site_name : 'beantragt.ai',
      'primary_color' => self::DEFAULT_PRIMARY,
      'primary_foreground_color' => $this->contrastingForeground(self::DEFAULT_PRIMARY),
      'secondary_color' => '#0f172a',
      'secondary_text_color' => '#0f172a',
      'background_color' => self::DEFAULT_BACKGROUND,
      'logo_alt' => $site_name !== '' ? $site_name : 'beantragt.ai',
      'logo_cid' => '',
      'inline_logo' => NULL,
      'footer' => '',
      'reply_to' => '',
    ];

    $jurisdiction = $this->loadJurisdiction($jurisdiction_id, $jurisdiction_slug);
    if (!$jurisdiction instanceof GroupInterface) {
      return $this->cache[$cache_key] = $branding;
    }
    if ($jurisdiction->hasTranslation($langcode)) {
      $jurisdiction = $jurisdiction->getTranslation($langcode);
    }

    $branding['tenant_id'] = (int) $jurisdiction->id();
    $platform_name = $this->fieldValue($jurisdiction, 'field_platform_name');
    $label = trim((string) $jurisdiction->label());
    $branding['name'] = $platform_name !== '' && strcasecmp($platform_name, 'beantragt.ai') !== 0
      ? $platform_name
      : ($label !== '' ? $label : $branding['name']);
    $branding['logo_alt'] = $branding['name'];
    $branding['footer'] = trim(strip_tags($this->fieldValue($jurisdiction, 'field_email_footer')));

    $reply_to = trim($this->fieldValue($jurisdiction, 'field_org_email'));
    if ($this->isHeaderSafeEmail($reply_to)) {
      $branding['reply_to'] = $reply_to;
    }

    $theme = $this->themeConfig($jurisdiction);
    $branding['primary_color'] = $this->normalizeColor($theme['primary'] ?? NULL, self::DEFAULT_PRIMARY);
    $branding['secondary_color'] = $this->normalizeColor($theme['secondary'] ?? NULL, '#0f172a');
    $branding['primary_foreground_color'] = $this->contrastingForeground($branding['primary_color']);
    $branding['secondary_text_color'] = $this->accessibleTextColor(
      $branding['secondary_color'],
      ['#ffffff', $branding['background_color']],
    );

    $logo_alt = isset($theme['logoAlt']) && is_scalar($theme['logoAlt'])
      ? trim(strip_tags((string) $theme['logoAlt']))
      : '';
    if ($logo_alt !== '') {
      $branding['logo_alt'] = $logo_alt;
    }

    $inline_logo = $this->inlineLogo($theme['logoDataUrl'] ?? NULL);
    if ($inline_logo !== NULL) {
      $branding['logo_cid'] = 'tenant-logo@beantragt.ai';
      $branding['inline_logo'] = $inline_logo + ['cid' => $branding['logo_cid']];
    }

    return $this->cache[$cache_key] = $branding;
  }

  /**
   * Loads an explicit jurisdiction without applying access-sensitive routing.
   */
  private function loadJurisdiction(?int $id, string $slug): ?GroupInterface {
    try {
      $storage = $this->entityTypeManager->getStorage('group');
      if ($id !== NULL && $id > 0) {
        $group = $storage->load($id);
        return $group instanceof GroupInterface && $group->bundle() === 'jur' ? $group : NULL;
      }

      if ($slug === '' || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) !== 1) {
        return NULL;
      }
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'jur')
        ->condition('field_slug', $slug)
        ->condition('status', 1)
        ->range(0, 1)
        ->execute();
      $group = $ids === [] ? NULL : $storage->load(reset($ids));
      return $group instanceof GroupInterface ? $group : NULL;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Unable to resolve mail branding jurisdiction: @error', [
        '@error' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Reads the theme section from field_jur_config.
   *
   * @return array<string, mixed>
   *   Stored theme values or an empty array.
   */
  private function themeConfig(GroupInterface $group): array {
    $source = $group->getUntranslated();
    if (!$source->hasField('field_jur_config') || $source->get('field_jur_config')->isEmpty()) {
      return [];
    }
    $decoded = json_decode((string) $source->get('field_jur_config')->value, TRUE);
    return is_array($decoded) && isset($decoded['theme']) && is_array($decoded['theme'])
      ? $decoded['theme']
      : [];
  }

  /**
   * Reads a scalar group field value.
   */
  private function fieldValue(GroupInterface $group, string $field): string {
    return $group->hasField($field) && !$group->get($field)->isEmpty()
      ? (string) ($group->get($field)->value ?? '')
      : '';
  }

  /**
   * Accepts only six-digit CSS hex colors.
   */
  private function normalizeColor(mixed $value, string $fallback): string {
    if (!is_scalar($value)) {
      return $fallback;
    }
    $color = trim((string) $value);
    return preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtolower($color) : $fallback;
  }

  /**
   * Chooses the higher-contrast black or white text for a background color.
   */
  private function contrastingForeground(string $background): string {
    return $this->contrastRatio($background, '#000000') >= $this->contrastRatio($background, '#ffffff')
      ? '#000000'
      : '#ffffff';
  }

  /**
   * Keeps a tenant text color only when it is readable on all surfaces.
   *
   * @param string $color
   *   Candidate tenant text color.
   * @param string[] $backgrounds
   *   Surfaces on which the color is used.
   */
  private function accessibleTextColor(string $color, array $backgrounds): string {
    foreach ($backgrounds as $background) {
      if ($this->contrastRatio($color, $background) < 4.5) {
        return '#0f172a';
      }
    }
    return $color;
  }

  /**
   * Calculates the WCAG contrast ratio between two six-digit hex colors.
   */
  private function contrastRatio(string $first, string $second): float {
    $lighter = max($this->relativeLuminance($first), $this->relativeLuminance($second));
    $darker = min($this->relativeLuminance($first), $this->relativeLuminance($second));
    return ($lighter + 0.05) / ($darker + 0.05);
  }

  /**
   * Calculates relative luminance for a normalized six-digit hex color.
   */
  private function relativeLuminance(string $color): float {
    $channels = [
      hexdec(substr($color, 1, 2)) / 255,
      hexdec(substr($color, 3, 2)) / 255,
      hexdec(substr($color, 5, 2)) / 255,
    ];
    $channels = array_map(
      static fn (float $channel): float => $channel <= 0.04045
        ? $channel / 12.92
        : (($channel + 0.055) / 1.055) ** 2.4,
      $channels,
    );
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
  }

  /**
   * Validates a mailbox before it reaches an email header.
   */
  private function isHeaderSafeEmail(string $email): bool {
    return $email !== ''
      && str_replace(["\r", "\n", "\0"], '', $email) === $email
      && filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE;
  }

  /**
   * Decodes a validated raster data URL for a MIME inline image.
   *
   * @return array{mime: string, content: string}|null
   *   MIME attachment data or NULL for unsupported/invalid logos.
   */
  private function inlineLogo(mixed $value): ?array {
    if (!is_string($value)
      || preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#', trim($value), $matches) !== 1) {
      return NULL;
    }
    $content = base64_decode($matches[2], TRUE);
    if (!is_string($content) || $content === '' || strlen($content) > 524288) {
      return NULL;
    }
    $mime = $matches[1] === 'png' ? 'image/png' : 'image/jpeg';
    $valid_magic = $mime === 'image/png'
      ? str_starts_with($content, "\x89PNG\r\n\x1a\n")
      : str_starts_with($content, "\xff\xd8\xff");
    return $valid_magic ? ['mime' => $mime, 'content' => $content] : NULL;
  }

}
