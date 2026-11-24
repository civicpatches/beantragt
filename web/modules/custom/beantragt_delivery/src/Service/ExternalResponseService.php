<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Service\ConfirmedApplicationMailbox;
use Drupal\beantragt_application\Delivery\DeliveryRoute;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicationDependentMutationLock;
use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_delivery\Exception\DeliveryRouteException;
use Drupal\beantragt_delivery\Service\Postbox\PostboxTransportInterface;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_workflow\Service\SemanticStatusChanger;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Stores verified PDF responses and explicitly releases them to the applicant.
 */
final class ExternalResponseService {

  public const MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;
  public const MAX_TOTAL_BYTES = 40 * 1024 * 1024;

  /**
   * Permission a staff member needs to read a response with protected data.
   */
  public const PROTECTED_PERMISSION = 'view protected application data';

  /**
   * A notice the applicant may receive once released.
   */
  public const KIND_DECISION = 'decision';

  /**
   * A filing receipt of the case system, for staff only.
   */
  public const KIND_FILING_RECEIPT = 'filing_receipt';

  public function __construct(
    private readonly ExternalResponseRepository $repository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly AttachmentScanServiceInterface $scanner,
    private readonly ApplicationDependentMutationLock $mutationLock,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly NotificationMailer $mailer,
    private readonly ?SemanticStatusChanger $statusChanger = NULL,
    private readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    private readonly ?TimelineLabels $timelineLabels = NULL,
    private readonly ?PostboxTransportInterface $postbox = NULL,
    private readonly ?ConfirmedApplicationMailbox $confirmedMailbox = NULL,
  ) {}

  /**
   * Effective route for a new response on the application.
   *
   * @return array{route: string, reason: ?string}
   *   Route and reason (DeliveryRules::effectiveRoute()).
   */
  public function deliveryRoute(ApplicationInterface $application, bool $isDecision): array {
    $chosen = $application->getDeliveryRoute();
    // The postbox only matters for bundid; other routes skip the lookup.
    $postbox = $chosen === DeliveryRoute::BUNDID && ($this->postbox?->available($this->scopeGroup($application)) ?? FALSE);
    return DeliveryRules::effectiveRoute($chosen, $isDecision, $this->isBulk($application), $postbox);
  }

  /**
   * Routes of the next decision and the next communication, for staff.
   *
   * @return array{decision: array{route: string, reason: ?string}, communication: array{route: string, reason: ?string}}
   *   Release previews.
   */
  public function deliveryPreview(ApplicationInterface $application): array {
    return [
      'decision' => $this->deliveryRoute($application, TRUE),
      'communication' => $this->deliveryRoute($application, FALSE),
    ];
  }

