<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\ApplicationInterface;

/**
 * Supplies the pre-check block of attachments in the staff attachment list.
 *
 * Implemented by other modules (tag beantragt_application_attachment_precheck)
 * so this module never depends on them. Without a provider the list stays as
 * it is.
 */
interface AttachmentPrecheckProviderInterface {

  /**
   * Keys of a finding or clean block, in wire order.
   *
   * The collector delivers nothing else, whatever a provider returns.
   */
  public const KEYS = [
    'state',
    'fieldId',
    'label',
    'proofStatus',
    'confidence',
    'page',
    'pagesChecked',
    'pageCount',
    'truncated',
    'codes',
    'criteria',
    'notices',
  ];

  /**
   * Keys of an unavailable block, in wire order.
   */
  public const UNAVAILABLE_KEYS = ['state', 'fieldId', 'label'];

  /**
   * Returns pre-check blocks keyed by attachment entity id.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application whose attachment list is delivered.
   * @param int[] $attachment_ids
   *   Entity ids of the attachments the list actually emits.
   *
   * @return array<int, array<string, mixed>>
   *   At most one block per given attachment id; attachments without a block
   *   are absent. A block holds "state" (finding, clean or unavailable),
   *   never a name, a file name or an internal id.
   */
  public function forApplication(ApplicationInterface $application, array $attachment_ids): array;

}
