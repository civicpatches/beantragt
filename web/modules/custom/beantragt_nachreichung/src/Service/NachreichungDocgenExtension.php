<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\DocgenCaseExtensionInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * The letter's hint to the cover sheet (system value).
 *
 * Blockers and activation of request letters run through the docgen
 * appendix (CoverSheetAppendix), inside docgen's own transactions.
 */
class NachreichungDocgenExtension implements DocgenCaseExtensionInterface {

  /**
   * Text of nachforderung.deckblatt_hinweis while cover sheets are on.
   */
  public const HINT = 'Diesem Schreiben liegt ein Deckblatt bei. Legen Sie es obenauf, wenn Sie uns Unterlagen schicken oder abgeben.';

  /**
   * Constructs the extension.
   */
  public function __construct(private readonly NachreichungSectionValidator $section) {}

  /**
   * {@inheritdoc}
   */
  public function systemValues(ApplicationInterface $application, AccountInterface $account): array {
    return $this->section->coverSheet($application) ? ['nachforderung.deckblatt_hinweis' => self::HINT] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function releaseBlockers(array $row): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function released(ApplicationInterface $application, array $rows, AccountInterface $account): void {
  }

}
