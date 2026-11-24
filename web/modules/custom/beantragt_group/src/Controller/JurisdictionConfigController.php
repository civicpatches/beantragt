<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Controller;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\ProfileConsentText;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_group\Service\BoundaryGeoJsonValidator;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\beantragt_group\Service\JurisdictionConfigValidator;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Service\NotificationTemplateCatalogInterface;
use Drupal\beantragt_group\Trait\JurisdictionIdResolverTrait;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupMembershipInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configuration-as-Content API for jurisdiction groups.
 *
 * Admin endpoints read and write per-jurisdiction settings sections that are
 * stored partly as discrete fields on the jur group entity and partly as
 * top-level keys in the field_jur_config JSON blob. A public endpoint exposes
 * the non-PII configuration bundle that the Nuxt frontend boots from.
 *
 * Ported from the Mark-a-Spot TenantSettingsController /
 * MarkASpotSettingsController pattern and adapted to beantragt house style.
 *
 * Admin endpoints accept both numeric group IDs and URL slugs as the
 * jurisdiction_id parameter via JurisdictionIdResolverTrait.
 */
final class JurisdictionConfigController extends ControllerBase {

  use JurisdictionIdResolverTrait;

  /**
   * Settings sections exposed by the admin endpoints.
   */
  private const ALLOWED_SECTIONS = [
    'general',
    'branding',
    'languages',
    'retention',
    'resubmission',
    'delegation',
    'applicant_profile',
    'embed',
    'uploads',
    'notification_mail',
    'boundary',
    'assistant',
  ];

  /**
   * General-section keys stored as discrete fields on the group entity.
   */
  private const GENERAL_ENTITY_FIELDS = [
    'platformName' => 'field_platform_name',
    'emailFooter' => 'field_email_footer',
    'legalNotice' => 'field_legal_notice',
    'privacyPolicy' => 'field_privacy_policy',
  ];

  /**
   * General-section keys stored under the "general" JSON key.
   */
  private const GENERAL_JSON_KEYS = ['contactEmail', 'website', 'phone'];

  /**
   * General-section keys whose HTML content is sanitized on write.
   */
  private const HTML_SANITIZED_KEYS = ['legalNotice', 'privacyPolicy'];

  /**
   * Branding color keys mapped to their "theme" JSON sub-keys.
   */
  private const BRANDING_COLOR_KEYS = [
    'primaryColor' => 'primary',
    'secondaryColor' => 'secondary',
    'neutralColor' => 'neutral',
  ];

  /**
   * Branding logo keys mapped to their "theme" JSON sub-keys.
   *
   * The optional logoDarkDataUrl is the logo for the dark view of the screen
   * pages. Mails, letterheads and print keep reading logoDataUrl only.
   */
  private const BRANDING_LOGO_KEYS = [
    'logoDataUrl' => 'logoDataUrl',
    'logoDarkDataUrl' => 'logoDarkDataUrl',
    'logoAlt' => 'logoAlt',
  ];

  /**
   * Languages-section keys.
   */
  private const LANGUAGES_KEYS = ['default', 'available'];

  /**
   * Retention-section keys.
   */
  private const RETENTION_KEYS = ['dataRetentionDays'];

  /**
   * Resubmission-section keys.
   */
  private const RESUBMISSION_KEYS = ['reminderDays', 'reminderScope'];

  /**
   * Delegation-section keys.
   */
  private const DELEGATION_KEYS = ['noteRequired', 'personalAssignment'];

  /**
   * Applicant-profile-section keys.
   */
  private const APPLICANT_PROFILE_KEYS = ['enabled', 'scope', 'bankConsent'];

  /**
   * Applicant profile reuse is off until the jurisdiction admin enables it.
   *
   * The bank consent checkbox has its own switch, also off by default: the
   * profile stores bank details with that consent, but no form prefills them
   * yet, so a jurisdiction offers it only on purpose.
   */
  private const APPLICANT_PROFILE_DEFAULT = [
    'enabled' => FALSE,
    'scope' => 'organisation',
    'bankConsent' => FALSE,
  ];

  /**
   * Citizen assistant policy keys stored under the "assistant" JSON key.
   */
  private const ASSISTANT_KEYS = ['tone', 'verbosity', 'acknowledgementStyle', 'additionalInstructions'];

  /**
   * Safe defaults for jurisdictions without an explicit assistant policy.
   */
  private const ASSISTANT_DEFAULT = [
    'tone' => 'friendly',
    'verbosity' => 'concise',
    'acknowledgementStyle' => 'contextual',
    'additionalInstructions' => '',
  ];

  /**
   * Upload quota-section keys.
   */
  private const UPLOADS_KEYS = ['maxStorageBytes', 'warningThresholdPercent'];

  /**
   * Notification mail-section keys.
   */
  private const NOTIFICATION_MAIL_KEYS = ['templates'];

  /**
   * Allowed keys for one notification mail template entry.
   */
  private const NOTIFICATION_TEMPLATE_KEYS = ['enabled', 'default', 'translations'];

  /**
   * Allowed text keys in one notification mail template text entry.
   */
  private const NOTIFICATION_TEMPLATE_TEXT_KEYS = ['subject', 'body'];

  /**
   * Maximum notification mail subject length.
   */
  private const NOTIFICATION_SUBJECT_MAX_LENGTH = 500;

  /**
   * Maximum notification mail body length.
   */
  private const NOTIFICATION_BODY_MAX_LENGTH = 10000;

  /**
   * Embed generator defaults stored under the "embed" JSON key.
   */
  private const EMBED_KEYS = ['defaultTheme', 'defaultWidth', 'defaultHeight', 'showBorder'];

  /**
   * Fallback embed generator defaults.
   */
  private const EMBED_DEFAULT = [
    'defaultTheme' => 'light',
    'defaultWidth' => '100%',
    'defaultHeight' => '800px',
    'showBorder' => TRUE,
  ];

