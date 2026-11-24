<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentScanServiceInterface;
use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Entity\DocgenTemplateInterface;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Template catalog: upload with /inspect, versions, release.
 */
class TemplateCatalog {

  public const DIRECTORY = 'private://docgen/templates';

  /**
   * Template IDs whose deletion purgeScope() currently allows.
   *
   * @var array<int, true>
   */
  private static array $purging = [];

  /**
   * Constructs the catalog.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly DocgenClient $client,
    private readonly DocgenScopeAccess $scopeAccess,
    private readonly DocumentRepository $documents,
    private readonly UuidInterface $uuid,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly ?AttachmentScanServiceInterface $scanner = NULL,
  ) {}

  /**
   * TRUE while purgeScope() deletes this (possibly released) version.
   */
  public static function isPurging(int $template_id): bool {
    return isset(self::$purging[$template_id]);
  }

  /**
   * Removes the templates of a deleted organisation or jurisdiction.
   *
   * Released versions are otherwise immutable and undeletable. When their
   * scope group is gone they can never be used or listed again, so they go
   * with the group, files included. The scope is the one of listForScope():
   * an organisation's templates, or a jurisdiction's own templates without
   * organisation; templates of organisations below a deleted jurisdiction
   * stay with their organisation. A version that a decision document still
   * references stays (the document keeps its hash and version) and is
   * returned for the operator's log.
   *
   * @param int $group_id
   *   ID of the deleted group.
   * @param string $bundle
   *   Its bundle, 'org' or 'jur'.
   *
   * @return int[]
   *   IDs of versions kept because documents reference them.
   */
  public function purgeScope(int $group_id, string $bundle): array {
    if ($group_id < 1 || !in_array($bundle, ['org', 'jur'], TRUE)) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('docgen_template');
    $query = $storage->getQuery()->accessCheck(FALSE);
    if ($bundle === 'org') {
      $query->condition('field_organisation', $group_id);
    }
    else {
      $query->condition('field_jurisdiction', $group_id)->notExists('field_organisation');
    }
    $kept = [];
    foreach ($storage->loadMultiple($query->execute()) as $template) {
      if (!$template instanceof DocgenTemplateInterface) {
        continue;
      }
      $id = (int) $template->id();
      if ($this->documents->countForTemplate($id) > 0) {
        $kept[] = $id;
        continue;
      }
      $file = $template->getFile();
      self::$purging[$id] = TRUE;
      try {
        $template->delete();
      }
      finally {
        unset(self::$purging[$id]);
      }
      $file?->delete();
    }
    $directory = self::DIRECTORY . '/' . $group_id;
    $path = $this->fileSystem->realpath($directory);
    if ($kept === [] && is_string($path) && is_dir($path)) {
      $this->fileSystem->deleteRecursive($directory);
    }
    return $kept;
  }

  /**
   * Lists all template versions of a scope, newest version first per family.
   *
   * @return \Drupal\beantragt_docgen\Entity\DocgenTemplateInterface[]
   *   Templates.
   */
  public function listForScope(GroupInterface $scope): array {
    $storage = $this->entityTypeManager->getStorage('docgen_template');
    $query = $storage->getQuery()->accessCheck(FALSE)->sort('label')->sort('family')->sort('version', 'DESC');
    if ($scope->bundle() === 'org') {
      $query->condition('field_organisation', (int) $scope->id());
    }
    else {
      $query->condition('field_jurisdiction', (int) $scope->id())->notExists('field_organisation');
    }
    return array_values(array_filter($storage->loadMultiple($query->execute()), static fn ($template): bool => $template instanceof DocgenTemplateInterface));
  }

