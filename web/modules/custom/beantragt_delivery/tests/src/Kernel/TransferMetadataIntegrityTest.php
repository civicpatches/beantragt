<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\TransferPlanner;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Corrupt checkpoint storage remains readable but cannot regain send privilege.
 */
#[Group('beantragt_delivery')]
final class TransferMetadataIntegrityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Lost checkpoint evidence never means that submission has not started.
   */
  public function testCorruptMetadataIsReadableButNeverRetryableOrSendable(): void {
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $database = $this->container->get('database');
    $database->schema()->createTable('beantragt_delivery_log', beantragt_delivery_schema()['beantragt_delivery_log']);
    $database->schema()->createTable('synthetic_application', [
      'fields' => ['id' => ['type' => 'int', 'not null' => TRUE]],
      'primary key' => ['id'],
    ]);
    $database->insert('synthetic_application')->fields(['id' => 1])->execute();
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('id')->willReturn(1);
    $application->method('uuid')->willReturn('synthetic-application');
    $application->method('getData')->willReturn('{}');
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(100);
    $time->method('getRequestTime')->willReturn(100);
    $logs = new DeliveryLogRepository($database, $this->container->get('uuid'), $time, new NullLogger());
    $envelope = new DeliveryEnvelope(1, 'synthetic-application', 'SYNTHETIC', 'schema', 'Synthetic', 1, hash('sha256', '{}'), 0, 0);
    $id = $logs->createQueued($application, $envelope, 'fit_connect', 'destination', [
      'scope_group_id' => 1,
      'dispatch_mode' => 'automatic',
      'payload_reference' => 'synthetic-payload',
    ], DeliveryLogRepository::STATUS_FAILED);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($application);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('application')->willReturn($storage);
    $entities->method('getDefinition')->with('application')->willReturn(new ContentEntityType([
      'id' => 'application',
      'base_table' => 'synthetic_application',
      'entity_keys' => ['id' => 'id'],
    ]));
    // Only the dependencies preceding the fail-closed integrity check exist.
    // Any attempted mapping/profile/payload/network work fails this test.
    $reflection = new \ReflectionClass(TransferPlanner::class);
    $planner = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('database')->setValue($planner, $database);
    $reflection->getProperty('entities')->setValue($planner, $entities);
    foreach (['{"fitConnect":{"phase":"creating"}', 'null', '"invalid-shape"', '', NULL] as $metadata) {
      $database->update('beantragt_delivery_log')->fields([
        'metadata' => $metadata,
        'last_error' => 'fit_connect.api_request_failed',
      ])->condition('id', $id)->execute();
      $row = $logs->load($id);
      $this->assertSame([], $row['metadata']);
      $this->assertSame('failed', $row['binding_integrity']);
      $this->assertCount(1, $logs->listForApplication($application));
      $this->assertSame('failed', $logs->listForApplication($application, 20, TRUE)[0]['binding_integrity']);
      $this->assertFalse($planner->canRetry($row));
      $row['lastError'] = 'integration.application_changed';
      $this->assertFalse($planner->canReplan($row));
      try {
        $planner->prepareSend($row, $application);
        $this->fail('Corrupt checkpoint metadata must never authorize a send.');
      }
      catch (IntegrationException $exception) {
        $this->assertSame('integration.binding_invalid', $exception->errorCode);
      }
      $this->assertSame('failed', $logs->load($id)['status']);
      $this->assertSame(0, $logs->load($id)['attempts']);
    }
  }

}