  /**
   * Ingests a response whose transport identity and contract were verified.
   *
   * The trusted adapter alone may attest external release. The default always
   * requires staff release. A replay never upgrades a pending response.
   * A new response closes the procedure (the notice exists, whether or not
   * staff have released it yet), unless the sending connection leaves
   * closing to staff ($closes = FALSE). $released_by_person marks a notice
   * the current user released in beantragt.ai (docgen): the status change
   * then names that person, not the system. $contains_protected marks
   * documents that carry protected application data in clear text (a docgen
   * notice drafted with clear values): staff then read them only with the
   * protected-data permission and every staff download is logged in
   * beantragt_protected_access_log. The applicant's own access is unchanged.
   * $ensure_closed lets a producer that resumes an interrupted provision
   * (docgen) repeat the status change for a response stored earlier: the
   * first attempt may have ended between storing and closing. The change is
   * idempotent (a closed procedure is skipped). A plain replay never closes
   * again, so a procedure staff reopened stays open.
   * $outcome (approved, rejected, filed or NULL) picks the follow-up status
   * through $followUp, the follow-up flags of the sending connection. A
   * "filed" response is a filing receipt: stored for staff, never released
   * and never announced to the applicant.
   * $isDecision marks a decision (docgen passes it from the template
   * purposes); NULL derives it from the outcome, where everything but a
   * filing receipt counts as a decision. A provided decision goes by post
   * in stage 1 and announces no email (DeliveryRules).
   *
   * @throws \Drupal\beantragt_delivery\Service\ApplicationAnonymizedException
   *   When the application was anonymized meanwhile; nothing is stored.
   * @throws \InvalidArgumentException
   *   When the outcome is unknown.
   */
  public function ingest(ApplicationInterface $application, string $source, string $external_id, string $external_reference, string $title, array $documents, bool $released = FALSE, bool $closes = TRUE, bool $released_by_person = FALSE, bool $contains_protected = FALSE, bool $ensure_closed = FALSE, ?string $outcome = NULL, array $followUp = [], ?string $outcomeLabel = NULL, ?bool $isDecision = NULL): array {
    if ($outcome !== NULL && !in_array($outcome, SemanticStatusChanger::OUTCOMES, TRUE)) {
      throw new \InvalidArgumentException('Invalid external response outcome.');
    }
    // The case system's own word for the outcome ("Festsetzung"); only
    // together with an outcome, plain text, short.
    if ($outcomeLabel !== NULL && ($outcome === NULL || trim($outcomeLabel) === '' || mb_strlen($outcomeLabel) > 40 || preg_match('/[\x00-\x1f\x7f]/', $outcomeLabel))) {
      throw new \InvalidArgumentException('Invalid external response outcome label.');
    }
    $isDecision ??= DeliveryRules::isDecisionResponse($outcome);
    [$response, $created] = $this->store($application, $source, $external_id, $external_reference, $title, $documents, $released, $contains_protected, $outcome, $outcomeLabel === NULL ? NULL : trim($outcomeLabel), $isDecision);
    // Only after the response is committed and the lock is released: the
    // status save dispatches workflow events that must never see a notice
    // that is rolled back later.
    if (($created || $ensure_closed) && $closes && $this->statusChanger !== NULL) {
      $this->statusChanger->caseSystemResponded($application, $outcome, $released_by_person, $followUp, $source);
    }
    return $response;
  }

