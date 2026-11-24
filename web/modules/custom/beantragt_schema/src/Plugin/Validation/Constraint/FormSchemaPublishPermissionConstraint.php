<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires the publish permission for every save that raises visibility.
 *
 * A form is visible through three stored values: the published flag
 * (`status`), `field_workflow_status = published` and, for the public,
 * `field_publication_scope = internet`. A save that raises the visibility
 * level needs "publish form_schema entities"; the admin permission does not
 * imply it. The violation is entity-level on purpose: JSON:API drops
 * field-level violations of fields a request did not change, but keeps
 * entity-level ones, so a status-only or scope-only PATCH is refused too.
 *
 * Entity validation only, never preSave(): seed scripts and CLI tooling save
 * without validating and keep publishing.
 */
#[Constraint(
  id: 'FormSchemaPublishPermission',
  label: new TranslatableMarkup('Form schema publish permission', [], ['context' => 'Validation']),
  type: 'entity',
)]
final class FormSchemaPublishPermissionConstraint extends SymfonyConstraint {

  /**
   * Safely parameterized entity-level validation message.
   */
  public string $message = '@detail';

  /**
   * Message shown when an account without the permission raises visibility.
   */
  public const DETAIL = 'You may edit this form schema, but publishing it requires the "publish form_schema entities" permission.';

}
