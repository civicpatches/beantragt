<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_delivery\Service\DeliveryEnvelopeBuilder;
use Drupal\beantragt_delivery\Service\TransferAttachmentSelector;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Psr\Log\NullLogger;

/**
 * Tests delivery envelope building.
 *
 * @group beantragt_delivery
 */
final class DeliveryEnvelopeBuilderTest extends UnitTestCase {

  /**
   * Applications without a pinned form revision still have an envelope.
   */
  public function testBuildWithoutSubmissionRevision(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(42);
    $application->method('uuid')->willReturn('6f9719b8-b31e-4b3f-a972-5b18fdcfa0ce');
    $application->method('getData')->willReturn('{"name":"Ada","service":"parking"}');
    $application->method('getReferenceNumber')->willReturn('MS-2026-00042');
    $application->method('getSchemaVersion')->willReturn(3);
    $application->method('hasField')->willReturn(FALSE);

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $protection = new ApplicationDataProtectionService($manager, new NullLogger());
    $builder = new DeliveryEnvelopeBuilder(new TransferAttachmentSelector($manager, $protection));
    $envelope = $builder->build($application);

    $this->assertSame(2, $envelope->fieldCount);
    $this->assertSame(0, $envelope->attachmentCount);
    $this->assertSame([], $envelope->attachmentManifest);
  }

}
