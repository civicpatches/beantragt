<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_application\Entity\ApplicationAttachmentInterface;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\RevisionLog;
use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\TransferAttachmentSelector;
use Drupal\beantragt_delivery\Value\DeliveryEnvelope;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Uses real original claims, revisioned applications and private managed files.
 */
#[Group('beantragt_delivery')]
final class TransferAttachmentSelectorTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget', 'serialization', 'language',
    'beantragt_schema', 'beantragt_workflow_status', 'beantragt_application',
    'beantragt_group', 'beantragt_roles_api', 'beantragt_notification',
    'beantragt_comment', 'beantragt_delivery',
  ];

  /**
   * Original application key restored after each test.
   */
  private string|false $originalKey;

  /**
   * Actual selector wired by Drupal.
   */
  private TransferAttachmentSelector $selector;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('beantragt_delivery.contract_registry')->setClass(SyntheticDataContractRegistry::class);
    $container->register('stream_wrapper.private', PrivateStream::class)->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('a', 32));
    parent::setUp();
    $this->setSetting('beantragt_fim_contract_directory', dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    $this->container->get('stream_wrapper_manager')->register();
    $this->installConfig(['user', 'system', 'file', 'beantragt_group', 'beantragt_application', 'beantragt_delivery']);
    foreach (['form_schema', 'workflow_status', 'application', 'application_attachment', 'application_comment', 'file'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    require_once DRUPAL_ROOT . '/modules/custom/beantragt_delivery/beantragt_delivery.install';
    $this->installSchema('beantragt_delivery', array_keys(beantragt_delivery_schema()));
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $http->expects($this->never())->method('send');
    $this->container->set('http_client', $http);
    $this->container->get('config.factory')->getEditable('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->setCurrentUser($this->createUser([
      'bypass beantragt group scope', 'administer application_attachment entities',
      'administer application entities', 'approve application dispatch',
    ]));
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->selector = new TransferAttachmentSelector($this->entityTypeManager(), $this->container->get('beantragt_application.data_protection'));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' : 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * Later case files never enter a rebuilt original-submission binding.
   */
  public function testOnlyOriginalPositiveClaimSurvivesReplanning(): void {
    [$form, $application, $original] = $this->submission();
    $revision = (int) $form->getRevisionId();
    $manifest = $this->selector->select($application, $revision, TRUE);
    $this->assertCount(1, $manifest);
    $this->assertSame($original->uuid(), $manifest[0]['id']);
    $this->assertSame('synthetic.pdf', $manifest[0]['name']);
    $this->assertSame('proof', $manifest[0]['field_id']);
    $this->assertSame($revision, $manifest[0]['form_revision_id']);
    // The generated label adds a system revision after the insert; the claim
    // still binds the insert revision, which holds the submitted data.
    $revision_ids = array_keys($this->entityTypeManager()->getStorage('application')->getQuery()->accessCheck(FALSE)
      ->allRevisions()->condition('id', $application->id())->execute());
    $this->assertGreaterThan(1, count($revision_ids));
    $this->assertSame(min($revision_ids), $manifest[0]['submission_revision_id']);
    $this->assertSame(hash('sha256', $this->pdf()), $manifest[0]['sha256']);
    $this->assertSame('claimed', $original->get('field_upload_status')->value);

    // These entities replicate CommentApiController::uploadAttachment():
    // field_application is populated; origin is comment_attachment, comment
    // private path, and no original-public-submission claim proof is present.
    $comment = $this->attachment($form, 'comment_attachment', 'application_comments');
    $comment->set('field_application', $application->id())->save();
    $reply = $this->attachment($form, 'reply_document', 'external_responses');
    $reply->set('field_application', $application->id())->save();
    $late = $this->attachment($form);
    $this->container->get('beantragt_application.public_attachment_claims')->claim([$late], $application);
    $this->assertTrue($late->get('field_submission_revision_id')->isEmpty());
    $application->setNewRevision(TRUE);
    $files = [$original, $comment, $reply, $late];
    $current_claims = array_map(static fn($file): array => ['attachmentId' => $file->uuid()], $files);
    $data = json_encode(['proof' => $current_claims], JSON_THROW_ON_ERROR);
    $application->set('field_data', $data);
    $application->refreshCrmDataFromSchema($data, $form);
    $application->save();
    // Even an explicit call cannot turn a subsequent application revision into
    // the original submission. Replan intersects current and original data.
    $this->container->get('beantragt_application.public_attachment_claims')->claim([$late], $application, TRUE);
    $this->assertTrue($late->get('field_submission_revision_id')->isEmpty());
    $this->assertSame($manifest, $this->selector->select($application, $revision, TRUE));
    $this->assertSame($manifest, $this->container->get('beantragt_delivery.envelope_builder')->buildTransfer($application, $revision, TRUE)->attachmentManifest);
    $this->assertSame([], $this->selector->select($application, $revision, FALSE));
  }

  /**
   * A follow-up document (Nachreichung) is never an original (N16).
   *
   * Even with every claim of an original submission in place, the
   * provenance alone keeps it out of the hand-over until step 6.
   */
  public function testNachreichungProvenanceIsNeverSelected(): void {
    [$form, $application, $original] = $this->submission();
    $revision = (int) $form->getRevisionId();
    $this->assertCount(1, $this->selector->select($application, $revision, TRUE));
    $original->set('field_provenance', 'nachreichung')->save();
    $this->assertSame([], $this->selector->select($application, $revision, TRUE));
    $original->set('field_provenance', 'bulk')->save();
    $this->assertCount(1, $this->selector->select($application, $revision, TRUE));
  }

  /**
   * A follow-up event code never stands in for unchanged submitted data.
   */
  public function testFollowUpEventWithChangedDataBreaksSubmissionProof(): void {
    [$form, $application, $original] = $this->submission();
    $claims = $this->container->get('beantragt_application.public_attachment_claims');
    $bound = $this->attachment($form);
    $claims->claim([$bound], $application, TRUE);
    $this->assertFalse($bound->get('field_submission_revision_id')->isEmpty());

    $data = json_encode(['proof' => [['attachmentId' => $original->uuid()]]], JSON_THROW_ON_ERROR);
    $application->setNewRevision(TRUE);
    $application->set('field_data', $data);
    $application->refreshCrmDataFromSchema($data, $form);
    RevisionLog::setEvent($application, 'label_generated');
    $application->save();
    $log = RevisionLog::decode((string) $application->getRevisionLogMessage());
    $this->assertSame(['label_generated'], $log['events']);

    $late = $this->attachment($form);
    $claims->claim([$late], $application, TRUE);
    $this->assertTrue($late->get('field_submission_revision_id')->isEmpty());
  }

  /**
   * Real planner replan freezes provenance and gateway rejects changed bytes.
   */
  public function testPlannerAndGatewayEnforceFrozenOriginalFiles(): void {
    [$form, $application, $original] = $this->submission(FALSE, TRUE);
    $definition = $this->assignment($form, $application);
    $planner = $this->container->get('beantragt_delivery.transfer_planner');
    $logs = $this->container->get('beantragt_delivery.log_repository');
    $row = $logs->load($planner->plan($application));
    $this->assertSame('awaiting_approval', $row['status'], json_encode($row));
    $manifest = $row['metadata']['attachmentManifest'];
    $this->assertCount(1, $manifest);
    $this->assertSame($original->uuid(), $manifest[0]['id']);
    $this->assertTrue($row['connection_snapshot']['send_attachments']);

    $comment = $this->attachment($form, 'comment_attachment', 'application_comments');
    $comment->set('field_application', $application->id())->save();
    $late = $this->attachment($form);
    $this->container->get('beantragt_application.public_attachment_claims')->claim([$late], $application);
    $data = $this->container->get('beantragt_application.data_protection')->revealData(json_decode($application->getData(), TRUE));
    $data['proof'][] = ['attachmentId' => $comment->uuid()];
    $data['proof'][] = ['attachmentId' => $late->uuid()];
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    $application->setNewRevision(TRUE);
    $application->set('field_data', $json);
    $application->refreshCrmDataFromSchema($json, $form);
    $application->save();
    $uid = (int) $this->container->get('current_user')->id();
    $this->assertCode('integration.application_changed', fn() => $planner->approve($application, $row['uuid'], $uid));
    $replanned = $planner->replan($application, $row['uuid'], $uid);
    $this->assertNotSame($row['uuid'], $replanned['uuid']);
    $this->assertSame($manifest, $replanned['metadata']['attachmentManifest']);

    $repository = $this->container->get('beantragt_delivery.integration_repository');
    foreach ([FALSE, TRUE] as $send) {
      $repository->transition('assignment', $definition['uuid'], 'inactive');
      $repository->update('assignment', $definition['uuid'], ['send_attachments' => $send]);
      $repository->transition('assignment', $definition['uuid'], 'active');
      $frozen = $logs->load($replanned['id']);
      $this->assertSame($manifest, $frozen['metadata']['attachmentManifest']);
      $this->assertTrue($frozen['connection_snapshot']['send_attachments']);
    }
    $queued = $planner->approve($application, $replanned['uuid'], $uid);
    $this->assertSame('queued', $queued['status']);
    $this->assertTrue($logs->claimFitConnectProcessing($queued['id'], 1));
    $envelope = DeliveryEnvelope::fromLogRow($logs->load($queued['id']));
    $file = $original->get('field_file')->entity;
    file_put_contents($file->getFileUri(), $this->pdf() . 'tampered');
    $result = $this->container->get('beantragt_delivery.fit_connect_gateway')->deliver($envelope, $queued['id']);
    $this->assertSame('integration.attachment_changed', $result->errorCode);
    $this->assertArrayNotHasKey('fitConnect', $logs->load($queued['id'])['metadata']);
    // setUp's HTTP mock forbids both request() and send(): even token access
    // would fail this test, before a creating checkpoint or external POST.
  }

  /**
   * A clean label without an actual scanner byte proof fails required uploads.
   */
  public function testRequiredUploadNeedsPositiveFreshCleanVerdict(): void {
    [$form, $application, $attachment] = $this->submission(TRUE);
    $revision = (int) $form->getRevisionId();
    $manifest = $this->selector->select($application, $revision, TRUE);
    $this->assertCount(1, $this->selector->verified($application, $revision, $manifest, TRUE));
    $database = $this->container->get('database');
    foreach (['quarantined', 'pending', 'failed', 'skipped', 'clean'] as $status) {
      // A direct update also proves the selector ignores a stale entity cache.
      $database->update('application_attachment')->fields([
        'field_scan_status' => $status,
        'field_scan_sha256' => $status === 'clean' ? NULL : $manifest[0]['sha256'],
      ])->condition('id', $attachment->id())->execute();
      $this->assertCode('integration.attachment_not_clean', fn() => $this->selector->select($application, $revision, TRUE));
    }
  }

  /**
   * Frozen bytes, exact revision field identities and opt-in fail closed.
   */
  public function testByteAndProvenanceChangesCannotReuseFrozenManifest(): void {
    [$form, $application, $attachment] = $this->submission();
    $revision = (int) $form->getRevisionId();
    $manifest = $this->selector->select($application, $revision, TRUE);
    $bytes = $this->selector->verified($application, $revision, $manifest, TRUE);
    $this->assertSame($this->pdf(), $bytes[0]['bytes']);
    $this->assertCode('integration.attachment_changed', fn() => $this->selector->verified($application, $revision, $manifest, FALSE));
    $file = $attachment->get('field_file')->entity;
    file_put_contents($file->getFileUri(), $this->pdf() . 'changed');
    $this->assertCode('integration.attachment_changed', fn() => $this->selector->verified($application, $revision, $manifest, TRUE));
    file_put_contents($file->getFileUri(), $this->pdf());
    $attachment->set('field_submission_revision_id', NULL)->save();
    $this->assertSame([], $this->selector->select($application, $revision, TRUE));
    $this->assertCode('integration.attachment_changed', fn() => $this->selector->verified($application, $revision, $manifest, TRUE));
  }

  /**
   * Required evidence respects byte, MIME and filename transport bounds.
   */
  public function testRequiredFilesRespectTransportBounds(): void {
    [$form, $application, $attachment] = $this->submission(TRUE);
    $revision = (int) $form->getRevisionId();
    $file = $attachment->get('field_file')->entity;
    file_put_contents($file->getFileUri(), str_repeat('x', TransferAttachmentSelector::MAX_FILE_BYTES + 1));
    $this->assertCode('integration.attachment_bounds_exceeded', fn() => $this->selector->select($application, $revision, TRUE));
    file_put_contents($file->getFileUri(), $this->pdf());
    $attachment->set('field_mime_type', 'application/octet-stream')->save();
    $this->assertCode('integration.attachment_invalid', fn() => $this->selector->select($application, $revision, TRUE));
    $attachment->set('field_mime_type', 'application/pdf')->set('field_original_filename', '../synthetic.pdf')->save();
    $this->assertCode('integration.attachment_invalid', fn() => $this->selector->select($application, $revision, TRUE));
  }

  /**
   * Privileged JSON:API field writes cannot forge either server-owned proof.
   */
  public function testProofFieldsDenyClientEdits(): void {
    [, , $attachment] = $this->submission();
    foreach (['field_scan_sha256', 'field_submission_revision_id', 'field_scan_status'] as $field) {
      if ($field !== 'field_scan_status') {
        $this->assertTrue($attachment->getFieldDefinition($field)->isReadOnly());
      }
      $this->assertFalse($attachment->get($field)->access('edit', $this->container->get('current_user')));
    }
  }

  /**
   * Saves an original public submission then invokes its real claim service.
   */
  private function submission(bool $required = FALSE, bool $reference = FALSE): array {
    $jurisdiction = $this->createGroup(['type' => 'jur', 'label' => 'Synthetic jurisdiction']);
    $status = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Synthetic state',
      'field_group' => $jurisdiction->id(),
      'field_machine_name' => 'synthetic',
      'field_is_default' => TRUE,
    ]);
    $status->save();
    // A rule needs the form to carry "in case system" (the acceptance moves
    // the case there), so the form switches that status on.
    $case_system = $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Synthetic case system',
      'field_group' => $jurisdiction->id(),
      'field_machine_name' => 'synthetic_case_system',
      'field_semantic_flags' => [['value' => 'in_case_system']],
    ]);
    $case_system->save();
    $field = ['id' => 'proof', 'label' => 'Synthetic evidence', 'type' => 'file', 'required' => $required];
    $fixture = $reference ? json_decode(file_get_contents(__DIR__ . '/../../fixtures/us116-renderer-submission.json'), TRUE, flags: JSON_THROW_ON_ERROR) : NULL;
    $schema = $fixture['form'] ?? ['sections' => []];
    $schema['sections'][] = ['id' => 'uploads', 'title' => 'Uploads', 'fields' => [$field]];
    $form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Synthetic form',
      'field_jurisdiction' => $jurisdiction->id(),
      'field_group' => $jurisdiction->id(),
      'field_schema' => json_encode($schema, JSON_THROW_ON_ERROR),
      'field_workflow_definition' => json_encode([
        'mode' => 'catalog',
        'defaultStatus' => 'synthetic',
        'statuses' => [['machineName' => 'synthetic'], ['machineName' => 'synthetic_case_system']],
        'transitions' => [['from' => 'synthetic', 'to' => 'synthetic_case_system']],
      ], JSON_THROW_ON_ERROR),
    ]);
    $form->save();
    $attachment = $this->attachment($form);
    // PublicApplicationSubmissionValidator persists this server claim shape.
    $claim = [
      'attachmentId' => $attachment->uuid(),
      'filename' => 'synthetic.pdf',
      'size' => strlen($this->pdf()),
      'mimeType' => 'application/pdf',
    ];
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'uid' => $this->container->get('current_user')->id(),
      'field_schema' => $form->id(),
      'field_workflow_status' => $status->id(),
      'field_jurisdiction' => $jurisdiction->id(),
      'field_group' => $jurisdiction->id(),
      'field_data' => json_encode([...($fixture['data'] ?? []), 'proof' => [$claim]], JSON_THROW_ON_ERROR),
      'field_email_confirmed' => TRUE,
      'created' => time(),
    ]);
    $application->save();
    $this->container->get('beantragt_application.public_attachment_claims')->claim([$attachment], $application, TRUE);
    return [$form, $application, $attachment];
  }

  /**
   * Activates a complete reference mapping through production repositories.
   */
  private function assignment(FormSchemaInterface $form, ApplicationInterface $application): array {
    $scope = (int) $application->get('field_jurisdiction')->target_id;
    $destination = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $config = $this->container->get('config.factory')->getEditable('beantragt_delivery.settings');
    $config->set('credential_profiles.synthetic', [
      'allowed_scopes' => [$scope],
      'client_id' => 'synthetic-client',
      'client_secret_env' => 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY',
      'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
      'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
      'metadata_schema_version' => '2.1.0',
      'confirmed_destinations' => [[
        'scope_group_id' => $scope,
        'destination_id' => $destination,
        'service_urn' => 'urn:synthetic:attachment',
        'contract_key' => 'fim:S00000096',
        'contract_version' => '1.0',
      ],
      ],
    ])->save();
    $repository = $this->container->get('beantragt_delivery.integration_repository');
    $uid = (int) $this->container->get('current_user')->id();
    $connection = $repository->create('connection', [
      'scope_group_id' => $scope,
      'label' => 'Synthetic attachment connection',
      'adapter' => 'fit_connect',
      'environment' => 'test',
      'credential_profile' => 'synthetic',
      'destination_id' => $destination,
      'service_urn' => 'urn:synthetic:attachment',
      'service_name' => 'Synthetic service',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], $uid);
    $repository->transition('connection', $connection['uuid'], 'active');
    $mapping = $repository->create('mapping', [
      'scope_group_id' => $scope,
      'label' => 'Synthetic mapping',
      'contract_key' => 'fim:S00000096',
      'contract_version' => '1.0',
    ], $uid);
    $fixture = json_decode(file_get_contents(__DIR__ . '/../../fixtures/us116-renderer-submission.json'), TRUE, flags: JSON_THROW_ON_ERROR);
    $repository->createVersion($mapping['uuid'], [
      'form_schema_uuid' => $form->uuid(),
      'compatible_revisions' => [(int) $form->getRevisionId()],
      'rules' => $fixture['rules'],
    ], $uid);
    $repository->transitionVersion($mapping['uuid'], 1, 'active');
    $assignment = $repository->create('assignment', [
      'scope_group_id' => $scope,
      'form_schema_uuid' => $form->uuid(),
      'compatible_revisions' => [(int) $form->getRevisionId()],
      'service_urn' => 'urn:synthetic:attachment',
      'connection_uuid' => $connection['uuid'],
      'mapping_uuid' => $mapping['uuid'],
      'mapping_version' => 1,
      'dispatch_mode' => 'approval',
      'send_attachments' => TRUE,
    ], $uid);
    return $repository->transition('assignment', $assignment['uuid'], 'active');
  }

  /**
   * Creates a synthetic upload with an independently verified scanner hash.
   */
  private function attachment(FormSchemaInterface $form, string $field = 'proof', string $path = 'application_attachments'): ApplicationAttachmentInterface {
    $directory = 'private://' . $path;
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri = $directory . '/' . $this->container->get('uuid')->generate() . '.pdf';
    file_put_contents($uri, $this->pdf());
    $file = $this->entityTypeManager()->getStorage('file')->create([
      'uri' => $uri,
      'filename' => 'synthetic.pdf',
      'filemime' => 'application/pdf',
      'filesize' => strlen($this->pdf()),
      'uid' => $this->container->get('current_user')->id(),
    ]);
    $file->save();
    $attachment = $this->entityTypeManager()->getStorage('application_attachment')->create([
      'uid' => $this->container->get('current_user')->id(),
      'field_schema' => $form->id(),
      'field_field_id' => $field,
      'field_file' => $file->id(),
      'field_upload_status' => 'uploaded',
      'field_scan_status' => 'clean',
      'field_scan_sha256' => hash('sha256', $this->pdf()),
      'field_original_filename' => 'synthetic.pdf',
      'field_mime_type' => 'application/pdf',
      'field_size' => strlen($this->pdf()),
      'created' => time(),
    ]);
    $attachment->save();
    return $attachment;
  }

  /**
   * Tiny synthetic PDF recognized by libmagic, never real applicant data.
   */
  private function pdf(): string {
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
  }

  /**
   * Checks a stable value-free attachment rejection.
   */
  private function assertCode(string $code, callable $operation): void {
    try {
      $operation();
      $this->fail('Expected ' . $code);
    }
    catch (IntegrationException $exception) {
      $this->assertSame($code, $exception->getMessage());
    }
  }

}
