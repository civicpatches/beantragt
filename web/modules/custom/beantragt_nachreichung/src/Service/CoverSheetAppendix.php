<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_docgen\DocgenAppendix;
use Drupal\beantragt_docgen\DocgenAppendixBatchInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\RequestLetterValues;
use Drupal\beantragt_nachreichung\Repository\CoverCodeRepository;
use Drupal\beantragt_nachreichung\Repository\NachforderungRepository;
use Drupal\beantragt_nachreichung\Repository\NachreichungEventLog;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Request record and cover sheet of every request letter (docgen appendix).
 *
 * Every request letter draft gets a draft request record; with the
 * jurisdiction's coverSheet switch on it also gets a draft identifier and
 * the standalone cover sheet DOCX that docgen renders into the companion
 * PDF. The draft identifier stays inactive (unknown on lookup) until the
 * letter is released; a discard or a re-render voids it.
 */
class CoverSheetAppendix implements DocgenAppendixBatchInterface {

  /**
   * Cover requirements for the active event snapshot only.
   *
   * @var array<string, bool>|null
   */
  private ?array $coverRequirements = NULL;

  /**
   * Constructs the appendix.
   */
  public function __construct(
    private readonly NachreichungSectionValidator $section,
    private readonly RequestLetterValues $values,
    private readonly RequestedProofs $proofs,
    private readonly CoverSheetBuilder $sheets,
    private readonly CoverCodeRepository $codes,
    private readonly NachforderungRepository $requests,
    private readonly NachreichungEventLog $events,
    private readonly NachreichungActivation $activation,
    private readonly EntityTypeManagerInterface $manager,
    private readonly UuidInterface $uuid,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function prepare(ApplicationInterface $application, string $document_uuid, AccountInterface $account): ?DocgenAppendix {
    $data = json_decode((string) ($application->getData() ?? ''), TRUE);
    if (is_array($data) && ($data['_anonymized'] ?? NULL) === TRUE) {
      throw new DocgenApiException('docgen.application_anonymized', 409);
    }
    $cover = $this->section->coverSheet($application);
    if ($cover && $this->values->holidayRegion($application) === NULL) {
      throw new DocgenApiException('docgen.holiday_region_required', 422);
    }
    $deadline = $this->values->deadline($application);
    if (!$deadline instanceof \DateTimeImmutable) {
      // Without a jurisdiction there is no letterhead either; docgen refuses
      // the draft on its own.
      return NULL;
    }
    $requested = $this->proofs->requested($application) ?? [];
    $schema = PublicSubmission::submittedSchema($application, $this->manager);
    $payload = [
      'uid' => (int) $account->id(),
      'deadline' => $deadline->format('Y-m-d'),
      'requested' => $requested,
      'formRevisionId' => $schema instanceof RevisionableInterface && $schema->getRevisionId() !== NULL ? (int) $schema->getRevisionId() : NULL,
      'cover' => NULL,
    ];
    if (!$cover) {
      return new DocgenAppendix(NULL, $payload);
    }
    try {
      $issued = $this->sheets->issue($application, $requested, $deadline);
    }
    catch (\Throwable $exception) {
      // Only the class: messages could carry key or file details.
      $this->logger->error('Cover sheet of a request letter could not be built: @class.', ['@class' => $exception::class]);
      throw new DocgenApiException('docgen.cover_sheet_unavailable', 503, [], 30);
    }
    $payload['cover'] = [
      'codeHash' => $issued['codeHash'],
      'keyVersion' => $issued['keyVersion'],
    ];
    return new DocgenAppendix($issued['docx'], $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function commit(ApplicationInterface $application, string $document_uuid, DocgenAppendix $appendix, int $now): void {
    $payload = $appendix->payload;
    $application_id = (int) $application->id();
    $uid = (int) ($payload['uid'] ?? 0);
    $fields = [
      'deadline_printed' => (string) $payload['deadline'],
      'deadline' => (string) $payload['deadline'],
      'requested' => json_encode($payload['requested'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
      'form_revision_id' => $payload['formRevisionId'] ?? NULL,
    ];
    $request = $this->requests->draftForDocument($document_uuid);
    if ($request !== NULL) {
      // A re-render keeps the record and moves its deadline.
      $request_id = (int) $request['id'];
      $this->requests->update($request_id, $fields);
    }
    else {
      $request_id = $this->requests->insert($fields + [
        'uuid' => $payload['requestUuid'] ?? $this->uuid->generate(),
        'application_id' => $application_id,
        'channel' => 'letter',
        'docgen_document_uuid' => $document_uuid,
        'created' => $now,
        'state' => 'draft',
      ]);
    }
    // A re-render voids the previous draft identifier.
    $this->codes->deleteDrafts($document_uuid);
    $cover = $payload['cover'] ?? NULL;
    if (!is_array($cover)) {
      return;
    }
    $cover_uuid = $this->uuid->generate();
    $this->codes->insert([
      'uuid' => $cover_uuid,
      'application_id' => $application_id,
      'nachforderung_id' => $request_id,
      'docgen_document_uuid' => $document_uuid,
      'origin' => 'letter',
      'code_hash' => (string) $cover['codeHash'],
      'key_version' => (int) $cover['keyVersion'],
      'state' => 'draft',
      'created' => $now,
    ]);
    $this->events->append($application_id, $cover_uuid, 'cover_issued', ['origin' => 'letter'], $uid, $now);
  }

  /**
   * {@inheritdoc}
   */
  public function released(ApplicationInterface $application, array $rows, string $response_uuid, int $now): void {
    $this->activation->released($application, $rows, $response_uuid, $now);
  }

  /**
   * {@inheritdoc}
   */
  public function discarded(ApplicationInterface $application, string $document_uuid): void {
    $this->codes->deleteDrafts($document_uuid);
    $this->requests->deleteDraft($document_uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function coverRequired(array $row): bool {
    if (($row['purpose'] ?? '') !== 'request_info') {
      return FALSE;
    }
    if ($this->coverRequirements !== NULL && array_key_exists((string) $row['uuid'], $this->coverRequirements)) {
      return $this->coverRequirements[(string) $row['uuid']];
    }
    if ($this->codes->existsForDocument((string) $row['uuid'])) {
      return TRUE;
    }
    $application = $this->manager->getStorage('application')->load((int) $row['application_id']);
    return $application instanceof ApplicationInterface && $this->section->coverSheet($application);
  }

  /**
   * {@inheritdoc}
   */
  public function withCoverRequirements(array $applications, array $rows, callable $read): mixed {
    $previous = $this->coverRequirements;
    $requests = array_filter($rows, static fn(array $row): bool => ($row['purpose'] ?? '') === 'request_info');
    $issued = array_fill_keys($this->codes->documentsWithCodes(array_column($requests, 'uuid')), TRUE);
    $settings = [];
    $this->coverRequirements = [];
    try {
      foreach ($requests as $row) {
        $id = (int) $row['application_id'];
        $settings[$id] ??= isset($applications[$id]) && $this->section->coverSheet($applications[$id]);
        $this->coverRequirements[(string) $row['uuid']] = isset($issued[$row['uuid']]) || $settings[$id];
      }
      return $read();
    }
    finally {
      $this->coverRequirements = $previous;
    }
  }

}
