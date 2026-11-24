<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_delivery\Service\ExternalResponseRepository;
use Drupal\beantragt_delivery\Service\ExternalResponseService;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deletes crash leftovers without retaining citizen documents after erasure.
 */
#[Group('beantragt_delivery')]
final class ExternalResponseOrphanCleanupTest extends KernelTestBase {

  /**
   * Modules enabled for this test.
   *
   * @var string[]
   */
  protected static $modules = ['system', 'user', 'file'];

  /**
   * Repository under test.
   */
  private ExternalResponseRepository $repository;

  /**
   * Service under test.
   */
  private ExternalResponseService $responses;

  /**
   * Application mock that owns the external responses.
   */
  private ApplicationInterface $application;

  /**
   * Registers a private stream wrapper in the test container.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', PrivateStream::class)
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    $this->container->get('stream_wrapper_manager')->register();
    self::assertContains('private', stream_get_wrappers());
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'file']);
    foreach (ExternalResponseRepository::schema() as $table => $schema) {
      $this->container->get('database')->schema()->createTable($table, $schema);
    }
    $this->repository = new ExternalResponseRepository($this->container->get('database'));
    $this->application = $this->createMock(ApplicationInterface::class);
    $this->application->method('id')->willReturn(1);
    $this->application->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $manager = $this->container->get('entity_type.manager');
    $this->responses = new ExternalResponseService(
      $this->repository, $manager, $this->container->get('file_system'), $this->container->get('file.repository'),
      $this->createMock(AttachmentScanServiceInterface::class),
      new ApplicationDependentMutationLock($manager, $this->container->get('lock')),
      $this->container->get('uuid'), $this->container->get('datetime.time'), $this->createMock(NotificationMailer::class),
    );
  }

  /**
   * Tests that application deletion erases linked, orphan and unmanaged files.
   */
  public function testApplicationDeletionErasesLinkedOrphanAndUnmanagedFiles(): void {
    $prefix = 'private://external_responses/' . $this->application->uuid();
    $linked = $this->file($prefix . '/response/linked.pdf');
    $orphan = $this->file($prefix . '/interrupted/permanent.pdf');
    $temporary = $this->file($prefix . '/interrupted/temporary.pdf', FALSE);
    $other = $this->file('private://external_responses/another-application/keep.pdf');
    $this->link($linked);
    $unmanaged = $prefix . '/interrupted/unmanaged.pdf';
    file_put_contents($unmanaged, '%PDF-1.7 unmanaged crash bytes');
    $this->responses->cleanupForApplication($this->application);
    clearstatcache();
    self::assertFalse(is_dir($prefix));
    self::assertFalse(file_exists($unmanaged));
    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache();
    foreach ([$linked, $orphan, $temporary] as $file) {
      self::assertNull($storage->load($file->id()));
      self::assertFalse(file_exists($file->getFileUri()));
    }
    self::assertNotNull($storage->load($other->id()));
    self::assertTrue(file_exists($other->getFileUri()));
    self::assertSame([], $this->repository->list(1, TRUE));
  }

  /**
   * Tests bounded stale cleanup that keeps linked, fresh and other files.
   */
  public function testStaleCleanupIsBoundedAndPreservesLinkedFreshAndOtherFiles(): void {
    $prefix = 'private://external_responses/' . $this->application->uuid();
    $linked = $this->file($prefix . '/response/linked.pdf');
    $this->link($linked);
    $fresh = $this->file($prefix . '/interrupted/fresh.pdf', TRUE, time());
    $unrelated = $this->file('private://application_attachments/unrelated.pdf');
    for ($i = 0; $i < 26; $i++) {
      $this->file($prefix . '/interrupted/orphan-' . $i . '.pdf');
    }
    self::assertSame(25, $this->responses->cleanupStaleOrphanFiles());
    self::assertSame(1, $this->responses->cleanupStaleOrphanFiles());
    self::assertSame(0, $this->responses->cleanupStaleOrphanFiles());
    foreach ([$linked, $fresh, $unrelated] as $file) {
      self::assertTrue(file_exists($file->getFileUri()));
    }
  }

  /**
   * Creates a file entity backed by a synthetic PDF on disk.
   */
  private function file(string $uri, bool $permanent = TRUE, ?int $created = NULL): FileInterface {
    $directory = dirname($uri);
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    self::assertGreaterThan(0, file_put_contents($uri, '%PDF-1.7 synthetic'));
    self::assertTrue(is_file($uri));
    $file = $this->container->get('entity_type.manager')->getStorage('file')->create([
      'uri' => $uri,
      'filename' => basename($uri),
      'uid' => 0,
      'status' => $permanent ? 1 : 0,
      'created' => $created ?? time() - 172800,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Links a file to a stored external response document.
   */
  private function link(FileInterface $file): void {
    $this->repository->insert([
      'uuid' => 'response',
      'application_id' => 1,
      'source' => 'fit_connect',
      'external_id_hash' => hash('sha256', 'reply'),
      'external_reference' => 'case',
      'title' => 'Decision',
    ], [
      [
        'uuid' => 'document',
        'response_uuid' => 'response',
        'file_id' => $file->id(),
        'sha512' => str_repeat('a', 128),
      ],
    ]);
  }

}