  /**
   * Loads a template version by UUID.
   */
  public function load(string $uuid): ?DocgenTemplateInterface {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
      return NULL;
    }
    $templates = $this->entityTypeManager->getStorage('docgen_template')->loadByProperties(['uuid' => strtolower($uuid)]);
    $template = reset($templates);
    return $template instanceof DocgenTemplateInterface ? $template : NULL;
  }

  /**
   * Lists all versions of a family, newest first.
   *
   * @return \Drupal\beantragt_docgen\Entity\DocgenTemplateInterface[]
   *   Versions.
   */
  public function versions(string $family): array {
    $storage = $this->entityTypeManager->getStorage('docgen_template');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('family', $family)->sort('version', 'DESC')->execute();
    return array_values(array_filter($storage->loadMultiple($ids), static fn ($template): bool => $template instanceof DocgenTemplateInterface));
  }

  /**
   * Returns the newest released version of a family, or NULL.
   */
  public function latestReleased(string $family): ?DocgenTemplateInterface {
    $storage = $this->entityTypeManager->getStorage('docgen_template');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('family', $family)
      ->condition('status', DocgenTemplateInterface::STATUS_RELEASED)
      ->sort('version', 'DESC')->range(0, 1)->execute();
    $template = $ids === [] ? NULL : $storage->load(reset($ids));
    return $template instanceof DocgenTemplateInterface ? $template : NULL;
  }

  /**
   * Returns the newest version (draft or released) of a family.
   */
  public function latestVersion(string $family): ?DocgenTemplateInterface {
    $storage = $this->entityTypeManager->getStorage('docgen_template');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('family', $family)->sort('version', 'DESC')->range(0, 1)->execute();
    $template = $ids === [] ? NULL : $storage->load(reset($ids));
    return $template instanceof DocgenTemplateInterface ? $template : NULL;
  }

  /**
   * Uploads a template as a new draft (new family or next version).
   */
  public function upload(GroupInterface $scope, string $label, string $purpose, string $filename, string $bytes, AccountInterface $account, ?DocgenTemplateInterface $previous = NULL): DocgenTemplateInterface {
    $label = trim($label);
    if ($label === '' || mb_strlen($label) > 255) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'name']);
    }
    if (!in_array($purpose, DocgenTemplateInterface::PURPOSES, TRUE)) {
      throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'purpose']);
    }
    $filename = self::safeFilename($filename, 'vorlage.docx');
    DocxPrecheck::assertDocx($bytes);
    $this->assertClean($bytes, $filename);
    $inspection = $this->client->inspect($bytes);
    $jurisdiction = $this->scopeAccess->jurisdictionOf($scope);
    if (!$jurisdiction instanceof GroupInterface) {
      throw new DocgenApiException('docgen.scope_not_found', 404);
    }
    $family = $previous instanceof DocgenTemplateInterface ? $previous->getFamily() : $this->uuid->generate();
    // The next version number is read and written under a family lock, so two
    // parallel uploads cannot both become the same version.
    $lock_name = self::familyLockName($family);
    if (!$this->lock->acquire($lock_name, 60.0)) {
      throw new DocgenApiException('docgen.locked', 409);
    }
    try {
      $version = $previous instanceof DocgenTemplateInterface
        ? ($this->latestVersion($family)?->getVersion() ?? $previous->getVersion()) + 1
        : 1;
      return $this->store($scope, $jurisdiction, [
        'family' => $family,
        'version' => $version,
        'label' => $label,
        'purpose' => $purpose,
        'filename' => $filename,
        'inspection' => $inspection,
      ], $bytes, $account);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Writes the file and the draft version entity.
   *
   * @param \Drupal\group\Entity\GroupInterface $scope
   *   Organisation or jurisdiction that owns the template.
   * @param \Drupal\group\Entity\GroupInterface $jurisdiction
   *   The jurisdiction of the scope.
   * @param array{family: string, version: int, label: string, purpose: string, filename: string, inspection: array<string, mixed>} $meta
   *   Family, version number, name, purpose, filename and /inspect result.
   * @param string $bytes
   *   The checked DOCX.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The uploading person.
   */
  private function store(GroupInterface $scope, GroupInterface $jurisdiction, array $meta, string $bytes, AccountInterface $account): DocgenTemplateInterface {
    $directory = self::DIRECTORY . '/' . (int) $scope->id();
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new DocgenApiException('docgen.storage_unavailable', 503);
    }
    $file = $this->fileRepository->writeData($bytes, $directory . '/' . $this->uuid->generate() . '.docx', FileExists::Error);
    try {
      $file->setFilename($meta['filename']);
      $file->setOwnerId((int) $account->id());
      $file->setPermanent();
      $file->save();
      $template = $this->entityTypeManager->getStorage('docgen_template')->create([
        'family' => $meta['family'],
        'version' => $meta['version'],
        'label' => $meta['label'],
        'purpose' => $meta['purpose'],
        'status' => DocgenTemplateInterface::STATUS_DRAFT,
        'field_jurisdiction' => (int) $jurisdiction->id(),
        'field_organisation' => $scope->bundle() === 'org' ? (int) $scope->id() : NULL,
        'file' => ['target_id' => (int) $file->id()],
        'filename' => $meta['filename'],
        'sha256' => hash('sha256', $bytes),
        'size' => strlen($bytes),
        'inspection' => json_encode($meta['inspection'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'uid' => (int) $account->id(),
      ]);
      $template->save();
    }
    catch (\Throwable $exception) {
      $file->delete();
      throw $exception;
    }
    assert($template instanceof DocgenTemplateInterface);
    return $template;
  }

  /**
   * Releases a draft version; released versions are immutable.
   *
   * Runs under the family lock on a freshly loaded version, like
   * updateDraft() and deleteDraft(): a request holding an outdated instance
   * can never release a deleted draft or delete a released version.
   */
  public function release(DocgenTemplateInterface $template, AccountInterface $account): DocgenTemplateInterface {
    return $this->underFamilyLock($template, function (DocgenTemplateInterface $current) use ($account): DocgenTemplateInterface {
      if ($current->isReleased()) {
        throw new DocgenApiException('docgen.template_immutable', 409);
      }
      if ($current->getInspection()['errors'] !== []) {
        throw new DocgenApiException('docgen.template_has_errors', 409, ['errors' => $current->getInspection()['errors']]);
      }
      // Fails when the stored file is gone or no longer matches its hash.
      $this->bytes($current);
      $current->set('status', DocgenTemplateInterface::STATUS_RELEASED);
      $current->set('released_by', (int) $account->id());
      $current->set('released', $this->time->getRequestTime());
      $current->save();
      return $current;
    });
  }

  /**
   * Updates name or purpose of a draft.
   */
  public function updateDraft(DocgenTemplateInterface $template, ?string $label, ?string $purpose): DocgenTemplateInterface {
    return $this->underFamilyLock($template, function (DocgenTemplateInterface $current) use ($label, $purpose): DocgenTemplateInterface {
      if ($current->isReleased()) {
        throw new DocgenApiException('docgen.template_immutable', 409);
      }
      if ($label !== NULL) {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 255) {
          throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'name']);
        }
        $current->set('label', $label);
      }
      if ($purpose !== NULL) {
        if (!in_array($purpose, DocgenTemplateInterface::PURPOSES, TRUE)) {
          throw new DocgenApiException('docgen.input_invalid', 422, ['field' => 'purpose']);
        }
        $current->set('purpose', $purpose);
      }
      $current->save();
      return $current;
    });
  }

  /**
   * Deletes a draft version and its file.
   */
  public function deleteDraft(DocgenTemplateInterface $template): void {
    $this->underFamilyLock($template, function (DocgenTemplateInterface $current): void {
      if ($current->isReleased()) {
        throw new DocgenApiException('docgen.template_immutable', 409);
      }
      $file = $current->getFile();
      $current->delete();
      $file?->delete();
    });
  }

  /**
   * Runs a change of one version under its family lock on a fresh load.
   */
  private function underFamilyLock(DocgenTemplateInterface $template, callable $operation): mixed {
    $lock_name = self::familyLockName($template->getFamily());
    if (!$this->lock->acquire($lock_name, 60.0)) {
      throw new DocgenApiException('docgen.locked', 409);
    }
    try {
      $current = $template->isNew() ? NULL : $this->entityTypeManager->getStorage('docgen_template')->loadUnchanged($template->id());
      if (!$current instanceof DocgenTemplateInterface) {
        throw new DocgenApiException('docgen.template_not_found', 404);
      }
      return $operation($current);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Lock shared by upload, release, update and deletion of one family.
   */
  private static function familyLockName(string $family): string {
    return 'beantragt_docgen:template_family:' . $family;
  }

  /**
   * Reads the template bytes and checks them against the stored hash.
   */
  public function bytes(DocgenTemplateInterface $template): string {
    $file = $template->getFile();
    if (!$file instanceof FileInterface || !str_starts_with($file->getFileUri(), self::DIRECTORY . '/')) {
      throw new DocgenApiException('docgen.storage_unavailable', 503);
    }
    $bytes = @file_get_contents($file->getFileUri());
    if (!is_string($bytes) || !hash_equals($template->getSha256(), hash('sha256', $bytes))) {
      throw new DocgenApiException('docgen.integrity_failed', 500);
    }
    return $bytes;
  }

  /**
   * Requires a clean malware scan.
   */
  public function assertClean(string $bytes, string $filename): void {
    if (!$this->scanner instanceof AttachmentScanServiceInterface) {
      // Fail closed: no upload is stored unscanned.
      throw new DocgenApiException('docgen.scan_unavailable', 503);
    }
    $result = $this->scanner->scan($bytes, $filename, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    if ($result->status() !== AttachmentScanResult::STATUS_CLEAN) {
      throw new DocgenApiException('docgen.scan_failed', 422);
    }
  }

  /**
   * Keeps a filename printable and ending in the given extension.
   */
  public static function safeFilename(string $filename, string $fallback): string {
    $extension = strtolower(pathinfo($fallback, PATHINFO_EXTENSION));
    $base = pathinfo(trim($filename), PATHINFO_FILENAME);
    $base = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $base) ?? '';
    $base = trim(preg_replace('/\s+/u', ' ', $base) ?? '');
    if ($base === '') {
      return $fallback;
    }
    return mb_substr($base, 0, 150) . '.' . $extension;
  }

}
