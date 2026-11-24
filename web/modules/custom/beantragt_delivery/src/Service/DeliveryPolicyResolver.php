<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Value\DeliveryPolicy;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves form-level delivery policy for an application.
 */
final class DeliveryPolicyResolver {

  /**
   * Supported policy keys.
   */
  private const ALLOWED_KEYS = [
    'processingMode',
    'adapter',
  ];

  /**
   * Constructs the resolver.
   */
  public function __construct(
    private readonly LoggerInterface $logger = new NullLogger(),
  ) {}

  /**
   * Resolves the delivery policy from the referenced FormSchema DSL.
   */
  public function resolve(ApplicationInterface $application): ?DeliveryPolicy {
    if (!$application->hasField('field_schema') || $application->get('field_schema')->isEmpty()) {
      return NULL;
    }

    $schema = $application->get('field_schema')->entity;
    if (!$schema instanceof FormSchemaInterface) {
      return NULL;
    }

    $schema_json = $schema->getSchema();
    if ($schema_json === NULL || trim($schema_json) === '') {
      return NULL;
    }

    try {
      $decoded = json_decode($schema_json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->logger->warning('Application @application has malformed form schema JSON; automatic delivery is disabled.', [
        '@application' => (string) ($application->uuid() ?? $application->id() ?? ''),
        '@message' => $e->getMessage(),
      ]);
      return new DeliveryPolicy();
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
      $this->logger->warning('Application @application references a form schema with invalid root shape; automatic delivery is disabled.', [
        '@application' => (string) ($application->uuid() ?? $application->id() ?? ''),
      ]);
      return new DeliveryPolicy();
    }

    if (!array_key_exists('delivery', $decoded)) {
      return NULL;
    }
    $delivery = $decoded['delivery'] ?? NULL;
    if (!is_array($delivery) || $delivery === [] || array_is_list($delivery)) {
      $this->logger->warning('Application @application references an invalid form delivery policy; automatic delivery is disabled.', [
        '@application' => (string) ($application->uuid() ?? $application->id() ?? ''),
      ]);
      return new DeliveryPolicy();
    }

    if (!$this->isSupportedPolicy($delivery)) {
      $this->logger->warning('Application @application references an unsupported form delivery policy; automatic delivery is disabled.', [
        '@application' => (string) ($application->uuid() ?? $application->id() ?? ''),
      ]);
      return new DeliveryPolicy();
    }

    return DeliveryPolicy::fromArray($delivery);
  }

  /**
   * Returns TRUE when the policy contains only supported bounded values.
   *
   * @param array<string, mixed> $policy
   *   Delivery policy.
   */
  private function isSupportedPolicy(array $policy): bool {
    foreach ($policy as $key => $value) {
      if (!in_array($key, self::ALLOWED_KEYS, TRUE)) {
        return FALSE;
      }
    }

    if (array_key_exists('processingMode', $policy)
      && (!is_string($policy['processingMode'])
        || !in_array($policy['processingMode'], [
          DeliveryPolicy::PROCESSING_SELF_CONTAINED,
          DeliveryPolicy::PROCESSING_PASS_THROUGH,
        ], TRUE))) {
      return FALSE;
    }

    if (array_key_exists('adapter', $policy)
      && (!is_string($policy['adapter'])
        || !in_array($policy['adapter'], [
          DeliveryPolicy::ADAPTER_MOCK,
          DeliveryPolicy::ADAPTER_WEBHOOK,
          DeliveryPolicy::ADAPTER_FIT_CONNECT,
        ], TRUE))) {
      return FALSE;
    }

    return TRUE;
  }

}
