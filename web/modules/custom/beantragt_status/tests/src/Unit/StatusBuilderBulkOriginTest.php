<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_status\Unit;

use Drupal\beantragt_status\Service\StatusBuilder;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A bulk-origin application never gets a public status code mail.
 *
 * The applicant email of a staff bulk case comes from an unverified
 * manifest, so the public status OTP must never be sent to it.
 */
#[Group('beantragt_status')]
final class StatusBuilderBulkOriginTest extends UnitTestCase {

  /**
   * Bulk origin answers like an unknown reference; citizen origin matches.
   */
  public function testBulkOriginNeverMatchesForStatusCode(): void {
    $this->assertFalse($this->builder('bulk')->referenceMatchesApplicantEmail('MS-BB-2026-00001', 'fiktiv@example.test'));
    $this->assertTrue($this->builder('citizen')->referenceMatchesApplicantEmail('MS-BB-2026-00001', 'fiktiv@example.test'));
  }

  /**
   * Builds a status builder whose lookups return one fictional application.
   */
  private function builder(string $origin): StatusBuilder {
    $origin_field = $this->createMock(FieldItemListInterface::class);
    $origin_field->method('__get')->willReturnMap([['value', $origin]]);
    $application = $this->createMock(ContentEntityInterface::class);
    $application->method('hasField')->willReturnCallback(fn (string $name): bool => $name === 'field_origin');
    $application->method('get')->willReturnCallback(fn (string $name) => $origin_field);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $schema = $this->createMock(ContentEntityInterface::class);

    return new class($this->createMock(EntityTypeManagerInterface::class), $logger_factory, $this->createMock(ConfigFactoryInterface::class), $this->createMock(TimeInterface::class), $application, $schema) extends StatusBuilder {

      public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, TimeInterface $time, private readonly ContentEntityInterface $application, private readonly ContentEntityInterface $schema) {
        parent::__construct($entity_type_manager, $logger_factory, $config_factory, $time);
      }

      /**
       * Returns the fictional application for every reference.
       */
      protected function loadApplicationByReference(string $reference_number): ?ContentEntityInterface {
        return $this->application;
      }

      /**
       * Treats every email as the applicant's.
       */
      protected function applicantEmailMatches(ContentEntityInterface $application, string $email): bool {
        return TRUE;
      }

      /**
       * Returns the fictional submitted schema.
       */
      protected function submittedSchema(ContentEntityInterface $application): ?ContentEntityInterface {
        return $this->schema;
      }

      /**
       * Treats the schema as published on the internet.
       */
      protected function isPublicSchema(mixed $schema_entity): bool {
        return TRUE;
      }

    };
  }

}
