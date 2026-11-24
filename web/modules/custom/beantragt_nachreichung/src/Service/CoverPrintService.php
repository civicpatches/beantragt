<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\DocgenClient;
use Drupal\beantragt_docgen\Service\DocumentService;
use Drupal\beantragt_docgen\Service\RequestLetterValues;
use Drupal\beantragt_nachreichung\Exception\NachreichungException;
use Drupal\beantragt_nachreichung\Repository\CoverCodeRepository;
use Drupal\beantragt_nachreichung\Repository\NachforderungRepository;
use Drupal\beantragt_nachreichung\Repository\NachreichungEventLog;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Staff reprint "Deckblatt drucken" and "Deckblatt sperren".
 *
 * Every print issues a new identifier, valid at once, bound to the newest
 * waiting request record; the released letter and its sheet stay as they
 * are. The PDF comes from the same renderer as the letter's sheet
 * (DocumentService::printable()). Identifier row, audit row and events are
 * stored in one transaction after the PDF exists; without the audit row no
 * byte leaves.
 *
 * The per-application lock spans the whole docgen chain; before storing,
 * the lock is renewed (lost to another request: nothing is stored) and the
 * limit of active identifiers is checked again inside the transaction.
 */
class CoverPrintService {

  /**
   * Revoke reasons.
   */
  public const REASONS = ['lost', 'copied', 'other'];

  /**
   * Docgen calls of one print (inspect, render, finalize).
   */
  private const DOCGEN_CALLS = 3;