  /**
   * Persists a response once.
   *
   * @return array{0: array, 1: bool}
   *   The serialized response and TRUE when it was stored by this call.
   */
  private function store(ApplicationInterface $application, string $source, string $external_id, string $external_reference, string $title, array $documents, bool $released, bool $contains_protected = FALSE, ?string $outcome = NULL, ?string $outcome_label = NULL, bool $isDecision = FALSE): array {
    $kind = $outcome === 'filed' ? self::KIND_FILING_RECEIPT : self::KIND_DECISION;
    // A filing receipt is for staff only, whatever the sender claims.
    $released = $released && $kind === self::KIND_DECISION;
    if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $source) || $external_id === '' || strlen($external_id) > 512
      || mb_strlen($external_reference) > 255 || trim($title) === '' || mb_strlen($title) > 255) {
      throw new \InvalidArgumentException('Invalid external response identity.');
    }
    $this->lock($application);
    $files = [];
    $transaction = $this->repository->startTransaction();
    try {
      // The retention run anonymizes under the same lock: an application
      // anonymized after the caller loaded it gets no copy with clear data.
      // The same fresh load carries the route a staff change just stored.
      $current = $this->freshApplication($application);
      if ($this->isAnonymized($current)) {
        throw new ApplicationAnonymizedException('Application was anonymized.');
      }
      $existing = $this->repository->findExternal($source, $external_id);
      if ($existing !== NULL) {
        if ((int) $existing['application_id'] !== (int) $application->id()) {
          throw new \UnexpectedValueException('External response is assigned to another application.');
        }
        return [$this->serialize($application, $existing), FALSE];
      }
      $this->validateDocuments($documents);
      // Scan every document before persisting any file or releasing a response.
      foreach ($documents as $document) {
        if ($this->scanner->scan($document['bytes'], $document['filename'], 'application/pdf')->status() !== AttachmentScanResult::STATUS_CLEAN) {
          throw new \UnexpectedValueException('External response document did not pass security scanning.');
        }
      }
      $uuid = $this->uuid->generate();
      $directory = 'private://external_responses/' . $application->uuid() . '/' . $uuid;
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        throw new \RuntimeException('External response storage is unavailable.');
      }
      $rows = [];
      foreach ($documents as $document) {
        $document_uuid = $this->uuid->generate();
        $file = $this->fileRepository->writeData($document['bytes'], $directory . '/' . $document_uuid . '.pdf', FileExists::Error);
        $files[] = $file;
        $file->setFilename($document['filename']);
        $file->setOwnerId((int) $application->getOwnerId());
        $file->save();
        $rows[] = [
          'uuid' => $document_uuid,
          'response_uuid' => $uuid,
          'file_id' => (int) $file->id(),
          'sha512' => strtolower($document['sha512']),
        ];
      }
      $now = $this->time->getCurrentTime();
      $row = [
        'uuid' => $uuid,
        'application_id' => (int) $application->id(),
        'source' => $source,
        'external_id_hash' => hash('sha256', $external_id),
        'external_reference' => $external_reference,
        'title' => $title,
        'received' => $now,
        'released' => $released ? $now : NULL,
        'available' => $released ? $now : NULL,
        'retrieved' => NULL,
        'notification_state' => $this->isBulk($application) ? 'not_applicable' : 'pending',
        'contains_protected' => $contains_protected ? 1 : 0,
        'outcome' => $outcome,
        'outcome_label' => $outcome_label,
        'kind' => $kind,
        'is_decision' => $isDecision ? 1 : 0,
        'delivery_route' => '',
        'route_reason' => NULL,
        'postal_due_at' => NULL,
      ];
      if ($released) {
        // Provisioning fixes the route for the lifetime of the response.
        $row = $this->provision($current ?? $application, $isDecision, $now) + $row;
      }
      $this->repository->insert($row, $rows);
      // Commit permanent files only together with their durable references.
      foreach ($files as $file) {
        $file->setPermanent();
        $file->save();
      }
      // Application deletion owns cleanup after successful persistence.
      $files = [];
      if ($released) {
        $this->notify($application, $uuid);
      }
      return [$this->serialize($application, $row), TRUE];
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      foreach ($files as $file) {
        $file->delete();
      }
      throw $exception;
    }
    finally {
      $this->mutationLock->release($application);
    }
  }

  /**
   * Returns a stored response of this application by its transport identity.
   *
   * Lets a producer that saw ingest() fail tell "nothing was provided" from
   * "provided, but a later step failed" without ingesting again.
   */
  public function findIngested(ApplicationInterface $application, string $source, string $external_id): ?array {
    $row = $this->repository->findExternal($source, $external_id);
    if ($row === NULL || (int) $row['application_id'] !== (int) $application->id()) {
      return NULL;
    }
    return $this->serialize($application, $row);
  }

  /**
   * Enforces PDF-only, bounded, correctly hashed document input.
   */
  public function validateDocuments(array $documents): void {
    if ($documents === [] || count($documents) > 10) {
      throw new \InvalidArgumentException('External response requires one to ten PDF documents.');
    }
    $total = 0;
    foreach ($documents as $document) {
      foreach (['bytes', 'filename', 'mimeType', 'sha512'] as $key) {
        if (!is_array($document) || !isset($document[$key]) || !is_string($document[$key])) {
          throw new \InvalidArgumentException('Invalid external response document.');
        }
      }
      $size = strlen($document['bytes']);
      $total += $size;
      if ($size < 8 || $size > self::MAX_DOCUMENT_BYTES || $total > self::MAX_TOTAL_BYTES
        || $document['mimeType'] !== 'application/pdf' || !str_starts_with($document['bytes'], '%PDF-')
        || (new \finfo(FILEINFO_MIME_TYPE))->buffer($document['bytes']) !== 'application/pdf'
        || !preg_match('/^[a-fA-F0-9]{128}$/', $document['sha512'])
        || !hash_equals(strtolower($document['sha512']), hash('sha512', $document['bytes']))
        || !preg_match('/^[^\x00-\x1F\x7F\/\\\\]{1,200}\.pdf$/iu', $document['filename'])) {
        throw new \InvalidArgumentException('Invalid external response PDF or checksum.');
      }
    }
  }

  /**
   * Makes a response available without changing its original release time.
   */
  public function release(ApplicationInterface $application, string $response_uuid): array {
    $this->lock($application);
    try {
      $row = $this->requireResponse($application, $response_uuid);
      if (($row['kind'] ?? self::KIND_DECISION) === self::KIND_FILING_RECEIPT) {
        throw new \DomainException('A filing receipt is never released to the applicant.');
      }
      // Provision at release time from the stored route; a second
      // release changes nothing and announces nothing.
      $now = $this->time->getCurrentTime();
      $provision = $this->provision($this->freshApplication($application) ?? $application, (int) ($row['is_decision'] ?? 0) === 1, $now);
      if ($this->repository->release($row['uuid'], $now, $provision) > 0) {
        $this->notify($application, $row['uuid']);
      }
      return $this->serialize($application, $this->requireResponse($application, $response_uuid));
    }
    finally {
      $this->mutationLock->release($application);
    }
  }

  /**
   * Returns response metadata and scoped document links.
   */
  public function listForApplication(ApplicationInterface $application, bool $staff, ?AccountInterface $reader = NULL): array {
    if (!$staff && $reader !== NULL && $this->readsAsApplicant($application, $reader)) {
      $this->confirmedMailbox?->auditRead($application, $reader);
    }
    $responses = array_map(fn(array $row): array => $this->serialize($application, $row, $staff), $this->repository->list((int) $application->id(), $staff));
    // Staff see the outcome in the words of the municipality first (the
    // connection's texts), then in those of the case system, then the
    // product's word (outcomeLabel NULL).
    $configured = $staff && $this->timelineLabels !== NULL && $responses !== [] ? $this->timelineLabels->outcomeLabels($application) : [];
    foreach ($responses as &$response) {
      if (!$staff) {
        // The word names the outcome for case workers only.
        $response['outcomeLabel'] = NULL;
      }
      elseif (($configured[$response['outcome'] ?? ''] ?? '') !== '') {
        $response['outcomeLabel'] = $configured[$response['outcome']];
      }
    }
    unset($response);
    return $responses;
  }

  /**
   * Returns an application-bound document, with pending files for staff only.
   *
   * A response marked as containing protected data is served to staff only
   * with the protected-data permission, and each such download is logged
   * (channel external_response_download). Without the permission, or
   * without the logging service, it throws ProtectedResponseAccessException.
   * The applicant reads it as before. The applicant is identified
   * positively: the owner of the application, or an account whose email
   * matches the applicant email of the application (public submission).
   * Every other reader counts as staff.
   */
  public function download(ApplicationInterface $application, string $response_uuid, string $file_uuid, bool $staff, ?AccountInterface $reader = NULL): FileInterface {
    $row = $this->requireResponse($application, $response_uuid);
    if (!$staff && $row['released'] === NULL) {
      throw new \OutOfBoundsException('External response not found.');
    }
    $protected = (int) ($row['contains_protected'] ?? 0) === 1 && !$this->readsAsApplicant($application, $reader);
    if ($protected && ($reader === NULL || !$reader->hasPermission(self::PROTECTED_PERMISSION) || !$this->dataProtection instanceof ApplicationDataProtectionService)) {
      throw new ProtectedResponseAccessException('Protected external response requires the protected-data permission.');
    }
    foreach ($this->repository->documents($response_uuid) as $document) {
      if ($document['uuid'] !== $file_uuid) {
        continue;
      }
      $file = $this->entityTypeManager->getStorage('file')->load($document['file_id']);
      $prefix = 'private://external_responses/' . $application->uuid() . '/' . $response_uuid . '/';
      if (!$file instanceof FileInterface || !str_starts_with($file->getFileUri(), $prefix) || !is_file($file->getFileUri())) {
        break;
      }
      if ($protected) {
        $this->dataProtection->logProtectedReveal($application, $reader, 'external_response_download');
      }
      // Retrieval evidence: only the positively identified applicant of a
      // released response (pending ones are refused above for non-staff),
      // never staff, the service account or a bulk case.
      if (!$staff && $reader !== NULL && $this->isApplicant($application, $reader)) {
        $this->confirmedMailbox?->auditRead($application, $reader);
        $this->repository->retrieved($response_uuid, $this->time->getCurrentTime());
      }
      return $file;
    }
    throw new \OutOfBoundsException('External response document not found.');
  }

  /**
   * Whether an account reads a response as the applicant, not as staff.
   *
   * Positive identification only, so a new role or group permission that
   * grants view access never opens the protected copy unnoticed. Accepts a
   * direct owner or the verified mailbox of a confirmed anonymous submission.
   */
  private function readsAsApplicant(ApplicationInterface $application, ?AccountInterface $reader): bool {
    if ($reader === NULL || (int) $reader->id() <= 0 || array_intersect(BeantragtRoles::SERVICE_ACCOUNT_ROLES, (array) $reader->getRoles()) !== []) {
      return FALSE;
    }
    if ((int) $reader->id() === (int) $application->getOwnerId()) {
      return TRUE;
    }
    return $this->confirmedMailbox?->allowsRead($application, $reader) === TRUE;
  }

  /**
   * Whether the reader is the applicant whose download counts as retrieval.
   *
   * The positively identified applicant (owner or matching applicant
   * email), never the service account and never on a bulk application.
   */
  private function isApplicant(ApplicationInterface $application, ?AccountInterface $reader): bool {
    return $reader !== NULL
      && $this->readsAsApplicant($application, $reader)
      && !$this->isBulk($application);
  }

  /**
   * Loads the stored application past the entity cache (caller holds lock).
   */
  private function freshApplication(ApplicationInterface $application): ?ApplicationInterface {
    $storage = $this->entityTypeManager->getStorage('application');
    $storage->resetCache([(int) $application->id()]);
    $current = $storage->load((int) $application->id());
    return $current instanceof ApplicationInterface ? $current : NULL;
  }

  /**
   * Whether a freshly loaded application is anonymized.
   */
  private function isAnonymized(?ApplicationInterface $current): bool {
    if (!$current instanceof ApplicationInterface) {
      return FALSE;
    }
    $data = json_decode((string) ($current->getData() ?? ''), TRUE);
    return is_array($data) && ($data['_anonymized'] ?? NULL) === TRUE;
  }

  /**
   * Whether the application comes from bulk intake.
   */
  private function isBulk(ApplicationInterface $application): bool {
    return $application->hasField('field_origin') && $application->get('field_origin')->value === 'bulk';
  }

  /**
   * Resolves the scope group like ScopeAccess::scopeGroup().
   */
  private function scopeGroup(ApplicationInterface $application): ?GroupInterface {
    foreach (['field_organisation', 'field_group', 'field_jurisdiction'] as $field_name) {
      if (!$application->hasField($field_name) || $application->get($field_name)->isEmpty()) {
        continue;
      }
      $group = $application->get($field_name)->entity ?? NULL;
      if ($group instanceof GroupInterface) {
        return $group;
      }
    }
    return NULL;
  }

  /**
   * Provision columns to store at release.
   *
   * The notification state changes only for a non-bulk response on route
   * postal ("postal": no email); bulk responses stay not_applicable and
   * portal responses pending.
   *
   * @return array{delivery_route: string, route_reason: ?string, postal_due_at: ?int, notification_state?: string}
   *   Columns for the response row.
   */
  private function provision(ApplicationInterface $application, bool $isDecision, int $now): array {
    $route = $this->deliveryRoute($application, $isDecision);
    $postal = $route['route'] === DeliveryRoute::POSTAL;
    $provision = [
      'delivery_route' => $route['route'],
      'route_reason' => $route['reason'],
      'postal_due_at' => $postal ? $now : NULL,
    ];
    if ($postal && !$this->isBulk($application)) {
      $provision['notification_state'] = 'postal';
    }
    return $provision;
  }

  /**
   * Records that a due postal dispatch was handed over to the mail room.
   *
   * Never overwrites: the first handover with its time and person stays.
   *
   * @throws \OutOfBoundsException
   *   When the response does not belong to the application.
   * @throws \Drupal\beantragt_delivery\Exception\DeliveryRouteException
   *   Codes delivery.locked (another mutation holds the application),
   *   delivery.not_postal or delivery.already_handed_over (409). Storage
   *   errors propagate unchanged.
   */
  public function handOver(ApplicationInterface $application, string $response_uuid, AccountInterface $account): void {
    if (!$this->mutationLock->acquireApplication($application)) {
      throw new DeliveryRouteException('delivery.locked', 409);
    }
    try {
      $row = $this->requireResponse($application, $response_uuid);
      if ($this->repository->handOver($row['uuid'], $this->time->getCurrentTime(), (int) $account->id()) > 0) {
        return;
      }
      $row = $this->requireResponse($application, $response_uuid);
      if ($row['released'] === NULL || $row['postal_due_at'] === NULL) {
        throw new DeliveryRouteException('delivery.not_postal', 409);
      }
      throw new DeliveryRouteException('delivery.already_handed_over', 409);
    }
    finally {
      $this->mutationLock->release($application);
    }
  }

  /**
   * Released responses of an application with their delivery evidence.
   *
   * @return array<int, array<string, mixed>>
   *   Repository rows, newest provision first.
   */
  public function releasedResponses(ApplicationInterface $application): array {
    $rows = array_values(array_filter($this->repository->list((int) $application->id(), TRUE), static fn (array $row): bool => $row['released'] !== NULL));
    usort($rows, static fn (array $a, array $b): int => ((int) $b['released'] <=> (int) $a['released'])
      ?: ((int) $b['received'] <=> (int) $a['received']));
    return $rows;
  }

  /**
   * Removes response files and rows while the caller holds the deletion lock.
   */
  public function cleanupForApplication(ApplicationInterface $application): void {
    $this->cleanupForApplicationIdentity((int) $application->id(), (string) $application->uuid());
  }

  /**
   * Erases documents even when only an orphaned application identity remains.
   */
  public function cleanupForApplicationIdentity(int $application_id, string $application_uuid): void {
    if ($application_uuid !== '' && !preg_match('/^[A-Za-z0-9-]{1,128}$/D', $application_uuid)) {
      throw new \InvalidArgumentException('Invalid application storage identity.');
    }
    $directory = 'private://external_responses/' . $application_uuid;
    $storage = $this->entityTypeManager->getStorage('file');
    $ids = $application_uuid === '' ? [] : $storage->getQuery()->accessCheck(FALSE)
      ->condition('uri', $directory . '/', 'STARTS_WITH')->execute();
    // Include linked rows and files stranded before response persistence.
    $responses = $this->repository->list($application_id, TRUE);
    foreach ($responses as $row) {
      foreach ($this->repository->documents($row['uuid']) as $document) {
        $ids[] = $document['file_id'];
      }
    }
    foreach (array_unique($ids) as $id) {
      $storage->load($id)?->delete();
    }
    // A hard crash can leave bytes before even a file entity was committed.
    $path = $application_uuid === '' ? FALSE : $this->fileSystem->realpath($directory);
    if (is_string($path) && is_dir($path)) {
      $this->fileSystem->deleteRecursive($directory);
    }
    foreach ($responses as $row) {
      $this->repository->delete($row['uuid']);
    }
  }

  /**
   * Deletes at most 25 managed orphans older than a day.
   */
  public function cleanupStaleOrphanFiles(): int {
    $deleted = 0;
    $storage = $this->entityTypeManager->getStorage('file');
    foreach ($this->repository->staleOrphanFileIds($this->time->getCurrentTime() - 86400) as $id) {
      $file = $storage->load($id);
      if ($file instanceof FileInterface) {
        $file->delete();
        $deleted++;
      }
    }
    return $deleted;
  }

  /**
   * Locks and revalidates the application before a response mutation.
   */
  private function lock(ApplicationInterface $application): void {
    if (!$this->mutationLock->acquireApplication($application)) {
      throw new \RuntimeException('Application is unavailable or currently locked.');
    }
  }

  /**
   * Loads a response only within the given application.
   */
  private function requireResponse(ApplicationInterface $application, string $uuid): array {
    return $this->repository->find(['uuid' => $uuid, 'application_id' => (int) $application->id()])
      ?? throw new \OutOfBoundsException('External response not found.');
  }

  /**
   * Sends at most one notification after committed release.
   */
  private function notify(ApplicationInterface $application, string $uuid): void {
    if ($this->isBulk($application)) {
      $this->repository->notificationNotApplicable($uuid);
      return;
    }
    $this->repository->afterCommit(function () use ($application, $uuid): void {
      if (!$this->repository->claimNotification($uuid)) {
        return;
      }
      try {
        $sent = $this->mailer->sendCitizenNotificationWithResult($application, 'external_response_available');
      }
      catch (\Throwable) {
        $sent = FALSE;
      }
      // A fence-suppressed mail still counts as sent in stage 1.
      $this->repository->notificationResult($uuid, $sent, $this->time->getCurrentTime());
    });
  }

  /**
   * Recovers unattempted notifications after a crash or deferred commit.
   */
  public function recoverPendingNotifications(): int {
    $processed = 0;
    foreach ($this->repository->pendingNotifications() as $row) {
      $application = $this->entityTypeManager->getStorage('application')->load($row['application_id']);
      if (!$application instanceof ApplicationInterface) {
        continue;
      }
      try {
        $this->lock($application);
        try {
          $this->notify($application, $row['uuid']);
          $processed++;
        }
        finally {
          $this->mutationLock->release($application);
        }
      }
      catch (\Throwable) {
        // Keep pending for the next bounded recovery run.
      }
    }
    return $processed;
  }

  /**
   * Projects stored response metadata for the portal API.
   *
   * Staff also see containsProtected: such documents need the protected-data
   * permission to download.
   */
  private function serialize(ApplicationInterface $application, array $row, bool $staff = FALSE): array {
    $documents = [];
    foreach ($this->repository->documents($row['uuid']) as $document) {
      $file = $this->entityTypeManager->getStorage('file')->load($document['file_id']);
      if (!$file instanceof FileInterface) {
        continue;
      }
      $documents[] = [
        'id' => $document['uuid'],
        'filename' => $file->getFilename(),
        'mimeType' => 'application/pdf',
        'size' => (int) $file->getSize(),
        'sha512' => $document['sha512'],
        'downloadUrl' => '/api/applications/' . $application->id() . '/responses/' . $row['uuid'] . '/documents/' . $document['uuid'] . '/download',
      ];
    }
    $output = [
      'id' => $row['uuid'],
      'title' => $row['title'],
      'source' => $row['source'] === 'fit_connect' ? 'FIT-Connect' : $row['source'],
      'externalReference' => $row['external_reference'],
      'status' => $row['released'] === NULL ? 'pending' : 'released',
      'documents' => $documents,
      'notificationState' => $row['notification_state'],
      'outcome' => isset($row['outcome']) && $row['outcome'] !== '' ? (string) $row['outcome'] : NULL,
      'outcomeLabel' => isset($row['outcome_label']) && $row['outcome_label'] !== '' ? (string) $row['outcome_label'] : NULL,
      'kind' => (string) ($row['kind'] ?? self::KIND_DECISION),
      'isDecision' => (int) ($row['is_decision'] ?? 0) === 1,
      'deliveryRoute' => (string) ($row['delivery_route'] ?? ''),
    ];
    if ($staff) {
      $output['containsProtected'] = (int) ($row['contains_protected'] ?? 0) === 1;
    }
    foreach (['received', 'released', 'available', 'retrieved'] as $field) {
      $output[$field . 'At'] = $row[$field] === NULL ? NULL : (int) $row[$field];
    }
    return $output;
  }

}
