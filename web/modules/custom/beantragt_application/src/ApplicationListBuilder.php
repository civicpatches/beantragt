<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Access\ApplicationCaseScope;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list builder for the application entity type.
 */
class ApplicationListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs a new ApplicationListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\beantragt_application\Service\TenantScopedEntityQuery $tenantQuery
   *   The application query scope service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current account.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
    protected TenantScopedEntityQuery $tenantQuery,
    protected AccountInterface $account,
  ) {
    parent::__construct(
      $entity_type,
      $entity_type_manager->getStorage($entity_type->id()),
    );
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('beantragt_application.tenant_scoped_entity_query'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['field_reference_number'] = $this->t('Reference');
    $header['label'] = $this->t('Label');
    $header['field_schema'] = $this->t('Schema');
    $header['field_workflow_status'] = $this->t('Status');
    $header['uid'] = $this->t('Author');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()->accessCheck(FALSE)
      ->sort($this->entityType->getKey('id'));
    $this->tenantQuery->applyAccountScope($query, 'application', $this->account);

    if ($this->limit) {
      $query->pager($this->limit);
    }

    return $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function load(): array {
    // Core keeps the collection route administrative. An entity query's
    // accessCheck(TRUE) alone does not check each custom entity's access.
    return array_filter(parent::load(), fn (EntityInterface $entity): bool => $entity->access('view', $this->account));
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    $cacheability = CacheableMetadata::createFromRenderArray($build['table']);
    $cacheability->addCacheContexts(['user', 'user.permissions'])
      ->addCacheTags(ApplicationCaseScope::cacheTags($this->account))
      ->addCacheTags(['group_list'])
      ->applyTo($build['table']);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\beantragt_application\Entity\ApplicationInterface $entity */
    $row['id'] = $entity->id();
    $row['field_reference_number'] = $entity->getReferenceNumber() ?? '-';
    $row['label'] = $entity->toLink();

    $schema = $entity->get('field_schema')->entity;
    $row['field_schema'] = $schema ? $schema->label() : '-';

    $row['field_workflow_status'] = $entity->getWorkflowStatus();
    $row['uid'] = $entity->getOwner()?->getDisplayName() ?? '-';
    $row['created'] = $this->dateFormatter->format(
      $entity->getCreatedTime(),
      'short',
    );
    return $row + parent::buildRow($entity);
  }

}
