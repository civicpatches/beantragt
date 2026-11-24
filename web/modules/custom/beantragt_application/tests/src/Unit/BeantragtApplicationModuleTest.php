<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\ApplicationDeletionLockedException;
use Drupal\beantragt_application\Service\ApplicantScopeMutationLock;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests beantragt_application module hooks.
 */
#[Group('beantragt_application')]
final class BeantragtApplicationModuleTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    include_once dirname(__DIR__, 3) . '/beantragt_application.module';
  }

  /**
   * Application saves fail closed while cleanup owns the applicant scope.
   */
  public function testApplicationPresaveRejectsConcurrentScopeCleanup(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $scope_lock = $this->createMock(ApplicantScopeMutationLock::class);
    $scope_lock->expects($this->once())
      ->method('acquireForApplication')
      ->with($application)
      ->willReturn(FALSE);
    $scope_lock->expects($this->never())->method('release');

    $container = new ContainerBuilder();
    $container->set('beantragt_application.applicant_scope_mutation_lock', $scope_lock);
    \Drupal::setContainer($container);

    $this->expectException(ApplicationDeletionLockedException::class);
    $this->expectExceptionMessage('The applicant scope is currently being mutated.');

    \beantragt_application_application_presave($application);
  }

  /**
   * Deleting any attachment also deletes its permanent guard derivative.
   */
  public function testAttachmentDeleteCascadesToGuardDerivative(): void {
    $derivative_field = $this->createMock(FieldItemListInterface::class);
    $derivative_field->method('isEmpty')->willReturn(FALSE);
    $derivative_field->method('__get')->with('target_id')->willReturn(654);
    $attachment = $this->createMock(ApplicationAttachmentInterface::class);
    $attachment->method('hasField')->with('field_guard_derivative')->willReturn(TRUE);
    $attachment->method('get')->with('field_guard_derivative')->willReturn($derivative_field);

    $derivative = $this->createMock(FileInterface::class);
    $derivative->expects($this->once())->method('delete');
    $file_storage = $this->createMock(EntityStorageInterface::class);
    $file_storage->method('load')->with(654)->willReturn($derivative);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('file')->willReturn($file_storage);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    \Drupal::setContainer($container);

    \beantragt_application_application_attachment_delete($attachment);
  }

}
