<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_notification\Unit\Controller;

use Drupal\beantragt_notification\Controller\NotificationSettingsController;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests notification settings controller behavior.
 */
#[CoversClass(NotificationSettingsController::class)]
#[Group('beantragt_notification')]
final class NotificationSettingsControllerTest extends UnitTestCase {

  /**
   * ListTemplates() exposes every runtime notification template.
   */
  public function testListTemplatesIncludesCommentNotifications(): void {
    $mail_config = $this->createMock(ImmutableConfig::class);
    $mail_config->method('get')
      ->willReturnCallback(static fn(string $key): ?array => [
        'application_confirmation' => [
          'subject' => 'Confirmation @reference_number',
          'body' => 'Confirmation body',
        ],
        'status_change' => [
          'subject' => 'Status @reference_number',
          'body' => 'Status body',
        ],
        'approval' => [
          'subject' => 'Approval @reference_number',
          'body' => 'Approval body',
        ],
        'rejection' => [
          'subject' => 'Rejection @reference_number',
          'body' => 'Rejection body',
        ],
        'new_application_alert' => [
          'subject' => 'New application @reference_number',
          'body' => 'New application body',
        ],
        'assignment_alert' => [
          'subject' => 'Assignment @reference_number',
          'body' => 'Assignment body',
        ],
        'delegation_alert' => [
          'subject' => 'Delegation @reference_number',
          'body' => 'Delegation body',
        ],
        'escalation_alert' => [
          'subject' => 'Escalation @reference_number',
          'body' => 'Escalation body',
        ],
        'deadline_alert' => [
          'subject' => 'Deadline @reference_number',
          'body' => 'Deadline body',
        ],
        'resubmission_reminder' => [
          'subject' => 'Resubmission @reference_number',
          'body' => 'Resubmission body',
        ],
        'application_comment_staff' => [
          'subject' => 'Question @reference_number',
          'body' => 'Question body',
        ],
        'application_comment_citizen' => [
          'subject' => 'Reply @reference_number',
          'body' => 'Reply body',
        ],
      ][$key] ?? NULL);

    $settings_config = $this->createMock(ImmutableConfig::class);
    $settings_config->method('get')
      ->with('enabled_templates')
      ->willReturn([
        'application_comment_staff' => TRUE,
        'application_comment_citizen' => TRUE,
        'delegation_alert' => TRUE,
        'deadline_alert' => TRUE,
        'resubmission_reminder' => TRUE,
      ]);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->willReturnMap([
        ['beantragt_notification.mail', $mail_config],
        ['beantragt_notification.settings', $settings_config],
      ]);

    $controller = new NotificationSettingsController($config_factory);

    $response = $controller->listTemplates();
    $payload = json_decode((string) $response->getContent(), TRUE);

    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertArrayHasKey('application_comment_staff', $payload['data']);
    $this->assertArrayHasKey('application_comment_citizen', $payload['data']);
    $this->assertArrayHasKey('assignment_alert', $payload['data']);
    $this->assertArrayHasKey('delegation_alert', $payload['data']);
    $this->assertArrayHasKey('deadline_alert', $payload['data']);
    $this->assertArrayHasKey('resubmission_reminder', $payload['data']);
    $this->assertTrue($payload['data']['application_comment_staff']['enabled']);
    $this->assertTrue($payload['data']['application_comment_citizen']['enabled']);
    $this->assertTrue($payload['data']['delegation_alert']['enabled']);
    $this->assertTrue($payload['data']['deadline_alert']['enabled']);
    $this->assertTrue($payload['data']['resubmission_reminder']['enabled']);
  }

  /**
   * UpdateTemplate() writes through the injected editable config factory.
   */
  public function testUpdateTemplateWritesEditableConfig(): void {
    $mail_config = $this->createMock(Config::class);
    $mail_config->expects($this->once())
      ->method('get')
      ->with('application_confirmation')
      ->willReturn([
        'subject' => 'Old subject',
        'body' => 'Old body',
      ]);
    $mail_config->expects($this->once())
      ->method('set')
      ->with('application_confirmation', [
        'subject' => 'New subject',
        'body' => 'New body',
      ])
      ->willReturnSelf();
    $mail_config->expects($this->once())
      ->method('save')
      ->willReturnSelf();

    $settings_config = $this->createMock(Config::class);
    $settings_config->expects($this->once())
      ->method('set')
      ->with('enabled_templates.application_confirmation', FALSE)
      ->willReturnSelf();
    $settings_config->expects($this->once())
      ->method('save')
      ->willReturnSelf();

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->expects($this->exactly(2))
      ->method('getEditable')
      ->willReturnMap([
        ['beantragt_notification.mail', $mail_config],
        ['beantragt_notification.settings', $settings_config],
      ]);

    $controller = new NotificationSettingsController($config_factory);
    $request = new Request([], [], [], [], [], [], json_encode([
      'subject' => 'New subject',
      'body' => 'New body',
      'enabled' => FALSE,
    ], JSON_THROW_ON_ERROR));

    $response = $controller->updateTemplate($request, 'application_confirmation');

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->assertSame([
      'status' => 'updated',
      'template_id' => 'application_confirmation',
    ], json_decode((string) $response->getContent(), TRUE));
  }

}
