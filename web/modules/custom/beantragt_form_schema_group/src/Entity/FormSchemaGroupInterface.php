<?php

declare(strict_types=1);

namespace Drupal\beantragt_form_schema_group\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for form_schema_group entities.
 */
interface FormSchemaGroupInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface {

  /**
   * Returns a content violation when assigning a group's workspace or form set.
   *
   * @throws \Drupal\beantragt_schema\Exception\WorkspaceCreateDenied
   *   When the workspace or a referenced form is inaccessible to the actor.
   */
  public function workspaceScopeViolation(AccountInterface $account): ?string;

  /**
   * Returns the created timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Sets the created timestamp.
   */
  public function setCreatedTime(int $timestamp): static;

  /**
   * Returns the form schema group description.
   */
  public function getDescription(): ?string;

  /**
   * Returns the workflow status.
   */
  public function getWorkflowStatus(): string;

  /**
   * Sets the workflow status.
   */
  public function setWorkflowStatus(string $status): static;

  /**
   * Returns referenced form schema entity IDs in configured order.
   *
   * @return int[]
   *   Form schema entity IDs.
   */
  public function getFormSchemaIds(): array;

}