  /**
   * Margin for building the sheet and storing it.
   */
  private const LOCK_MARGIN_SECONDS = 30.0;

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly NachreichungSectionValidator $section,
    private readonly RequestLetterValues $values,
    private readonly CoverSheetBuilder $sheets,
    private readonly CoverCodeRepository $codes,
    private readonly NachforderungRepository $requests,
    private readonly NachreichungEventLog $events,
    private readonly DocumentService $documents,
    private readonly DocgenClient $docgen,
    private readonly ProtectedAccessLog $audit,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ?NachreichungSources $sources = NULL,
  ) {}

  /**
   * Why the print request is refused, NULL when it is allowed.
   */
  public function printBlocker(ApplicationInterface $application): ?string {
    $data = json_decode((string) ($application->getData() ?? ''), TRUE);
    return match (TRUE) {
      is_array($data) && ($data['_anonymized'] ?? NULL) === TRUE => 'nachreichung.application_anonymized',
      !$this->section->coverSheet($application) => 'nachreichung.cover_sheet_disabled',
      $this->values->holidayRegion($application) === NULL => 'nachreichung.holiday_region_required',
      $this->codes->activeCount((int) $application->id()) >= CoverCodeRepository::ACTIVE_LIMIT => 'nachreichung.cover_limit_reached',
      default => NULL,
    };
  }

  /**
   * Prints a new cover sheet.
   *
   * @return array{pdf: string, filename: string}
   *   PDF/A bytes and the download name.
   */
  public function print(ApplicationInterface $application, AccountInterface $account): array {
    $name = 'beantragt_nachreichung:cover:' . $application->id();
    $seconds = self::DOCGEN_CALLS * $this->docgen->configuration()['timeout'] + self::LOCK_MARGIN_SECONDS;
    if (!$this->lock->acquire($name, $seconds)) {
      throw new NachreichungException('nachreichung.locked', 409);
    }
    try {
      $blocker = $this->printBlocker($application);
      if ($blocker !== NULL) {
        throw match ($blocker) {
          'nachreichung.holiday_region_required' => new NachreichungException($blocker, 422),
          'nachreichung.cover_limit_reached' => new NachreichungException($blocker, 409, ['limit' => CoverCodeRepository::ACTIVE_LIMIT]),
          default => new NachreichungException($blocker, 409),
        };
      }
      $request = $this->requests->newestWaiting((int) $application->id());
      $requested = $request !== NULL ? json_decode((string) ($request['requested'] ?? '[]'), TRUE) : [];
      $deadline = $request !== NULL ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request['deadline'], new \DateTimeZone(date_default_timezone_get())) : FALSE;
      try {
        $issued = $this->sheets->issue($application, is_array($requested) ? $requested : [], $deadline instanceof \DateTimeImmutable ? $deadline : NULL);
      }
      catch (\Throwable $exception) {
        $this->logger->error('Cover sheet reprint could not be built: @class.', ['@class' => $exception::class]);
        throw new NachreichungException('nachreichung.cover_sheet_unavailable', 503);
      }
      try {
        $printed = $this->documents->printable($application, $issued['docx'], $account);
      }
      catch (DocgenApiException $exception) {
        throw match ($exception->errorCode) {
          'docgen.busy' => new NachreichungException('nachreichung.busy', 503, [], $exception->retryAfter ?? 5),
          'docgen.letterhead_missing' => new NachreichungException('nachreichung.letterhead_missing', 409),
          'docgen.application_anonymized' => new NachreichungException('nachreichung.application_anonymized', 409),
          default => new NachreichungException('nachreichung.cover_sheet_unavailable', 503),
        };
      }
      // Renewing fails when the lock ran out during a stalled render and
      // another request holds it: that one decides, this one stores
      // nothing.
      if (!$this->lock->acquire($name, $seconds)) {
        throw new NachreichungException('nachreichung.locked', 409);
      }
      $this->store($application, $account, $issued, $request);
      $reference = (string) ($application->getReferenceNumber() ?? '');
      $filename = 'deckblatt' . ($reference !== '' ? '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $reference) : '') . '.pdf';
      return ['pdf' => $printed['pdf'], 'filename' => $filename];
    }
    finally {
      $this->lock->release($name);
    }
  }

  /**
   * Revokes an active identifier of the application.
   */
  public function revoke(ApplicationInterface $application, string $cover_uuid, string $reason, AccountInterface $account): void {
    if (!in_array($reason, self::REASONS, TRUE)) {
      throw new NachreichungException('nachreichung.input_invalid', 400);
    }
    $row = $this->codes->findForApplication((int) $application->id(), $cover_uuid);
    if ($row === NULL) {
      throw new NachreichungException('nachreichung.cover_not_found', 404);
    }
    if ($row['state'] === 'draft') {
      throw new NachreichungException('nachreichung.cover_not_active', 409);
    }
    $now = $this->time->getRequestTime();
    if ($row['state'] === 'revoked') {
      throw new NachreichungException('nachreichung.cover_already_revoked', 409);
    }
    // One transaction: the identifier, every undecided envelope that names
    // it ("Bitte ansehen") and the event with their count (3.14).
    $transaction = $this->database->startTransaction();
    try {
      if (!$this->codes->revoke((int) $row['id'], (int) $account->id(), $reason, $now)) {
        throw new NachreichungException('nachreichung.cover_already_revoked', 409);
      }
      $params = ['reason' => $reason, 'envelopes' => $this->sources?->coverRevoked((int) $row['id']) ?? 0];
      $this->events->append((int) $application->id(), (string) $row['uuid'], 'cover_revoked', $params, (int) $account->id(), $now);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);
  }

  /**
   * Stores identifier, audit row and events in one transaction.
   */
  private function store(ApplicationInterface $application, AccountInterface $account, array $issued, ?array $request): void {
    $now = $this->time->getRequestTime();
    $cover_uuid = $this->uuid->generate();
    $transaction = $this->database->startTransaction();
    try {
      // Checked again where it is stored: a letter released or a sheet
      // printed during the render may have reached the limit.
      if ($this->codes->activeCount((int) $application->id(), TRUE) >= CoverCodeRepository::ACTIVE_LIMIT) {
        throw new NachreichungException('nachreichung.cover_limit_reached', 409, ['limit' => CoverCodeRepository::ACTIVE_LIMIT]);
      }
      $this->codes->insert([
        'uuid' => $cover_uuid,
        'application_id' => (int) $application->id(),
        'nachforderung_id' => $request !== NULL ? (int) $request['id'] : NULL,
        'docgen_document_uuid' => NULL,
        'origin' => 'staff',
        'code_hash' => $issued['codeHash'],
        'key_version' => $issued['keyVersion'],
        'state' => 'active',
        'created' => $now,
        'activated_at' => $now,
      ]);
      try {
        $this->audit->recordSubject('cover_code', $cover_uuid, $account, 'cover_printed', $application);
      }
      catch (\Throwable $exception) {
        $this->logger->error('Cover sheet print could not be audited: @class.', ['@class' => $exception::class]);
        throw new NachreichungException('nachreichung.audit_unavailable', 503);
      }
      $this->events->append((int) $application->id(), $cover_uuid, 'cover_issued', ['origin' => 'staff'], (int) $account->id(), $now);
      $this->events->append((int) $application->id(), $cover_uuid, 'cover_printed', [], (int) $account->id(), $now);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);
  }

}
