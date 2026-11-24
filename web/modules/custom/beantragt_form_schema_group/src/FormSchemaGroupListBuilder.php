<?php

declare(strict_types=1);

namespace Drupal\beantragt_form_schema_group;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list builder for form_schema_group entities.
 */
class FormSchemaGroupListBuilder extends EntityListBuilder {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs a FormSchemaGroupListBuilder object.
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
    $header['field_schemas'] = $this->t('Forms');
    $header['uid'] = $this->t('Author');
    $header['created'] = $this->t('Created');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\beantragt_form_schema_group\Entity\FormSchemaGroupInterface $entity */
    $row['id'] = $entity->id();
    $row['label'] = $entity->toLink();
    $row['field_workflow_status'] = $entity->getWorkflowStatus();
    $row['field_schemas'] = count($entity->getFormSchemaIds());
    $row['uid'] = $entity->getOwner()?->getDisplayName() ?? '-';
    $row['created'] = $this->dateFormatter->format(
      $entity->getCreatedTime(),
      'short',
    );
    return $row + parent::buildRow($entity);
  }

}
