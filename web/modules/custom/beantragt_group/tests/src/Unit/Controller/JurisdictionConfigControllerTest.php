<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Controller;

use Drupal\beantragt_application\ProfileConsentText;
use Drupal\beantragt_group\Controller\JurisdictionConfigController;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\beantragt_group\Service\JurisdictionConfigValidator;
use Drupal\beantragt_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\beantragt_group\Service\NotificationTemplateCatalogInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the jurisdiction settings controller request handling.
 */
#[CoversClass(JurisdictionConfigController::class)]
#[Group('beantragt_group')]
final class JurisdictionConfigControllerTest extends UnitTestCase {

  /**
   * The config service mock.
   */
  private JurisdictionConfigServiceInterface $configService;

  /**
   * The group storage mock.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Application storage mock.
   */
  private EntityStorageInterface $applicationStorage;

  /**
   * Application attachment storage mock.
   */
  private EntityStorageInterface $attachmentStorage;

  /**
   * Entity type manager mock.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Current user mock.
   */
  private AccountInterface $account;

  /**
   * Notification template catalog mock.
   */
  private NotificationTemplateCatalogInterface $notificationTemplateCatalog;

  /**
   * Jurisdiction hierarchy resolver mock.
   */
  private JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The controller under test.
   */
  private JurisdictionConfigController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $container = new ContainerBuilder();
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->applicationStorage = $this->createMock(EntityStorageInterface::class);
    $this->attachmentStorage = $this->createMock(EntityStorageInterface::class);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnCallback(
      fn(string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
        'group' => $this->groupStorage,
        'application' => $this->applicationStorage,
        'application_attachment' => $this->attachmentStorage,
        default => throw new \InvalidArgumentException($entity_type_id),
      }
    );

    $this->account = $this->createMock(AccountInterface::class);
    $this->account->method('getDisplayName')->willReturn('test-admin');