  /**
   * Fallback languages config when none is stored yet.
   */
  private const LANGUAGES_DEFAULT = [
    'default' => 'de',
    'available' => ['de'],
  ];

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected readonly JurisdictionConfigServiceInterface $configService,
    protected readonly JurisdictionConfigValidator $validator,
    protected readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    protected readonly LoggerInterface $logger,
    protected readonly AttachmentStorageQuota $attachmentStorageQuota,
    protected readonly ?NotificationTemplateCatalogInterface $notificationTemplateCatalog = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $notification_catalog = $container->get(
      'beantragt_notification.template_catalog',
      ContainerInterface::NULL_ON_INVALID_REFERENCE,
    );

    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('beantragt_group.jurisdiction_config'),
      $container->get('beantragt_group.jurisdiction_config_validator'),
      $container->get('beantragt_group.jurisdiction_hierarchy_resolver'),
      $container->get('logger.channel.beantragt_group'),
      $container->get('beantragt_application.attachment_storage_quota'),
      $notification_catalog instanceof NotificationTemplateCatalogInterface ? $notification_catalog : NULL,
    );
  }

  /**
   * Access check for the admin jurisdiction settings endpoints.
   *
   * Grants access to user 1, Drupal administrators, and department admins
   * whose jurisdiction membership scope (self + descendants) contains the
   * requested jurisdiction.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier from the route (numeric ID or slug).
   * @param string $section
   *   The settings section from the route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessCheck(AccountInterface $account, string $jurisdiction_id, string $section): AccessResultInterface {
    // Resolve slug to numeric ID.
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return AccessResult::forbidden('Jurisdiction not found.')
        ->addCacheContexts(['url.path']);
    }

    // User 1 (superadmin) always has access — bypasses all checks.
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->addCacheContexts(['user']);
    }

    // Drupal administrators always have access.
    if (in_array('administrator', $account->getRoles(), TRUE)) {
      return AccessResult::allowed()->addCacheContexts(['user.roles']);
    }

    if ($section === 'retention' && !$account->hasPermission('administer beantragt retention policies')) {
      return AccessResult::forbidden('Retention policy changes require the retention administration permission.')
        ->addCacheContexts(['user.permissions', 'url.path']);
    }

    // Decisions below depend on the user's group memberships. Tag the result
    // with the membership-list cache tag so it invalidates when a membership
    // is added, removed, or its roles change — without it a demoted admin
    // would keep a cached allow. Mirrors GroupMembership::loadByUser().
    $membership_cache_tag = 'group_relationship_list:plugin:group_membership:entity:' . $account->id();

    // Tenant management role: allow access if the requested jurisdiction falls
    // within the hierarchy (self + descendants) of any jurisdiction group where
    // the user holds an ADMIN group role. Plain membership is not enough.
    if (BeantragtRoles::isManagementAccount($account)) {
      foreach (GroupMembership::loadByUser($account) as $membership) {
        $managedGroup = $membership->getGroup();
        if (!$this->isJurisdictionGroup($managedGroup)) {
          continue;
        }
        if (!$this->membershipGrantsAdmin($membership)) {
          continue;
        }
        $scopeIds = $this->hierarchyResolver->getDescendantIds((int) $managedGroup->id());
        if (in_array($resolved_id, $scopeIds, TRUE)) {
          return AccessResult::allowed()
            ->addCacheContexts(['user', 'url.path'])
            ->addCacheTags([$membership_cache_tag]);
        }
      }
    }

    return AccessResult::forbidden('User is not an administrator or department admin for this jurisdiction.')
      ->addCacheContexts(['user', 'user.roles', 'url.path'])
      ->addCacheTags([$membership_cache_tag]);
  }

  /**
   * Checks whether a membership carries an admin group role.
   *
   * @param \Drupal\group\Entity\GroupMembershipInterface $membership
   *   The group membership.
   *
   * @return bool
   *   TRUE when the membership holds an admin group role.
   */
  private function membershipGrantsAdmin(GroupMembershipInterface $membership): bool {
    return ApplicationCaseScope::hasAdminGroupRole($membership->getRoles(FALSE));
  }

  /**
   * Returns one settings section for a jurisdiction group.
   *
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   * @param string $section
   *   The settings section: general, branding, or languages.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON envelope {"section": ..., "data": {...}}, or an error response.
   */
  public function getSection(string $jurisdiction_id, string $section): JsonResponse {
    if (!in_array($section, self::ALLOWED_SECTIONS, TRUE)) {
      return $this->errorResponse('Unknown section: ' . $section . '. Allowed: ' . implode(', ', self::ALLOWED_SECTIONS) . '.', NULL, Response::HTTP_NOT_FOUND);
    }
    if ($section === 'notification_mail' && !$this->notificationTemplateCatalog instanceof NotificationTemplateCatalogInterface) {
      return $this->errorResponse('Notification mail templates are unavailable.', NULL, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return $this->errorResponse('Jurisdiction not found.', NULL, Response::HTTP_NOT_FOUND);
    }

    return $this->sectionResponse($group, $section);
  }

  /**
   * Updates one settings section for a jurisdiction group.
   *
   * Accepts a JSON body {"data": {...}} with any subset of the section's
   * allowed keys. Unknown keys are rejected, provided values are validated
   * individually before anything is written.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request carrying the JSON body.
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   * @param string $section
   *   The settings section: general, branding, or languages.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON envelope with the updated section, or an error response.
   */
  public function patchSection(Request $request, string $jurisdiction_id, string $section): JsonResponse {
    if (!in_array($section, self::ALLOWED_SECTIONS, TRUE)) {
      return $this->errorResponse('Unknown section: ' . $section . '. Allowed: ' . implode(', ', self::ALLOWED_SECTIONS) . '.', NULL, Response::HTTP_NOT_FOUND);
    }
    if ($section === 'notification_mail' && !$this->notificationTemplateCatalog instanceof NotificationTemplateCatalogInterface) {
      return $this->errorResponse('Notification mail templates are unavailable.', NULL, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $group = $this->loadJurisdictionGroup($jurisdiction_id);
    if (!$group) {
      return $this->errorResponse('Jurisdiction not found.', NULL, Response::HTTP_NOT_FOUND);
    }

    $body = $request->getContent();
    if ($section === 'boundary' && strlen($body) > BoundaryGeoJsonValidator::MAX_PAYLOAD_BYTES) {
      return $this->errorResponse('Boundary payload exceeds the 2 MB limit.', 'boundary', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }
    $payload = json_decode($body, TRUE);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
      return $this->errorResponse('Request body must be a JSON object with a "data" object.');
    }

    $data = $payload['data'];
    if ($data === []) {
      return $this->errorResponse('No fields provided.');
    }

    // Strict allowlist: reject unknown keys with a clear message.
    $allowedKeys = $this->sectionAllowedKeys($section);
    foreach (array_keys($data) as $key) {
      if (!in_array((string) $key, $allowedKeys, TRUE)) {
        return $this->errorResponse(
          'Unknown key: ' . $key . '. Allowed keys for ' . $section . ': ' . implode(', ', $allowedKeys) . '.',
          (string) $key
        );
      }
    }

    $locked_group = NULL;
    try {
      $error = $this->configService->withLockedGroup(
        $group,
        function (GroupInterface $fresh_group) use ($section, $data, &$locked_group): ?JsonResponse {
          $locked_group = $fresh_group;
          return match ($section) {
            'general' => $this->applyGeneral($fresh_group, $data),
            'branding' => $this->applyBranding($fresh_group, $data),
            'languages' => $this->applyLanguages($fresh_group, $data),
            'retention' => $this->applyRetention($fresh_group, $data),
            'resubmission' => $this->applyResubmission($fresh_group, $data),
            'delegation' => $this->applyDelegation($fresh_group, $data),
            'applicant_profile' => $this->applyApplicantProfile($fresh_group, $data),
            'assistant' => $this->applyAssistant($fresh_group, $data),
            'embed' => $this->applyEmbed($fresh_group, $data),
            'uploads' => $this->applyUploads($fresh_group, $data),
            'notification_mail' => $this->applyNotificationMail($fresh_group, $data),
            'boundary' => $this->applyBoundary($fresh_group, $data),
          };
        },
      );
    }
    catch (\Exception $exception) {
      $this->logger->error(
        'Failed to lock settings mutation for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $exception->getMessage()],
      );
      return $this->errorResponse('Failed to save settings.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    if ($error !== NULL) {
      return $error;
    }
    if (!$locked_group instanceof GroupInterface) {
      return $this->errorResponse('Failed to save settings.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    $group = $locked_group;

    $this->logger->notice(
      'User @user updated @section settings for jurisdiction @id (keys: @keys)',
      [
        '@user' => $this->currentUser->getDisplayName(),
        '@section' => $section,
        '@id' => $group->id(),
        '@keys' => implode(', ', array_keys($data)),
      ]
    );

    return $this->sectionResponse($group, $section);
  }

  /**
   * Returns the public, non-PII configuration bundle for a jurisdiction.
   *
   * @param string $slug
   *   The jurisdiction URL slug.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The cacheable public config bundle, or a 404.
   */
  public function publicConfig(string $slug): CacheableJsonResponse {
    $cache_metadata = (new CacheableMetadata())
      ->addCacheContexts(['url.path'])
      ->setCacheMaxAge(3600);

    $slug = $this->normalizePublicSlug($slug);
    $group = $slug !== NULL ? $this->loadPublishedJurisdictionBySlug($slug) : NULL;

    if ($slug === NULL || !$this->isJurisdictionGroup($group)) {
      // Tag the cached 404 with the group list so creating or publishing a
      // jurisdiction with this slug invalidates it.
      $cache_metadata->addCacheTags(['group_list']);
      $response = new CacheableJsonResponse([
        'error' => ['message' => 'Jurisdiction not found.'],
      ], Response::HTTP_NOT_FOUND);
      $response->addCacheableDependency($cache_metadata);
      return $response;
    }

    /** @var \Drupal\group\Entity\GroupInterface $group */
    $payload = $this->publicConfigPayload($group, $slug, TRUE, FALSE);

    $cache_metadata->addCacheTags(['group:' . $group->id()]);

    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency($cache_metadata);
    $response->setMaxAge(3600);
    $response->setPublic();
    return $response;
  }

  /**
   * Returns the v1 public jurisdiction configuration contract.
   *
   * The v1 shape is intended for external API users: it wraps data, includes
   * API metadata, and avoids numeric Drupal entity IDs.
   *
   * @param string $slug
   *   The jurisdiction URL slug.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The cacheable public config bundle, or a v1 error envelope.
   */
  public function publicConfigV1(string $slug): CacheableJsonResponse {
    $cache_metadata = (new CacheableMetadata())
      ->addCacheContexts(['url.path'])
      ->setCacheMaxAge(3600);

    $slug = $this->normalizePublicSlug($slug);
    $group = $slug !== NULL ? $this->loadPublishedJurisdictionBySlug($slug) : NULL;

    if ($slug === NULL || !$this->isJurisdictionGroup($group)) {
      $cache_metadata->addCacheTags(['group_list']);
      return $this->publicApiErrorResponse(
        'jurisdiction.not_found',
        'Jurisdiction not found.',
        Response::HTTP_NOT_FOUND,
        $cache_metadata
      );
    }

    /** @var \Drupal\group\Entity\GroupInterface $group */
    $cache_metadata->addCacheTags(['group:' . $group->id()]);

    $response = new CacheableJsonResponse([
      'data' => $this->publicConfigPayload($group, $slug, FALSE, TRUE),
      'meta' => [
        'apiVersion' => '1.0',
        'resource' => 'jurisdictionConfig',
      ],
    ]);
    $response->addCacheableDependency($cache_metadata);
    $response->setMaxAge(3600);
    $response->setPublic();
    return $response;
  }

  /**
   * Returns the normalized boundary for one published jurisdiction.
   */
  public function publicBoundaryV1(string $slug): CacheableJsonResponse {
    $cache_metadata = (new CacheableMetadata())
      ->addCacheContexts(['url.path'])
      ->setCacheMaxAge(3600);

    $slug = $this->normalizePublicSlug($slug);
    $group = $slug !== NULL ? $this->loadPublishedJurisdictionBySlug($slug) : NULL;
    if ($slug === NULL || !$this->isJurisdictionGroup($group)) {
      $cache_metadata->addCacheTags(['group_list']);
      return $this->publicApiErrorResponse(
        'jurisdiction.not_found',
        'Jurisdiction not found.',
        Response::HTTP_NOT_FOUND,
        $cache_metadata,
      );
    }

    /** @var \Drupal\group\Entity\GroupInterface $group */
    $cache_metadata->addCacheTags(['group:' . $group->id()]);
    $response = new CacheableJsonResponse([
      'data' => [
        'boundary' => $this->normalizedBoundary($group),
      ],
      'meta' => [
        'apiVersion' => '1.0',
        'resource' => 'jurisdictionBoundary',
      ],
    ]);
    $response->addCacheableDependency($cache_metadata);
    $response->setMaxAge(3600);
    $response->setPublic();
    return $response;
  }

  /**
   * Validates and applies a general-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyGeneral(GroupInterface $group, array $data): ?JsonResponse {
    foreach ($data as $key => $value) {
      $error = $this->validator->validateGeneralValue((string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
    }

    $target = $group->getUntranslated();
    foreach (self::GENERAL_ENTITY_FIELDS as $apiKey => $fieldName) {
      if (array_key_exists($apiKey, $data) && $target->hasField($fieldName)) {
        $value = $data[$apiKey];
        // HTML content fields are sanitized on write: the admin XSS filter
        // strips script tags, event handlers, and unsafe protocols while
        // keeping legitimate markup. The FILTERED value is persisted and
        // returned (an equality reject would false-positive on harmless
        // normalizations like a bare "&").
        if (is_string($value) && in_array($apiKey, self::HTML_SANITIZED_KEYS, TRUE)) {
          $value = Xss::filterAdmin($value);
        }
        $target->set($fieldName, $value);
      }
    }

    $jsonSubset = array_intersect_key($data, array_flip(self::GENERAL_JSON_KEYS));
    if ($jsonSubset !== []) {
      $merged = array_merge($this->configService->getSection($group, 'general'), $jsonSubset);
      return $this->persistSection($group, 'general', $merged);
    }

    // Entity-field-only PATCH: nothing to write into the JSON blob.
    return $this->saveGroup($target);
  }

  /**
   * Validates and applies a branding-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyBranding(GroupInterface $group, array $data): ?JsonResponse {
    foreach (array_keys(self::BRANDING_COLOR_KEYS) as $apiKey) {
      if (array_key_exists($apiKey, $data)) {
        $error = $this->validator->validateColorValue($data[$apiKey]);
        if ($error !== NULL) {
          return $this->errorResponse("$apiKey: $error", $apiKey);
        }
      }
    }
    if (array_key_exists('logoDataUrl', $data)) {
      $error = $this->validator->validateLogoDataUrl($data['logoDataUrl']);
      if ($error !== NULL) {
        return $this->errorResponse("logoDataUrl: $error", 'logoDataUrl');
      }
    }
    if (array_key_exists('logoDarkDataUrl', $data)) {
      $error = $this->validator->validateLogoDataUrl($data['logoDarkDataUrl'], 'logoDarkDataUrl');
      if ($error !== NULL) {
        return $this->errorResponse("logoDarkDataUrl: $error", 'logoDarkDataUrl');
      }
    }
    if (array_key_exists('logoAlt', $data)) {
      $error = $this->validator->validateLogoAlt($data['logoAlt']);
      if ($error !== NULL) {
        return $this->errorResponse("logoAlt: $error", 'logoAlt');
      }
    }

    $target = $group->getUntranslated();
    if (array_key_exists('customCss', $data)) {
      $error = $this->validator->validateCustomCss($data['customCss']);
      if ($error !== NULL) {
        return $this->errorResponse("customCss: $error", 'customCss');
      }
      if ($target->hasField('field_custom_css')) {
        $target->set('field_custom_css', $data['customCss']);
      }
    }

    $themeSubset = array_intersect_key($data, array_merge(self::BRANDING_COLOR_KEYS, self::BRANDING_LOGO_KEYS));
    if ($themeSubset !== []) {
      $theme = $this->configService->getSection($group, 'theme');
      foreach (self::BRANDING_COLOR_KEYS as $apiKey => $jsonKey) {
        if (array_key_exists($apiKey, $data)) {
          $theme[$jsonKey] = $data[$apiKey];
        }
      }
      foreach (self::BRANDING_LOGO_KEYS as $apiKey => $jsonKey) {
        if (array_key_exists($apiKey, $data)) {
          $theme[$jsonKey] = $data[$apiKey];
        }
      }
      // An empty dark logo removes the key; the screen pages fall back.
      if (($theme['logoDarkDataUrl'] ?? NULL) === '') {
        unset($theme['logoDarkDataUrl']);
      }
      return $this->persistSection($group, 'theme', $theme);
    }

    // customCss-only PATCH: nothing to write into the JSON blob.
    return $this->saveGroup($target);
  }

  /**
   * Validates and applies a languages-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyLanguages(GroupInterface $group, array $data): ?JsonResponse {
    $current = $this->normalizeLanguages($this->configService->getSection($group, 'languages'));
    $merged = array_merge($current, array_intersect_key($data, array_flip(self::LANGUAGES_KEYS)));

    $error = $this->validator->validateLanguages($merged);
    if ($error !== NULL) {
      return $this->errorResponse($error, 'languages');
    }

    return $this->persistSection($group, 'languages', [
      'default' => $merged['default'],
      'available' => array_values($merged['available']),
    ]);
  }

  /**
   * Validates and applies a retention-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyRetention(GroupInterface $group, array $data): ?JsonResponse {
    foreach ($data as $key => $value) {
      $error = $this->validator->validateRetentionValue((string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
    }

    $current = $this->normalizeRetention($this->configService->getSection($group, 'retention'));
    $merged = array_merge($current, array_intersect_key($data, array_flip(self::RETENTION_KEYS)));
    $days = $merged['dataRetentionDays'];
    return $this->persistSection($group, 'retention', [
      'dataRetentionDays' => is_string($days) ? (int) trim($days) : $days,
    ]);
  }

  /**
   * Validates and applies a resubmission-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyResubmission(GroupInterface $group, array $data): ?JsonResponse {
    foreach ($data as $key => $value) {
      $error = $this->validator->validateResubmissionValue((string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
    }

    $current = $this->normalizeResubmission($this->configService->getSection($group, 'resubmission'));
    $merged = array_merge($current, array_intersect_key($data, array_flip(self::RESUBMISSION_KEYS)));
    $values = [];
    if ($merged['reminderDays'] !== NULL) {
      $days = $merged['reminderDays'];
      $values['reminderDays'] = is_string($days) ? (int) trim($days) : $days;
    }
    if ($merged['reminderScope'] !== NULL) {
      $values['reminderScope'] = $merged['reminderScope'];
    }

    return $this->persistSection($group, 'resubmission', $values);
  }

  /**
   * Validates and applies a delegation-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyDelegation(GroupInterface $group, array $data): ?JsonResponse {
    $root_id = $this->hierarchyResolver->getRootJurisdictionId((int) $group->id());
    if (array_key_exists('personalAssignment', $data) && $root_id !== NULL && $root_id !== (int) $group->id()) {
      return $this->errorResponse('Personal assignment is configured at the tenant root jurisdiction.', 'personalAssignment', Response::HTTP_FORBIDDEN);
    }

    foreach ($data as $key => $value) {
      $error = $this->validator->validateDelegationValue((string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
    }

    $current = $this->normalizeDelegation($this->configService->getSection($group, 'delegation'));
    $merged = array_merge($current, array_intersect_key($data, array_flip(self::DELEGATION_KEYS)));
    return $this->persistSection($group, 'delegation', [
      'noteRequired' => $merged['noteRequired'],
      'personalAssignment' => $merged['personalAssignment'],
    ]);
  }

  /**
   * Validates and applies an applicant-profile-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyApplicantProfile(GroupInterface $group, array $data): ?JsonResponse {
    foreach ($data as $key => $value) {
      $error = $this->validator->validateApplicantProfileValue((string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
    }

    $current = $this->normalizeApplicantProfile($this->configService->getSection($group, 'applicant_profile'));
    $merged = array_merge($current, array_intersect_key($data, array_flip(self::APPLICANT_PROFILE_KEYS)));
    return $this->persistSection($group, 'applicant_profile', [
      'enabled' => $merged['enabled'],
      'scope' => $merged['scope'],
      'bankConsent' => $merged['bankConsent'],
    ]);
  }

  /**
   * Validates and applies the jurisdiction-level citizen assistant policy.
   */
  private function applyAssistant(GroupInterface $group, array $data): ?JsonResponse {
    foreach ($data as $key => $value) {
      $error = $this->validator->validateAssistantValue((string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
    }

    return $this->persistMergedSection(
      $group,
      'assistant',
      array_intersect_key($data, array_flip(self::ASSISTANT_KEYS)),
    );
  }

  /**
   * Validates and applies an embed-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyEmbed(GroupInterface $group, array $data): ?JsonResponse {
    foreach ($data as $key => $value) {
      $error = $this->validator->validateEmbedValue((string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
    }

    $current = $this->normalizeEmbed($this->configService->getSection($group, 'embed'));
    $merged = array_merge($current, array_intersect_key($data, array_flip(self::EMBED_KEYS)));
    return $this->persistSection($group, 'embed', [
      'defaultTheme' => $merged['defaultTheme'],
      'defaultWidth' => $merged['defaultWidth'],
      'defaultHeight' => $merged['defaultHeight'],
      'showBorder' => (bool) $merged['showBorder'],
    ]);
  }

  /**
   * Validates and applies the jurisdiction boundary section.
   */
  private function applyBoundary(GroupInterface $group, array $data): ?JsonResponse {
    $result = BoundaryGeoJsonValidator::validate($data['boundary'] ?? NULL);
    if (!$result['valid']) {
      return $this->errorResponse((string) $result['error'], 'boundary');
    }

    return $this->persistSection(
      $group,
      'boundary',
      $result['normalized'] === NULL ? [] : ['geojson' => $result['normalized']],
    );
  }

  /**
   * Validates and applies an uploads-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyUploads(GroupInterface $group, array $data): ?JsonResponse {
    foreach ($data as $key => $value) {
      $error = $this->validator->validateUploadsValue((string) $key, $value);
      if ($error !== NULL) {
        return $this->errorResponse($error, (string) $key);
      }
    }

    $current = $this->normalizeUploads($this->configService->getSection($group, 'uploads'));
    $merged = array_merge($current, array_intersect_key($data, array_flip(self::UPLOADS_KEYS)));
    return $this->persistSection($group, 'uploads', [
      'maxStorageBytes' => is_string($merged['maxStorageBytes']) ? (int) trim($merged['maxStorageBytes']) : $merged['maxStorageBytes'],
      'warningThresholdPercent' => is_string($merged['warningThresholdPercent']) ? (int) trim($merged['warningThresholdPercent']) : $merged['warningThresholdPercent'],
    ]);
  }

  /**
   * Validates and applies a notification_mail-section PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param array $data
   *   The allowlisted PATCH data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function applyNotificationMail(GroupInterface $group, array $data): ?JsonResponse {
    $templates = $data['templates'] ?? NULL;
    if (!is_array($templates)) {
      return $this->errorResponse('templates must be an object.', 'templates');
    }

    $catalog = $this->notificationTemplateCatalog();
    if (!$catalog instanceof NotificationTemplateCatalogInterface) {
      return $this->errorResponse('Notification mail templates are unavailable.', NULL, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    $sanitized = [];
    foreach ($templates as $templateKey => $templatePatch) {
      $templateKey = (string) $templateKey;
      if (!$catalog->has($templateKey)) {
        return $this->errorResponse('Unknown template key: ' . $templateKey . '.', 'templates.' . $templateKey);
      }
      if (!is_array($templatePatch)) {
        return $this->errorResponse('Template payload must be an object.', 'templates.' . $templateKey);
      }

      $unknown = array_diff(array_keys($templatePatch), self::NOTIFICATION_TEMPLATE_KEYS);
      if ($unknown !== []) {
        $unknown_key = (string) reset($unknown);
        return $this->errorResponse('Unknown template field: ' . $unknown_key . '.', 'templates.' . $templateKey . '.' . $unknown_key);
      }

      $sanitized[$templateKey] = [];
      if (array_key_exists('enabled', $templatePatch)) {
        if (!is_bool($templatePatch['enabled'])) {
          return $this->errorResponse('enabled must be a boolean.', 'templates.' . $templateKey . '.enabled');
        }
        if ($templatePatch['enabled'] === FALSE && $catalog->isLockedEnabled($templateKey)) {
          return $this->errorResponse($templateKey . ' is a system template and cannot be disabled.', 'templates.' . $templateKey . '.enabled');
        }
        $sanitized[$templateKey]['enabled'] = $templatePatch['enabled'];
      }

      if (array_key_exists('default', $templatePatch)) {
        $default = $this->sanitizeNotificationTemplateTextEntry(
          $templatePatch['default'],
          'templates.' . $templateKey . '.default',
        );
        if ($default instanceof JsonResponse) {
          return $default;
        }
        if ($default !== []) {
          $sanitized[$templateKey]['default'] = $default;
        }
      }

      if (array_key_exists('translations', $templatePatch)) {
        if (!is_array($templatePatch['translations'])) {
          return $this->errorResponse('translations must be an object.', 'templates.' . $templateKey . '.translations');
        }
        $translations = [];
        foreach ($templatePatch['translations'] as $langcode => $translation) {
          $langcode = (string) $langcode;
          if (!in_array($langcode, JurisdictionConfigValidator::SUPPORTED_LANGUAGES, TRUE)) {
            return $this->errorResponse('Invalid langcode: ' . $langcode . '.', 'templates.' . $templateKey . '.translations.' . $langcode);
          }
          $entry = $this->sanitizeNotificationTemplateTextEntry(
            $translation,
            'templates.' . $templateKey . '.translations.' . $langcode,
          );
          if ($entry instanceof JsonResponse) {
            return $entry;
          }
          if ($entry !== []) {
            $translations[$langcode] = $entry;
          }
        }
        $sanitized[$templateKey]['translations'] = $translations;
      }

      if ($catalog->isLockedEnabled($templateKey)) {
        $sanitized[$templateKey]['enabled'] = TRUE;
      }
    }

    $current = $this->normalizeNotificationMailStorage($this->configService->getSection($group, 'notification_mail'));
    foreach ($sanitized as $templateKey => $templatePatch) {
      $current['templates'][$templateKey] = $this->mergeNotificationTemplatePatch(
        $current['templates'][$templateKey] ?? [],
        $templatePatch,
      );
    }
    $required_token_error = $this->validateNotificationMailRequiredTokens(
      $current,
      $catalog,
      array_keys($sanitized),
    );
    if ($required_token_error instanceof JsonResponse) {
      return $required_token_error;
    }

    return $this->persistSection($group, 'notification_mail', $current);
  }

  /**
   * Persists a JSON section (and any pending discrete field changes).
   *
   * The config service writes the JSON blob onto the untranslated entity and
   * saves it, which also persists discrete field values already set on the
   * same entity object — one save per PATCH.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param string $jsonKey
   *   The top-level field_jur_config key.
   * @param array $values
   *   The new section values.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function persistSection(GroupInterface $group, string $jsonKey, array $values): ?JsonResponse {
    try {
      $this->configService->updateSection($group, $jsonKey, $values);
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to save settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return $this->errorResponse('Failed to save settings.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return NULL;
  }

  /**
   * Atomically merges a partial JSON section onto the freshest stored values.
   */
  private function persistMergedSection(GroupInterface $group, string $jsonKey, array $values): ?JsonResponse {
    try {
      $this->configService->mergeSection($group, $jsonKey, $values);
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to save settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return $this->errorResponse('Failed to save settings.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return NULL;
  }

  /**
   * Saves a group entity for PATCHes that only touch discrete fields.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The (untranslated) jurisdiction group with pending field changes.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   An error response, or NULL on success.
   */
  private function saveGroup(GroupInterface $group): ?JsonResponse {
    try {
      $this->configService->saveGroup($group);
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Failed to save settings for jurisdiction @id: @message',
        ['@id' => $group->id(), '@message' => $e->getMessage()]
      );
      return $this->errorResponse('Failed to save settings.', NULL, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return NULL;
  }

  /**
   * Builds the {"section", "data"} envelope for one section.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param string $section
   *   The settings section.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON envelope response.
   */
  private function sectionResponse(GroupInterface $group, string $section): JsonResponse {
    $response = new JsonResponse([
      'section' => $section,
      'data' => $this->buildSectionData($group, $section),
      'jurisdiction' => [
        'id' => (string) $group->id(),
        'slug' => $this->fieldString($group->getUntranslated(), 'field_slug'),
      ],
    ]);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

  /**
   * Builds the data shape of one settings section.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param string $section
   *   The settings section.
   *
   * @return array
   *   The section data.
   */
  private function buildSectionData(GroupInterface $group, string $section): array {
    $source = $group->getUntranslated();

    switch ($section) {
      case 'general':
        $general = $this->configService->getSection($group, 'general');
        $data = [];
        foreach (self::GENERAL_ENTITY_FIELDS as $apiKey => $fieldName) {
          $data[$apiKey] = $this->fieldString($source, $fieldName);
        }
        foreach (self::GENERAL_JSON_KEYS as $apiKey) {
          $data[$apiKey] = $this->jsonString($general, $apiKey);
        }
        return $data;

      case 'branding':
        $theme = $this->configService->getSection($group, 'theme');
        $data = [];
        foreach (self::BRANDING_COLOR_KEYS as $apiKey => $jsonKey) {
          $data[$apiKey] = $this->jsonString($theme, $jsonKey);
        }
        foreach (self::BRANDING_LOGO_KEYS as $apiKey => $jsonKey) {
          $data[$apiKey] = $this->jsonString($theme, $jsonKey);
        }
        $data['customCss'] = $this->fieldString($source, 'field_custom_css');
        return $data;

      case 'languages':
        return $this->normalizeLanguages($this->configService->getSection($group, 'languages'));

      case 'retention':
        return $this->normalizeRetention($this->configService->getSection($group, 'retention'));

      case 'resubmission':
        return $this->normalizeResubmission($this->configService->getSection($group, 'resubmission'));

      case 'delegation':
        $data = $this->normalizeDelegation($this->configService->getSection($group, 'delegation'));
        $root_id = $this->hierarchyResolver->getRootJurisdictionId((int) $group->id());
        if ($root_id !== NULL && $root_id !== (int) $group->id()) {
          $root = $this->entityTypeManager->getStorage('group')->load($root_id);
          if ($root instanceof GroupInterface) {
            $root_data = $this->normalizeDelegation($this->configService->getSection($root, 'delegation'));
            $data['personalAssignment'] = $root_data['personalAssignment'];
          }
          $data['personalAssignmentEditable'] = FALSE;
        }
        return $data;

      case 'applicant_profile':
        return $this->normalizeApplicantProfile($this->configService->getSection($group, 'applicant_profile'))
          + $this->applicantProfileConsentTexts();

      case 'assistant':
        return $this->normalizeAssistant($this->configService->getSection($group, 'assistant'));

      case 'embed':
        return $this->normalizeEmbed($this->configService->getSection($group, 'embed'));

      case 'uploads':
        return $this->uploadSettingsData($group);

      case 'notification_mail':
        return $this->notificationMailSettingsData($group);

      case 'boundary':
        return ['boundary' => $this->normalizedBoundary($group)];

      default:
        return [];
    }
  }

  /**
   * Returns the per-section allowlist of PATCHable keys.
   *
   * @param string $section
   *   The settings section.
   *
   * @return string[]
   *   The allowed data keys.
   */
  private function sectionAllowedKeys(string $section): array {
    return match ($section) {
      'general' => array_merge(array_keys(self::GENERAL_ENTITY_FIELDS), self::GENERAL_JSON_KEYS),
      'branding' => array_merge(array_keys(self::BRANDING_COLOR_KEYS), array_keys(self::BRANDING_LOGO_KEYS), ['customCss']),
      'languages' => self::LANGUAGES_KEYS,
      'retention' => self::RETENTION_KEYS,
      'resubmission' => self::RESUBMISSION_KEYS,
      'delegation' => self::DELEGATION_KEYS,
      'applicant_profile' => self::APPLICANT_PROFILE_KEYS,
      'assistant' => self::ASSISTANT_KEYS,
      'embed' => self::EMBED_KEYS,
      'uploads' => self::UPLOADS_KEYS,
      'notification_mail' => self::NOTIFICATION_MAIL_KEYS,
      'boundary' => ['boundary'],
      default => [],
    };
  }

  /**
   * Returns a stored boundary only when it still passes normalization.
   *
   * Seeded or imported settings can bypass the admin PATCH validator. Invalid
   * stored geometry therefore degrades to no boundary instead of leaking an
   * unsupported shape or rejecting citizen locations.
   */
  private function normalizedBoundary(GroupInterface $group): ?array {
    $section = $this->configService->getSection($group, 'boundary');
    $result = BoundaryGeoJsonValidator::validate($section['geojson'] ?? NULL);
    return $result['valid'] ? $result['normalized'] : NULL;
  }

  /**
   * Builds notification mail template settings for administration.
   *
   * @return array<string, mixed>
   *   Notification mail settings.
   */
  private function notificationMailSettingsData(GroupInterface $group): array {
    $catalog = $this->notificationTemplateCatalog();
    if (!$catalog instanceof NotificationTemplateCatalogInterface) {
      return ['templates' => []];
    }

    $stored = $this->normalizeNotificationMailStorage($this->configService->getSection($group, 'notification_mail'));
    $data = ['templates' => []];
    foreach ($catalog->all() as $templateKey => $definition) {
      $template = $stored['templates'][$templateKey] ?? [];
      $default = is_array($template['default'] ?? NULL) ? $template['default'] : [];
      $translations = is_array($template['translations'] ?? NULL) ? $template['translations'] : [];
      $enabled = is_bool($template['enabled'] ?? NULL)
        ? $template['enabled']
        : $definition['enabled'];

      if ($catalog->isLockedEnabled($templateKey)) {
        $enabled = TRUE;
      }

      $data['templates'][$templateKey] = [
        'id' => $templateKey,
        'enabled' => $enabled,
        'lockedEnabled' => $definition['lockedEnabled'],
        'requiredTokens' => $definition['requiredTokens'],
        'default' => [
          'subject' => is_string($default['subject'] ?? NULL) ? $default['subject'] : $definition['subject'],
          'body' => is_string($default['body'] ?? NULL) ? $default['body'] : $definition['body'],
        ],
        'translations' => $translations,
      ];
    }

    return $data;
  }

  /**
   * Builds uploads policy plus durable attachment usage for a jurisdiction.
   *
   * @return array<string, int|string|bool>
   *   Upload settings and usage.
   */
  private function uploadSettingsData(GroupInterface $group): array {
    $policy = $this->normalizeUploads($this->configService->getSection($group, 'uploads'));
    $usage = $this->attachmentStorageQuota->usageForJurisdiction($group);
    return $this->attachmentStorageQuota->summaryFromUsage($usage['usedStorageBytes'], $usage['attachmentCount'], $policy);
  }

  /**
   * Normalizes upload quota settings to the API shape.
   *
   * @param array $uploads
   *   The stored uploads config.
   *
   * @return array{maxStorageBytes: int, warningThresholdPercent: int}
   *   Normalized policy.
   */
  private function normalizeUploads(array $uploads): array {
    return AttachmentStorageQuota::normalizePolicy($uploads);
  }

  /**
   * Returns the optional notification template catalogue.
   */
  private function notificationTemplateCatalog(): ?NotificationTemplateCatalogInterface {
    return $this->notificationTemplateCatalog;
  }

  /**
   * Normalizes persisted notification mail settings to the storage shape.
   *
   * @param array $section
   *   Stored notification_mail section.
   *
   * @return array{templates: array<string, array<string, mixed>>}
   *   Normalized storage data.
   */
  private function normalizeNotificationMailStorage(array $section): array {
    $catalog = $this->notificationTemplateCatalog();
    if (!$catalog instanceof NotificationTemplateCatalogInterface) {
      return ['templates' => []];
    }

    $stored_templates = is_array($section['templates'] ?? NULL) ? $section['templates'] : [];
    $templates = [];
    foreach ($stored_templates as $templateKey => $template) {
      $templateKey = (string) $templateKey;
      if (!$catalog->has($templateKey) || !is_array($template)) {
        continue;
      }

      $normalized = [];
      if (is_bool($template['enabled'] ?? NULL)) {
        $normalized['enabled'] = $template['enabled'];
      }
      if ($catalog->isLockedEnabled($templateKey)) {
        $normalized['enabled'] = TRUE;
      }

      $default = $this->normalizeNotificationTemplateTextEntry($template['default'] ?? NULL);
      if ($default !== []) {
        $normalized['default'] = $default;
      }

      $translations = [];
      if (is_array($template['translations'] ?? NULL)) {
        foreach ($template['translations'] as $langcode => $translation) {
          $langcode = (string) $langcode;
          if (!in_array($langcode, JurisdictionConfigValidator::SUPPORTED_LANGUAGES, TRUE)) {
            continue;
          }
          $entry = $this->normalizeNotificationTemplateTextEntry($translation);
          if ($entry !== []) {
            $translations[$langcode] = $entry;
          }
        }
      }
      if ($translations !== []) {
        $normalized['translations'] = $translations;
      }

      $templates[$templateKey] = $normalized;
    }

    return ['templates' => $templates];
  }

  /**
   * Normalizes one stored notification template text entry.
   *
   * @return array<string, string>
   *   Normalized subject/body values.
   */
  private function normalizeNotificationTemplateTextEntry(mixed $entry): array {
    if (!is_array($entry)) {
      return [];
    }

    $normalized = [];
    foreach (self::NOTIFICATION_TEMPLATE_TEXT_KEYS as $key) {
      if (array_key_exists($key, $entry) && is_string($entry[$key])) {
        if (trim($entry[$key]) !== '') {
          $normalized[$key] = $entry[$key];
        }
      }
    }
    return $normalized;
  }

  /**
   * Validates and sanitizes one incoming notification template text entry.
   *
   * @return array<string, string>|\Symfony\Component\HttpFoundation\JsonResponse
   *   Sanitized text entry, or an error response.
   */
  private function sanitizeNotificationTemplateTextEntry(mixed $entry, string $path): array|JsonResponse {
    if (!is_array($entry)) {
      return $this->errorResponse('Template text entry must be an object.', $path);
    }

    $unknown = array_diff(array_keys($entry), self::NOTIFICATION_TEMPLATE_TEXT_KEYS);
    if ($unknown !== []) {
      $unknown_key = (string) reset($unknown);
      return $this->errorResponse('Unknown template text field: ' . $unknown_key . '.', $path . '.' . $unknown_key);
    }

    $sanitized = [];
    foreach (self::NOTIFICATION_TEMPLATE_TEXT_KEYS as $key) {
      if (!array_key_exists($key, $entry)) {
        continue;
      }
      if (!is_string($entry[$key])) {
        return $this->errorResponse($key . ' must be a string.', $path . '.' . $key);
      }
      $max_length = $key === 'subject' ? self::NOTIFICATION_SUBJECT_MAX_LENGTH : self::NOTIFICATION_BODY_MAX_LENGTH;
      if (mb_strlen($entry[$key]) > $max_length) {
        return $this->errorResponse($key . ' must not exceed ' . $max_length . ' characters.', $path . '.' . $key);
      }
      $value = strip_tags($entry[$key]);
      if (trim($value) === '') {
        continue;
      }
      $sanitized[$key] = $value;
    }

    return $sanitized;
  }

  /**
   * Applies a validated partial template patch to stored template data.
   *
   * Submitted translations replace all stored translations. Omitting the key
   * leaves stored translations unchanged.
   *
   * @param array<string, mixed> $current
   *   Current stored template data.
   * @param array<string, mixed> $patch
   *   Sanitized partial patch.
   *
   * @return array<string, mixed>
   *   Merged template data.
   */
  private function mergeNotificationTemplatePatch(array $current, array $patch): array {
    $merged = $current;
    if (array_key_exists('enabled', $patch)) {
      $merged['enabled'] = $patch['enabled'];
    }
    if (isset($patch['default']) && is_array($patch['default'])) {
      $merged['default'] = array_merge(
        is_array($merged['default'] ?? NULL) ? $merged['default'] : [],
        $patch['default'],
      );
    }
    if (array_key_exists('translations', $patch) && is_array($patch['translations'])) {
      if ($patch['translations'] === []) {
        unset($merged['translations']);
      }
      else {
        $merged['translations'] = $patch['translations'];
      }
    }

    return $merged;
  }

  /**
   * Validates that effective template text keeps catalogue-required tokens.
   *
   * @param array{templates: array<string, array<string, mixed>>} $section
   *   Normalized notification_mail section.
   * @param \Drupal\beantragt_group\Service\NotificationTemplateCatalogInterface $catalog
   *   The notification template catalogue.
   * @param list<string> $templateKeys
   *   Template keys present in the incoming PATCH payload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   Error response when a required token is missing.
   */
  private function validateNotificationMailRequiredTokens(array $section, NotificationTemplateCatalogInterface $catalog, array $templateKeys): ?JsonResponse {
    foreach ($templateKeys as $templateKey) {
      $templateKey = (string) $templateKey;
      $definition = $catalog->get($templateKey);
      if ($definition === NULL || $definition['requiredTokens'] === []) {
        continue;
      }

      $template = is_array($section['templates'][$templateKey] ?? NULL)
        ? $section['templates'][$templateKey]
        : [];
      $default = is_array($template['default'] ?? NULL) ? $template['default'] : [];
      $default_text = [
        'subject' => is_string($default['subject'] ?? NULL) ? $default['subject'] : $definition['subject'],
        'body' => is_string($default['body'] ?? NULL) ? $default['body'] : $definition['body'],
      ];
      $error = $this->missingRequiredTokenError(
        $templateKey,
        'default',
        $default_text,
        $definition['requiredTokens'],
      );
      if ($error instanceof JsonResponse) {
        return $error;
      }

      $translations = is_array($template['translations'] ?? NULL) ? $template['translations'] : [];
      foreach ($translations as $langcode => $translation) {
        if (!is_array($translation)) {
          continue;
        }
        $translation_text = [
          'subject' => is_string($translation['subject'] ?? NULL) ? $translation['subject'] : $default_text['subject'],
          'body' => is_string($translation['body'] ?? NULL) ? $translation['body'] : $default_text['body'],
        ];
        $error = $this->missingRequiredTokenError(
          $templateKey,
          'translations.' . $langcode,
          $translation_text,
          $definition['requiredTokens'],
        );
        if ($error instanceof JsonResponse) {
          return $error;
        }
      }
    }

    return NULL;
  }

  /**
   * Returns an error response when a text entry misses a required token.
   *
   * @param string $templateKey
   *   Notification template key.
   * @param string $path
   *   Template entry path below templates.<templateKey>.
   * @param array{subject: string, body: string} $text
   *   Effective template text.
   * @param list<string> $requiredTokens
   *   Required token strings.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   Error response when a token is missing.
   */
  private function missingRequiredTokenError(string $templateKey, string $path, array $text, array $requiredTokens): ?JsonResponse {
    $combined = $text['subject'] . "\n" . $text['body'];
    foreach ($requiredTokens as $token) {
      $body_only = $templateKey === 'application_confirmation_request' && $token === '@confirmation_url';
      $haystack = $body_only ? $text['body'] : $combined;
      if (!str_contains($haystack, $token)) {
        return $this->errorResponse(
          'Template ' . $templateKey . ' must include required token ' . $token . '.',
          'templates.' . $templateKey . '.' . $path . ($body_only ? '.body' : ''),
        );
      }
    }

    return NULL;
  }

  /**
   * Normalizes stored embed defaults to the API shape.
   *
   * @param array $embed
   *   The stored embed config.
   *
   * @return array
   *   Embed defaults with stable keys.
   */
  private function normalizeEmbed(array $embed): array {
    $normalized = self::EMBED_DEFAULT;
    foreach (self::EMBED_KEYS as $key) {
      if (!array_key_exists($key, $embed)) {
        continue;
      }
      $value = $embed[$key];
      if ($key === 'showBorder') {
        if (is_bool($value)) {
          $normalized[$key] = $value;
        }
        continue;
      }
      if (is_string($value) && $this->validator->validateEmbedValue($key, $value) === NULL) {
        $normalized[$key] = $value;
      }
    }
    return $normalized;
  }

  /**
   * Normalizes a stored retention config to the API shape.
   *
   * @param array $retention
   *   The stored retention config.
   *
   * @return array
   *   Retention config with nullable dataRetentionDays.
   */
  private function normalizeRetention(array $retention): array {
    $days = $retention['dataRetentionDays'] ?? NULL;
    if (is_int($days)) {
      return ['dataRetentionDays' => $days];
    }
    if (is_string($days) && preg_match('/^\d+$/', $days) === 1) {
      return ['dataRetentionDays' => (int) $days];
    }
    return ['dataRetentionDays' => NULL];
  }

  /**
   * Normalizes a stored resubmission config to the API shape.
   *
   * @param array $resubmission
   *   The stored resubmission config.
   *
   * @return array
   *   Resubmission config with nullable reminderDays and reminderScope.
   */
  private function normalizeResubmission(array $resubmission): array {
    $days = $resubmission['reminderDays'] ?? NULL;
    if (is_string($days) && preg_match('/^\d+$/', trim($days)) === 1) {
      $days = (int) trim($days);
    }
    if (!is_int($days) || $days < 1 || $days > 365) {
      $days = NULL;
    }

    $scope = $resubmission['reminderScope'] ?? NULL;
    if (!is_string($scope) || !in_array($scope, ['org', 'user'], TRUE)) {
      $scope = NULL;
    }

    return [
      'reminderDays' => $days,
      'reminderScope' => $scope,
    ];
  }

  /**
   * Normalizes stored delegation config to the API shape.
   *
   * @param array $delegation
   *   The stored delegation config.
   *
   * @return array
   *   Delegation config with a stable noteRequired boolean.
   */
  private function normalizeDelegation(array $delegation): array {
    return [
      'personalAssignment' => ($delegation['personalAssignment'] ?? TRUE) !== FALSE,
      'noteRequired' => is_bool($delegation['noteRequired'] ?? NULL)
        ? $delegation['noteRequired']
        : FALSE,
    ];
  }

  /**
   * Normalizes stored applicant profile settings to the API shape.
   *
   * Invalid or missing values fall back to the disabled default, so a damaged
   * blob never switches profile reuse on.
   *
   * @param array $applicant_profile
   *   The stored applicant profile config.
   *
   * @return array{enabled: bool, scope: string, bankConsent: bool}
   *   Applicant profile settings.
   */
  private function normalizeApplicantProfile(array $applicant_profile): array {
    $normalized = self::APPLICANT_PROFILE_DEFAULT;
    foreach (self::APPLICANT_PROFILE_KEYS as $key) {
      if (array_key_exists($key, $applicant_profile)
        && $this->validator->validateApplicantProfileValue($key, $applicant_profile[$key]) === NULL) {
        $normalized[$key] = $applicant_profile[$key];
      }
    }
    return $normalized;
  }

  /**
   * Returns the backend-owned consent templates with the {stelle} placeholder.
   *
   * The public config is cached per path and shared, so the label of the
   * organisation or jurisdiction is substituted by the Nuxt proxy.
   *
   * @return array{consentTextVersion: string, consentText: array<string, string>, bankConsentTextVersion: string, bankConsentText: array<string, string>}
   *   Versions and templates keyed by locale, one pair per consent kind.
   */
  private function applicantProfileConsentTexts(): array {
    return [
      'consentTextVersion' => ProfileConsentText::version(ProfileConsentText::KIND_CONTACT),
      'consentText' => ProfileConsentText::templates(ProfileConsentText::KIND_CONTACT),
      'bankConsentTextVersion' => ProfileConsentText::version(ProfileConsentText::KIND_BANK),
      'bankConsentText' => ProfileConsentText::templates(ProfileConsentText::KIND_BANK),
    ];
  }

  /**
   * Normalizes stored citizen assistant policy to the stable admin API shape.
   */
  private function normalizeAssistant(array $assistant): array {
    $normalized = self::ASSISTANT_DEFAULT;
    foreach (self::ASSISTANT_KEYS as $key) {
      if (!array_key_exists($key, $assistant)) {
        continue;
      }
      $value = $assistant[$key];
      if ($this->validator->validateAssistantValue($key, $value) === NULL) {
        $normalized[$key] = $value;
      }
    }
    return $normalized;
  }

  /**
   * Normalizes a stored languages config to the API shape.
   *
   * @param array $languages
   *   The stored languages config.
   *
   * @return array
   *   Languages config with guaranteed default/available keys.
   */
  private function normalizeLanguages(array $languages): array {
    $default = isset($languages['default']) && is_string($languages['default']) && $languages['default'] !== ''
      ? $languages['default']
      : self::LANGUAGES_DEFAULT['default'];
    $available = isset($languages['available']) && is_array($languages['available']) && $languages['available'] !== []
      ? array_values($languages['available'])
      : self::LANGUAGES_DEFAULT['available'];

    return [
      'default' => $default,
      'available' => $available,
    ];
  }

  /**
   * Loads a jurisdiction group entity from a slug or numeric ID.
   *
   * @param string $jurisdiction_id
   *   The jurisdiction identifier (numeric ID or slug).
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The loaded group entity, or NULL if not found or wrong bundle.
   */
  private function loadJurisdictionGroup(string $jurisdiction_id): ?GroupInterface {
    $resolved_id = $this->resolveJurisdictionId($jurisdiction_id);
    if ($resolved_id === NULL) {
      return NULL;
    }

    $group = $this->entityTypeManager()->getStorage('group')->load($resolved_id);
    if (!$this->isJurisdictionGroup($group)) {
      return NULL;
    }

    /** @var \Drupal\group\Entity\GroupInterface $group */
    return $group;
  }

  /**
   * Reads a string field value from a group entity.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity (default translation).
   * @param string $fieldName
   *   The field machine name.
   *
   * @return string
   *   The field value, or an empty string.
   */
  private function fieldString(GroupInterface $group, string $fieldName): string {
    if ($group->hasField($fieldName) && !$group->get($fieldName)->isEmpty()) {
      return (string) $group->get($fieldName)->value;
    }
    return '';
  }

  /**
   * Normalizes a public jurisdiction slug.
   *
   * @param string $slug
   *   The raw route slug.
   *
   * @return string|null
   *   The normalized slug, or NULL when invalid.
   */
  private function normalizePublicSlug(string $slug): ?string {
    $slug = strtolower(trim($slug));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Loads one published jurisdiction group by public slug.
   *
   * @param string $slug
   *   The normalized public slug.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The jurisdiction group, or NULL when no unique match exists.
   */
  private function loadPublishedJurisdictionBySlug(string $slug): ?GroupInterface {
    $groups = $this->entityTypeManager()->getStorage('group')->loadByProperties([
      'type' => $this->getJurisdictionGroupType(),
      'field_slug' => $slug,
      'status' => 1,
    ]);

    if (count($groups) !== 1) {
      return NULL;
    }

    $group = reset($groups);
    return $group instanceof GroupInterface ? $group : NULL;
  }

  /**
   * Builds the public jurisdiction config payload.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   * @param string $slug
   *   The normalized public slug.
   * @param bool $include_numeric_id
   *   Whether to include the legacy numeric Drupal group ID.
   * @param bool $include_uuid
   *   Whether to include the external group UUID.
   *
   * @return array<string, mixed>
   *   Public, non-PII configuration data.
   */
  private function publicConfigPayload(GroupInterface $group, string $slug, bool $include_numeric_id, bool $include_uuid): array {
    $source = $group->getUntranslated();
    $general = $this->configService->getSection($group, 'general');
    $languages = $this->configService->getSection($group, 'languages');
    $applicant_profile = $this->normalizeApplicantProfile($this->configService->getSection($group, 'applicant_profile'))
      + $this->applicantProfileConsentTexts();

    $jurisdiction = [
      'slug' => $slug,
      'name' => $this->publicJurisdictionName($source),
    ];
    if ($include_numeric_id) {
      $jurisdiction = ['id' => (int) $group->id()] + $jurisdiction;
    }
    if ($include_uuid) {
      $jurisdiction['uuid'] = (string) $group->uuid();
    }

    return [
      'jurisdiction' => $jurisdiction,
      'theme' => (object) $this->publicTheme($this->configService->getSection($group, 'theme')),
      'languages' => $this->normalizeLanguages($languages),
      'general' => [
        'contactEmail' => $this->jsonString($general, 'contactEmail'),
        'website' => $this->jsonString($general, 'website'),
        'phone' => $this->jsonString($general, 'phone'),
      ],
      'legal' => [
        'legalNotice' => $this->fieldString($source, 'field_legal_notice'),
        'privacyPolicy' => $this->fieldString($source, 'field_privacy_policy'),
      ],
      'customCss' => $this->fieldString($source, 'field_custom_css'),
      'applicantProfile' => $applicant_profile,
    ];
  }

  /**
   * Builds a v1 public API error response.
   *
   * @param string $code
   *   Stable error code.
   * @param string $message
   *   Human-readable error message.
   * @param int $status
   *   HTTP status code.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache_metadata
   *   Cache metadata for the response.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   Cacheable v1 error response.
   */
  private function publicApiErrorResponse(string $code, string $message, int $status, CacheableMetadata $cache_metadata): CacheableJsonResponse {
    $response = new CacheableJsonResponse([
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
      'meta' => [
        'apiVersion' => '1.0',
      ],
    ], $status);
    $response->addCacheableDependency($cache_metadata);
    $response->setMaxAge($cache_metadata->getCacheMaxAge());
    $response->setPublic();
    return $response;
  }

  /**
   * Returns the public display name for a jurisdiction.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity (default translation).
   *
   * @return string
   *   The platform name when it is configured, otherwise the group label.
   */
  private function publicJurisdictionName(GroupInterface $group): string {
    $platform_name = trim($this->fieldString($group, 'field_platform_name'));
    if ($platform_name !== '' && strcasecmp($platform_name, 'beantragt.ai') !== 0) {
      return $platform_name;
    }

    $label = trim((string) $group->label());
    return $label !== '' ? $label : $platform_name;
  }

  /**
   * Projects stored theme config to the public allowlisted shape.
   *
   * @param array $theme
   *   Stored theme data from field_jur_config.
   *
   * @return array
   *   Public theme data limited to known non-sensitive keys.
   */
  private function publicTheme(array $theme): array {
    $public_theme = [];
    foreach (['primary', 'secondary', 'neutral', 'logoDataUrl', 'logoDarkDataUrl', 'logoAlt'] as $key) {
      if (array_key_exists($key, $theme) && is_scalar($theme[$key])) {
        $public_theme[$key] = (string) $theme[$key];
      }
    }
    return $public_theme;
  }

  /**
   * Reads a string value from decoded JSON section data.
   *
   * @param array $data
   *   The decoded section data.
   * @param string $key
   *   The key to read.
   *
   * @return string
   *   The value, or an empty string for missing/non-scalar values.
   */
  private function jsonString(array $data, string $key): string {
    $value = $data[$key] ?? '';
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Builds the error envelope response.
   *
   * @param string $message
   *   The human-readable error message.
   * @param string|null $field
   *   The offending field/key, when applicable.
   * @param int $status
   *   The HTTP status code.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The error response.
   */
  private function errorResponse(string $message, ?string $field = NULL, int $status = Response::HTTP_BAD_REQUEST): JsonResponse {
    $error = ['message' => $message];
    if ($field !== NULL) {
      $error['field'] = $field;
    }
    return new JsonResponse(['error' => $error], $status);
  }

}
