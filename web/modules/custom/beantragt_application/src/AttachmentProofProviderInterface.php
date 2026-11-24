<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Supplies the proof list of an intake channel that has assessed the files.
 */
interface AttachmentProofProviderInterface {

  /**
   * File field marker for an unassigned document, outside schema ID syntax.
   */
  public const UNASSIGNED_FIELD_ID = '@unassigned';

  /**
   * Returns proofs over visible attachments, or NULL if the channel differs.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application already authorized for the staff attachment list.
   * @param int[] $attachmentIds
   *   Entity IDs of visible, non-quarantined attachments.
   * @param callable $canViewField
   *   Checks access to a proof field before displaying it.
   */
  public function proofsForApplication(ApplicationInterface $application, array $attachmentIds, callable $canViewField): ?array;

}
