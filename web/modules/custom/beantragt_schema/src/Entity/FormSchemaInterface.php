<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for Form Schema entities.
 */
interface FormSchemaInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface, EntityPublishedInterface, RevisionLogInterface {

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
   * Gets the JSON schema.
   *
   * @return string|null
   *   The JSON schema string, or NULL if not set.
   */
  public function getSchema(): ?string;

  /**
   * Sets the JSON schema.
   *
   * @param string $schema
   *   The JSON schema string.
   *
   * @return static
   *   The called entity.
   */
  public function setSchema(string $schema): static;

  /**
   * Gets the workflow definition JSON.
   *
   * @return string|null
   *   The workflow definition string, or NULL if not set.
   */
  public function getWorkflowDefinition(): ?string;

  /**
   * Sets the workflow definition JSON.
   *
   * @param string|null $workflow_definition
   *   The workflow definition string, or NULL to clear it.
   *
   * @return static
   *   The called entity.
   */
  public function setWorkflowDefinition(?string $workflow_definition): static;

  /**
   * Gets the schema status (draft, published, archived).
   *
   * @return string
   *   The schema status.
   */
  public function getSchemaStatus(): string;

  /**
   * Sets the schema status.
   *
   * @param string $status
   *   The schema status.
   *
   * @return static
   *   The called entity.
   */
  public function setSchemaStatus(string $status): static;

  /**
   * Gets the publication scope (internet or intranet).
   *
   * @return string
   *   The publication scope.
   */
  public function getPublicationScope(): string;

  /**
   * Sets the publication scope.
   *
   * @param string $scope
   *   The publication scope.
   *
   * @return static
   *   The called entity.
   */
  public function setPublicationScope(string $scope): static;

  /**
   * Gets the version number.
   *
   * @return int
   *   The version number.
   */
  public function getVersion(): int;

  /**
   * Fences a workspace normalization that happened after entity pre-save.
   *
   * Group scope normalization runs in a presave hook after FormSchema's
   * entity preSave(). A normalized jurisdiction, organisation, or legacy
   * group boundary must therefore still produce a new revision and version.
   */
  public function fenceNormalizedWorkspaceScope(): void;

  /**
   * Gets the source filename.
   *
   * @return string|null
   *   The original filename, or NULL if not set.
   */
  public function getSourceFilename(): ?string;

  /**
   * Gets the per-form reference number pattern override.
   *
   * @return string|null
   *   The pattern string, or NULL if no per-form override is set.
   */
  public function getReferencePattern(): ?string;

}