    $this->configService = $this->createMock(JurisdictionConfigServiceInterface::class);
    $this->configService->method('withLockedGroup')
      ->willReturnCallback(
        static fn(GroupInterface $group, callable $callback): mixed => $callback($group),
      );
    $this->configService->method('saveGroup')
      ->willReturnCallback(function (GroupInterface $group): void {
        $fresh = $this->groupStorage->loadUnchanged($group->id());
        if ($fresh instanceof GroupInterface && $group->hasField('field_jur_config')) {
          $source = $fresh->getUntranslated();
          if ($source->hasField('field_jur_config')) {
            $group->set(
              'field_jur_config',
              $source->get('field_jur_config')->isEmpty()
                ? NULL
                : $source->get('field_jur_config')->value,
            );
          }
        }
        $group->save();
      });
    $this->hierarchyResolver = $this->createMock(JurisdictionHierarchyResolverInterface::class);
    $this->notificationTemplateCatalog = $this->createNotificationTemplateCatalog();
    $this->controller = $this->createController($this->notificationTemplateCatalog);
  }

  /**
   * Notification mail endpoints fail closed without the optional catalog.
   */
  public function testNotificationMailFailsClosedWithoutCatalog(): void {
    $controller = $this->createController(NULL);
    $this->groupStorage->expects($this->never())->method('load');
    $this->configService->expects($this->never())->method('updateSection');

    $get = $controller->getSection('3', 'notification_mail');
    $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $get->getStatusCode());

    $patch = $controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"templates":{}}}'),
      '3',
      'notification_mail'
    );
    $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $patch->getStatusCode());
  }

  /**
   * Unknown sections are rejected with 404 before any entity access.
   */
  public function testUnknownSectionIsRejected(): void {
    $this->groupStorage->expects($this->never())->method('load');

    $response = $this->controller->getSection('3', 'features');
    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertStringContainsString('Unknown section', $payload['error']['message']);

    $patch = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{}}'),
      '3',
      'features'
    );
    $this->assertSame(Response::HTTP_NOT_FOUND, $patch->getStatusCode());
  }

  /**
   * Boundary PATCH normalizes GeoJSON into existing jurisdiction settings.
   */
  public function testBoundaryPatchPersistsNormalizedSettingsSection(): void {
    $group = $this->mockGroup();
    $stored = [];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $candidate, string $section) use (&$stored, $group): array {
        return $candidate === $group && $section === 'boundary' ? $stored : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with($group, 'boundary', $this->callback(static function (array $values): bool {
        return ($values['geojson']['type'] ?? NULL) === 'FeatureCollection'
          && count($values['geojson']['features'][0]['geometry']['coordinates'][0] ?? []) === 5;
      }))
      ->willReturnCallback(static function (GroupInterface $candidate, string $section, array $values) use (&$stored): array {
        $stored = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: json_encode([
        'data' => [
          'boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[[7, 50], [8, 50], [8, 51], [7, 51]]],
          ],
        ],
      ])),
      '3',
      'boundary',
    );
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('FeatureCollection', $payload['data']['boundary']['type']);
  }

  /**
   * Oversized boundary requests are rejected before persistence.
   */
  public function testBoundaryPatchRejectsOversizedPayload(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');
    $body = '{"data":{"boundary":"' . str_repeat('x', 2 * 1024 * 1024) . '"}}';

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: $body),
      '3',
      'boundary',
    );

    $this->assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $response->getStatusCode());
  }

  /**
   * Unknown jurisdictions return 404.
   */
  public function testUnknownJurisdictionReturns404(): void {
    $this->groupStorage->method('load')->with(99)->willReturn(NULL);

    $response = $this->controller->getSection('99', 'general');
    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('Jurisdiction not found.', $payload['error']['message']);
  }

  /**
   * A malformed PATCH body is rejected with 400.
   */
  public function testPatchInvalidBodyIsRejected(): void {
    $this->mockGroup();

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: 'not json'),
      '3',
      'branding'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * Unknown data keys are rejected with 400 and the offending field.
   */
  public function testPatchUnknownKeyIsRejected(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"primaryColor":"blue","sneaky":"x"}}'),
      '3',
      'branding'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('sneaky', $payload['error']['field']);
    $this->assertStringContainsString('Unknown key: sneaky', $payload['error']['message']);
  }

  /**
   * Retention settings require an explicit compliance permission.
   */
  public function testRetentionAccessRequiresDedicatedPermission(): void {
    $this->mockGroup();
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(44);
    $account->method('getRoles')->willReturn(['authenticated', 'department_admin']);
    $account->method('hasPermission')->willReturn(FALSE);

    $result = $this->controller->accessCheck($account, '3', 'retention');

    $this->assertFalse($result->isAllowed());
    $this->assertContains('user.permissions', $result->getCacheContexts());
  }

  /**
   * Validation failures are rejected with 400 and never persisted.
   */
  public function testPatchInvalidColorIsRejected(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"primaryColor":"hotpink"}}'),
      '3',
      'branding'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('primaryColor', $payload['error']['field']);
  }

  /**
   * Invalid logo payloads are rejected before persistence.
   */
  public function testPatchInvalidLogoIsRejected(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"logoDataUrl":"https://example.com/logo.png"}}'),
      '3',
      'branding'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('logoDataUrl', $payload['error']['field']);
  }

  /**
   * A valid branding PATCH merges into the theme key and returns the envelope.
   */
  public function testPatchBrandingMergesThemeKey(): void {
    $this->mockGroup();

    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => $section === 'theme'
        ? ['primary' => 'blue', 'secondary' => 'teal']
        : []);
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'theme',
        ['primary' => 'rose', 'secondary' => 'teal']
      )
      ->willReturnArgument(2);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"primaryColor":"rose"}}'),
      '3',
      'branding'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('branding', $payload['section']);
    $this->assertArrayHasKey('primaryColor', $payload['data']);
  }

  /**
   * A valid branding logo PATCH merges into the theme key.
   */
  public function testPatchBrandingMergesLogoIntoThemeKey(): void {
    $this->mockGroup();
    $logo = self::dataUrl('image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><rect width="16" height="16"/></svg>');
    $theme = ['primary' => 'blue', 'secondary' => 'teal'];

    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$theme): array {
        return $section === 'theme' ? $theme : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'theme',
        [
          'primary' => 'blue',
          'secondary' => 'teal',
          'logoDataUrl' => $logo,
          'logoAlt' => 'Musterstadt',
        ]
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$theme): array {
        $theme = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'logoDataUrl' => $logo,
          'logoAlt' => 'Musterstadt',
        ],
      ])),
      '3',
      'branding'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame($logo, $payload['data']['logoDataUrl']);
    $this->assertSame('Musterstadt', $payload['data']['logoAlt']);
  }

  /**
   * The dark logo is validated with its own field name and stored in theme.
   */
  public function testPatchDarkLogo(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');
    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"logoDarkDataUrl":"https://example.com/dark.png"}}'),
      '3',
      'branding'
    );
    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('logoDarkDataUrl', $payload['error']['field']);
    $this->assertStringStartsWith('logoDarkDataUrl: logoDarkDataUrl ', (string) ($payload['error']['message'] ?? ''));
  }

  /**
   * A valid dark logo is stored next to the logo; empty removes the key.
   */
  public function testPatchDarkLogoStoresAndRemoves(): void {
    $this->mockGroup();
    $dark = self::dataUrl('image/png', "\x89PNG\r\n\x1A\ndark");
    $theme = ['primary' => 'blue', 'logoDataUrl' => 'data:image/png;base64,iVBORw0KGgo='];
    $stored = [];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$theme): array {
        return $section === 'theme' ? $theme : [];
      });
    $this->configService->method('updateSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$theme, &$stored): array {
        $theme = $values;
        $stored[] = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode(['data' => ['logoDarkDataUrl' => $dark]])),
      '3',
      'branding'
    );
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame($dark, $stored[0]['logoDarkDataUrl']);
    $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $stored[0]['logoDataUrl']);
    $this->assertSame($dark, json_decode((string) $response->getContent(), TRUE)['data']['logoDarkDataUrl']);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"logoDarkDataUrl":""}}'),
      '3',
      'branding'
    );
    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertArrayNotHasKey('logoDarkDataUrl', $stored[1]);
    $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $stored[1]['logoDataUrl']);
    $this->assertSame('', json_decode((string) $response->getContent(), TRUE)['data']['logoDarkDataUrl']);
  }

  /**
   * A customCss-only branding PATCH must not write into the JSON blob.
   */
  public function testPatchBrandingCustomCssOnlySkipsJsonBlob(): void {
    $group = $this->mockGroup();
    $group->expects($this->once())->method('save');
    $group->expects($this->once())
      ->method('set')
      ->with('field_custom_css', '.x { color: red; }');
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"customCss":".x { color: red; }"}}'),
      '3',
      'branding'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('branding', $payload['section']);
  }

  /**
   * HTML content fields are sanitized on write, not rejected.
   *
   * A script payload in legalNotice is accepted (200) but the persisted
   * value is the Xss::filterAdmin() output with the script tag stripped.
   */
  public function testPatchGeneralSanitizesHtmlFieldsOnWrite(): void {
    $group = $this->mockGroup();
    $captured = [];
    $group->method('set')->willReturnCallback(
      static function (string $field, $value) use (&$captured, $group) {
        $captured[$field] = $value;
        return $group;
      }
    );
    $group->expects($this->once())->method('save');

    $payload = '<p>Impressum</p><script>alert(1)</script>';
    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode(['data' => ['legalNotice' => $payload]])),
      '3',
      'general'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertArrayHasKey('field_legal_notice', $captured);
    $this->assertSame(Xss::filterAdmin($payload), $captured['field_legal_notice']);
    $this->assertStringNotContainsString('<script', (string) $captured['field_legal_notice']);
    $this->assertStringContainsString('<p>Impressum</p>', (string) $captured['field_legal_notice']);
  }

  /**
   * Legitimate content with a bare ampersand saves (normalized, not 400).
   */
  public function testPatchGeneralAmpersandContentSaves(): void {
    $group = $this->mockGroup();
    $captured = [];
    $group->method('set')->willReturnCallback(
      static function (string $field, $value) use (&$captured, $group) {
        $captured[$field] = $value;
        return $group;
      }
    );

    $payload = 'AGB & Datenschutz: Müller & Partner GmbH<br/>Zeile 2';
    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode(['data' => ['privacyPolicy' => $payload]])),
      '3',
      'general'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(Xss::filterAdmin($payload), $captured['field_privacy_policy']);
    $this->assertStringContainsString('Müller &amp; Partner GmbH', (string) $captured['field_privacy_policy']);
  }

  /**
   * Entity-field-only PATCHes refresh the JSON blob from storage.
   *
   * A concurrent section update committed between request start and save
   * must not be reverted by the stale blob riding along with the save.
   */
  public function testEntityFieldOnlyPatchRefreshesJsonBlobFromStorage(): void {
    $group = $this->mockGroup();
    $captured = [];
    $group->method('set')->willReturnCallback(
      static function (string $field, $value) use (&$captured, $group) {
        $captured[$field] = $value;
        return $group;
      }
    );
    $group->expects($this->once())->method('save');
    $this->configService->expects($this->never())->method('updateSection');

    $freshBlob = '{"theme":{"primary":"rose"}}';
    $freshField = $this->createMock(FieldItemListInterface::class);
    $freshField->method('isEmpty')->willReturn(FALSE);
    $freshField->method('__get')->willReturnCallback(
      static fn(string $property) => $property === 'value' ? $freshBlob : NULL
    );
    $fresh = $this->createMock(GroupInterface::class);
    $fresh->method('getUntranslated')->willReturn($fresh);
    $fresh->method('hasField')->willReturn(TRUE);
    $fresh->method('get')->willReturn($freshField);
    $this->groupStorage->method('loadUnchanged')->with(3)->willReturn($fresh);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"customCss":".x { color: red; }"}}'),
      '3',
      'branding'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame('.x { color: red; }', $captured['field_custom_css']);
    // The stale in-memory blob got replaced by the fresh stored value.
    $this->assertSame($freshBlob, $captured['field_jur_config']);
  }

  /**
   * Languages PATCH merges with the stored config before validation.
   */
  public function testPatchLanguagesDefaultMustStayAvailable(): void {
    $this->mockGroup();
    // Stored config has only German available; switching the default to
    // English without extending "available" must fail.
    $this->configService->method('getSection')->willReturn(['default' => 'de', 'available' => ['de']]);
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"default":"en"}}'),
      '3',
      'languages'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
  }

  /**
   * A retention PATCH persists the jurisdiction policy section.
   */
  public function testPatchRetentionPersistsPolicy(): void {
    $this->mockGroup();
    $retention = ['dataRetentionDays' => 730];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$retention): array {
        return $section === 'retention' ? $retention : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'retention',
        ['dataRetentionDays' => 365]
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$retention): array {
        $retention = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"dataRetentionDays":"365"}}'),
      '3',
      'retention'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('retention', $payload['section']);
    $this->assertSame(365, $payload['data']['dataRetentionDays']);
  }

  /**
   * Invalid retention values are rejected before persistence.
   */
  #[DataProvider('invalidRetentionDaysProvider')]
  public function testPatchRetentionRejectsInvalidPolicy(int|bool $days): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: json_encode(['data' => ['dataRetentionDays' => $days]], JSON_THROW_ON_ERROR)),
      '3',
      'retention'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('dataRetentionDays', $payload['error']['field']);
  }

  /**
   * Supplies the days for each case.
   *
   * @return array<string, array{int|bool}>
   *   Named test cases.
   */
  public static function invalidRetentionDaysProvider(): array {
    return [
      'negative days' => [-1],
      'boolean instead of days' => [TRUE],
    ];
  }

  /**
   * A resubmission PATCH persists the jurisdiction reminder section.
   */
  public function testPatchResubmissionPersistsPolicy(): void {
    $this->mockGroup();
    $resubmission = ['reminderDays' => 42, 'reminderScope' => 'org'];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$resubmission): array {
        return $section === 'resubmission' ? $resubmission : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'resubmission',
        ['reminderDays' => 14, 'reminderScope' => 'user']
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$resubmission): array {
        $resubmission = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"reminderDays":"14","reminderScope":"user"}}'),
      '3',
      'resubmission'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('resubmission', $payload['section']);
    $this->assertSame(14, $payload['data']['reminderDays']);
    $this->assertSame('user', $payload['data']['reminderScope']);
  }

  /**
   * Invalid resubmission scope is rejected before persistence.
   */
  public function testPatchResubmissionRejectsInvalidScope(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"reminderScope":"team"}}'),
      '3',
      'resubmission'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('reminderScope', $payload['error']['field']);
  }

  /**
   * A child jurisdiction cannot set the tenant-wide personal assignment.
   */
  public function testPatchDelegationRejectsPersonalAssignmentOnChildJurisdiction(): void {
    $this->mockChildJurisdiction(['personalAssignment' => TRUE], ['personalAssignment' => TRUE]);
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"personalAssignment":false}}'),
      '3',
      'delegation'
    );

    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('personalAssignment', $payload['error']['field']);
  }

  /**
   * A child jurisdiction shows the root switch read-only over its own value.
   */
  public function testGetDelegationOnChildJurisdictionProjectsRootSwitch(): void {
    $this->mockChildJurisdiction(
      ['noteRequired' => TRUE, 'personalAssignment' => TRUE],
      ['noteRequired' => FALSE, 'personalAssignment' => FALSE],
    );

    $response = $this->controller->getSection('3', 'delegation');

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($payload['data']['noteRequired']);
    $this->assertFalse($payload['data']['personalAssignment']);
    $this->assertFalse($payload['data']['personalAssignmentEditable']);
  }

  /**
   * A delegation PATCH persists the jurisdiction policy section.
   */
  public function testPatchDelegationPersistsPolicy(): void {
    $this->mockGroup();
    $delegation = ['noteRequired' => FALSE];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$delegation): array {
        return $section === 'delegation' ? $delegation : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'delegation',
        ['noteRequired' => TRUE, 'personalAssignment' => FALSE]
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$delegation): array {
        $delegation = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"noteRequired":true,"personalAssignment":false}}'),
      '3',
      'delegation'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('delegation', $payload['section']);
    $this->assertTrue($payload['data']['noteRequired']);
    $this->assertFalse($payload['data']['personalAssignment']);
  }

  /**
   * Invalid delegation values are rejected before persistence.
   */
  public function testPatchDelegationRejectsNonBooleanPolicy(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"noteRequired":"true"}}'),
      '3',
      'delegation'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('noteRequired', $payload['error']['field']);
  }

  /**
   * An applicant profile PATCH stores both keys, defaulting the scope.
   */
  public function testPatchApplicantProfilePersistsSwitch(): void {
    $this->mockGroup();
    $applicant_profile = [];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$applicant_profile): array {
        return $section === 'applicant_profile' ? $applicant_profile : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'applicant_profile',
        ['enabled' => TRUE, 'scope' => 'organisation', 'bankConsent' => FALSE]
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$applicant_profile): array {
        $applicant_profile = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"enabled":true}}'),
      '3',
      'applicant_profile'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('applicant_profile', $payload['section']);
    $this->assertTrue($payload['data']['enabled']);
    $this->assertSame('organisation', $payload['data']['scope']);
    $this->assertFalse($payload['data']['bankConsent']);
    $this->assertSame(ProfileConsentText::version(ProfileConsentText::KIND_CONTACT), $payload['data']['consentTextVersion']);
    $this->assertSame(ProfileConsentText::templates(ProfileConsentText::KIND_CONTACT), $payload['data']['consentText']);
    $this->assertSame(ProfileConsentText::version(ProfileConsentText::KIND_BANK), $payload['data']['bankConsentTextVersion']);
    $this->assertSame(ProfileConsentText::templates(ProfileConsentText::KIND_BANK), $payload['data']['bankConsentText']);
  }

  /**
   * Switching the profile off keeps the stored scope.
   */
  public function testPatchApplicantProfileKeepsStoredScope(): void {
    $this->mockGroup();
    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => $section === 'applicant_profile'
        ? ['enabled' => TRUE, 'scope' => 'jurisdiction', 'bankConsent' => TRUE]
        : []);
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'applicant_profile',
        ['enabled' => FALSE, 'scope' => 'jurisdiction', 'bankConsent' => TRUE]
      )
      ->willReturnArgument(2);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"enabled":false}}'),
      '3',
      'applicant_profile'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Invalid applicant profile values and text overrides are rejected.
   */
  #[DataProvider('invalidApplicantProfilePatchProvider')]
  public function testPatchApplicantProfileRejectsInvalidData(string $content, string $field): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');
    $this->configService->expects($this->never())->method('mergeSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: $content),
      '3',
      'applicant_profile'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame($field, $payload['error']['field']);
  }

  /**
   * Provides rejected applicant profile PATCH bodies.
   *
   * @return array<string, array{string, string}>
   *   Request body and the reported field.
   */
  public static function invalidApplicantProfilePatchProvider(): array {
    return [
      'string enabled' => ['{"data":{"enabled":"true"}}', 'enabled'],
      'unknown scope' => ['{"data":{"scope":"everyone"}}', 'scope'],
      'string bank switch' => ['{"data":{"bankConsent":"true"}}', 'bankConsent'],
      'backend-owned text' => ['{"data":{"consentText":{"de":"Alles speichern"}}}', 'consentText'],
      'text version' => ['{"data":{"consentTextVersion":"2099-01-01"}}', 'consentTextVersion'],
      'bank text version' => ['{"data":{"bankConsentTextVersion":"2099-01-01"}}', 'bankConsentTextVersion'],
    ];
  }

  /**
   * A missing or damaged applicant profile section reads as disabled.
   */
  public function testGetApplicantProfileFallsBackToDisabled(): void {
    $this->mockGroup();
    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => $section === 'applicant_profile'
        ? ['enabled' => 'yes', 'scope' => 'everyone', 'bankConsent' => 1]
        : []);

    $response = $this->controller->getSection('3', 'applicant_profile');

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['data']['enabled']);
    $this->assertFalse($payload['data']['bankConsent']);
    $this->assertSame('organisation', $payload['data']['scope']);
  }

  /**
   * An assistant PATCH merges and persists the conversation policy.
   */
  public function testPatchAssistantPersistsConversationPolicy(): void {
    $this->mockGroup();
    $assistant = [
      'tone' => 'friendly',
      'verbosity' => 'concise',
      'acknowledgementStyle' => 'contextual',
      'additionalInstructions' => '',
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$assistant): array {
        return $section === 'assistant' ? $assistant : [];
      });
    $this->configService->expects($this->once())
      ->method('mergeSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'assistant',
        ['tone' => 'empathetic']
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$assistant): array {
        $assistant = array_replace($assistant, $values);
        return $assistant;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"tone":"empathetic"}}'),
      '3',
      'assistant'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('assistant', $payload['section']);
    $this->assertSame('empathetic', $payload['data']['tone']);
    $this->assertSame('concise', $payload['data']['verbosity']);
    $this->assertSame('contextual', $payload['data']['acknowledgementStyle']);
    $this->assertSame('', $payload['data']['additionalInstructions']);
  }

  /**
   * Invalid assistant policy values are rejected before persistence.
   */
  public function testPatchAssistantRejectsInvalidTone(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"tone":"sarcastic"}}'),
      '3',
      'assistant'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('tone', $payload['error']['field']);
  }

  /**
   * Upload storage settings persist and return current durable usage.
   */
  public function testPatchUploadsPersistsStoragePolicy(): void {
    $this->mockGroup();
    $uploads = [
      'maxStorageBytes' => 104857600,
      'warningThresholdPercent' => 80,
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$uploads): array {
        return $section === 'uploads' ? $uploads : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'uploads',
        [
          'maxStorageBytes' => 209715200,
          'warningThresholdPercent' => 75,
        ],
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$uploads): array {
        $uploads = $values;
        return $values;
      });
    $this->applicationStorage->method('getQuery')->willReturn($this->queryReturning([]));
    $this->attachmentStorage->expects($this->never())->method('getQuery');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"maxStorageBytes":"209715200","warningThresholdPercent":"75"}}'),
      '3',
      'uploads'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('uploads', $payload['section']);
    $this->assertSame(209715200, $payload['data']['maxStorageBytes']);
    $this->assertSame(75, $payload['data']['warningThresholdPercent']);
    $this->assertSame(0, $payload['data']['usedStorageBytes']);
    $this->assertSame('ok', $payload['data']['quotaStatus']);
  }

  /**
   * Invalid upload warning thresholds are rejected before persistence.
   */
  public function testPatchUploadsRejectsInvalidWarningThreshold(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"warningThresholdPercent":101}}'),
      '3',
      'uploads'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('warningThresholdPercent', $payload['error']['field']);
  }

  /**
   * A valid embed PATCH persists generator defaults.
   */
  public function testPatchEmbedPersistsGeneratorDefaults(): void {
    $this->mockGroup();
    $embed = [
      'defaultTheme' => 'light',
      'defaultWidth' => '100%',
      'defaultHeight' => '800px',
      'showBorder' => TRUE,
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$embed): array {
        return $section === 'embed' ? $embed : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'embed',
        [
          'defaultTheme' => 'dark',
          'defaultWidth' => '90%',
          'defaultHeight' => '640px',
          'showBorder' => FALSE,
        ]
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$embed): array {
        $embed = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"defaultTheme":"dark","defaultWidth":"90%","defaultHeight":"640px","showBorder":false}}'),
      '3',
      'embed'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('embed', $payload['section']);
    $this->assertSame('dark', $payload['data']['defaultTheme']);
    $this->assertSame('90%', $payload['data']['defaultWidth']);
    $this->assertSame('640px', $payload['data']['defaultHeight']);
    $this->assertFalse($payload['data']['showBorder']);
  }

  /**
   * Invalid embed dimensions are rejected before persistence.
   */
  public function testPatchEmbedRejectsInvalidDimension(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"defaultWidth":"100%\\" onload=\\"alert(1)"}}'),
      '3',
      'embed'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('defaultWidth', $payload['error']['field']);
  }

  /**
   * Reading embed settings normalizes missing and invalid stored values.
   */
  public function testGetEmbedSettingsNormalizesStoredDefaults(): void {
    $this->mockGroup();
    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => $section === 'embed'
        ? [
          'defaultTheme' => 'sepia',
          'defaultWidth' => '75%',
          'defaultHeight' => '<script>',
          'showBorder' => 'yes',
        ]
        : []);

    $response = $this->controller->getSection('3', 'embed');

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('embed', $payload['section']);
    $this->assertSame([
      'defaultTheme' => 'light',
      'defaultWidth' => '75%',
      'defaultHeight' => '800px',
      'showBorder' => TRUE,
    ], $payload['data']);
  }

  /**
   * A valid notification_mail PATCH persists template text.
   */
  public function testPatchNotificationMailPersistsTemplateText(): void {
    $this->mockGroup();
    $stored = [
      'templates' => [
        'status_change' => [
          'enabled' => TRUE,
          'default' => [
            'subject' => 'Old subject @reference_number',
            'body' => 'Old body',
          ],
        ],
      ],
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$stored): array {
        return $section === 'notification_mail' ? $stored : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'notification_mail',
        [
          'templates' => [
            'status_change' => [
              'enabled' => FALSE,
              'default' => [
                'subject' => 'New subject @reference_number',
                'body' => 'Old body',
              ],
              'translations' => [
                'en' => [
                  'body' => 'New English body',
                ],
              ],
            ],
          ],
        ],
      )
      ->willReturnCallback(static function (GroupInterface $group, string $section, array $values) use (&$stored): array {
        $stored = $values;
        return $values;
      });

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'status_change' => [
              'enabled' => FALSE,
              'default' => [
                'subject' => 'New subject @reference_number',
              ],
              'translations' => [
                'en' => [
                  'body' => 'New English body',
                ],
              ],
            ],
          ],
        ],
      ])),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('notification_mail', $payload['section']);
    $this->assertFalse($payload['data']['templates']['status_change']['enabled']);
    $this->assertSame('New subject @reference_number', $payload['data']['templates']['status_change']['default']['subject']);
    $this->assertSame('New English body', $payload['data']['templates']['status_change']['translations']['en']['body']);
  }

  /**
   * Submitted translations replace the complete stored translations map.
   */
  public function testPatchNotificationMailReplacesTranslations(): void {
    $this->mockGroup();
    $stored = [
      'templates' => [
        'status_change' => [
          'default' => [
            'subject' => 'Status @reference_number',
            'body' => 'Status body',
          ],
          'translations' => [
            'de' => [
              'subject' => 'Alter Status @reference_number',
              'body' => 'Alter deutscher Text',
            ],
            'en' => [
              'subject' => 'Old status @reference_number',
              'body' => 'Old English body',
            ],
          ],
        ],
      ],
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$stored): array {
        return $section === 'notification_mail' ? $stored : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'notification_mail',
        [
          'templates' => [
            'status_change' => [
              'default' => [
                'subject' => 'Status @reference_number',
                'body' => 'Status body',
              ],
              'translations' => [
                'en' => [
                  'body' => 'New English body @reference_number',
                ],
              ],
            ],
          ],
        ],
      )
      ->willReturnArgument(2);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'status_change' => [
              'translations' => [
                'en' => [
                  'body' => 'New English body @reference_number',
                ],
              ],
            ],
          ],
        ],
      ])),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * An explicitly empty translations map removes all stored translations.
   */
  public function testPatchNotificationMailRemovesAllTranslations(): void {
    $this->mockGroup();
    $stored = [
      'templates' => [
        'status_change' => [
          'default' => [
            'subject' => 'Status @reference_number',
          ],
          'translations' => [
            'de' => ['subject' => 'Status @reference_number'],
            'en' => ['subject' => 'Status @reference_number'],
          ],
        ],
      ],
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$stored): array {
        return $section === 'notification_mail' ? $stored : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'notification_mail',
        [
          'templates' => [
            'status_change' => [
              'default' => [
                'subject' => 'Status @reference_number',
              ],
            ],
          ],
        ],
      )
      ->willReturnArgument(2);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'status_change' => [
              'translations' => [],
            ],
          ],
        ],
      ])),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Omitting translations leaves the stored translations map unchanged.
   */
  public function testPatchNotificationMailPreservesOmittedTranslations(): void {
    $this->mockGroup();
    $stored = [
      'templates' => [
        'status_change' => [
          'enabled' => TRUE,
          'default' => [
            'subject' => 'Status @reference_number',
          ],
          'translations' => [
            'en' => ['subject' => 'Status @reference_number'],
          ],
        ],
      ],
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$stored): array {
        return $section === 'notification_mail' ? $stored : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'notification_mail',
        [
          'templates' => [
            'status_change' => [
              'enabled' => FALSE,
              'default' => [
                'subject' => 'Status @reference_number',
              ],
              'translations' => [
                'en' => ['subject' => 'Status @reference_number'],
              ],
            ],
          ],
        ],
      )
      ->willReturnArgument(2);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'status_change' => [
              'enabled' => FALSE,
            ],
          ],
        ],
      ])),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * A translation whose text sanitizes to empty removes that langcode.
   */
  public function testPatchNotificationMailRemovesEmptyTranslationEntry(): void {
    $this->mockGroup();
    $stored = [
      'templates' => [
        'status_change' => [
          'default' => [
            'subject' => 'Status @reference_number',
          ],
          'translations' => [
            'en' => ['subject' => 'Status @reference_number'],
          ],
        ],
      ],
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$stored): array {
        return $section === 'notification_mail' ? $stored : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'notification_mail',
        [
          'templates' => [
            'status_change' => [
              'default' => [
                'subject' => 'Status @reference_number',
              ],
            ],
          ],
        ],
      )
      ->willReturnArgument(2);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'status_change' => [
              'translations' => [
                'en' => [
                  'subject' => '',
                  'body' => '<p></p>',
                ],
              ],
            ],
          ],
        ],
      ])),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Invalid translation langcodes keep the existing validation error path.
   */
  public function testPatchNotificationMailRejectsInvalidTranslationLangcode(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'status_change' => [
              'translations' => [
                'xx' => [
                  'subject' => 'Status @reference_number',
                ],
              ],
            ],
          ],
        ],
      ])),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('templates.status_change.translations.xx', $payload['error']['field']);
  }

  /**
   * Required-token validation only scans templates present in the PATCH.
   */
  public function testPatchNotificationMailValidatesOnlyPayloadTemplates(): void {
    $this->mockGroup();
    $stored = [
      'templates' => [
        'status_change' => [
          'enabled' => TRUE,
          'default' => [
            'subject' => 'Broken stored subject',
            'body' => 'Broken stored body',
          ],
        ],
        'application_confirmation_request' => [
          'enabled' => TRUE,
          'default' => [
            'subject' => 'Old confirmation subject',
            'body' => 'Old body with @confirmation_url',
          ],
        ],
      ],
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$stored): array {
        return $section === 'notification_mail' ? $stored : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'notification_mail',
        [
          'templates' => [
            'status_change' => [
              'enabled' => TRUE,
              'default' => [
                'subject' => 'Broken stored subject',
                'body' => 'Broken stored body',
              ],
            ],
            'application_confirmation_request' => [
              'enabled' => TRUE,
              'default' => [
                'subject' => 'Updated confirmation subject',
                'body' => 'Confirm with @confirmation_url',
              ],
            ],
          ],
        ],
      )
      ->willReturnArgument(2);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'application_confirmation_request' => [
              'default' => [
                'subject' => 'Updated confirmation subject',
                'body' => 'Confirm with @confirmation_url',
              ],
            ],
          ],
        ],
      ])),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Empty text fields are treated as unset and are not persisted as blanks.
   */
  public function testPatchNotificationMailDropsEmptyTextFields(): void {
    $this->mockGroup();
    $stored = [
      'templates' => [
        'status_change' => [
          'enabled' => TRUE,
          'default' => [
            'subject' => 'Old subject @reference_number',
            'body' => 'Old body',
          ],
        ],
      ],
    ];
    $this->configService->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use (&$stored): array {
        return $section === 'notification_mail' ? $stored : [];
      });
    $this->configService->expects($this->once())
      ->method('updateSection')
      ->with(
        $this->isInstanceOf(GroupInterface::class),
        'notification_mail',
        [
          'templates' => [
            'status_change' => [
              'enabled' => TRUE,
              'default' => [
                'subject' => 'Old subject @reference_number',
                'body' => 'Updated body',
              ],
              'translations' => [
                'en' => [
                  'subject' => 'English subject @reference_number',
                ],
              ],
            ],
          ],
        ],
      )
      ->willReturnArgument(2);

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: (string) json_encode([
        'data' => [
          'templates' => [
            'status_change' => [
              'default' => [
                'subject' => '<p></p>',
                'body' => 'Updated body',
              ],
              'translations' => [
                'en' => [
                  'subject' => 'English subject @reference_number',
                  'body' => '<p></p>',
                ],
              ],
            ],
          ],
        ],
      ])),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
  }

  /**
   * Unknown notification template keys are rejected.
   */
  public function testPatchNotificationMailRejectsUnknownTemplateKey(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"templates":{"unknown":{"enabled":true}}}}'),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('templates.unknown', $payload['error']['field']);
  }

  /**
   * The double opt-in system template cannot be disabled per jurisdiction.
   */
  public function testPatchNotificationMailRejectsLockedTemplateDisable(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"templates":{"application_confirmation_request":{"enabled":false}}}}'),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('templates.application_confirmation_request.enabled', $payload['error']['field']);
  }

  /**
   * The confirmation URL token is required in the confirmation body.
   */
  public function testPatchNotificationMailRejectsConfirmationUrlOutsideBody(): void {
    $this->mockGroup();
    $this->configService->expects($this->never())->method('updateSection');

    $response = $this->controller->patchSection(
      Request::create('/x', 'PATCH', content: '{"data":{"templates":{"application_confirmation_request":{"default":{"subject":"Confirm at @confirmation_url","body":"Confirm your email without a link."}}}}}'),
      '3',
      'notification_mail'
    );

    $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('templates.application_confirmation_request.default.body', $payload['error']['field']);
    $this->assertStringContainsString('@confirmation_url', $payload['error']['message']);
  }

  /**
   * Public display name falls back to the group label for the product default.
   */
  public function testPublicJurisdictionNameFallsBackToGroupLabelForDefaultPlatformName(): void {
    $group = $this->mockPublicNameGroup(' beantragt.ai ', 'Stadt Sampletown');

    $this->assertSame('Stadt Sampletown', $this->invokePublicJurisdictionName($group));
  }

  /**
   * Public display name falls back to the group label for blank values.
   */
  public function testPublicJurisdictionNameFallsBackToGroupLabelForBlankPlatformName(): void {
    $group = $this->mockPublicNameGroup('   ', 'Stadt Sampletown');

    $this->assertSame('Stadt Sampletown', $this->invokePublicJurisdictionName($group));
  }

  /**
   * Public display name keeps a tenant-provided platform name when configured.
   */
  public function testPublicJurisdictionNameKeepsConfiguredPlatformName(): void {
    $group = $this->mockPublicNameGroup(' Serviceportal Sampletown ', 'Stadt Sampletown');

    $this->assertSame('Serviceportal Sampletown', $this->invokePublicJurisdictionName($group));
  }

  /**
   * Public config exposes the resolved display name in the API payload.
   */
  public function testPublicConfigUsesResolvedJurisdictionNameInPayload(): void {
    $group = $this->mockPublicConfigGroup(' beantragt.ai ', 'Stadt Sampletown');
    $this->groupStorage->method('loadByProperties')->willReturn([$group]);
    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => match ($section) {
        'languages' => ['default' => 'de', 'available' => ['de']],
        'theme' => ['primary' => '#16a34a'],
        default => [],
      });

    $response = $this->controller->publicConfig('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(339, $payload['jurisdiction']['id']);
    $this->assertArrayNotHasKey('uuid', $payload['jurisdiction']);
    $this->assertSame('Stadt Sampletown', $payload['jurisdiction']['name']);
    $this->assertSame('#16a34a', $payload['theme']['primary']);
    $this->assertContains('group:339', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Public config only exposes allowlisted theme keys.
   */
  public function testPublicConfigProjectsThemeThroughAllowlist(): void {
    $group = $this->mockPublicConfigGroup('Serviceportal Sampletown', 'Stadt Sampletown');
    $this->groupStorage->method('loadByProperties')->willReturn([$group]);
    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => match ($section) {
        'theme' => [
          'primary' => '#16a34a',
          'secondary' => 'teal',
          'neutral' => 'slate',
          'logoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
          'logoDarkDataUrl' => 'data:image/png;base64,iVBORw0KGgoAAAA=',
          'logoAlt' => 'Stadt Sampletown',
          'internalToken' => 'do-not-expose',
          'nested' => ['do-not-expose'],
        ],
        default => [],
      });

    $response = $this->controller->publicConfig('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame([
      'primary' => '#16a34a',
      'secondary' => 'teal',
      'neutral' => 'slate',
      'logoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
      'logoDarkDataUrl' => 'data:image/png;base64,iVBORw0KGgoAAAA=',
      'logoAlt' => 'Stadt Sampletown',
    ], $payload['theme']);
  }

  /**
   * Public config exposes a disabled applicant profile with consent templates.
   */
  public function testPublicConfigCarriesDisabledApplicantProfileByDefault(): void {
    $group = $this->mockPublicConfigGroup('Serviceportal Sampletown', 'Stadt Sampletown');
    $this->groupStorage->method('loadByProperties')->willReturn([$group]);
    $this->configService->method('getSection')->willReturn([]);

    $response = $this->controller->publicConfig('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $profile = $payload['applicantProfile'];
    $this->assertSame([
      'enabled',
      'scope',
      'bankConsent',
      'consentTextVersion',
      'consentText',
      'bankConsentTextVersion',
      'bankConsentText',
    ], array_keys($profile));
    $this->assertFalse($profile['enabled']);
    $this->assertFalse($profile['bankConsent']);
    $this->assertSame('organisation', $profile['scope']);
    $this->assertSame(ProfileConsentText::version(ProfileConsentText::KIND_CONTACT), $profile['consentTextVersion']);
    $this->assertSame(ProfileConsentText::version(ProfileConsentText::KIND_BANK), $profile['bankConsentTextVersion']);
    $this->assertSame(['de', 'en'], array_keys($profile['consentText']));
    $this->assertSame(['de', 'en'], array_keys($profile['bankConsentText']));
    foreach (['consentText', 'bankConsentText'] as $text_key) {
      foreach ($profile[$text_key] as $text) {
        $this->assertStringContainsString('{stelle}', $text);
      }
    }
    $this->assertStringContainsString('IBAN', $profile['bankConsentText']['de']);
    $this->assertStringNotContainsString('IBAN', $profile['consentText']['de']);
  }

  /**
   * Public v1 config reflects an enabled applicant profile switch.
   */
  public function testPublicConfigV1CarriesEnabledApplicantProfile(): void {
    $group = $this->mockPublicConfigGroup('Serviceportal Sampletown', 'Stadt Sampletown');
    $this->groupStorage->method('loadByProperties')->willReturn([$group]);
    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => $section === 'applicant_profile'
        ? ['enabled' => TRUE, 'scope' => 'jurisdiction', 'bankConsent' => TRUE]
        : []);

    $response = $this->controller->publicConfigV1('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertTrue($payload['data']['applicantProfile']['enabled']);
    $this->assertTrue($payload['data']['applicantProfile']['bankConsent']);
    $this->assertSame('jurisdiction', $payload['data']['applicantProfile']['scope']);
  }

  /**
   * Public v1 config uses a data envelope and external identifiers.
   */
  public function testPublicConfigV1OmitsNumericEntityId(): void {
    $group = $this->mockPublicConfigGroup('Serviceportal Sampletown', 'Stadt Sampletown');
    $this->groupStorage->method('loadByProperties')->willReturn([$group]);
    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => match ($section) {
        'languages' => ['default' => 'de', 'available' => ['de']],
        'theme' => ['primary' => '#16a34a'],
        default => [],
      });

    $response = $this->controller->publicConfigV1('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertArrayHasKey('data', $payload);
    $this->assertArrayHasKey('meta', $payload);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
    $this->assertSame('jurisdictionConfig', $payload['meta']['resource']);
    $this->assertArrayNotHasKey('id', $payload['data']['jurisdiction']);
    $this->assertSame('339d18f8-3b25-4ac9-a2d8-96e9d7d26b39', $payload['data']['jurisdiction']['uuid']);
    $this->assertSame('sampletown', $payload['data']['jurisdiction']['slug']);
    $this->assertSame('Serviceportal Sampletown', $payload['data']['jurisdiction']['name']);
    $this->assertSame('#16a34a', $payload['data']['theme']['primary']);
    $this->assertContains('group:339', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Public boundary output contains only normalized boundary data.
   */
  public function testPublicBoundaryV1UsesDedicatedMinimalEnvelope(): void {
    $group = $this->mockPublicConfigGroup('Serviceportal Sampletown', 'Stadt Sampletown');
    $this->groupStorage->method('loadByProperties')->willReturn([$group]);
    $boundary = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'properties' => [],
          'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [[[10, 53], [11, 53], [11, 54], [10, 53]]],
          ],
        ],
      ],
    ];
    $this->configService->expects($this->once())
      ->method('getSection')
      ->with($group, 'boundary')
      ->willReturn(['geojson' => $boundary, 'internal' => 'do-not-expose']);

    $response = $this->controller->publicBoundaryV1('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(['boundary' => $boundary], $payload['data']);
    $this->assertSame('jurisdictionBoundary', $payload['meta']['resource']);
    $this->assertContains('group:339', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Missing boundary configuration remains a valid nationwide response.
   */
  public function testPublicBoundaryV1ReturnsNullWithoutConfiguredBoundary(): void {
    $group = $this->mockPublicConfigGroup('Serviceportal Sampletown', 'Stadt Sampletown');
    $this->groupStorage->method('loadByProperties')->willReturn([$group]);
    $this->configService->method('getSection')->willReturn([]);

    $response = $this->controller->publicBoundaryV1('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertNull($payload['data']['boundary']);
  }

  /**
   * Duplicate published slugs fail closed instead of selecting a random group.
   */
  public function testPublicConfigFailsClosedForDuplicatePublishedSlugs(): void {
    $group_a = $this->mockPublicConfigGroup('Serviceportal A', 'A');
    $group_b = $this->mockPublicConfigGroup('Serviceportal B', 'B');
    $this->groupStorage->method('loadByProperties')->willReturn([$group_a, $group_b]);

    $response = $this->controller->publicConfig('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('Jurisdiction not found.', $payload['error']['message']);
  }

  /**
   * Public v1 config failures use the API error envelope.
   */
  public function testPublicConfigV1UsesStableErrorEnvelope(): void {
    $group_a = $this->mockPublicConfigGroup('Serviceportal A', 'A');
    $group_b = $this->mockPublicConfigGroup('Serviceportal B', 'B');
    $this->groupStorage->method('loadByProperties')->willReturn([$group_a, $group_b]);

    $response = $this->controller->publicConfigV1('sampletown');
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    $this->assertSame('jurisdiction.not_found', $payload['error']['code']);
    $this->assertSame('Jurisdiction not found.', $payload['error']['message']);
    $this->assertSame('1.0', $payload['meta']['apiVersion']);
    $this->assertContains('group_list', $response->getCacheableMetadata()->getCacheTags());
  }

  /**
   * Creates the shared notification template catalog mock.
   */
  private function createNotificationTemplateCatalog(): NotificationTemplateCatalogInterface {
    $definitions = [
      'application_confirmation_request' => [
        'subject' => 'Confirm email',
        'body' => 'Confirm @confirmation_url',
        'enabled' => TRUE,
        'requiredTokens' => ['@confirmation_url'],
        'lockedEnabled' => TRUE,
      ],
      'status_change' => [
        'subject' => 'Status @reference_number',
        'body' => 'Status body',
        'enabled' => TRUE,
        'requiredTokens' => ['@reference_number'],
        'lockedEnabled' => FALSE,
      ],
    ];

    $catalog = $this->createMock(NotificationTemplateCatalogInterface::class);
    $catalog->method('all')->willReturn($definitions);
    $catalog->method('keys')->willReturn(array_keys($definitions));
    $catalog->method('has')
      ->willReturnCallback(static fn(string $templateKey): bool => array_key_exists($templateKey, $definitions));
    $catalog->method('get')
      ->willReturnCallback(static fn(string $templateKey): ?array => $definitions[$templateKey] ?? NULL);
    $catalog->method('requiredTokens')
      ->willReturnCallback(static fn(string $templateKey): array => $definitions[$templateKey]['requiredTokens'] ?? []);
    $catalog->method('isLockedEnabled')
      ->willReturnCallback(static fn(string $templateKey): bool => (bool) ($definitions[$templateKey]['lockedEnabled'] ?? FALSE));

    return $catalog;
  }

  /**
   * Creates the controller under test with an optional notification catalog.
   */
  private function createController(?NotificationTemplateCatalogInterface $notificationTemplateCatalog): JurisdictionConfigController {
    $attachment_storage_quota = $this->getMockBuilder(AttachmentStorageQuota::class)
      ->setConstructorArgs([$this->entityTypeManager])
      ->onlyMethods(['usageForJurisdiction'])
      ->getMock();
    $attachment_storage_quota->method('usageForJurisdiction')->willReturn([
      'usedStorageBytes' => 0,
      'attachmentCount' => 0,
    ]);

    return new JurisdictionConfigController(
      $this->entityTypeManager,
      $this->account,
      $this->configService,
      new JurisdictionConfigValidator(new EmailValidator()),
      $this->hierarchyResolver,
      $this->createMock(LoggerInterface::class),
      $attachment_storage_quota,
      $notificationTemplateCatalog,
    );
  }

  /**
   * Builds a child jurisdiction (3) below a tenant root (7) with own policies.
   *
   * @param array<string, mixed> $child_delegation
   *   Delegation section stored on the child.
   * @param array<string, mixed> $root_delegation
   *   Delegation section stored on the root.
   */
  private function mockChildJurisdiction(array $child_delegation, array $root_delegation): void {
    $groups = [];
    foreach ([3, 7] as $id) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(TRUE);
      $group = $this->createMock(GroupInterface::class);
      $group->method('bundle')->willReturn('jur');
      $group->method('id')->willReturn($id);
      $group->method('getUntranslated')->willReturn($group);
      $group->method('hasField')->willReturn(TRUE);
      $group->method('get')->willReturn($field);
      $groups[$id] = $group;
    }
    $this->groupStorage->method('load')
      ->willReturnCallback(static fn($id): ?GroupInterface => $groups[(int) $id] ?? NULL);
    $this->hierarchyResolver->method('getRootJurisdictionId')->willReturn(7);
    $this->configService->method('getSection')
      ->willReturnCallback(static fn(GroupInterface $group, string $section): array => $section === 'delegation'
        ? ((int) $group->id() === 7 ? $root_delegation : $child_delegation)
        : []);
  }

  /**
   * Builds a jur group mock reachable via storage load(3).
   */
  private function mockGroup(): GroupInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn(3);
    $group->method('getUntranslated')->willReturn($group);
    $group->method('hasField')->willReturn(TRUE);
    $group->method('get')->willReturn($field);

    $this->groupStorage->method('load')->with(3)->willReturn($group);

    return $group;
  }

  /**
   * Invokes the private publicJurisdictionName() helper.
   */
  private function invokePublicJurisdictionName(GroupInterface $group): string {
    $method = new \ReflectionMethod($this->controller, 'publicJurisdictionName');
    $method->setAccessible(TRUE);
    return (string) $method->invoke($this->controller, $group);
  }

  /**
   * Builds a jur group mock for public display-name tests.
   */
  private function mockPublicNameGroup(string $platform_name, string $label): GroupInterface {
    $field = $this->fieldList($platform_name);
    $group = $this->createMock(GroupInterface::class);
    $group->method('label')->willReturn($label);
    $group->method('hasField')->with('field_platform_name')->willReturn(TRUE);
    $group->method('get')->with('field_platform_name')->willReturn($field);

    return $group;
  }

  /**
   * Builds a jur group mock for publicConfig() tests.
   */
  private function mockPublicConfigGroup(string $platform_name, string $label): GroupInterface {
    $fields = [
      'field_platform_name' => $this->fieldList($platform_name),
      'field_legal_notice' => $this->fieldList(''),
      'field_privacy_policy' => $this->fieldList(''),
      'field_custom_css' => $this->fieldList(''),
    ];

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('jur');
    $group->method('id')->willReturn(339);
    $group->method('uuid')->willReturn('339d18f8-3b25-4ac9-a2d8-96e9d7d26b39');
    $group->method('label')->willReturn($label);
    $group->method('getUntranslated')->willReturn($group);
    $group->method('hasField')->willReturnCallback(static fn(string $field_name): bool => isset($fields[$field_name]));
    $group->method('get')->willReturnCallback(static fn(string $field_name): FieldItemListInterface => $fields[$field_name]);

    return $group;
  }

  /**
   * Builds a field item list mock exposing a scalar value.
   */
  private function fieldList(string $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '');
    $field->method('__get')->willReturnCallback(
      static fn(string $property) => $property === 'value' ? $value : NULL
    );
    return $field;
  }

  /**
   * Builds a base64 data URL for tests.
   */
  private static function dataUrl(string $mime, string $payload): string {
    return 'data:' . $mime . ';base64,' . base64_encode($payload);
  }

  /**
   * Builds an entity query mock returning fixed ids.
   *
   * @param int[] $ids
   *   Entity ids returned by execute().
   */
  private function queryReturning(array $ids): QueryInterface {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($ids);
    return $query;
  }

}
