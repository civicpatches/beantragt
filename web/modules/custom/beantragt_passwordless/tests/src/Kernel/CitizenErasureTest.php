<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_passwordless\Kernel;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_sso\Service\SsoIdentityLinker;
use Drupal\Core\Site\Settings;
use Psr\Log\NullLogger;
use Drupal\beantragt_notification\Service\NotificationMailer;
use Drupal\beantragt_passwordless\Controller\GdprController;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Deleting all own data removes the account and keeps the record.
 *
 * Synthetic data only.
 */
#[Group('beantragt_passwordless')]
final class CitizenErasureTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget', 'serialization', 'language',
    'key', 'encrypt', 'real_aes', 'field_encrypt', 'beantragt_applicant_encryption_test',
    'beantragt_schema', 'beantragt_workflow_status', 'beantragt_application',
    'beantragt_group', 'beantragt_roles_api', 'beantragt_applicant',
    'beantragt_passwordless', 'beantragt_comment', 'beantragt_notification',
    'beantragt_sso',
  ];

  private const EMAIL = 'citizen@example.test';

  private const CREATED = 1767225600;

  /**
   * Original application key restored after every scenario.
   */
  private string|false $originalKey;

  /**
   * The citizen account.
   */
  private UserInterface $citizen;

  /**
   * Another citizen whose data must stay untouched.
   */
  private UserInterface $neighbour;

  /**
   * Operator account that seeds workspace fixtures.
   */
  private UserInterface $operator;

  /**
   * The jurisdiction of all fixtures.
   */
  private GroupInterface $jurisdiction;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', PrivateStream::class)->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . str_repeat('e', 32));
    parent::setUp();
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
    $this->container->get('stream_wrapper_manager')->register();
    $types = ['form_schema', 'workflow_status', 'application',
      'application_attachment', 'application_comment', 'applicant', 'file',
    ];
    foreach ($types as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['user', 'system', 'file', 'beantragt_group', 'beantragt_applicant', 'beantragt_application']);
    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'beantragt_applicant')
      ->set('make_entities_uncacheable', TRUE)
      ->save(TRUE);
    FieldStorageConfig::create(['entity_type' => 'group', 'field_name' => 'field_slug', 'type' => 'string'])->save();
    FieldConfig::create(['entity_type' => 'group', 'bundle' => 'jur', 'field_name' => 'field_slug', 'label' => 'Slug'])->save();
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->installSchema('beantragt_passwordless', ['beantragt_passwordless_codes', 'beantragt_verified_account_mail']);
    $this->installSchema('beantragt_sso', ['beantragt_sso_identities']);
    $this->container->set('beantragt_notification.mailer', $this->createMock(NotificationMailer::class));
    $this->config('beantragt_application.settings')->set('data_retention_days', 0)->save();

    $this->citizen = $this->createUser([], NULL, FALSE, ['mail' => self::EMAIL]);
    $this->container->get('beantragt_passwordless.verified_mail')->verify($this->citizen, self::EMAIL, 'test');
    $this->neighbour = $this->createUser([], NULL, FALSE, ['mail' => 'neighbour@example.test']);
    $this->operator = $this->createUser(['administer beantragt retention policies', 'bypass beantragt group scope']);
    $this->setCurrentUser($this->operator);
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $this->jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Synthetic town',
      'field_slug' => 'synthetic-town',
      'field_jur_config' => json_encode([
        'retention' => ['dataRetentionDays' => 3650],
        'general' => ['contactEmail' => 'office@synthetic-town.example', 'phone' => '0000 111'],
      ], JSON_THROW_ON_ERROR),
    ]);
    $this->setCurrentUser($this->citizen);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv($this->originalKey === FALSE ? 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' : 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $this->originalKey);
    parent::tearDown();
  }

  /**
   * The preview names what goes and what stays, and deletes nothing.
   */
  public function testPreviewListsDeletedAndKeptData(): void {
    $fixture = $this->seedCitizen();

    $response = GdprController::create($this->container)->preview();
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $preview = $this->decode($response);

    $this->assertTrue($preview['deletes']['account']);
    $this->assertSame(self::EMAIL, $preview['deletes']['accountEmail']);
    $this->assertCount(2, $preview['deletes']['profiles']);
    $this->assertSame('Synthetic town', $preview['deletes']['profiles'][0]['scopeLabel']);
    $this->assertSame(1, $preview['deletes']['unconfirmedApplications']);
    $this->assertSame(1, $preview['deletes']['unconfirmedUploads']);
    $this->assertSame(1, $preview['deletes']['loginLinks']);
    $this->assertCount(1, $preview['keeps']);
    $kept = $preview['keeps'][0];
    $this->assertSame($fixture['submitted']->uuid(), $kept['id']);
    $this->assertSame(self::EMAIL, $kept['applicantEmail']);
    $this->assertSame($fixture['submitted']->getReferenceNumber(), $kept['referenceNumber']);
    $this->assertSame('Synthetic form', $kept['formTitle']);
    $this->assertSame('Synthetic town', $kept['stelle']['jurisdiction']);
    $this->assertSame('office@synthetic-town.example', $kept['stelle']['contact']['email']);
    $this->assertSame(3650, $kept['retention']['days']);
    $this->assertSame('jurisdiction', $kept['retention']['source']);
    $this->assertSame(date(\DateTimeInterface::ATOM, self::CREATED + 3650 * 86400), $kept['retention']['deleteAfter']);

    $this->assertInstanceOf(UserInterface::class, $this->reload('user', (int) $this->citizen->id()));
    $this->assertInstanceOf(ApplicationInterface::class, $this->reload('application', (int) $fixture['unconfirmed']->id()));
    $this->assertSame(1, $this->ssoRows((int) $this->citizen->id()));
  }

  /**
   * An unverified matching address never expands erasure ownership.
   */
  public function testErasureDoesNotUseUnverifiedMailboxForProfiles(): void {
    $fixture = $this->seedCitizen();
    $this->container->get('beantragt_passwordless.verified_mail')->invalidate($this->citizen);

    $preview = $this->decode(GdprController::create($this->container)->preview());
    $this->assertCount(1, $preview['deletes']['profiles']);

    $response = $this->erase();
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertNull($this->reload('applicant', $fixture['ownProfile']));
    $this->assertInstanceOf(FieldableEntityInterface::class, $this->reload('applicant', $fixture['emailProfile']));
    $this->assertSame(0, (int) $this->container->get('database')->select('beantragt_passwordless_codes')->condition('email', self::EMAIL)->countQuery()->execute()->fetchField());
  }

  /**
   * Erasure frees the same subject to register as a new citizen.
   */
  public function testErasedSubjectCanRegisterFreshCitizen(): void {
    $this->seedCitizen();
    $uid = (int) $this->citizen->id();
    $this->assertSame(200, $this->erase()->getStatusCode());
    $this->assertSame(0, $this->ssoRows($uid));
    $this->container->set('session', new Session(new MockArraySessionStorage()));
    $linker = new SsoIdentityLinker(
      $this->container->get('database'), $this->container->get('entity_type.manager'),
      $this->createMock(ApplicantManager::class), new NullLogger(), NULL,
      $this->container->get('beantragt_passwordless.verified_mail'), $this->container->get('lock'),
    );
    try {
      $fresh = $linker->authenticate('synthetic_idp', ['create_users' => TRUE], 'citizen', [], []);
    }
    catch (\RuntimeException) {
      $this->fail('An erased subject must be free to register a fresh citizen.');
    }
    $this->assertNotSame($uid, $fresh['uid']);
    $this->assertSame(1, $this->ssoRows($fresh['uid']));
    $this->assertNull($this->reload('user', $uid));
  }

  /**
   * The erasure removes the account data and keeps the record unchanged.
   */
  public function testErasureDeletesAccountDataAndKeepsSubmittedApplication(): void {
    $fixture = $this->seedCitizen();
    $submitted_id = (int) $fixture['submitted']->id();
    $before = $this->reload('application', $submitted_id);
    $this->assertInstanceOf(ApplicationInterface::class, $before);
    $revisions_before = $this->revisionCount($submitted_id);
    $uid = (int) $this->citizen->id();
    $session = $this->container->get('request_stack')->getCurrentRequest()->getSession();
    $handler = $this->container->get('session_handler.storage');
    $session->set('uid', (int) $this->neighbour->id());
    $handler->write('neighbour-device', 'neighbour data');
    $session->set('uid', $uid);
    $handler->write('citizen-phone', 'phone data');
    $handler->write('citizen-laptop', 'laptop data');

    $response = $this->erase();
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $summary = $this->decode($response);
    $this->assertSame([
      'account' => TRUE,
      'profiles' => 2,
      'unconfirmedApplications' => 1,
      'unconfirmedUploads' => 1,
      'loginLinks' => 1,
    ], $summary['deleted']);
    $this->assertCount(1, $summary['keeps']);
    $this->assertSame($before->getReferenceNumber(), $summary['keeps'][0]['referenceNumber']);
    $this->assertNotEmpty($response->headers->getCookies());
    $this->assertSame('', $handler->read('citizen-phone'));
    $this->assertSame('', $handler->read('citizen-laptop'));
    $this->assertSame('neighbour data', $handler->read('neighbour-device'));

    // Deleted: account, profiles, unconfirmed submission with its message,
    // SSO identity binding and pending login code.
    $this->assertNull($this->reload('user', $uid));
    $this->assertNull($this->reload('applicant', $fixture['ownProfile']));
    $this->assertNull($this->reload('applicant', $fixture['emailProfile']));
    $this->assertNull($this->reload('application', (int) $fixture['unconfirmed']->id()));
    $this->assertNull($this->reload('application_comment', $fixture['unconfirmedComment']));
    // An upload that never became part of a submitted application goes with
    // its file instead of moving to the anonymous user.
    $this->assertNull($this->reload('application_attachment', $fixture['draftUpload']));
    $this->assertNull($this->reload('file', $fixture['draftFile']));
    $this->assertFileDoesNotExist($fixture['draftFileUri']);
    $this->assertSame(0, $this->ssoRows($uid));
    $this->assertSame(0, (int) $this->container->get('database')->select('beantragt_passwordless_codes')->condition('email', self::EMAIL)->countQuery()->execute()->fetchField());

    // Kept unchanged: the submitted application with data, contact address,
    // revisions, attachment and messages; only the owner becomes anonymous.
    $after = $this->reload('application', $submitted_id);
    $this->assertInstanceOf(ApplicationInterface::class, $after);
    $this->assertSame($before->get('field_data')->value, $after->get('field_data')->value);
    $this->assertSame($before->get('field_crm_data')->value, $after->get('field_crm_data')->value);
    $this->assertSame($before->get('field_applicant_email')->value, $after->get('field_applicant_email')->value);
    $this->assertSame(self::EMAIL, $after->get('field_applicant_email')->value);
    $this->assertSame($before->get('field_workflow_status')->target_id, $after->get('field_workflow_status')->target_id);
    $this->assertSame($before->getReferenceNumber(), $after->getReferenceNumber());
    $this->assertSame((int) $before->getRevisionId(), (int) $after->getRevisionId());
    $this->assertSame($revisions_before, $this->revisionCount($submitted_id));
    $this->assertSame(0, (int) $after->getOwnerId());
    $database = $this->container->get('database');
    $this->assertSame(0, (int) $database->select('application_revision')->condition('uid', $uid)->countQuery()->execute()->fetchField());
    $this->assertSame(0, (int) $database->select('application_revision')->condition('revision_user', $uid)->countQuery()->execute()->fetchField());

    $attachment = $this->reload('application_attachment', $fixture['attachment']);
    $this->assertInstanceOf(ContentEntityInterface::class, $attachment);
    $this->assertSame(0, (int) $attachment->get('uid')->target_id);
    $file = $this->reload('file', $fixture['file']);
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertSame(0, (int) $file->getOwnerId());
    $this->assertFileExists($file->getFileUri());

    $citizen_message = $this->reload('application_comment', $fixture['citizenComment']);
    $this->assertInstanceOf(ContentEntityInterface::class, $citizen_message);
    $this->assertSame('Synthetic question', $citizen_message->get('field_body')->value);
    $this->assertSame(0, (int) $citizen_message->get('uid')->target_id);
    $staff_reply = $this->reload('application_comment', $fixture['staffComment']);
    $this->assertInstanceOf(ContentEntityInterface::class, $staff_reply);
    $this->assertSame('Synthetic decision note', $staff_reply->get('field_body')->value);
    $this->assertSame((int) $this->neighbour->id(), (int) $staff_reply->get('uid')->target_id);

    // Other people keep everything, also a profile of another account that
    // carries the same email address.
    $this->assertInstanceOf(UserInterface::class, $this->reload('user', (int) $this->neighbour->id()));
    $this->assertInstanceOf(ContentEntityInterface::class, $this->reload('applicant', $fixture['neighbourProfile']));
    $this->assertInstanceOf(ContentEntityInterface::class, $this->reload('applicant', $fixture['foreignEmailProfile']));
    $this->assertSame(1, $this->ssoRows((int) $this->neighbour->id()));
  }

  /**
   * Public submissions of the service account matched by email.
   *
   * The confirmed one stays unchanged with the service account as owner,
   * the unconfirmed one is deleted.
   */
  public function testEmailMatchedPublicSubmissionsOfServiceAccount(): void {
    Role::create(['id' => 'service_account', 'label' => 'Service account'])->save();
    $service = $this->createUser([], NULL, FALSE, ['roles' => ['service_account']]);
    $this->setCurrentUser($this->operator);
    $etm = $this->entityTypeManager();
    $status = $etm->getStorage('workflow_status')->create([
      'label' => 'Received',
      'field_group' => $this->jurisdiction->id(),
      'field_machine_name' => 'received',
      'field_is_default' => TRUE,
    ]);
    $status->save();
    $form = $etm->getStorage('form_schema')->create([
      'label' => 'Synthetic public form',
      'field_schema' => json_encode([
        'title' => 'Synthetic public form',
        'sections' => [[
          'id' => 'main',
          'title' => 'Main',
          'fields' => [[
            'id' => 'name',
            'type' => 'text',
            'label' => 'Name',
            'crm_key' => 'person.full_name',
            'privacy' => ['kind' => 'contact'],
          ],
          ],
        ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_group' => $this->jurisdiction->id(),
      'field_publication_scope' => 'internet',
      'field_workflow_status' => 'published',
      'status' => TRUE,
    ]);
    $form->save();
    $public = [];
    foreach (['confirmed' => TRUE, 'unconfirmed' => FALSE] as $key => $confirmed) {
      $application = $etm->getStorage('application')->create([
        'uid' => $service->id(),
        'field_schema' => $form->id(),
        'field_workflow_status' => $status->id(),
        'field_jurisdiction' => $this->jurisdiction->id(),
        'field_group' => $this->jurisdiction->id(),
        'field_data' => '{"name":"Synthetic Citizen"}',
        'field_applicant_email' => self::EMAIL,
        'field_email_confirmed' => $confirmed,
        'field_public_status_token_hash' => hash('sha256', 'synthetic-' . $key),
        'created' => self::CREATED,
      ]);
      $application->save();
      $public[$key] = (int) $application->id();
    }
    $this->setCurrentUser($this->citizen);
    $before = $this->reload('application', $public['confirmed']);
    $this->assertInstanceOf(ApplicationInterface::class, $before);

    $preview = $this->decode(GdprController::create($this->container)->preview());
    $this->assertSame(1, $preview['deletes']['unconfirmedApplications']);
    $this->assertSame([$before->uuid()], array_column($preview['keeps'], 'id'));

    $response = $this->erase();
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertNull($this->reload('application', $public['unconfirmed']));
    $after = $this->reload('application', $public['confirmed']);
    $this->assertInstanceOf(ApplicationInterface::class, $after);
    $this->assertSame((int) $service->id(), (int) $after->getOwnerId());
    $this->assertSame($before->get('field_data')->value, $after->get('field_data')->value);
    $this->assertSame(self::EMAIL, $after->get('field_applicant_email')->value);
    $this->assertSame((int) $before->getRevisionId(), (int) $after->getRevisionId());
    $this->assertInstanceOf(UserInterface::class, $this->reload('user', (int) $service->id()));
  }

  /**
   * Without a retention rule the preview reports no deletion date.
   */
  public function testPreviewWithoutRetentionRuleHasNoDate(): void {
    $this->setCurrentUser($this->operator);
    $this->jurisdiction->set('field_jur_config', json_encode(['general' => []], JSON_THROW_ON_ERROR))->save();
    $this->setCurrentUser($this->citizen);
    $this->seedCitizen();

    $kept = $this->decode(GdprController::create($this->container)->preview())['keeps'][0];
    $this->assertSame(0, $kept['retention']['days']);
    $this->assertSame('global', $kept['retention']['source']);
    $this->assertNull($kept['retention']['deleteAfter']);
    $this->assertNull($kept['stelle']['contact']['email']);
  }

  /**
   * Seeds the citizen's account data, one submitted and one unconfirmed case.
   *
   * @return array<string, mixed>
   *   Fixture handles.
   */
  private function seedCitizen(): array {
    $this->setCurrentUser($this->operator);
    $etm = $this->entityTypeManager();
    $status = $etm->getStorage('workflow_status')->create([
      'label' => 'Received',
      'field_group' => $this->jurisdiction->id(),
      'field_machine_name' => 'received',
      'field_is_default' => TRUE,
    ]);
    $status->save();
    $form = $etm->getStorage('form_schema')->create([
      'label' => 'Synthetic form',
      'field_schema' => '{"sections":[]}',
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_group' => $this->jurisdiction->id(),
    ]);
    $form->save();

    $submitted = $this->application($form->id(), $status->id(), TRUE);
    $unconfirmed = $this->application($form->id(), $status->id(), FALSE);

    $directory = 'private://application_attachments';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri = $directory . '/synthetic-proof.pdf';
    file_put_contents($uri, "%PDF-1.4\n%%EOF\n");
    $file = $etm->getStorage('file')->create([
      'uri' => $uri,
      'filename' => 'synthetic-proof.pdf',
      'filemime' => 'application/pdf',
      'status' => 1,
      'uid' => $this->citizen->id(),
    ]);
    $file->save();
    $attachment = $etm->getStorage('application_attachment')->create([
      'uid' => $this->citizen->id(),
      'field_schema' => $form->id(),
      'field_application' => $submitted->id(),
      'field_field_id' => 'proof',
      'field_file' => $file->id(),
      'field_upload_status' => 'uploaded',
      'field_scan_status' => 'clean',
      'field_original_filename' => 'synthetic-proof.pdf',
    ]);
    $attachment->save();

    // Upload of an abandoned draft: never attached to an application.
    $draft_uri = $directory . '/synthetic-draft-scan.pdf';
    file_put_contents($draft_uri, "%PDF-1.4\n%%EOF\n");
    $draft_file = $etm->getStorage('file')->create([
      'uri' => $draft_uri,
      'filename' => 'synthetic-draft-scan.pdf',
      'filemime' => 'application/pdf',
      'status' => 1,
      'uid' => $this->citizen->id(),
    ]);
    $draft_file->save();
    $draft_upload = $etm->getStorage('application_attachment')->create([
      'uid' => $this->citizen->id(),
      'field_schema' => $form->id(),
      'field_field_id' => 'proof',
      'field_file' => $draft_file->id(),
      'field_upload_status' => 'uploaded',
      'field_scan_status' => 'clean',
      'field_original_filename' => 'synthetic-draft-scan.pdf',
    ]);
    $draft_upload->save();

    $comments = $etm->getStorage('application_comment');
    $citizen_comment = $comments->create([
      'uid' => $this->citizen->id(),
      'field_application' => $submitted->id(),
      'field_body' => 'Synthetic question',
      'field_author_role' => 'citizen',
      'field_visibility' => 'public',
    ]);
    $citizen_comment->save();
    $staff_comment = $comments->create([
      'uid' => $this->neighbour->id(),
      'field_application' => $submitted->id(),
      'field_body' => 'Synthetic decision note',
      'field_author_role' => 'staff',
      'field_visibility' => 'public',
    ]);
    $staff_comment->save();
    $unconfirmed_comment = $comments->create([
      'uid' => $this->citizen->id(),
      'field_application' => $unconfirmed->id(),
      'field_body' => 'Synthetic draft note',
      'field_author_role' => 'citizen',
      'field_visibility' => 'public',
    ]);
    $unconfirmed_comment->save();

    $profiles = $etm->getStorage('applicant');
    $own_profile = $profiles->create([
      'uid' => $this->citizen->id(),
      'field_email' => self::EMAIL,
      'field_full_name' => 'Synthetic Citizen',
      'field_consent_time' => self::CREATED,
      'field_consent_text_version' => '2026-10-20.3',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'status' => TRUE,
    ]);
    $own_profile->save();
    $email_profile = $profiles->create([
      'uid' => 0,
      'field_email' => self::EMAIL,
      'field_full_name' => 'Synthetic Citizen',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'status' => TRUE,
    ]);
    $email_profile->save();
    $neighbour_profile = $profiles->create([
      'uid' => $this->neighbour->id(),
      'field_email' => 'neighbour@example.test',
      'field_full_name' => 'Synthetic Neighbour',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'status' => TRUE,
    ]);
    $neighbour_profile->save();
    // Another account's profile that carries the citizen's address, for
    // example after an administrator changed an account address.
    $foreign_email_profile = $profiles->create([
      'uid' => $this->neighbour->id(),
      'field_email' => self::EMAIL,
      'field_full_name' => 'Synthetic Neighbour',
      'field_jurisdiction' => ['target_id' => $this->jurisdiction->id()],
      'status' => TRUE,
    ]);
    $foreign_email_profile->save();

    $database = $this->container->get('database');
    foreach ([(int) $this->citizen->id() => 'citizen', (int) $this->neighbour->id() => 'neighbour'] as $uid => $subject) {
      $database->insert('beantragt_sso_identities')->fields([
        'provider' => 'synthetic_idp',
        'subject_hash' => hash_hmac('sha256', "synthetic_idp\n" . $subject, Settings::getHashSalt()),
        'uid' => $uid,
        'created' => self::CREATED,
        'changed' => self::CREATED,
      ])->execute();
    }
    $database->insert('beantragt_passwordless_codes')->fields([
      'email' => self::EMAIL,
      'code' => 'synthetic-hash',
      'created' => self::CREATED,
      'expires' => self::CREATED + 600,
    ])->execute();

    $this->setCurrentUser($this->citizen);
    return [
      'submitted' => $submitted,
      'unconfirmed' => $unconfirmed,
      'attachment' => (int) $attachment->id(),
      'file' => (int) $file->id(),
      'citizenComment' => (int) $citizen_comment->id(),
      'staffComment' => (int) $staff_comment->id(),
      'unconfirmedComment' => (int) $unconfirmed_comment->id(),
      'ownProfile' => (int) $own_profile->id(),
      'emailProfile' => (int) $email_profile->id(),
      'neighbourProfile' => (int) $neighbour_profile->id(),
      'foreignEmailProfile' => (int) $foreign_email_profile->id(),
      'draftUpload' => (int) $draft_upload->id(),
      'draftFile' => (int) $draft_file->id(),
      'draftFileUri' => $draft_uri,
    ];
  }

  /**
   * Saves an application owned by the citizen.
   */
  private function application(int|string $form_id, int|string $status_id, bool $confirmed): ApplicationInterface {
    $application = $this->entityTypeManager()->getStorage('application')->create([
      'uid' => $this->citizen->id(),
      'field_schema' => $form_id,
      'field_workflow_status' => $status_id,
      'field_jurisdiction' => $this->jurisdiction->id(),
      'field_group' => $this->jurisdiction->id(),
      'field_data' => '{"name":"Synthetic Citizen"}',
      'field_applicant_email' => self::EMAIL,
      'field_email_confirmed' => $confirmed,
      'created' => self::CREATED,
    ]);
    $application->save();
    $this->assertInstanceOf(ApplicationInterface::class, $application);
    return $application;
  }

  /**
   * Calls the erasure endpoint as the current user.
   */
  private function erase(): JsonResponse {
    $request = Request::create('/auth/gdpr/all', 'DELETE');
    $request->setSession($this->container->get('request_stack')->getCurrentRequest()->getSession());
    $request->headers->set('X-CSRF-Token', $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY));
    return GdprController::create($this->container)->deleteAll($request);
  }

  /**
   * Loads an entity bypassing every cache.
   */
  private function reload(string $entity_type_id, int $id): ?object {
    $storage = $this->entityTypeManager()->getStorage($entity_type_id);
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * Counts revisions of an application.
   */
  private function revisionCount(int $id): int {
    return (int) $this->container->get('database')->select('application_revision')->condition('id', $id)->countQuery()->execute()->fetchField();
  }

  /**
   * Counts SSO identity rows of an account.
   */
  private function ssoRows(int $uid): int {
    return (int) $this->container->get('database')->select('beantragt_sso_identities')->condition('uid', $uid)->countQuery()->execute()->fetchField();
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   Decoded body.
   */
  private function decode(JsonResponse $response): array {
    return json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
  }

}
