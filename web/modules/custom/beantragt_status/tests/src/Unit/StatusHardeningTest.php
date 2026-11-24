<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_status\Unit;

use Drupal\beantragt_status\Controller\StatusController;
use Drupal\beantragt_status\EventSubscriber\StatusCodeSubscriber;
use Drupal\beantragt_status\Service\StatusBuilder;
use Drupal\beantragt_status\Service\StatusOtpService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/PasswordVerifySpy.php';
require_once dirname(__DIR__, 2) . '/fixtures/password_verify.php';

/**
 * Guards atomic verification, deferred lookup and public timeline filtering.
 */
#[Group('beantragt_status')]
final class StatusHardeningTest extends UnitTestCase {

  /**
   * Original service credentials.
   *
   * @var array<string, string|false>
   */
  private array $originalEnv = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['NUXT_DRUPAL_SERVICE_USER' => 'nuxt_service', 'NUXT_DRUPAL_SERVICE_PASSWORD' => 'test-secret'] as $key => $value) {
      $this->originalEnv[$key] = getenv($key);
      putenv($key . '=' . $value);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->originalEnv as $key => $value) {
      putenv($value === FALSE ? $key : $key . '=' . $value);
    }
    PasswordVerifySpy::$hashes = NULL;
    parent::tearDown();
  }

  /**
   * Wrong guesses use an SQL increment and cap even for stale snapshots.
   */
  public function testFailedAttemptQueryShape(): void {
    PasswordVerifySpy::$hashes = [];
    $otp = $this->verificationService(FALSE, 0);
    $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '000000'));
    $this->assertCount(1, PasswordVerifySpy::$hashes);
  }

  /**
   * Missing, expired and capped lookups still perform exactly one bcrypt.
   */
  public function testNoEligibleCodePerformsOneDummyVerify(): void {
    PasswordVerifySpy::$hashes = [];
    $otp = $this->verificationService(FALSE, 0, FALSE);
    $this->assertFalse($otp->verifyCode('TEST-1', 'citizen@example.test', '123456'));
    $this->assertCount(1, PasswordVerifySpy::$hashes);
    $this->assertSame(12, password_get_info(PasswordVerifySpy::$hashes[0])['options']['cost']);
  }

  /**
   * Invalidation happens after insertion and cannot touch a newer racing row.
   */
  public function testRequestInsertsBeforeInvalidatingOlderCodes(): void {
    $database = $this->createMock(Connection::class);
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnSelf();
    $database->method('delete')->willReturn($delete);
    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnCallback(function (array $fields) use ($insert) {
      $this->assertSame(12, password_get_info($fields['code'])['options']['cost']);
      return $insert;
    });
    $inserted = FALSE;
    $insert->expects($this->once())->method('execute')->willReturnCallback(function () use (&$inserted) {
      $inserted = TRUE;
      return '43';
    });
    $database->method('insert')->willReturn($insert);
    $update = $this->createMock(Update::class);
    $update->expects($this->once())->method('fields')->with(['verified' => 2])->willReturnSelf();
    $conditions = [];
    $update->expects($this->exactly(4))->method('condition')->willReturnCallback(
      function ($field, $value, $operator = '=') use ($update, &$conditions) {
        $conditions[$field] = [$value, $operator];
        return $update;
      },
    );
    $update->expects($this->once())->method('execute')->willReturnCallback(function () use (&$conditions) {
      $this->assertSame(['43', '<'], $conditions['id']);
      $this->assertSame([0, '='], $conditions['verified']);
      $this->assertSame([hash_hmac('sha256', 'reference:TEST-1', 'test-private-key'), '='], $conditions['reference_hash']);
      $this->assertSame([hash_hmac('sha256', 'email:citizen@example.test', 'test-private-key'), '='], $conditions['email_hash']);
      return 1;
    });
    $database->expects($this->once())->method('update')->willReturnCallback(function () use (&$inserted, $update) {
      $this->assertTrue($inserted);
      return $update;
    });
    $key = $this->createMock(PrivateKey::class);
    $key->method('get')->willReturn('test-private-key');
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())->method('mail')->willReturn(['result' => TRUE]);
    $otp = new StatusOtpService($database, $mail, $this->getConfigFactoryStub(['system.site' => ['name' => 'Test platform']]), $this->createMock(LanguageManagerInterface::class), $key, $this->createMock(LoggerInterface::class));
    $this->assertTrue($otp->requestCode('TEST-1', 'citizen@example.test', 'de')['success']);
  }

  /**
   * Deferred work contains errors as well as exceptions after the response.
   */
  public function testDeferredSenderContainsThrowable(): void {
    $builder = $this->createMock(StatusBuilder::class);
    $builder->method('referenceMatchesApplicantEmail')->willThrowException(new \Error('Test failure'));
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error')->with('Deferred status code request failed.');
    $subscriber = new StatusCodeSubscriber($builder, $this->otp($this->createMock(Connection::class)), $logger);
    $subscriber->enqueue('TEST-1', 'citizen@example.test', 'de');
    $subscriber->onTerminate();
    $subscriber->onTerminate();
  }

  /**
   * A lost atomic claim never exposes status data through the controller.
   */
  public function testLostClaimReturnsUnauthorizedWithoutStatus(): void {
    $builder = $this->createMock(StatusBuilder::class);
    $builder->expects($this->never())->method('buildByReferenceAndApplicantEmail');
    $controller = $this->controller($builder, $this->verificationService(TRUE, 0));
    $response = $controller->verifyReferenceCode('TEST-1', $this->request('citizen@example.test', '123456'));
    $this->assertSame(401, $response->getStatusCode());
    $this->assertSame(['error' => 'Invalid or expired status code.'], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Responses cannot depend on reference/email matching or OTP side effects.
   */
  public function testRequestReturnsIdenticalResponseBeforeAnyLookupOrCode(): void {
    $builder = $this->createMock(StatusBuilder::class);
    $builder->expects($this->never())->method('referenceMatchesApplicantEmail');
    $builder->expects($this->never())->method('jurisdictionIdForReference');
    $database = $this->createMock(Connection::class);
    foreach (['select', 'insert', 'update', 'delete'] as $method) {
      $database->expects($this->never())->method($method);
    }
    $controller = $this->controller($builder, $this->otp($database));
    $matching = $controller->requestReferenceCode('TEST-1', $this->request('citizen@example.test'));
    $mismatch = $controller->requestReferenceCode('TEST-1', $this->request('other@example.test'));
    $unknown = $controller->requestReferenceCode('UNKNOWN', $this->request('citizen@example.test'));
    foreach ([$mismatch, $unknown] as $response) {
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame($matching->headers->all(), $response->headers->all());
      $this->assertSame($matching->getContent(), $response->getContent());
    }
  }

  /**
   * An atomic successful claim allows only whitelisted timeline keys.
   */
  #[DataProvider('timelineActors')]
  public function testLegacyTimelineIsWhitelisted(string $actor, ?string $expected_actor, bool $token_route): void {
    $builder = $this->createMock(StatusBuilder::class);
    $builder->method($token_route ? 'buildByPublicStatusToken' : 'buildByReferenceAndApplicantEmail')->willReturn([
      'org_name' => 'Test office',
      'submitted_at' => '2026-01-01',
      'timeline' => [
        [
          'status' => 'received',
          'timestamp' => '2026-01-01',
          'label' => 'Received',
          'color' => 'blue',
          'icon' => 'inbox',
          'actor' => $actor,
          'note' => 'private',
          'revision_log' => 'private',
          'user' => ['name' => 'private'],
          'uid' => 1,
        ],
        ['status' => 'missing timestamp'],
        ['timestamp' => 'missing status'],
        'invalid',
      ],
    ]);
    if ($token_route) {
      $database = $this->createMock(Connection::class);
      $database->expects($this->never())->method('select');
      $controller = $this->controller($builder, $this->otp($database));
      $response = $controller->getApplicationStatus(str_repeat('t', 43), Request::create('/?schemaId=63d5c333-9d2a-495d-8975-8ae9ec94aea1'));
    }
    else {
      $controller = $this->controller($builder, $this->verificationService(TRUE, 1));
      $response = $controller->verifyReferenceCode('TEST-1', $this->request('citizen@example.test', '123456'));
    }
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'org_name' => 'Test office',
      'submitted_at' => '2026-01-01',
      'timeline' => [[
        'status' => 'received',
        'label' => 'Received',
        'color' => 'blue',
        'icon' => 'inbox',
        'timestamp' => '2026-01-01',
        'actor' => $expected_actor,
      ],
      ],
    ], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * Public actor values.
   */
  public static function timelineActors(): array {
    return [
      'verify staff' => ['staff', 'staff', FALSE],
      'verify private' => ['named-user', NULL, FALSE],
      'token staff' => ['staff', 'staff', TRUE],
      'token private' => ['named-user', NULL, TRUE],
    ];
  }

  /**
   * Builds a real verifier with strict atomic query expectations.
   */
  private function verificationService(bool $match, int $affected, bool $eligible = TRUE): StatusOtpService {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())->method('fetchObject')->willReturn($eligible ? (object) [
      'id' => 42,
      'code' => password_hash('123456', PASSWORD_BCRYPT),
      'expires' => time() + 600,
      'attempts' => 0,
    ] : FALSE);
    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->expects($this->once())->method('orderBy')->with('id', 'DESC')->willReturnSelf();
    $select->expects($this->once())->method('range')->with(0, 1)->willReturnSelf();
    $selection = [];
    $select->expects($this->exactly(5))->method('condition')->willReturnCallback(
      function ($field, $value, $operator = '=') use ($select, &$selection) {
        $selection[$field] = [$value, $operator];
        return $select;
      },
    );
    $select->method('execute')->willReturnCallback(function () use (&$selection, $statement) {
      $this->assertSame([5, '<'], $selection['attempts']);
      $this->assertSame([0, '='], $selection['verified']);
      $this->assertSame('>=', $selection['expires'][1]);
      $this->assertLessThanOrEqual(time(), $selection['expires'][0]);
      return $statement;
    });
    $update = $this->createMock(Update::class);
    $update->expects($this->exactly($eligible ? 1 : 0))->method('expression')->with('attempts', 'attempts + 1')->willReturnSelf();
    if ($match) {
      $update->expects($this->once())->method('fields')->with(['verified' => 1])->willReturnSelf();
    }
    else {
      $update->expects($this->never())->method('fields');
    }
    $conditions = [];
    $update->expects($this->exactly($eligible ? ($match ? 3 : 4) : 0))->method('condition')->willReturnCallback(
      function ($field, $value, $operator = NULL) use ($update, &$conditions) {
        $conditions[] = [$field, $value, $operator];
        return $update;
      },
    );
    $update->expects($this->exactly($eligible ? 1 : 0))->method('execute')->willReturnCallback(function () use (&$conditions, $affected, $match) {
      $target = $match ? [['id', 42, '=']] : [
        ['reference_hash', hash_hmac('sha256', 'reference:TEST-1', 'test-private-key'), '='],
        ['email_hash', hash_hmac('sha256', 'email:citizen@example.test', 'test-private-key'), '='],
      ];
      $this->assertSame([...$target, ['verified', 0, '='], ['attempts', 5, '<']], $conditions);
      return $affected;
    });
    $database = $this->createMock(Connection::class);
    $database->method('startTransaction')->willReturn(new class() extends Transaction {

      /**
       * No database transaction is needed for query-shape assertions.
       */
      public function __construct() {}

      /**
       * No transaction to release.
       */
      public function __destruct() {}

      /**
       * Preserve any assertion failure caught by the verifier.
       */
      public function rollBack() {}

    });
    $database->method('select')->willReturn($select);
    $database->expects($this->exactly($eligible ? 1 : 0))->method('update')->with('beantragt_status_otp_codes')->willReturn($update);
    return $this->otp($database);
  }

  /**
   * Builds the OTP service without invoking external mail or storage.
   */
  private function otp(Connection $database): StatusOtpService {
    $key = $this->createMock(PrivateKey::class);
    $key->method('get')->willReturn('test-private-key');
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->never())->method('mail');
    return new StatusOtpService(
      $database, $mail,
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LanguageManagerInterface::class), $key,
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Builds a controller with real deferral and permissive flood storage.
   */
  private function controller(StatusBuilder $builder, StatusOtpService $otp): StatusController {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    return new StatusController($builder, $otp, $flood, new StatusCodeSubscriber($builder, $otp, $this->createMock(LoggerInterface::class)));
  }

  /**
   * Builds a service-authenticated request.
   */
  private function request(string $email, string $code = ''): Request {
    return Request::create('/api/status/TEST-1', 'POST', [], [], [], [
      'REMOTE_ADDR' => '192.0.2.1',
      'HTTP_X_BEANTRAGT_SERVICE_AUTHORIZATION' => 'Basic ' . base64_encode('nuxt_service:test-secret'),
    ], json_encode(['email' => $email, 'code' => $code, 'langcode' => 'de'], JSON_THROW_ON_ERROR));
  }

}
