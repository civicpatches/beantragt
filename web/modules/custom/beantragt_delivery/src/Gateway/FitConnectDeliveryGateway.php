<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Gateway;

use Drupal\beantragt_delivery\Exception\FitConnectTransportException;
use Drupal\beantragt_delivery\Service\FitConnectSubmissionMetadataBuilder;
use Drupal\beantragt_delivery\Service\FitConnectAccessTokenClient;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\DeliveryResult;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\file\FileInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\beantragt_delivery\Service\DeliveryLogRepository;
use Drupal\beantragt_delivery\Service\FitConnectSubmissionApiClient;
use Drupal\beantragt_delivery\Service\FitConnectMetadataDocumentBuilder;
use Drupal\beantragt_delivery\Service\FitConnectJweEncrypter;
use Drupal\beantragt_delivery\Service\FitConnectCaseRepository;
use Drupal\beantragt_delivery\Service\TransferPlanner;
use Drupal\beantragt_delivery\Service\TransferAttachmentSelector;
use Drupal\beantragt_delivery\Exception\IntegrationException;

/**
 * Encrypts and delivers applications to the FIT-Connect TEST Submission API.
 */
final class FitConnectDeliveryGateway implements DeliveryGatewayInterface {

  /**
   * Constructs the FIT-Connect TEST gateway.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FitConnectAccessTokenClient $tokenClient,
    private readonly FitConnectSubmissionMetadataBuilder $metadataBuilder,
    private readonly FitConnectSubmissionApiClient $apiClient,
    private readonly FitConnectMetadataDocumentBuilder $documentBuilder,
    private readonly FitConnectJweEncrypter $encrypter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DeliveryLogRepository $logRepository,
    private readonly ApplicationDataProtectionService $dataProtection,
    private readonly ?FitConnectCaseRepository $caseRepository = NULL,
    private readonly ?TransferPlanner $transferPlanner = NULL,
    private readonly ?TransferAttachmentSelector $transferAttachments = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'fit_connect';
  }

  /**
   * {@inheritdoc}
   */
  public function deliver(DeliveryEnvelope $envelope, ?int $log_id = NULL): DeliveryResult {
    $metadata = [
      'gateway' => $this->id(),
      'applicationUuid' => $envelope->applicationUuid,
      'submissionMetadataReady' => FALSE,
      'submissionPackageEncrypted' => FALSE,
    ];
    try {
      $row = $log_id === NULL ? NULL : $this->logRepository->load($log_id);
      if ($row === NULL || $row['applicationUuid'] !== $envelope->applicationUuid
        || (int) $row['applicationId'] !== $envelope->applicationId
        || $row['adapter'] !== 'fit_connect'
        || $row['status'] !== DeliveryLogRepository::STATUS_PROCESSING) {
        throw new FitConnectTransportException('fit_connect.log_context_required', 'FIT-Connect requires a matching processing delivery log.');
      }
      $bound = $row['scope_group_id'] !== NULL;
      if (!$bound) {
        if ($this->transferPlanner !== NULL) {
          $this->transferPlanner->assertLegacy($row);
        }
        elseif (($row['legacy_eligible'] ?? NULL) !== 1) {
          throw new IntegrationException('integration.legacy_delivery_forbidden');
        }
      }
      // A crash or timeout can leave the remote outcome unknown. Never repeat
      // POST, even if the response IDs could not be saved. Reconciliation
      // is required for any submission already in progress.
      $progress = $row['metadata']['fitConnect'] ?? [];
      if ($progress !== []) {
        return DeliveryResult::failed('fit_connect.submission_already_started', 'A previous submission attempt requires reconciliation; no new submission was created.', [
          ...$metadata,
          ...$progress,
        ]);
      }
      $application = $this->entityTypeManager->getStorage('application')->load($envelope->applicationId);
      if (!$application instanceof ApplicationInterface || $application->uuid() !== $envelope->applicationUuid || !$application->isEmailConfirmed()) {
        throw new FitConnectTransportException('fit_connect.application_unavailable', 'The confirmed application is unavailable.');
      }
      if ($bound) {
        if ($this->transferPlanner === NULL) {
          throw new IntegrationException('integration.binding_invalid');
        }
        $prepared = $this->transferPlanner->prepareSend($row, $application);
        $settings = $prepared['settings'];
        $data = $prepared['json'];
      }
      else {
        // Only rows explicitly marked by the migration may use this path.
        $settings = FitConnectSettings::fromConfig($this->configFactory);
        $settings->assertTestTransportAllowed();
        if ($missing = $settings->missingForSubmission()) {
          return DeliveryResult::failed('fit_connect.missing_configuration', 'FIT-Connect configuration is incomplete.', ['missing' => $missing]);
        }
        $data = $application->getData() ?? '{}';
        json_decode($data, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals($envelope->dataHash, hash('sha256', $data))) {
          throw new FitConnectTransportException('fit_connect.application_changed', 'Application data changed after enqueueing.');
        }
        $fields = json_decode($data, TRUE, flags: JSON_THROW_ON_ERROR);
        if (!is_array($fields)) {
          throw new FitConnectTransportException('fit_connect.invalid_data', 'Application data must be a JSON object.');
        }
        $data = json_encode((object) $this->dataProtection->revealData($fields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        unset($fields);
      }
      $metadata += $settings->toSafeMetadata();
      if ($bound && count($envelope->attachmentManifest) !== $envelope->attachmentCount) {
        throw new IntegrationException('integration.attachment_changed', 409);
      }
      $selector = $this->transferAttachments;
      if ($bound && $selector === NULL && ($envelope->attachmentManifest !== [] || ($row['connection_snapshot']['send_attachments'] ?? FALSE))) {
        throw new IntegrationException('integration.attachment_provenance_invalid', 409);
      }
      $attachments = $bound
        ? ($selector?->verified($application, (int) $row['form_schema_revision_id'], $envelope->attachmentManifest, (bool) ($row['connection_snapshot']['send_attachments'] ?? FALSE)) ?? [])
        : $this->loadAttachments($envelope);
      $authorize = function () use ($bound, $row, $application, $settings, $selector, $envelope): void {
        if ($bound) {
          $current = $this->transferPlanner->prepareSend($row, $application);
          // No request may continue using a token for a replaced profile.
          if ($current['settings'] != $settings) {
            throw new IntegrationException('integration.profile_changed');
          }
          $selector?->verified($application, (int) $row['form_schema_revision_id'], $envelope->attachmentManifest, (bool) ($row['connection_snapshot']['send_attachments'] ?? FALSE));
        }
      };
      $token = '';
      $request = function (string $method, string $path, int $expected, array $options = []) use ($authorize, $settings, &$token): array {
        $authorize();
        return $this->apiClient->request($settings, $token, $method, $path, $expected, $options);
      };
      $authorize();
      $token = $this->tokenClient->fetchToken($settings)->accessToken;
      $destination_path = '/v2/destinations/' . rawurlencode($settings->destinationId);
      $destination = $request('GET', $destination_path, 200);
      if (($destination['status'] ?? '') !== 'active') {
        throw new FitConnectTransportException('fit_connect.destination_inactive', 'FIT-Connect destination is not active.');
      }
      if (!is_array($destination['metadataVersions'] ?? NULL) || !in_array('2.1.0', $destination['metadataVersions'], TRUE)) {
        throw new FitConnectTransportException('fit_connect.metadata_version_unsupported', 'Destination does not support metadata 2.1.0.');
      }
      if ($bound) {
        $this->assertDestinationContract($destination, $settings);
        $this->logRepository->recordDestination($log_id, $destination, $settings->serviceUrn, $settings->schemaUri);
      }
      $kid = $destination['encryptionKid'] ?? NULL;
      if (!is_string($kid) || $kid === '') {
        throw new FitConnectTransportException('fit_connect.invalid_key', 'Destination encryption key identifier is missing.');
      }
      $key = $request('GET', $destination_path . '/keys/' . rawurlencode($kid), 200);
      $this->encrypter->validateKey($key, $kid);
      $document = $this->documentBuilder->build($data, $attachments, $envelope->referenceNumber, gmdate('Y-m-d', $application->getCreatedTime()), $settings);
      if ($settings->replyEnabled) {
        if ($this->caseRepository === NULL) {
          throw new FitConnectTransportException('fit_connect.reply_storage_unavailable', 'Reply key storage is unavailable.');
        }
        $supported = FALSE;
        foreach (($destination['publicServices'] ?? []) as $service) {
          if (($service['identifier'] ?? '') === $settings->serviceUrn
            && in_array($settings->replyProcessStandard, $service['replyChannels']['fitConnect']['processStandards'] ?? [], TRUE)) {
            $supported = TRUE;
          }
        }
        if (!$supported) {
          throw new FitConnectTransportException('fit_connect.reply_channel_unsupported', 'The selected service does not advertise the configured FIT-Connect reply process standard.');
        }
        $public_key = $this->caseRepository->prepare($application, $log_id, $settings);
        $document['replyChannel'] = [
          'fitConnect' => [
            'processStandards' => [$settings->replyProcessStandard],
            'encryptionPublicKey' => $public_key,
          ],
        ];
      }
      $encrypted_data = $this->encrypter->encrypt($data, $key);
      $encrypted_metadata = $this->encrypter->encrypt(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key);
      foreach ($attachments as &$attachment) {
        $attachment['encrypted'] = $this->encrypter->encrypt($attachment['bytes'], $key, $attachment['mimeType']);
        unset($attachment['bytes']);
      }
      unset($attachment, $data, $document);
      if (!$bound) {
        $metadata = array_merge($metadata, $this->metadataBuilder->build($envelope, $settings)->toSafeMetadata());
      }
      else {
        $metadata['payloadSha256'] = $row['payload_sha256'];
        $metadata['submissionMetadataReady'] = TRUE;
      }
      $metadata['submissionPackageEncrypted'] = TRUE;

      // Write before POST, then persist returned IDs before uploading
      // attachments or finalizing the submission.
      $authorize();
      $this->logRepository->checkpointFitConnect($log_id, 'creating', $settings->destinationId);
      $created = $this->apiClient->request($settings, $token, 'POST', '/v2/submissions', 201, [
        'json' => [
          'destinationId' => $settings->destinationId,
          'announcedAttachments' => array_column($attachments, 'attachmentId'),
          'publicService' => ['name' => $envelope->schemaLabel ?: 'Application', 'identifier' => $settings->serviceUrn],
        ],
      ]);
      $submission_id = $created['submissionId'] ?? NULL;
      $case_id = $created['caseId'] ?? NULL;
      if (!is_string($submission_id) || !Uuid::isValid($submission_id) || !is_string($case_id) || !Uuid::isValid($case_id)) {
        throw new FitConnectTransportException('fit_connect.invalid_submission_response', 'FIT-Connect did not return valid submission identifiers.');
      }
      $metadata = [
        ...$metadata,
        'submissionId' => $submission_id,
        'caseId' => $case_id,
        'destinationId' => $settings->destinationId,
      ];
      $this->logRepository->checkpointFitConnect($log_id, 'created', $settings->destinationId, $submission_id, $case_id);
      if ($settings->replyEnabled) {
        $this->caseRepository->bind($log_id, $submission_id, $case_id);
      }
      $path = '/v2/submissions/' . $submission_id;
      foreach ($attachments as $attachment) {
        $request('PUT', $path . '/attachments/' . $attachment['attachmentId'], 204, [
          'headers' => ['Content-Type' => 'application/jose'],
          'body' => $attachment['encrypted'],
        ]);
      }
      $request('PUT', $path, 200, [
        'json' => [
          'encryptedMetadata' => $encrypted_metadata,
          'encryptedData' => $encrypted_data,
        ],
      ]);
      $this->logRepository->checkpointFitConnect($log_id, 'submitted', $settings->destinationId, $submission_id, $case_id);
      try {
        $authorize();
        $metadata['events'] = $this->apiClient->events($settings, $token, $case_id);
      }
      catch (FitConnectTransportException | IntegrationException $e) {
        // An informational event lookup cannot undo successful submission.
        $metadata['events'] = [
          'eventTypes' => [],
          'signaturesVerified' => FALSE,
          'errorCode' => $e->errorCode,
          'statusCode' => $e instanceof FitConnectTransportException ? $e->statusCode : NULL,
        ];
      }
      // Accepted means transport acceptance only, not recipient acceptance.
      return DeliveryResult::accepted($submission_id, $case_id, $metadata);
    }
    catch (IntegrationException $e) {
      return DeliveryResult::failed($e->errorCode, 'Transfer authorization or integrity check failed.');
    }
    catch (FitConnectTransportException $e) {
      return DeliveryResult::failed($e->errorCode, $e->getMessage(), [...$metadata, 'statusCode' => $e->statusCode]);
    }
    catch (\Throwable) {
      return DeliveryResult::failed('fit_connect.package_failed', 'FIT-Connect could not prepare or persist the submission.', $metadata);
    }
  }

  /**
   * Requires the bound service and schema in destination metadata.
   */
  private function assertDestinationContract(array $destination, FitConnectSettings $settings): void {
    foreach (($destination['publicServices'] ?? []) as $service) {
      if (!is_array($service) || ($service['identifier'] ?? NULL) !== $settings->serviceUrn) {
        continue;
      }
      foreach (($service['submissionSchemas'] ?? []) as $schema) {
        if (is_array($schema) && ($schema['schemaUri'] ?? NULL) === $settings->schemaUri
          && ($schema['mimeType'] ?? NULL) === 'application/json') {
          return;
        }
      }
      throw new FitConnectTransportException('integration.destination_contract_mismatch', 'Destination no longer offers the bound schema.');
    }
    throw new FitConnectTransportException('integration.destination_service_mismatch', 'Destination no longer offers the bound service.');
  }

  /**
   * Resolves only the claimed, clean attachments captured in the outbox.
   *
   * @return array<int, array<string, string>>
   *   Transient bytes and metadata, never an outbox value.
   */
  private function loadAttachments(DeliveryEnvelope $envelope): array {
    if (count($envelope->attachmentManifest) !== $envelope->attachmentCount) {
      throw new FitConnectTransportException('fit_connect.attachment_unavailable', 'Attachment manifest is incomplete.');
    }
    $attachments = [];
    foreach ($envelope->attachmentManifest as $item) {
      $id = (string) ($item['id'] ?? '');
      if (!Uuid::isValid($id)) {
        throw new FitConnectTransportException('fit_connect.attachment_unavailable', 'Attachment identifier is invalid.');
      }
      $matches = $this->entityTypeManager->getStorage('application_attachment')->loadByProperties([
        'uuid' => $id,
        'field_application' => $envelope->applicationId,
      ]);
      $attachment = reset($matches);
      if (!$attachment instanceof ApplicationAttachmentInterface
        || (int) $attachment->get('field_application')->target_id !== $envelope->applicationId
        || $attachment->get('field_scan_status')->value !== 'clean') {
        throw new FitConnectTransportException('fit_connect.attachment_unavailable', 'A claimed, clean attachment is unavailable.');
      }
      $file = $this->entityTypeManager->getStorage('file')->load($attachment->get('field_file')->target_id);
      if (!$file instanceof FileInterface || !str_starts_with($file->getFileUri(), 'private://') || !is_file($file->getFileUri())) {
        throw new FitConnectTransportException('fit_connect.attachment_unavailable', 'Private attachment file is unavailable.');
      }
      $bytes = @file_get_contents($file->getFileUri());
      if ($bytes === FALSE || (isset($item['size']) && strlen($bytes) !== (int) $item['size'])) {
        throw new FitConnectTransportException('fit_connect.attachment_changed', 'Attachment bytes are unavailable or changed.');
      }
      $filename = (string) ($attachment->get('field_original_filename')->value ?: $file->getFilename());
      $mime_type = (string) ($attachment->get('field_mime_type')->value ?: $file->getMimeType());
      $attachments[] = ['attachmentId' => $id, 'bytes' => $bytes, 'filename' => $filename, 'mimeType' => $mime_type];
    }
    return $attachments;
  }

  /**
   * Runs a secret-safe FIT-Connect configuration preflight.
   *
   * @return array<string, mixed>
   *   Preflight status.
   */
  public function preflight(bool $fetch_token = FALSE): array {
    $settings = FitConnectSettings::fromConfig($this->configFactory);
    $missing = $settings->missingForSubmission();
    $output = [
      'adapter' => $this->id(),
      'transportConfigReady' => $missing === [],
      'encryptedSubmissionPackageReady' => FALSE,
      'missing' => $missing,
      'settings' => $settings->toSafeMetadata(),
    ];

    try {
      $settings->assertTestTransportAllowed();
      $output['encryptedSubmissionPackageReady'] = $missing === [];
    }
    catch (FitConnectTransportException $e) {
      $output['transportConfigReady'] = FALSE;
      $output['errorCode'] = $e->errorCode;
      return $output;
    }

    if (!$fetch_token) {
      return $output;
    }

    try {
      $output['token'] = $this->tokenClient->fetchToken($settings)->toSafeMetadata();
    }
    catch (FitConnectTransportException $e) {
      $output['token'] = [
        'accessTokenFetched' => FALSE,
        'errorCode' => $e->errorCode,
        'statusCode' => $e->statusCode,
      ];
    }

    return $output;
  }

}
