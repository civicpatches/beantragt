<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * One version of a decision template.
 */
interface DocgenTemplateInterface extends ContentEntityInterface, EntityChangedInterface {

  public const STATUS_DRAFT = 'draft';
  public const STATUS_RELEASED = 'released';

  /**
   * Purposes of a decision document, in catalog order.
   */
  public const PURPOSES = ['approval', 'rejection', 'request_info', 'acknowledgement', 'other'];

  /**
   * Purposes whose release closes the procedure (decision issued).
   */
  public const CLOSING_PURPOSES = ['approval', 'rejection'];

  /**
   * Returns the UUID shared by all versions of the template.
   */
  public function getFamily(): string;

  /**
   * Returns the version number within the family, starting at 1.
   */
  public function getVersion(): int;

  /**
   * Returns one of self::PURPOSES.
   */
  public function getPurpose(): string;

  /**
   * Returns draft or released.
   */
  public function getStatus(): string;

  /**
   * Returns TRUE for an immutable released version.
   */
  public function isReleased(): bool;

  /**
   * Returns the organisation, or the jurisdiction when it has none.
   */
  public function getScopeGroup(): ?GroupInterface;

  /**
   * Returns the private template file.
   */
  public function getFile(): ?FileInterface;

  /**
   * Returns the SHA-256 of the template file.
   */
  public function getSha256(): string;

  /**
   * Returns the stored docgen /inspect result.
   *
   * @return array{placeholders: string[], loops: string[], errors: array<int, mixed>}
   *   Placeholders, loops and template errors.
   */
  public function getInspection(): array;

}
