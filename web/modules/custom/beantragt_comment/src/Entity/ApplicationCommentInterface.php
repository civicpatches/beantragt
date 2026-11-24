<?php

declare(strict_types=1);

namespace Drupal\beantragt_comment\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for Application Comment entities.
 */
interface ApplicationCommentInterface extends ContentEntityInterface, EntityOwnerInterface, EntityPublishedInterface {

  /**
   * Gets the entity creation timestamp.
   *
   * @return int
   *   The creation timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Sets the entity creation timestamp.
   *
   * @param int $timestamp
   *   The creation timestamp.
   *
   * @return static
   *   The called entity.
   */
  public function setCreatedTime(int $timestamp): static;

  /**
   * Gets the comment body text.
   *
   * @return string|null
   *   The body text, or NULL if not set.
   */
  public function getBody(): ?string;

  /**
   * Sets the comment body text.
   *
   * @param string $body
   *   The body text.
   *
   * @return static
   *   The called entity.
   */
  public function setBody(string $body): static;

  /**
   * Gets the author role (citizen or staff).
   *
   * @return string
   *   The author role.
   */
  public function getAuthorRole(): string;

  /**
   * Sets the author role.
   *
   * @param string $role
   *   The author role ('citizen' or 'staff').
   *
   * @return static
   *   The called entity.
   */
  public function setAuthorRole(string $role): static;

  /**
   * Gets the visibility channel (public or internal).
   *
   * @return string
   *   The visibility channel.
   */
  public function getVisibility(): string;

  /**
   * Sets the visibility channel.
   *
   * @param string $visibility
   *   The visibility channel ('public' or 'internal').
   *
   * @return static
   *   The called entity.
   */
  public function setVisibility(string $visibility): static;

  /**
   * Whether this comment is an internal staff note.
   *
   * @return bool
   *   TRUE for internal staff notes.
   */
  public function isInternal(): bool;

  /**
   * Gets the referenced application entity ID.
   *
   * @return int|null
   *   The application entity ID, or NULL if not set.
   */
  public function getApplicationId(): ?int;

}
