<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_status\Kernel;

use Drupal\beantragt_status\Controller\StatusController;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exercises real OTP storage, termination mail and token expiry queries.
 */
#[Group('beantragt_status')]
final class StatusHardeningKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget',
    'serialization', 'beantragt_schema', 'beantragt_workflow',
    'beantragt_workflow_status', 'beantragt_application', 'beantragt_group',
    'beantragt_roles_api', 'beantragt_status',
  ];

  /**
   * A public form fixture.
   */
  private ContentEntityInterface $form;

  /**
   * Original environment.
   *
   * @var array<string, string|false>
   */
  private array $originalEnv = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    foreach ([
      'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' => str_repeat('k', 32),
      'NUXT_DRUPAL_SERVICE_USER' => 'nuxt_service',
      'NUXT_DRUPAL_SERVICE_PASSWORD' => 'test-secret',
    ] as $key => $value) {
      $this->originalEnv[$key] = getenv($key);
      putenv($key . '=' . $value);
    }
    parent::setUp();
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application', 'beantragt_status']);
    foreach (['file', 'form_schema', 'workflow_status', 'application'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('beantragt_status', ['beantragt_status_otp_codes']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Test platform')->set('mail', 'noreply@example.test')->save();
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));
    $this->form = $this->entityTypeManager()->getStorage('form_schema')->create([
      'label' => 'Public test form',
      'status' => 1,
      'field_publication_scope' => 'internet',
      'field_workflow_status' => 'published',
      'field_schema' => '{"title":"Public test form","sections":[{"id":"main","title":"Main","fields":[{"id":"name","type":"text","label":"Name","privacy":{"kind":"identity"}}]}]}',
    ]);
    $this->form->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->originalEnv as $key => $value) {
      putenv($value === FALSE ? $key : $key . '=' . $value);
    }
    parent::tearDown();
  }

  /**
   * Six wrong attempts stop at five and reject even the correct code.
   */
  public function testAttemptCapAndSingleUse(): void {
    $otp = $this->container->get('beantragt_status.status_otp');
    $this->assertTrue($otp->requestCode('TEST-1', 'citizen@example.test', 'de')['success']);
    $database = $this->container->get('database');
    $database->update('beantragt_status_otp_codes')->fields(['code' => password_hash('123456', PASSWORD_BCRYPT)])->execute();
    for ($attempt = 0; $attempt < 6; $attempt++) {
      $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '000000'));
    }
    $this->assertSame(5, (int) $database->select('beantragt_status_otp_codes', 'c')->fields('c', ['attempts'])->execute()->fetchField());
    $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '123456'));
    $database->update('beantragt_status_otp_codes')->fields(['attempts' => 0])->execute();
    $this->assertTrue($otp->verifyCode('TEST-1', 'citizen@example.test', '123456'));
    $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '123456'));
  }

  /**
   * Racing pending rows share the cap and cannot reveal an older valid code.
   */
  public function testConcurrentPendingRowsShareAttemptCap(): void {
    $otp = $this->container->get('beantragt_status.status_otp');
    $this->assertTrue($otp->requestCode('TEST-1', 'citizen@example.test', 'de')['success']);
    $database = $this->container->get('database');
    $database->update('beantragt_status_otp_codes')->fields(['code' => password_hash('123456', PASSWORD_BCRYPT)])->execute();
    $older = $database->select('beantragt_status_otp_codes', 'c')->fields('c')->execute()->fetchAssoc();
    $newer = $older;
    unset($newer['id']);
    $newer['code'] = password_hash('654321', PASSWORD_BCRYPT);
    $database->insert('beantragt_status_otp_codes')->fields($newer)->execute();
    $this->assertSame(2, $this->codeCount());
    for ($attempt = 0; $attempt < 5; $attempt++) {
      $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '000000'));
    }
    $attempts = $database->select('beantragt_status_otp_codes', 'c')->fields('c', ['attempts'])->execute()->fetchCol();
    $this->assertSame([5, 5], array_map('intval', $attempts));
    $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '123456'));
    $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '654321'));

    // Even with eligible older rows, only the newest row is compared.
    $database->update('beantragt_status_otp_codes')->fields(['attempts' => 0])->execute();
    $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '123456'));
    $this->assertTrue($otp->verifyCode('TEST-1', 'citizen@example.test', '654321'));
  }

  /**
   * Requesting a new code invalidates only older pending codes for its target.
   */
  public function testNewCodeInvalidatesOlderPendingRows(): void {
    $otp = $this->container->get('beantragt_status.status_otp');
    $this->assertTrue($otp->requestCode('TEST-1', 'citizen@example.test', 'de')['success']);
    $this->assertTrue($otp->requestCode('TEST-1', 'other@example.test', 'de')['success']);
    $this->assertTrue($otp->requestCode('TEST-1', 'citizen@example.test', 'de')['success']);
    $states = $this->container->get('database')->select('beantragt_status_otp_codes', 'c')->fields('c', ['verified'])->orderBy('id')->execute()->fetchCol();
    $this->assertSame([2, 0, 0], array_map('intval', $states));
  }

  /**
   * Only a matching pair creates one mail and row, after termination only.
   */
  public function testDeferredMailAfterTermination(): void {
    $this->application(time());
    $controller = StatusController::create($this->container);
    $pairs = [
      ['TEST-1', 'citizen@example.test'],
      ['TEST-1', 'other@example.test'],
      ['UNKNOWN', 'citizen@example.test'],
    ];
    foreach ($pairs as [$reference, $email]) {
      $request = Request::create('/api/status/' . $reference . '/request-code', 'POST', [], [], [], [
        'REMOTE_ADDR' => '192.0.2.1',
        'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:test-secret'),
      ], json_encode(['email' => $email, 'langcode' => 'de'], JSON_THROW_ON_ERROR));
      $this->assertSame(200, $controller->requestReferenceCode($reference, $request)->getStatusCode());
    }
    $this->assertCount(0, $this->container->get('state')->get('system.test_mail_collector', []));
    $this->assertSame(0, $this->codeCount());
    $event = new TerminateEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), new Response());
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::TERMINATE);
    $this->assertSame(1, $this->codeCount());
    $mails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(1, $mails);
    $this->assertSame('beantragt_status_status_lookup_code', $mails[0]['id']);
    $this->assertSame('citizen@example.test', $mails[0]['to']);
    $this->assertSame('de', $mails[0]['langcode']);
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::TERMINATE);
    $this->assertSame(1, $this->codeCount());
    $this->assertCount(1, $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * Both controller routes expire together; reference lookup and hash remain.
   */
  public function testPublicTokenExpiry(): void {
    $now = time();
    $application = $this->application($now - 181 * 86400);
    $controller = StatusController::create($this->container);
    $this->assertTokenResponses($controller, 404);
    $builder = $this->container->get('beantragt_status.status_builder');
    $this->assertTrue($builder->referenceMatchesApplicantEmail('TEST-1', 'citizen@example.test'));
    $this->assertNotNull($builder->buildByReferenceAndApplicantEmail('TEST-1', 'citizen@example.test'));
    $this->assertSame(hash('sha256', str_repeat('t', 43)), $application->get('field_public_status_token_hash')->value);

    $this->config('beantragt_status.settings')->set('public_status_link_ttl_days', 0)->save();
    $this->assertTokenResponses($controller, 200);
    $this->config('beantragt_status.settings')->set('public_status_link_ttl_days', 180)->save();
    $this->container->get('database')->update('application')->fields(['created' => $now - 179 * 86400])->condition('id', $application->id())->execute();
    $this->entityTypeManager()->getStorage('application')->resetCache();
    $this->assertTokenResponses($controller, 200);
  }

  /**
   * The update sets a default once and preserves explicit unlimited lifetime.
   */
  public function testConfigUpdatePreservesExplicitValue(): void {
    require_once dirname(__DIR__, 3) . '/beantragt_status.install';
    $this->config('beantragt_status.settings')->delete();
    beantragt_status_update_10002();
    $this->assertSame(180, $this->config('beantragt_status.settings')->get('public_status_link_ttl_days'));
    $this->config('beantragt_status.settings')->set('public_status_link_ttl_days', 0)->save();
    beantragt_status_update_10002();
    $this->assertSame(0, $this->config('beantragt_status.settings')->get('public_status_link_ttl_days'));
  }

  /**
   * Tests both public route handlers against real entity queries.
   */
  private function assertTokenResponses(StatusController $controller, int $expected): void {
    $token = str_repeat('t', 43);
    foreach (['getApplicationStatus' => '/api/application-status/', 'getApplicationStatusV1' => '/api/v1/status/'] as $method => $route) {
      $request = Request::create($route . $token . '?schemaId=' . $this->form->uuid(), 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
      $response = $controller->$method($token, $request);
      $this->assertSame($expected, $response->getStatusCode(), (string) $response->getContent());
      $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
      $this->assertSame($expected === 200, str_contains((string) $response->getContent(), 'Public test form'));
    }
  }

  /**
   * Creates a confirmed application directly, avoiding unrelated submit hooks.
   */
  private function application(int $created): ContentEntityInterface {
    $database = $this->container->get('database');
    $values = [
      'id' => 9001,
      'revision_id' => 9001,
      'uuid' => '11a87815-1122-4411-8123-990011223344',
      'uid' => 0,
      'status' => 1,
      'field_schema' => $this->form->id(),
      'field_reference_number' => 'TEST-1',
      'field_email_confirmed' => 1,
      'field_applicant_email' => 'citizen@example.test',
      'field_applicant_email_hash' => $this->container->get('beantragt_application.data_protection')->applicantEmailLookupHash('citizen@example.test'),
      'field_public_status_token_hash' => hash('sha256', str_repeat('t', 43)),
      'created' => $created,
      'changed' => $created,
    ];
    $database->insert('application')->fields($values)->execute();
    unset($values['uuid'], $values['field_public_status_token_hash']);
    $database->insert('application_revision')->fields($values)->execute();
    return $this->entityTypeManager()->getStorage('application')->load(9001);
  }

  /**
   * Counts actual OTP rows.
   */
  private function codeCount(): int {
    return (int) $this->container->get('database')->select('beantragt_status_otp_codes')->countQuery()->execute()->fetchField();
  }

}
