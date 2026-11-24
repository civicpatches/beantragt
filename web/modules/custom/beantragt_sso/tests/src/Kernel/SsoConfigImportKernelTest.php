<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Kernel;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\KernelTests\KernelTestBase;

/**
 * Proves the real configuration importer rejects unsafe SSO settings.
 *
 * @group beantragt_sso
 */
final class SsoConfigImportKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'beantragt_passwordless', 'beantragt_sso'];

  /**
   * Provides the unused profile dependency required by the SSO service.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('beantragt_applicant.manager', ApplicantManager::class)->setSynthetic(TRUE);
    $container->register('beantragt_application.retention_cleanup', ApplicantManager::class)->setSynthetic(TRUE);
  }

  /**
   * A staged unsafe provider never replaces active configuration.
   */
  public function testImporterRejectsUnverifiedEmailLinking(): void {
    $this->installConfig(['system', 'beantragt_sso']);
    $active = $this->container->get('config.storage');
    $source = new MemoryStorage();
    foreach ($active->listAll() as $name) {
      $source->write($name, $active->read($name));
    }
    $unsafe = $source->read('beantragt_sso.settings');
    $unsafe['providers']['bundid_mock']['link_by_email'] = TRUE;
    $unsafe['providers']['bundid_mock']['email_verified'] = FALSE;
    $source->write('beantragt_sso.settings', $unsafe);

    $comparer = new StorageComparer($source, $active);
    $comparer->createChangelist();
    $this->assertContains('beantragt_sso.settings', $comparer->getChangelist('update'));
    $importer = new ConfigImporter(
      $comparer,
      $this->container->get('event_dispatcher'),
      $this->container->get('config.manager'),
      $this->container->get('lock'),
      $this->container->get('config.typed'),
      $this->container->get('module_handler'),
      $this->container->get('module_installer'),
      $this->container->get('theme_handler'),
      $this->container->get('string_translation'),
      $this->container->get('extension.list.module'),
      $this->container->get('extension.list.theme'),
    );

    try {
      $importer->import();
      $this->fail('Unsafe SSO settings import was accepted.');
    }
    catch (ConfigImporterException) {
      $this->assertContains(
        'SSO provider "bundid_mock" cannot link users by email without verified email claims.',
        $importer->getErrors(),
      );
      $this->assertFalse((bool) $this->config('beantragt_sso.settings')->get('providers.bundid_mock.link_by_email'));
    }
  }

}
