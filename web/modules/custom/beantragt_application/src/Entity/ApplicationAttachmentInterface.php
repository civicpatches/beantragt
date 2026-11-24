<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for Application Attachment entities.
 */
interface ApplicationAttachmentInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Gets the entity creation timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Sets the entity creation timestamp.
   */
  public function setCreatedTime(int $timestamp): static;

  /**
   * Gets the form field id this attachment belongs to.
   */
  public function getFieldId(): string;

  /**
   * Returns TRUE when the attachment is already bound to an application.
   */
  public function isClaimed(): bool;

}
