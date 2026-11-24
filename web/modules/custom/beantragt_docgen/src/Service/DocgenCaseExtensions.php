<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\DocgenCaseExtensionInterface;
use Drupal\beantragt_docgen\DocgenRequestDraftGuardInterface;
use Drupal\beantragt_docgen\ProtectedDocgenCaseExtensionInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Collects case extensions without a reverse dependency on their modules.
 */
final class DocgenCaseExtensions implements DocgenCaseExtensionInterface, ProtectedDocgenCaseExtensionInterface, DocgenRequestDraftGuardInterface {

  /**
   * Registered extensions in collector order.
   *
   * @var \Drupal\beantragt_docgen\DocgenCaseExtensionInterface[]
   */
  private array $extensions = [];

  /**
   * Constructs the collector with a logger for committed release observers.
   */
  public function __construct(private readonly LoggerInterface $logger = new NullLogger()) {}

  /**
   * Registers an extension independent of rendering and draft services.
   */
  public function addExtension(DocgenCaseExtensionInterface $extension): void {
    $this->extensions[] = $extension;
  }

  /**
   * {@inheritdoc}
   */
  public function systemValues(ApplicationInterface $application, AccountInterface $account): array {
    $values = [];
    foreach ($this->extensions as $extension) {
      $values += self::providedValues($extension->systemValues($application, $account), $extension, $this->logger);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function protectedSystemValues(ApplicationInterface $application, AccountInterface $account): array {
    $values = [];
    foreach ($this->extensions as $extension) {
      if ($extension instanceof ProtectedDocgenCaseExtensionInterface) {
        $values += self::providedValues($extension->protectedSystemValues($application, $account), $extension, $this->logger);
      }
    }
    return $values;
  }

  /**
   * Bounds untrusted extension values before the renderer's string operations.
   *
   * @param array<string, mixed> $values
   *   Extension output, which may violate its declared value types.
   * @param \Drupal\beantragt_docgen\DocgenCaseExtensionInterface $extension
   *   Source extension, identified only by class in diagnostics.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger for invalid extension output, without keys or values.
   *
   * @return array<string, string>
   *   Only declared provided keys with string values.
   */
  public static function providedValues(array $values, DocgenCaseExtensionInterface $extension, LoggerInterface $logger): array {
    $provided = [];
    foreach (array_intersect_key($values, array_flip(VariableCatalog::PROVIDED)) as $key => $value) {
      if (!is_string($value)) {
        $logger->warning('Non-string document system value omitted from extension @class.', ['@class' => $extension::class]);
        continue;
      }
      if (trim($value) !== '') {
        $provided[$key] = $value;
      }
    }
    return $provided;
  }

  /**
   * {@inheritdoc}
   */
  public function releaseBlockers(array $row): array {
    $blockers = [];
    foreach ($this->extensions as $extension) {
      $blockers = array_merge($blockers, $extension->releaseBlockers($row));
    }
    return array_values(array_unique($blockers));
  }

  /**
   * {@inheritdoc}
   */
  public function assertRequestDraftAllowed(ApplicationInterface $application, array $documents): void {
    foreach ($this->extensions as $extension) {
      if ($extension instanceof DocgenRequestDraftGuardInterface) {
        $extension->assertRequestDraftAllowed($application, $documents);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function released(ApplicationInterface $application, array $rows, AccountInterface $account): void {
    foreach ($this->extensions as $extension) {
      try {
        $extension->released($application, $rows, $account);
      }
      catch (\Throwable $exception) {
        $this->logger->error('Document release observer failed: @class.', ['@class' => $exception::class]);
      }
    }
  }

}
