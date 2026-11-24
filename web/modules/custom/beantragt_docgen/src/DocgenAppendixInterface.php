<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Request record and cover sheet of request letters (purpose request_info).
 *
 * Implemented by beantragt_nachreichung as service beantragt_docgen.appendix;
 * docgen works without it. The cover sheet is a companion PDF on the letter's
 * own document row, rendered by DocumentService::printable() from the
 * standalone DOCX that prepare() returns, never part of the letter DOCX. It
 * is released only together with its letter, as the second document of the
 * same response.
 */
interface DocgenAppendixInterface {

  /**
   * Prepares the request record and, with the switch on, the cover sheet.
   *
   * Called for request letters only, before anything is stored and outside
   * any transaction.
   *
   * @return \Drupal\beantragt_docgen\DocgenAppendix|null
   *   What commit() stores, NULL when there is nothing to add.
   *
   * @throws \Drupal\beantragt_docgen\Exception\DocgenApiException
   *   Code docgen.cover_sheet_unavailable (503) when the identifier, its QR
   *   or the DOCX cannot be produced, docgen.holiday_region_required (422)
   *   when the cover sheet is on and the jurisdiction has no holiday region,
   *   docgen.application_anonymized (409).
   */
  public function prepare(ApplicationInterface $application, string $document_uuid, AccountInterface $account): ?DocgenAppendix;

  /**
   * Stores what prepare() returned, inside the draft's docgen transaction.
   *
   * Replaces the draft identifier and the draft request record of this
   * document (a re-render voids the previous draft identifier).
   */
  public function commit(ApplicationInterface $application, string $document_uuid, DocgenAppendix $appendix, int $now): void;

  /**
   * Activates identifiers and opens records, inside the release booking.
   *
   * Runs in the same transaction that books the documents as released; a
   * failure rolls the booking back and the release stays resumable.
   *
   * @param \Drupal\beantragt_application\Entity\ApplicationInterface $application
   *   The application.
   * @param array<int, array<string, mixed>> $rows
   *   Document rows of the release (released_by set, response_uuid not yet).
   * @param string $response_uuid
   *   The response that carries the documents.
   * @param int $now
   *   Release time.
   */
  public function released(ApplicationInterface $application, array $rows, string $response_uuid, int $now): void;

  /**
   * Removes the draft rows of a discarded document.
   */
  public function discarded(ApplicationInterface $application, string $document_uuid): void;

  /**
   * Whether this request letter draft must carry a cover sheet to be released.
   *
   * TRUE while the jurisdiction's cover sheet switch is on, or when an
   * identifier was issued for the document.
   */
  public function coverRequired(array $row): bool;

}
