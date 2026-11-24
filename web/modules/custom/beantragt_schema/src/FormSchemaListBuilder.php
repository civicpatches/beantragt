<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list builder for the form_schema entity type.
 */
final class FormSchemaListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs a new FormSchemaListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Title');
    $header['field_workflow_status'] = $this->t('Status');
    $header['field_publication_scope'] = $this->t('Publication scope');
    $header['field_version'] = $this->t('Version');
    $header['field_source_filename'] = $this->t('Source');
    $header['uid'] = $this->t('Author');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\beantragt_schema\Entity\FormSchemaInterface $entity */
    $row['id'] = $entity->id();
    $row['label'] = $entity->toLink();
    $row['field_workflow_status'] = $entity->getSchemaStatus();
    $row['field_publication_scope'] = $entity->getPublicationScope();
    $row['field_version'] = $entity->getVersion();
    $row['field_source_filename'] = $entity->getSourceFilename() ?? '-';
    $row['uid'] = $entity->getOwner()?->getDisplayName() ?? '-';
    $row['created'] = $this->dateFormatter->format(
      $entity->getCreatedTime(),
      'short',
    );
    return $row + parent::buildRow($entity);
  }

}
