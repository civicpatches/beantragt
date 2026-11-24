<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Exception;

use Drupal\Core\Entity\EntityStorageException;

/**
 * Carries the public reason for refusing a workspace-scoped create.
 */
final class WorkspaceCreateDenied extends EntityStorageException {

  public const WRITE_FORBIDDEN = 'errors.schema.writeForbidden';
  public const WORKSPACE_NOT_FOUND = 'errors.workspace.notFound';
  public const SCHEMA_NOT_FOUND = 'errors.schema.notFound';

  /**
   * Constructs a denial without including the submitted targets.
   *
   * @param self::WRITE_FORBIDDEN|self::WORKSPACE_NOT_FOUND|self::SCHEMA_NOT_FOUND $reason
   *   The machine-readable reason returned to the caller.
   */
  public function __construct(public readonly string $reason) {
    parent::__construct(match ($reason) {
      self::WORKSPACE_NOT_FOUND => 'The selected workspace could not be found.',
      self::SCHEMA_NOT_FOUND => 'The referenced form could not be found.',
      self::WRITE_FORBIDDEN => 'You are not authorized to manage one or more selected workspaces.',
    });
  }

}
