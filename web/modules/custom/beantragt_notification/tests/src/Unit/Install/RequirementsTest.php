<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\Install;

use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\group\Entity\GroupInterface;

/**
 * Tests the beantragt_notification runtime requirements.
 *
 * @group beantragt_notification
 */
class RequirementsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once dirname(__DIR__, 4) . '/beantragt_notification.install';
  }

  /**
   * Runtime reports OK when the comment notification hook chain is registered.
   */
  public function testRuntimeRequirementReportsOkWhenHooksAreRegistered(): void {
    $this->setModuleHandler($this->createModuleHandler(
      [
        'beantragt_comment' => TRUE,
        'beantragt_notification' => TRUE,
      ],
      [
        'application_comment_insert' => ['beantragt_comment' => TRUE],
        'mail' => ['beantragt_notification' => TRUE],
      ],
    ));

    $requirements = beantragt_notification_requirements('runtime');

    $this->assertArrayHasKey('beantragt_notification_comment_mail_hook', $requirements);
    $payload = $requirements['beantragt_notification_comment_mail_hook'];
    $this->assertSame(RequirementSeverity::OK, $payload['severity']);
    $this->assertStringContainsString('registered', (string) $payload['value']);
  }

  /**
   * Runtime reports OK when all jurisdiction templates are covered.
   */
  public function testRuntimeRequirementReportsOkWhenJurisdictionTemplatesAreCovered(): void {
    $jurisdiction_a = $this->createJurisdiction(1, 'Stadt Musterstadt', 'musterstadt');
    $jurisdiction_b = $this->createJurisdiction(2, 'Kreis Musterhausen', 'kreis-musterhausen');

    $this->setRuntimeContainer(
      $this->registeredModuleHandler(),
      [$jurisdiction_a, $jurisdiction_b],
      [
        1 => [
          'templates' => [
            'application_confirmation_request' => [
              'default' => ['subject' => 'Subject', 'body' => 'Body'],
            ],
          ],
        ],
        2 => [
          'templates' => [
            'application_confirmation_request' => [
              'default' => ['subject' => 'Subject', 'body' => 'Body'],
            ],
          ],
        ],
      ],
    );

    $requirements = beantragt_notification_requirements('runtime');

    $this->assertArrayHasKey('beantragt_notification_jurisdiction_mail_templates', $requirements);
    $payload = $requirements['beantragt_notification_jurisdiction_mail_templates'];
    $this->assertSame(RequirementSeverity::OK, $payload['severity']);
    $this->assertSame('2 jurisdiction(s) covered', (string) $payload['value']);
  }

  /**
   * Runtime reports WARNING when jurisdiction templates are incomplete.
   */
  public function testRuntimeRequirementWarnsWhenJurisdictionTemplatesAreIncomplete(): void {
    $covered = $this->createJurisdiction(1, 'Stadt Musterstadt', 'musterstadt');
    $missing_section = $this->createJurisdiction(2, 'Neue Kommune', 'neue-kommune');
    $missing_confirmation = $this->createJurisdiction(3, 'Alte Kommune', 'alte-kommune');

    $this->setRuntimeContainer(
      $this->registeredModuleHandler(),
      [$covered, $missing_section, $missing_confirmation],
      [
        1 => [
          'templates' => [
            'application_confirmation_request' => [
              'default' => ['subject' => 'Subject', 'body' => 'Body'],
            ],
          ],
        ],
        2 => [],
        3 => [
          'templates' => [
            'application_confirmation' => [
              'default' => ['subject' => 'Subject', 'body' => 'Body'],
            ],
          ],
        ],
      ],
    );

    $requirements = beantragt_notification_requirements('runtime');

    $this->assertArrayHasKey('beantragt_notification_jurisdiction_mail_templates', $requirements);
    $payload = $requirements['beantragt_notification_jurisdiction_mail_templates'];
    $this->assertSame(RequirementSeverity::Warning, $payload['severity']);
    $this->assertSame('2 jurisdiction(s) incomplete', (string) $payload['value']);
    $this->assertStringContainsString('Neue Kommune (neue-kommune)', (string) $payload['description']);
    $this->assertStringContainsString('Alte Kommune (alte-kommune)', (string) $payload['description']);
    $this->assertStringNotContainsString('Stadt Musterstadt (musterstadt)', (string) $payload['description']);
  }

  /**
   * Runtime reports ERROR when the comment insert hook is missing.
   */
  public function testRuntimeRequirementReportsErrorWhenCommentHookIsMissing(): void {
    $this->setModuleHandler($this->createModuleHandler(
      [
        'beantragt_comment' => TRUE,
        'beantragt_notification' => TRUE,
      ],
      [
        'application_comment_insert' => ['beantragt_comment' => FALSE],
        'mail' => ['beantragt_notification' => TRUE],
      ],
    ));

    $requirements = beantragt_notification_requirements('runtime');

    $this->assertArrayHasKey('beantragt_notification_comment_mail_hook', $requirements);
    $payload = $requirements['beantragt_notification_comment_mail_hook'];
    $this->assertSame(RequirementSeverity::Error, $payload['severity']);
    $this->assertStringContainsString(
      'beantragt_comment_application_comment_insert() is not registered',
      (string) $payload['description'],
    );
  }

  /**
   * Runtime reports ERROR when the comment module is disabled.
   */
  public function testRuntimeRequirementReportsErrorWhenCommentModuleIsMissing(): void {
    $this->setModuleHandler($this->createModuleHandler(
      [
        'beantragt_comment' => FALSE,
        'beantragt_notification' => TRUE,
      ],
      [
        'mail' => ['beantragt_notification' => TRUE],
      ],
    ));

    $requirements = beantragt_notification_requirements('runtime');

    $this->assertArrayHasKey('beantragt_notification_comment_mail_hook', $requirements);
    $payload = $requirements['beantragt_notification_comment_mail_hook'];
    $this->assertSame(RequirementSeverity::Error, $payload['severity']);
    $this->assertStringContainsString(
      'beantragt_comment module is disabled',
      (string) $payload['description'],
    );
  }

  /**
   * Runtime reports ERROR when the notification mail hook is missing.
   */
  public function testRuntimeRequirementReportsErrorWhenMailHookIsMissing(): void {
    $this->setModuleHandler($this->createModuleHandler(
      [
        'beantragt_comment' => TRUE,
        'beantragt_notification' => TRUE,
      ],
      [
        'application_comment_insert' => ['beantragt_comment' => TRUE],
        'mail' => ['beantragt_notification' => FALSE],
      ],
    ));

    $requirements = beantragt_notification_requirements('runtime');

    $this->assertArrayHasKey('beantragt_notification_comment_mail_hook', $requirements);
    $payload = $requirements['beantragt_notification_comment_mail_hook'];
    $this->assertSame(RequirementSeverity::Error, $payload['severity']);
    $this->assertStringContainsString(
      'beantragt_notification_mail() is not registered',
      (string) $payload['description'],
    );
  }

  /**
   * Unrelated phases do not emit runtime diagnostics.
   */
  public function testUnrelatedPhaseReturnsEmpty(): void {
    $this->setModuleHandler($this->createModuleHandler([], []));

    $this->assertSame([], beantragt_notification_requirements('install'));
  }

  /**
   * Wires a mocked module handler into Drupal's static container.
   */
  private function setModuleHandler(ModuleHandlerInterface $module_handler): void {
    $container = new ContainerBuilder();
    $container->set('module_handler', $module_handler);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Wires mocked runtime services into Drupal's static container.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   Module handler stub.
   * @param list<\Drupal\group\Entity\GroupInterface> $jurisdictions
   *   Jurisdiction groups returned by group storage.
   * @param array<int, array<string, mixed>> $sections_by_id
   *   Notification mail sections keyed by group id.
   */
  private function setRuntimeContainer(
    ModuleHandlerInterface $module_handler,
    array $jurisdictions,
    array $sections_by_id,
  ): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->with(['type' => 'jur'])
      ->willReturn($jurisdictions);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->once())
      ->method('getStorage')
      ->with('group')
      ->willReturn($storage);

    $config_service = $this->createMock(JurisdictionConfigServiceInterface::class);
    $config_service->method('getSection')
      ->willReturnCallback(static function (GroupInterface $group, string $section) use ($sections_by_id): array {
        if ($section !== 'notification_mail') {
          return [];
        }

        return $sections_by_id[(int) $group->id()] ?? [];
      });

    $container = new ContainerBuilder();
    $container->set('module_handler', $module_handler);
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('beantragt_group.jurisdiction_config', $config_service);
    \Drupal::setContainer($container);
  }

  /**
   * Creates a module handler with the notification hook chain registered.
   */
  private function registeredModuleHandler(): ModuleHandlerInterface {
    return $this->createModuleHandler(
      [
        'beantragt_comment' => TRUE,
        'beantragt_notification' => TRUE,
      ],
      [
        'application_comment_insert' => ['beantragt_comment' => TRUE],
        'mail' => ['beantragt_notification' => TRUE],
      ],
    );
  }

  /**
   * Creates a module handler stub for hook-cache diagnostics.
   *
   * @param array<string, bool> $enabled_modules
   *   Enabled state keyed by module name.
   * @param array<string, array<string, bool>> $implementations
   *   Hook implementation state keyed by hook, then module name.
   */
  private function createModuleHandler(
    array $enabled_modules,
    array $implementations,
  ): ModuleHandlerInterface {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);

    $module_handler->method('moduleExists')
      ->willReturnCallback(static function (string $module) use ($enabled_modules): bool {
        return $enabled_modules[$module] ?? FALSE;
      });

    $module_handler->method('hasImplementations')
      ->willReturnCallback(static function (string $hook, mixed $modules = NULL) use ($implementations): bool {
        $hook_implementations = $implementations[$hook] ?? [];

        if ($modules === NULL) {
          return in_array(TRUE, $hook_implementations, TRUE);
        }

        foreach ((array) $modules as $module) {
          if (empty($hook_implementations[$module])) {
            return FALSE;
          }
        }

        return TRUE;
      });

    return $module_handler;
  }

  /**
   * Creates a jurisdiction group mock.
   */
  private function createJurisdiction(int $id, string $label, string $slug): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('label')->willReturn($label);
    $group->method('hasField')
      ->willReturnCallback(static fn (string $field): bool => $field === 'field_slug');
    $group->method('get')
      ->willReturnCallback(fn (string $field): FieldItemListInterface => $field === 'field_slug'
        ? $this->fieldList($slug)
        : $this->fieldList('', TRUE));

    return $group;
  }

  /**
   * Creates a field-item list mock with string output.
   */
  private function fieldList(string $value, bool $empty = FALSE): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($empty);
    $field->method('getString')->willReturn($value);

    return $field;
  }

}
