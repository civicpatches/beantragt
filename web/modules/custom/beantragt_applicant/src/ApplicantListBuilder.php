<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a list builder for applicant entities.
 */
class ApplicantListBuilder extends EntityListBuilder {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs an ApplicantListBuilder.
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
    $header['label'] = $this->t('Name');
    $header['email'] = $this->t('Email');
    $header['city'] = $this->t('City');
    $header['created'] = $this->t('Created');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\beantragt_applicant\Entity\ApplicantInterface $entity */
    $row['id'] = $entity->id();
    $row['label'] = $entity->toLink();
    $row['email'] = $entity->getEmail() ?? '';
    $row['city'] = $entity->get('field_city')->value ?? '';
    $row['created'] = $this->dateFormatter->format($entity->getCreatedTime(), 'short');
    $row['status'] = $entity->isPublished() ? $this->t('Published') : $this->t('Unpublished');
    return $row + parent::buildRow($entity);
  }

}
