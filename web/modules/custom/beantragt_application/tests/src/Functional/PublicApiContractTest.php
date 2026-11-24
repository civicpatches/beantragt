<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Functional;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ProtectedAccessLog;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises public v1 HTTP contracts on an isolated canonical starter fixture.
 */
#[Group('beantragt_application')]
#[Group('api_contract')]
final class PublicApiContractTest extends BrowserTestBase {

  use GroupTestTrait;

  private const MAILBOX_ADDRESS = 'api-contract+follow-up@example.org';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'datetime', 'file', 'jsonapi', 'json_form_widget', 'serialization',
    'beantragt_schema', 'beantragt_workflow_status', 'beantragt_application',
    'beantragt_group', 'beantragt_status', 'beantragt_notification', 'beantragt_content',
    'beantragt_passwordless', 'beantragt_comment', 'beantragt_delivery', 'beantragt_application_api_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Discovery, validation, confirmation, idempotency and access work over HTTP.
   */
  public function testPublicSubmissionContractWithoutFrontend(): void {
    require_once DRUPAL_ROOT . '/../scripts/lib/starter-form-catalog.php';
    $this->createSubmissionOwner();
    $definition = array_values(array_filter(
      beantragt_starter_form_catalog(),
      static fn(array $form): bool => $form['key'] === 'business-registration',
    ))[0];
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'API contract municipality',
      'field_slug' => 'api-contract',
      'status' => TRUE,
    ]);
    $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Received',
      'field_group' => ['target_id' => $jurisdiction->id()],
      'field_machine_name' => 'received',
      'field_is_default' => TRUE,
      'field_semantic_flags' => [['value' => 'received']],
    ])->save();
    $this->config('beantragt_group.settings')
      ->set('frontend_base_url', $this->baseUrl)
      ->save();
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => $definition['title'],
      'field_schema' => Json::encode([
        'title' => $definition['title'],
        'sections' => $definition['sections'],
        'auth' => ['mode' => 'optional', 'collect' => ['fullName' => 'required', 'email' => 'required']],
      ]),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'internet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_group' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);
    $schema->save();
    $this->entityTypeManager()->getStorage('filter_format')->create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => ['filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<p> <strong>']]],
    ])->save();
    $content_section = $this->entityTypeManager()->getStorage('node')->create([
      'type' => 'content_section',
      'title' => 'API instructions',
      'status' => TRUE,
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_form_schema' => ['target_id' => $schema->id()],
      'field_content_placement' => 'form_intro',
      'body' => [
        'value' => '<p>API <strong>instructions</strong></p><script>alert(1)</script>',
        'format' => 'basic_html',
      ],
    ]);
    $content_section->save();

    $catalog = $this->getJson('/api/v1/jurisdictions/api-contract/forms');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('1.0', $catalog['meta']['apiVersion']);
    $this->assertCount(1, $catalog['data']);
    $this->assertSame($schema->uuid(), $catalog['data'][0]['id']);
    $this->assertNull($catalog['data'][0]['organisation']);

    $form = $this->getJson('/api/v1/forms/' . $schema->uuid() . '?jurisdiction=api-contract');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(count($definition['sections']), $form['data']['schema']['sections']);
    foreach ($definition['sections'] as $index => $section) {
      $projected = $form['data']['schema']['sections'][$index];
      $this->assertSame($section['id'], $projected['id']);
      $this->assertSame(array_column($section['fields'], 'id'), array_column($projected['fields'], 'id'));
      $this->assertSame(array_column($section['fields'], 'required'), array_column($projected['fields'], 'required'));
    }
    $this->getJson('/api/v1/forms/' . $schema->uuid() . '?jurisdiction=foreign-tenant');
    $this->assertSession()->statusCodeEquals(404);

    $sections = $this->getJson('/api/v1/jurisdictions/api-contract/sections?form=' . $schema->uuid() . '&placement=form_intro');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCount(1, $sections['data']);
    $this->assertSame($content_section->uuid(), $sections['data'][0]['id']);
    $section_body = $sections['data'][0]['attributes']['body'];
    $this->assertSame('', $section_body['value']);
    $this->assertSame('basic_html', $section_body['format']);
    $this->assertStringContainsString('<strong>instructions</strong>', $section_body['processed']);
    $this->assertStringNotContainsString('<script', $section_body['processed']);

    $payload = [
      'data' => [
        'type' => 'application',
        'attributes' => [
          'formId' => $schema->uuid(),
          'consentAccepted' => TRUE,
          'identity' => ['fullName' => 'API Contract Applicant', 'email' => strtoupper(self::MAILBOX_ADDRESS)],
          'data' => [
            'full_name' => 'API Contract Applicant',
            'email' => strtoupper(self::MAILBOX_ADDRESS),
            'street_address' => 'Example Street 1',
            'postal_code' => '12345',
            'city' => 'Example City',
            'legal_form' => 'Einzelunternehmen',
            'business_name' => 'Synthetic API Business',
            'business_address' => 'Example Street 1',
            'activity_description' => 'Synthetic software test activity only.',
            'business_start_date' => '2026-10-01',
            'activity_scope' => 'Haupterwerb',
          ],
        ],
      ],
    ];
    $invalid = $payload;
    $invalid['data']['attributes']['consentAccepted'] = FALSE;
    $error = $this->postJson('/api/v1/applications', $invalid);
    $this->assertSession()->statusCodeEquals(400);
    $this->assertSame('consent.required', $error['error']['code']);
    $this->assertPrivateResponse();

    $created = $this->postJson('/api/v1/applications', $payload, [
      'Idempotency-Key' => 'api-contract-submission-000000000001',
    ]);
    $this->assertSame(201, $this->getSession()->getStatusCode(), Json::encode($created));
    $this->assertSame('1.0', $created['meta']['apiVersion']);
    $this->assertSame($schema->uuid(), $created['data']['formId']);
    $this->assertTrue($created['confirmation']['required']);
    $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $created['data']['id']);
    $this->assertPrivateResponse();

    $application_count = (int) $this->entityTypeManager()->getStorage('application')->getQuery()->accessCheck(FALSE)->count()->execute();
    $this->assertSame(1, $application_count);
    $mail_count = count($this->container->get('state')->get('system.test_mail_collector', []));
    $this->assertSame(1, $mail_count, get_class($this->container->get('plugin.manager.mail')));
    $replayed = $this->postJson('/api/v1/applications', $payload, [
      'Idempotency-Key' => 'api-contract-submission-000000000001',
    ]);
    $this->assertSession()->statusCodeEquals(201);
    $this->assertSame($created['data'], $replayed['data']);
    $this->assertSession()->responseHeaderEquals('Idempotency-Replayed', 'true');
    $this->assertSame($application_count, (int) $this->entityTypeManager()->getStorage('application')->getQuery()->accessCheck(FALSE)->count()->execute());
    $this->assertCount($mail_count, $this->container->get('state')->get('system.test_mail_collector', []));
    $changed = $payload;
    $changed['data']['attributes']['data']['business_name'] = 'Different payload';
    $conflict = $this->postJson('/api/v1/applications', $changed, [
      'Idempotency-Key' => 'api-contract-submission-000000000001',
    ]);
    $this->assertSession()->statusCodeEquals(409);
    $this->assertSame('idempotency.conflict', $conflict['error']['code']);
    $this->assertSame($application_count, (int) $this->entityTypeManager()->getStorage('application')->getQuery()->accessCheck(FALSE)->count()->execute());
    $this->assertCount($mail_count, $this->container->get('state')->get('system.test_mail_collector', []));

    $messages = $this->container->get('state')->get('system.test_mail_collector', []);
    $confirmation_token = NULL;
    foreach ($messages as $message) {
      if (preg_match('~/confirm/([A-Za-z0-9_-]{32,96})~', $message['body'], $match) === 1) {
        $confirmation_token = $match[1];
      }
    }
    $this->assertNotNull($confirmation_token, 'Submission sends a confirmation token through Drupal mail.');
    $confirmed = $this->postJson('/api/v1/public/applications/confirm', ['token' => $confirmation_token]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('confirmed', $confirmed['status']);
    $status = $this->getJson($created['data']['statusUrl'] . '&jurisdiction=api-contract');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame($created['data']['referenceNumber'], $status['data']['referenceNumber']);
    $this->assertStringNotContainsString(self::MAILBOX_ADDRESS, strtolower(Json::encode($status)));
    $this->assertArrayNotHasKey('data', $status['data']);
    $this->assertPrivateResponse();

    foreach (['/api/v1/applications?jurisdiction=api-contract', '/api/v1/staff/applications?jurisdiction=api-contract'] as $path) {
      $denied = $this->getJson($path);
      $this->assertSession()->statusCodeEquals(401);
      $this->assertSame('auth.required', $denied['error']['code']);
      $this->assertPrivateResponse();
    }

    $pending = $this->postJson('/api/v1/applications', $payload);
    $this->assertSession()->statusCodeEquals(201);
    $this->assertTrue($pending['confirmation']['required']);
    $this->assertInstanceOf(FormSchemaInterface::class, $schema);
    $this->assertConfirmedMailboxAccess($schema, $created['data']['id'], $pending['data']['id']);
  }

  /**
   * Checks the receipt, mailbox access, and revocation through Drupal HTTP.
   */
  private function assertConfirmedMailboxAccess(FormSchemaInterface $schema, string $confirmed, string $pending): void {
    $owner_address = self::MAILBOX_ADDRESS;
    $folded_address = 'api-contract@example.org';
    $edited_address = 'edited-contact@example.org';
    $messages = $this->container->get('state')->get('system.test_mail_collector', []);
    $receipt_path = '/api-contract/meine-antraege/' . $confirmed;
    $this->assertStringContainsString($receipt_path, implode("\n", array_column($messages, 'body')));

    $permissions = [
      'view own application entities',
      'view application_comment entities',
      'create application_comment entities',
    ];
    $owner = $this->createUser($permissions, 'mailbox-owner', FALSE, ['mail' => $owner_address]);
    $this->createUser($permissions, 'mailbox-other', FALSE, ['mail' => $edited_address]);
    $this->createUser($permissions, 'mailbox-without-tag', FALSE, ['mail' => $folded_address]);
    $this->drupalLogin($owner);
    $this->assertCitizenReads([], [$confirmed, $pending]);
    $this->assertFollowUpDenied($confirmed);
    $this->assertMailboxAudit([]);
    $this->drupalLogout();

    // A change to the protected contact field updates its lookup hash without
    // changing the address proven by the earlier confirmation.
    $storage = $this->entityTypeManager()->getStorage('application');
    $matches = $storage->loadByProperties(['uuid' => $confirmed]);
    $application = reset($matches);
    $this->assertInstanceOf(ApplicationInterface::class, $application);
    $this->assertTrue($application->isEmailConfirmed());
    $this->assertFalse($application->get('field_email_confirmation_token_hash')->isEmpty());
    $this->assertGreaterThan(0, (int) $application->get('field_email_confirmed_time')->value);
    $service_owner_id = $application->getOwnerId();
    $this->assertTrue($application->get('field_applicant_email')->isEmpty());
    $data_protection = $this->container->get('beantragt_application.data_protection');
    $this->assertInstanceOf(ApplicationDataProtectionService::class, $data_protection);
    $data = $data_protection->revealDataJson($application->getData());
    $data['email'] = $edited_address;
    $application->set('field_data', Json::encode($data));
    $application->refreshCrmDataFromSchema(Json::encode($data), $schema);
    $application->save();
    $this->assertSame($data_protection->applicantEmailLookupHash($edited_address), $application->get('field_applicant_email_hash')->value);

    $this->loginByMail($edited_address);
    $this->assertCitizenReads([], [$confirmed, $pending]);
    $this->assertDetailResources($application, 404);
    $this->assertFollowUpDenied($confirmed);
    $this->drupalLogout();

    // The same address without its plus tag is a distinct verified mailbox.
    $this->loginByMail($folded_address);
    $this->assertCitizenReads([], [$confirmed, $pending]);
    $this->assertFollowUpDenied($confirmed);
    $this->assertMailboxAudit([]);
    $this->drupalLogout();

    $this->loginByMail($owner_address);
    $this->assertSame($owner_address, $this->container->get('beantragt_passwordless.verified_mail')->getVerifiedEmail($owner));
    $mailbox = $this->container->get('beantragt_application.confirmed_mailbox');
    $this->assertSame([(int) $application->id()], $mailbox->matchingApplicationIds($owner, $confirmed));
    $this->assertTrue($mailbox->allowsRead($application, $owner));
    $this->assertCitizenReads([$confirmed], [$pending]);
    $audit_row = [(int) $owner->id(), $confirmed, '', 'verified_mailbox_read'];
    $this->assertMailboxAudit(array_fill(0, 4, $audit_row));
    $this->assertDetailResources($application, 200);
    $this->assertMailboxAudit(array_fill(0, 6, $audit_row));
    $this->assertStatusHashHidden($application);
    $this->assertFollowUpDenied($pending);
    $this->assertPublicFollowUp($application);

    // A staff role outside this workspace still only grants citizen visibility
    // when the application is reached through mailbox proof on the proxy.
    if (!Role::load('case_worker')) {
      $this->createRole([], 'case_worker', 'Case worker');
    }
    $owner->addRole('case_worker');
    $owner->save();
    $resource = '/jsonapi/application/application';
    $hash = $application->get('field_public_status_token_hash')->value;
    $error = Json::decode($this->drupalGet($resource, [
      'query' => ['filter' => ['field_public_status_token_hash' => $hash]],
    ], ['Accept' => 'application/vnd.api+json']));
    $this->assertSession()->statusCodeEquals(403);
    $this->assertStringContainsString('not authorized to filter', $error['errors'][0]['detail']);

    $proxy_path = '/api/comments/' . $confirmed;
    $comments = $this->getJson($proxy_path);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertNotContains('Internal staff note', array_column($comments['data'], 'body'));
    $reply = $this->postJson($proxy_path, [
      'body' => 'Reply from the verified mailbox outside the staff scope.',
      'visibility' => 'internal',
    ], ['X-CSRF-Token' => $this->getCsrfToken()]);
    $this->assertSession()->statusCodeEquals(201);
    $this->assertSame('citizen', $reply['data']['authorRole']);
    $this->assertSame('public', $reply['data']['visibility']);
    $owner->removeRole('case_worker');
    $owner->save();

    // Four writes and two comment reads above, plus two proxy requests with
    // the out-of-scope staff role, were authorized using the same mailbox.
    $this->assertMailboxAudit(array_fill(0, 14, $audit_row));
    $this->assertSame($service_owner_id, $storage->loadUnchanged($application->id())->getOwnerId());

    // The same browser session loses mailbox access after the account changes.
    $owner->setEmail('changed-account@example.org');
    $owner->save();
    $this->assertCitizenReads([], [$confirmed, $pending]);
    $this->assertDetailResources($application, 404);
    $this->assertFollowUpDenied($confirmed);
    $this->drupalLogout();
    $this->loginByMail('changed-account@example.org');
    $this->assertCitizenReads([], [$confirmed, $pending]);
    $this->assertFollowUpDenied($confirmed);
    $this->assertMailboxAudit(array_fill(0, 14, $audit_row));
  }

  /**
   * Checks that JSON:API exposes the application without the status token hash.
   */
  private function assertStatusHashHidden(ApplicationInterface $application): void {
    $this->assertFalse($application->get('field_public_status_token_hash')->isEmpty());
    $document = Json::decode($this->drupalGet(
      '/jsonapi/application/application/' . $application->uuid(),
      [],
      ['Accept' => 'application/vnd.api+json'],
    ));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame($application->uuid(), $document['data']['id']);
    $attributes = $document['data']['attributes'];
    $this->assertSame($application->get('field_reference_number')->value, $attributes['field_reference_number']);
    $this->assertArrayNotHasKey('field_public_status_token_hash', $attributes);
  }

  /**
   * Checks that otherwise valid comment and upload requests are denied.
   */
  private function assertFollowUpDenied(string $uuid): void {
    $csrf = $this->getCsrfToken();
    $proxy = '/api/comments/' . $uuid;
    $v1 = '/api/v1/applications/' . $uuid;
    $scope = '?jurisdiction=api-contract';
    $headers = ['X-CSRF-Token' => $csrf];
    $this->postJson($proxy, ['body' => 'Denied reply.'], $headers);
    $this->assertSession()->statusCodeEquals(404);
    $this->postJson($v1 . '/comments' . $scope, $this->v1Reply(['body' => 'Denied reply.']), $headers);
    $this->assertSession()->statusCodeEquals(404);
    foreach ([$proxy . '/upload', $v1 . '/comment-attachments' . $scope] as $upload_path) {
      $this->uploadFollowUp($upload_path, $csrf);
      $this->assertSession()->statusCodeEquals(404);
    }
  }

  /**
   * Uploads and sends replies on both routes and verifies public visibility.
   */
  private function assertPublicFollowUp(ApplicationInterface $application): void {
    $question = 'Please provide the supporting document.';
    $storage = $this->entityTypeManager()->getStorage('application_comment');
    foreach (['public' => $question, 'internal' => 'Internal staff note'] as $visibility => $body) {
      $storage->create([
        'field_application' => $application->id(),
        'field_body' => $body,
        'field_author_role' => 'staff',
        'field_visibility' => $visibility,
        'status' => 1,
      ])->save();
    }

    $csrf = $this->getCsrfToken();
    $proxy = '/api/comments/' . $application->uuid();
    $v1 = '/api/v1/applications/' . $application->uuid();
    $scope = '?jurisdiction=api-contract';
    $legacy_upload = $this->uploadFollowUp($proxy . '/upload', $csrf);
    $this->assertSession()->statusCodeEquals(201);
    $this->assertSame('follow-up.png', $legacy_upload['data']['filename']);
    $v1_upload = $this->uploadFollowUp($v1 . '/comment-attachments' . $scope, $csrf);
    $this->assertSession()->statusCodeEquals(201);
    $this->assertSame('follow-up.png', $v1_upload['data']['filename']);
    $this->assertSame($application->uuid(), $v1_upload['data']['applicationId']);

    $proxy_reply = 'Supporting document via the portal.';
    $v1_reply = 'Supporting document via v1.';
    $headers = ['X-CSRF-Token' => $csrf];
    $reply = $this->postJson($proxy, [
      'body' => $proxy_reply,
      'visibility' => 'internal',
      'attachment_ids' => [$legacy_upload['data']['file_id']],
    ], $headers);
    $this->assertSession()->statusCodeEquals(201);
    $this->assertSame('citizen', $reply['data']['authorRole']);
    $this->assertSame('public', $reply['data']['visibility']);
    $reply = $this->postJson($v1 . '/comments' . $scope, $this->v1Reply([
      'body' => $v1_reply,
      'visibility' => 'internal',
      'attachments' => [
        [
          'attachmentId' => $v1_upload['data']['id'],
          'claimToken' => $v1_upload['data']['claimToken'],
        ],
      ],
    ]), $headers);
    $this->assertSession()->statusCodeEquals(201);
    $this->assertSame('citizen', $reply['data']['authorRole']);
    $this->assertSame('public', $reply['data']['visibility']);

    foreach ([$proxy, $v1 . '/comments' . $scope] as $path) {
      $comments = $this->getJson($path);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertEqualsCanonicalizing([$question, $proxy_reply, $v1_reply], array_column($comments['data'], 'body'));
      $by_body = array_column($comments['data'], NULL, 'body');
      foreach ([$proxy_reply, $v1_reply] as $body) {
        $this->assertSame('public', $by_body[$body]['visibility']);
        $this->assertSame(['follow-up.png'], array_column($by_body[$body]['attachments'], 'filename'));
      }
    }
  }

  /**
   * Builds a public v1 comment request body.
   */
  private function v1Reply(array $attributes): array {
    return ['data' => ['type' => 'application_comment', 'attributes' => $attributes]];
  }

  /**
   * Retrieves the CSRF token for the current browser session.
   */
  private function getCsrfToken(): string {
    $token = $this->drupalGet('/session/token', [], ['Accept' => 'text/plain']);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertNotSame('', $token);
    return $token;
  }

  /**
   * Sends a multipart attachment using the authenticated browser session.
   */
  private function uploadFollowUp(string $path, string $csrf): array {
    $file = DRUPAL_ROOT . '/core/tests/fixtures/files/image-1.png';
    $this->getSession()->getDriver()->getClient()->request('POST', $this->getAbsoluteUrl($path), [], [
      'file' => ['tmp_name' => $file, 'name' => 'follow-up.png'],
    ], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf]);
    return Json::decode($this->getSession()->getPage()->getContent());
  }

  /**
   * Checks the related resources loaded when the citizen opens the detail.
   */
  private function assertDetailResources(ApplicationInterface $application, int $status): void {
    $paths = [
      '/api/comments/' . $application->uuid(),
      '/api/applications/' . $application->id() . '/responses',
    ];
    foreach ($paths as $path) {
      $this->drupalGet($path, [], ['Accept' => 'application/json']);
      $this->assertSession()->statusCodeEquals($status);
    }
  }

  /**
   * Starts a cookie session with a code from the captured verification mail.
   */
  private function loginByMail(string $email): void {
    $challenge = $this->postJson('/auth/request-code', ['email' => $email]);
    $this->assertSession()->statusCodeEquals(200);
    $messages = $this->container->get('state')->get('system.test_mail_collector', []);
    $message = end($messages);
    $this->assertSame($email, $message['to']);
    $this->assertSame('beantragt_passwordless_verification_code', $message['id']);
    $login = $this->postJson('/auth/verify-code', [
      'email' => $email,
      'code' => $message['params']['code'],
      'challengeId' => $challenge['challengeId'],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($login['success']);
  }

  /**
   * Checks the citizen proxy contract and the public v1 HTTP contract.
   */
  private function assertCitizenReads(array $visible, array $hidden): void {
    $scope = ['jurisdiction' => 'api-contract'];
    foreach (['/api/citizen/applications' => 'applications', '/api/v1/applications' => 'data'] as $route => $key) {
      $listing = $this->getJson($route . '?' . http_build_query($scope));
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSame($visible, array_column($listing[$key], 'id'));
      $this->assertPrivateResponse();
      foreach ($visible as $uuid) {
        $detail = $this->getJson($route . '/' . $uuid . '?' . http_build_query($scope));
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSame($uuid, $detail[$key === 'data' ? 'data' : 'application']['id']);
        $this->assertPrivateResponse();
      }
      foreach ($hidden as $uuid) {
        $this->drupalGet($route . '/' . $uuid, ['query' => $scope], ['Accept' => 'application/json']);
        $this->assertSession()->statusCodeEquals(404);
      }
    }
  }

  /**
   * Checks that audit rows contain only identifiers and event metadata.
   */
  private function assertMailboxAudit(array $expected): void {
    $rows = $this->container->get('database')->select(ProtectedAccessLog::TABLE, 'audit')
      ->fields('audit', ['uid', 'subject_uuid', 'reference', 'channel'])
      ->condition('subject_type', 'application_mailbox')
      ->orderBy('id')
      ->execute();
    $actual = [];
    foreach ($rows as $row) {
      $actual[] = [(int) $row->uid, $row->subject_uuid, $row->reference, $row->channel];
    }
    $this->assertSame($expected, $actual);
  }

  /**
   * Sends a new link when the supplemental address is stored only as a hash.
   */
  public function testResendWithProtectedApplicationAndSupplementalEmail(): void {
    $this->createSubmissionOwner();
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Confirmation test workspace',
      'field_slug' => 'confirmation-test',
      'status' => TRUE,
    ]);
    $this->entityTypeManager()->getStorage('workflow_status')->create([
      'label' => 'Received',
      'field_group' => ['target_id' => $jurisdiction->id()],
      'field_machine_name' => 'received',
      'field_is_default' => TRUE,
      'field_semantic_flags' => [['value' => 'received']],
    ])->save();
    $this->config('beantragt_group.settings')
      ->set('frontend_base_url', $this->baseUrl)
      ->save();
    $schema = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Protected confirmation test',
      'field_schema' => Json::encode([
        'title' => 'Protected confirmation test',
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Details',
            'fields' => [
              [
                'id' => 'protected_note',
                'type' => 'text',
                'label' => 'Protected note',
                'sensitive' => TRUE,
              ],
            ],
          ],
        ],
        'auth' => ['mode' => 'optional', 'collect' => ['fullName' => 'required', 'email' => 'required']],
      ]),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'internet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'field_group' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ]);
    $schema->save();

    $email = 'protected-confirmation@example.org';
    $protected_note = 'synthetic protected fact';
    $created = $this->postJson('/api/v1/applications', [
      'data' => [
        'type' => 'application',
        'attributes' => [
          'formId' => $schema->uuid(),
          'consentAccepted' => TRUE,
          'identity' => ['fullName' => 'Test Applicant', 'email' => $email],
          'data' => ['protected_note' => $protected_note],
        ],
      ],
    ]);
    $this->assertSame(201, $this->getSession()->getStatusCode(), Json::encode($created));
    $this->assertTrue($created['confirmation']['required']);
    $storage = $this->entityTypeManager()->getStorage('application');
    $applications = $storage->loadByProperties(['uuid' => $created['data']['id']]);
    $application = reset($applications);
    $this->assertInstanceOf(ApplicationInterface::class, $application);
    $this->assertTrue($application->get('field_applicant_email')->isEmpty());
    $this->assertFalse($application->get('field_applicant_email_hash')->isEmpty());
    $this->assertStringNotContainsString($email, (string) $application->getData());
    $this->assertStringNotContainsString($protected_note, (string) $application->getData());
    $old_hash = $application->get('field_email_confirmation_token_hash')->value;

    $state = $this->container->get('state');
    $mail_count = count($state->get('system.test_mail_collector', []));
    $reference = $created['data']['referenceNumber'];
    $path = '/api/v1/public/applications/confirm/resend';
    $accepted = ['status' => 'accepted'];
    foreach ([
      ['reference' => $reference, 'email' => 'wrong@example.org'],
      ['reference' => 'UNKNOWN-2026-00001', 'email' => $email],
    ] as $pair) {
      $this->assertSame($accepted, $this->postJson($path, $pair));
      $this->assertSession()->statusCodeEquals(200);
      $this->assertPrivateResponse();
    }
    $this->assertCount($mail_count, $state->get('system.test_mail_collector', []));
    $this->assertSame($old_hash, $storage->loadUnchanged($application->id())->get('field_email_confirmation_token_hash')->value);

    $pair = ['reference' => $reference, 'email' => strtoupper($email)];
    $this->assertSame($accepted, $this->postJson($path, $pair));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertPrivateResponse();
    $this->assertNotSame($old_hash, $storage->loadUnchanged($application->id())->get('field_email_confirmation_token_hash')->value);
    $mails = $state->get('system.test_mail_collector', []);
    $this->assertCount($mail_count + 1, $mails, 'Resend delivers one additional confirmation mail.');
    $this->assertSame(1, $mail_count, 'Initial submission also delivers a confirmation mail.');
    foreach ($mails as $message) {
      $this->assertSame('beantragt_notification_application_confirmation_request', $message['id']);
      $this->assertSame($email, $message['to']);
      $this->assertStringNotContainsString($protected_note, $message['body']);
    }
    $this->assertSame(1, preg_match('~/confirm/([A-Za-z0-9_-]{64})~', $mails[0]['body'], $old_link));
    $this->assertSame(1, preg_match('~/confirm/([A-Za-z0-9_-]{64})~', $mails[1]['body'], $new_link));

    $this->assertSame(['error' => ['code' => 'rate_limit.exceeded']], $this->postJson($path, $pair));
    $this->assertSession()->statusCodeEquals(429);
    $this->assertPrivateResponse();
    $this->assertCount(2, $state->get('system.test_mail_collector', []));
    $confirm_path = '/api/v1/public/applications/confirm';
    $this->assertSame(['error' => ['code' => 'confirmation.invalid']], $this->postJson($confirm_path, ['token' => $old_link[1]]));
    $this->assertSession()->statusCodeEquals(404);
    $confirmed = $this->postJson($confirm_path, ['token' => $new_link[1]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame('confirmed', $confirmed['status']);
    $this->assertSame($reference, $confirmed['referenceNumber']);
  }

  /**
   * Provides the service account configured for the public submission owner.
   */
  private function createSubmissionOwner(): void {
    $service_user = trim((string) getenv('NUXT_DRUPAL_SERVICE_USER'));
    $this->assertNotSame('', $service_user, 'NUXT_DRUPAL_SERVICE_USER must be set for the test runner and the test web server.');
    if (!$this->entityTypeManager()->getStorage('user')->loadByProperties(['name' => $service_user])) {
      if (!Role::load('service_account')) {
        $this->createRole([], 'service_account', 'Service account');
      }
      $this->createUser([], $service_user, FALSE, ['roles' => ['service_account']]);
    }
  }

  /**
   * Gets an endpoint through the real Drupal HTTP kernel.
   */
  private function getJson(string $path): array {
    $parts = parse_url($path);
    parse_str($parts['query'] ?? '', $query);
    return Json::decode($this->drupalGet($parts['path'], ['query' => $query], ['Accept' => 'application/json']));
  }

  /**
   * Posts JSON through the same HTTP driver used by functional browser tests.
   */
  private function postJson(string $path, array $payload, array $headers = []): array {
    $this->getSession()->getDriver()->getClient()->request('POST', $this->getAbsoluteUrl($path), [], [], [
      'HTTP_ACCEPT' => 'application/json',
      'CONTENT_TYPE' => 'application/json',
      'HTTP_IDEMPOTENCY_KEY' => $headers['Idempotency-Key'] ?? '',
      'HTTP_X_CSRF_TOKEN' => $headers['X-CSRF-Token'] ?? '',
    ], Json::encode($payload));
    $response = $this->getSession()->getPage()->getContent();
    $this->refreshVariables();
    return Json::decode($response);
  }

  /**
   * PII and access errors must remain private and non-cacheable.
   */
  private function assertPrivateResponse(): void {
    $cache_control = $this->getSession()->getResponseHeader('Cache-Control');
    $this->assertStringContainsString('private', $cache_control);
    $this->assertStringContainsString('no-store', $cache_control);
  }

}
