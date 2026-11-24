<?php

declare(strict_types=1);

namespace Drupal\beantragt_notification\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST controller for managing notification email templates.
 *
 * Provides endpoints to list and update notification templates
 * and their enabled state. Requires the beantragt notification template
 * administration permission.
 */
final class NotificationSettingsController extends ControllerBase {

  /**
   * The valid template keys.
   *
   * @var string[]
   */
  protected const VALID_TEMPLATES = [
    'application_confirmation_request',
    'application_confirmation',
    'status_change',
    'approval',
    'rejection',
    'new_application_alert',
    'assignment_alert',
    'delegation_alert',
    'escalation_alert',
    'deadline_alert',
    'resubmission_reminder',
    'application_comment_staff',
    'application_comment_citizen',
    'external_response_available',
  ];

  /**
   * Constructs a NotificationSettingsController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Returns all notification templates with their enabled state.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with template data.
   */
  public function listTemplates(): JsonResponse {
    $mailConfig = $this->config('beantragt_notification.mail');
    $settingsConfig = $this->config('beantragt_notification.settings');
    $enabledTemplates = $settingsConfig->get('enabled_templates') ?? [];

    $templates = [];
    foreach (self::VALID_TEMPLATES as $key) {
      $template = $mailConfig->get($key);
      $templates[$key] = [
        'id' => $key,
        'subject' => $template['subject'] ?? '',
        'body' => $template['body'] ?? '',
        'enabled' => !empty($enabledTemplates[$key]),
      ];
    }

    return $this->privateNoStoreResponse(['data' => $templates]);
  }

  /**
   * Updates a single notification template's subject, body, and enabled state.
   *
   * Accepts a JSON body with optional keys: subject, body, enabled.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param string $template_id
   *   The template key to update.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response confirming the update.
   */
  public function updateTemplate(Request $request, string $template_id): JsonResponse {
    if (!in_array($template_id, self::VALID_TEMPLATES, TRUE)) {
      return $this->privateNoStoreResponse(
        ['error' => 'Invalid template ID.'],
        Response::HTTP_BAD_REQUEST
      );
    }

    $content = $request->getContent();
    $data = json_decode($content, TRUE);
    if (!is_array($data)) {
      return $this->privateNoStoreResponse(
        ['error' => 'Invalid JSON body.'],
        Response::HTTP_BAD_REQUEST
      );
    }

    if (isset($data['subject']) || isset($data['body'])) {
      $mailConfig = $this->configFactory->getEditable('beantragt_notification.mail');
      $current = $mailConfig->get($template_id) ?? [];

      if (isset($data['subject'])) {
        $subject = mb_substr((string) $data['subject'], 0, 500);
        $current['subject'] = strip_tags($subject);
      }
      if (isset($data['body'])) {
        $body = mb_substr((string) $data['body'], 0, 10000);
        $current['body'] = strip_tags($body);
      }

      $mailConfig->set($template_id, $current)->save();
    }

    if (isset($data['enabled'])) {
      $settingsConfig = $this->configFactory->getEditable('beantragt_notification.settings');
      $settingsConfig->set('enabled_templates.' . $template_id, (bool) $data['enabled'])->save();
    }

    return $this->privateNoStoreResponse([
      'status' => 'updated',
      'template_id' => $template_id,
    ]);
  }

  /**
   * Builds a private response for tenant administration endpoints.
   *
   * @param array<string, mixed> $payload
   *   Response payload.
   * @param int $status
   *   HTTP response status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with no-store cache headers.
   */
  private function privateNoStoreResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($payload, $status);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
