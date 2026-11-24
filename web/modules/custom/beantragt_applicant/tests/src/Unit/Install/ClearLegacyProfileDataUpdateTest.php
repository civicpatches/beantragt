<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Unit\Install;

use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\UpdateException;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the update that clears tax IDs and unconsented bank details.
 *
 * @group beantragt_applicant
 */
final class ClearLegacyProfileDataUpdateTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 4) . '/beantragt_applicant.install';
  }

  /**
   * Tax IDs always go; bank details only without a bank consent time.
   *
   * Mutation probe: keeping the IBAN of a profile without bank consent, or
   * clearing it on a profile with one, fails this test.
   */
  public function testClearsTaxIdAndOnlyUnconsentedBankDetails(): void {
    $sets = [];
    $with_consent = $this->profile(1, [
      'field_tax_id' => '65929970489',
      'field_iban' => 'DE89370400440532013000',
      'field_account_holder' => 'Bertha Bürger',
      'field_bank_consent_time' => '1781431200',
    ], $sets, TRUE);
    $without_consent = $this->profile(2, [
      'field_iban' => 'DE02120300000000202051',
      'field_account_holder' => 'Altbestand',
    ], $sets, TRUE);
    $untouched = $this->profile(3, ['field_full_name' => 'Ada Antrag'], $sets, FALSE);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn([1 => 1, 2 => 2, 3 => 3]);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([1 => $with_consent, 2 => $without_consent, 3 => $untouched]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('applicant')->willReturn($storage);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);

    $sandbox = [];
    $message = beantragt_applicant_update_10006($sandbox);

    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame(['field_tax_id' => NULL], $sets[1]);
    $this->assertSame(['field_iban' => NULL, 'field_account_holder' => NULL], $sets[2]);
    $this->assertArrayNotHasKey(3, $sets);
    $this->assertStringContainsString('tax ID on 1 applicant profiles and unconsented bank details on 1', $message);
  }

  /**
   * A profile that cannot be saved fails the update instead of finishing it.
   *
   * Mutation probe: counting before the save or finishing with failed
   * profiles reports a cleaned database that still holds the tax ID.
   */
  public function testFailedSaveIsNotCountedAndFailsTheUpdate(): void {
    $sets = [];
    $failing = $this->profile(1, ['field_tax_id' => '65929970489'], $sets, TRUE, TRUE);
    $cleared = $this->profile(2, ['field_tax_id' => '12345678901'], $sets, TRUE);
    $this->setUpdateContainer([1 => $failing, 2 => $cleared]);

    $sandbox = [];
    try {
      beantragt_applicant_update_10006($sandbox);
      $this->fail('The update finished although a profile could not be saved.');
    }
    catch (UpdateException $exception) {
      $this->assertStringContainsString('tax ID on 1 applicant profiles', $exception->getMessage());
      $this->assertStringContainsString('Failed to save applicants: 1', $exception->getMessage());
    }
    $this->assertSame(1, $sandbox['tax_ids']);
    $this->assertSame([1], $sandbox['failed']);
  }

  /**
   * Registers storage returning the given applicants and a logger.
   *
   * @param array<int, \Drupal\beantragt_applicant\Entity\ApplicantInterface> $applicants
   *   Applicants keyed by id.
   */
  private function setUpdateContainer(array $applicants): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine(array_keys($applicants), array_keys($applicants)));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($applicants);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('applicant')->willReturn($storage);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('logger.factory', $logger_factory);
    \Drupal::setContainer($container);
  }

  /**
   * Builds an applicant mock that records set() calls.
   *
   * @param int $id
   *   Applicant id.
   * @param array<string, string> $fields
   *   Field values keyed by field name.
   * @param array<int, array<string, mixed>> $sets
   *   Recorded set() calls keyed by applicant id.
   * @param bool $expect_save
   *   Whether the update must save the applicant.
   * @param bool $save_fails
   *   Whether the save throws.
   */
  private function profile(int $id, array $fields, array &$sets, bool $expect_save, bool $save_fails = FALSE): ApplicantInterface {
    $profile = $this->createMock(ApplicantInterface::class);
    $profile->method('id')->willReturn($id);
    $profile->method('hasField')->willReturn(TRUE);
    $profile->method('get')->willReturnCallback(function (string $name) use ($fields): FieldItemListInterface {
      $value = $fields[$name] ?? NULL;
      $items = $this->createMock(FieldItemListInterface::class);
      $items->method('isEmpty')->willReturn($value === NULL || $value === '');
      $items->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'value' ? $value : NULL);
      return $items;
    });
    $profile->method('set')->willReturnCallback(function (string $name, mixed $value) use ($id, &$sets, $profile): ApplicantInterface {
      $sets[$id][$name] = $value;
      return $profile;
    });
    $save = $profile->expects($expect_save ? $this->once() : $this->never())->method('save');
    if ($save_fails) {
      $save->willThrowException(new EntityStorageException('Simulated storage failure.'));
    }
    return $profile;
  }

}
