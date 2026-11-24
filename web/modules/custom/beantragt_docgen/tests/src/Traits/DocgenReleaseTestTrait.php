<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Traits;

use Drupal\beantragt_docgen\Service\LetterheadService;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_docgen\Service\DocgenClient;
use Drupal\beantragt_docgen\Service\DocgenScopeAccess;
use Drupal\beantragt_docgen\Service\DocgenSettingsRepository;
use Drupal\beantragt_docgen\Service\DocumentRepository;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\beantragt_docgen\Service\RenderDataBuilder;
use Drupal\beantragt_docgen\Service\TemplateCatalog;
use Drupal\Core\Database\Connection;

/**
 * Shared release service doubles for kernel tests with real SQL repositories.
 *
 * Consumers provide repository, manager(), responseService(), and anonymize().
 */
trait DocgenReleaseTestTrait {

  /**
   * Builds the service with the real response path and test doubles.
   *
   * @param array{scope?: \Drupal\beantragt_docgen\Service\DocgenScopeAccess, letterhead?: \Drupal\beantragt_docgen\Service\LetterheadService, extensions?: \Drupal\beantragt_docgen\DocgenCaseExtensionInterface, appendix?: \Drupal\beantragt_docgen\DocgenAppendixInterface, scan?: string, failOnReleased?: bool, anonymizeOnReleasing?: bool, client?: \Drupal\beantragt_docgen\Service\DocgenClient, catalog?: \Drupal\beantragt_docgen\Service\TemplateCatalog, dataBuilder?: \Drupal\beantragt_docgen\Service\RenderDataBuilder, templates?: \Drupal\beantragt_docgen\Entity\DocgenTemplateInterface[]} $options
   *   Test doubles to use.
   */
  protected function service(array $options = []): DocumentService {
    $database = $this->container->get('database');
    $manager = $this->manager();
    $repository = $this->repository;
    if (!empty($options['failOnReleased'])) {
      // Crash between the provision and its bookkeeping, exactly once.
      $repository = new class($database) extends DocumentRepository {

        /**
         * Whether the next booking as released fails.
         */
        public bool $armed = TRUE;

        /**
         * {@inheritdoc}
         */
        public function update(string $uuid, array $fields): void {
          if ($this->armed && ($fields['state'] ?? NULL) === DocumentRepository::STATE_RELEASED) {
            $this->armed = FALSE;
            throw new \RuntimeException('Simulated crash after the provision.');
          }
          parent::update($uuid, $fields);
        }

      };
    }
    if (!empty($options['anonymizeOnReleasing'])) {
      // The retention run anonymizes right after the release was stored.
      $repository = new class($database, fn() => $this->anonymize()) extends DocumentRepository {

        /**
         * Constructs the repository with the test that anonymizes.
         */
        public function __construct(Connection $database, private readonly \Closure $anonymize) {
          parent::__construct($database);
        }

        /**
         * {@inheritdoc}
         */
        public function update(string $uuid, array $fields): void {
          parent::update($uuid, $fields);
          if (($fields['state'] ?? NULL) === DocumentRepository::STATE_RELEASING) {
            ($this->anonymize)();
          }
        }

      };
    }
    $scope = $options['scope'] ?? $this->createMock(DocgenScopeAccess::class);
    if (!isset($options['scope'])) {
      $scope->method('canWorkOnCase')->willReturn(TRUE);
    }
    $letterhead = $options['letterhead'] ?? $this->createMock(LetterheadService::class);
    if (!isset($options['letterhead'])) {
      $letterhead->method('apply')->willReturn('test letterhead');
    }
    $arguments = [
      $repository,
      $options['catalog'] ?? $this->createMock(TemplateCatalog::class),
      $this->createMock(DocgenSettingsRepository::class),
      $options['dataBuilder'] ?? $this->createMock(RenderDataBuilder::class),
      $options['client'] ?? $this->createMock(DocgenClient::class),
      $scope,
      $manager,
      $this->container->get('file_system'),
      $this->container->get('file.repository'),
      $this->container->get('uuid'),
      $this->container->get('datetime.time'),
      $this->container->get('lock'),
      $this->container->get('logger.channel.beantragt_docgen'),
      $this->responseService($options['scan'] ?? AttachmentScanResult::STATUS_CLEAN),
      new ApplicationDependentMutationLock($manager, $this->container->get('lock'), $database),
      $options['extensions'] ?? NULL,
      $letterhead,
      $options['appendix'] ?? NULL,
    ];
    if (!isset($options['templates'])) {
      return new DocumentService(...$arguments);
    }
    // Template choice depends on the form's settings; the drafts under test
    // only need the released template on offer.
    $service = $this->getMockBuilder(DocumentService::class)->setConstructorArgs($arguments)->onlyMethods(['availableTemplates'])->getMock();
    $service->method('availableTemplates')->willReturn([
      'route' => DocgenSettingsRepository::ROUTE_TEMPLATE,
      'templates' => $options['templates'],
    ]);
    return $service;
  }

}
