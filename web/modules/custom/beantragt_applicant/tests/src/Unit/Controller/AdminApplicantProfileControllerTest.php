<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Unit\Controller;

use Drupal\beantragt_applicant\Controller\AdminApplicantProfileController;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\ApplicantScopeMutationLock;
use Drupal\beantragt_application\Service\TenantScopedEntityQuery;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests scoped applicant profile admin updates.
 */
#[CoversClass(AdminApplicantProfileController::class)]
#[Group('beantragt_applicant')]
final class AdminApplicantProfileControllerTest extends UnitTestCase {

  /**
   * Editable profile fields are normalized and mapped to Applicant fields.
   */
  public function testNormalizeUpdatesAllowsOnlyBasicContactFields(): void {
    $controller = $this->controller();

    $updates = $this->invokeNormalizeUpdates($controller, [
      'fullName' => ' Ada Antrag ',
      'phone' => ' +49 30 23125 123 ',
      'address' => NULL,
      'street' => ' Rathausplatz 1 ',
      'postalCode' => ' 12345 ',
      'city' => ' Dunkelstadt ',
    ]);

    $this->assertSame([
      'field_full_name' => 'Ada Antrag',
      'field_phone' => '+49 30 23125 123',
      'field_address' => '',
      'field_street' => 'Rathausplatz 1',
      'field_postal_code' => '12345',
      'field_city' => 'Dunkelstadt',
    ], $updates);
  }

  /**
   * Sensitive identity and financial fields are never writable here.
   */
  public function testNormalizeUpdatesRejectsIdentityFields(): void {
    $controller = $this->controller();

    foreach (['email', 'iban', 'birthdate', 'taxId', 'accountHolder', 'field_iban'] as $field) {
      $response = $this->invokeNormalizeUpdates($controller, [$field => 'secret']);

      $this->assertInstanceOf(JsonResponse::class, $response);
      $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }
  }

  /**
   * Overlong values are rejected before persistence.
   */
  public function testNormalizeUpdatesRejectsOverlongValues(): void {
    $response = $this->invokeNormalizeUpdates($this->controller(), [
      'phone' => str_repeat('1', 51),
    ]);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
  }

  /**
   * Profile mutation route requires a Drupal CSRF request header token.
   */
  public function testPatchRouteRequiresCsrfAndNoCache(): void {
    $routing = file_get_contents(dirname(__DIR__, 4) . '/beantragt_applicant.routing.yml');
    $this->assertIsString($routing);

    $this->assertStringContainsString('beantragt_applicant.admin_profile_update:', $routing);
    $this->assertStringContainsString("_user_is_logged_in: 'TRUE'", $routing);
    $this->assertStringContainsString("_csrf_request_header_token: 'TRUE'", $routing);
    $this->assertStringContainsString("_auth: ['cookie']", $routing);
    $this->assertStringContainsString('no_cache: TRUE', $routing);
  }

  /**
   * Deletion preview is authenticated and private without being a mutation.
   */
  public function testDeletePreviewRouteIsAuthenticatedAndNoCache(): void {
    $routing = file_get_contents(dirname(__DIR__, 4) . '/beantragt_applicant.routing.yml');
    $this->assertIsString($routing);

    $this->assertStringContainsString('beantragt_applicant.admin_profile_delete_preview:', $routing);
    $this->assertStringContainsString('methods: [POST]', $routing);
    $this->assertStringContainsString("_user_is_logged_in: 'TRUE'", $routing);
    $this->assertStringContainsString("_csrf_request_header_token: 'TRUE'", $routing);
    $this->assertStringContainsString("_auth: ['cookie']", $routing);
    $this->assertStringContainsString('no_cache: TRUE', $routing);
  }

  /**
   * Sensitive-value reveal requires authentication, CSRF and private caching.
   */
  public function testSensitiveRevealRouteIsProtectedAndNoCache(): void {
    $routing = file_get_contents(dirname(__DIR__, 4) . '/beantragt_applicant.routing.yml');
    $this->assertIsString($routing);

    $this->assertStringContainsString('beantragt_applicant.admin_profile_sensitive_reveal:', $routing);
    $this->assertStringContainsString("path: '/api/admin/applicant-sensitive/reveal'", $routing);
    $this->assertStringContainsString('methods: [POST]', $routing);
    $this->assertStringContainsString("_user_is_logged_in: 'TRUE'", $routing);
    $this->assertStringContainsString("_csrf_request_header_token: 'TRUE'", $routing);
    $this->assertStringContainsString("_auth: ['cookie']", $routing);
    $this->assertStringContainsString('no_cache: TRUE', $routing);
  }

  /**
   * Permanent deletion route requires authentication and CSRF protection.
   */
  public function testDeleteRouteRequiresCsrfAndNoCache(): void {
    $routing = file_get_contents(dirname(__DIR__, 4) . '/beantragt_applicant.routing.yml');
    $this->assertIsString($routing);

    $this->assertStringContainsString('beantragt_applicant.admin_profile_delete:', $routing);
    $this->assertStringContainsString('methods: [DELETE]', $routing);
    $this->assertStringContainsString("_user_is_logged_in: 'TRUE'", $routing);
    $this->assertStringContainsString("_csrf_request_header_token: 'TRUE'", $routing);
    $this->assertStringContainsString("_auth: ['cookie']", $routing);
    $this->assertStringContainsString('no_cache: TRUE', $routing);
  }

  /**
   * Management role alone cannot permanently delete applicant data.
   */
  public function testPermanentDeletionRequiresBothEntityPermissions(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn(['authenticated', 'department_admin']);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'delete any application entities',
    );

    $this->assertFalse($this->invokeCanPermanentlyDeleteApplicantData(
      $this->controller($account),
    ));
  }

  /**
   * Department Admin can delete when both entity permissions are present.
   */
  public function testPermanentDeletionAllowsFullyPermissionedManagement(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn(['authenticated', 'department_admin']);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => in_array($permission, [
        'delete any application entities',
        'administer scoped applicant cleanup',
      ], TRUE),
    );

    $this->assertTrue($this->invokeCanPermanentlyDeleteApplicantData(
      $this->controller($account),
    ));
  }

  /**
   * Applicant secrets require the existing protected-data permission.
   */
  public function testSensitiveRevealRequiresProtectedDataPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn(['authenticated', 'department_admin']);
    $account->method('hasPermission')->willReturn(FALSE);

    $this->assertFalse($this->invokeCanRevealSensitiveApplicantData(
      $this->controller($account),
    ));
  }

  /**
   * The protected-data processor role can reveal applicant secrets.
   */
  public function testSensitiveRevealAllowsFullyPermissionedManagement(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn([
      'authenticated',
      'department_admin',
      'protected_application_data_processor',
    ]);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'view protected application data',
    );

    $this->assertTrue($this->invokeCanRevealSensitiveApplicantData(
      $this->controller($account),
    ));
  }

  /**
   * The account holder stored with bank consent is offered for reveal.
   */
  public function testAvailableSensitiveFieldsIncludeAccountHolder(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('getRoles')->willReturn([
      'authenticated',
      'department_admin',
      'protected_application_data_processor',
    ]);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'view protected application data',
    );
    $values = [
      'field_iban' => 'DE89370400440532013000',
      'field_account_holder' => 'Ada Lovelace',
      'field_tax_id' => '',
    ];
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('hasField')->willReturnCallback(static fn(string $field): bool => array_key_exists($field, $values));
    $applicant->method('get')->willReturnCallback(function (string $field) use ($values): FieldItemListInterface {
      $items = $this->createMock(FieldItemListInterface::class);
      $items->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'value' ? $values[$field] : NULL);
      return $items;
    });

    $controller = $this->controller($account);
    $method = new \ReflectionMethod($controller, 'availableSensitiveFields');

    $this->assertSame(['iban', 'accountHolder'], $method->invoke($controller, $applicant));
  }

  /**
   * Plain request-body e-mail values are never URL-decoded a second time.
   */
  public function testNormalizeEmailPreservesLiteralPercentSequences(): void {
    $method = new \ReflectionMethod($this->controller(), 'normalizeEmail');
    $method->setAccessible(TRUE);

    $this->assertSame(
      'a%2bb@example.org',
      $method->invoke($this->controller(), ' A%2Bb@Example.org '),
    );
  }

  /**
   * Error payloads never become shared-cacheable profile responses.
   */
  public function testErrorResponsesArePrivateNoStore(): void {
    $method = new \ReflectionMethod($this->controller(), 'error');
    $method->setAccessible(TRUE);
    $response = $method->invoke($this->controller(), 'errors.admin.applicantsSaveFailed', Response::HTTP_FORBIDDEN);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
  }

  /**
   * Direct reveal calls are limited at the Drupal boundary.
   */
  public function testSensitiveRevealFloodProtection(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_applicant.sensitive_reveal', 20, 60, '42:7')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $response = $this->invokeSensitiveRevealFloodResponse(
      $this->controller($this->account(42), $flood),
      7,
    );

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    $this->assertPrivateNoStore($response);
  }

  /**
   * Allowed reveal calls consume one Drupal flood slot.
   */
  public function testSensitiveRevealRegistersFloodAttempt(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_applicant.sensitive_reveal', 20, 60, '42:7')
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('beantragt_applicant.sensitive_reveal', 60, '42:7');

    $response = $this->invokeSensitiveRevealFloodResponse(
      $this->controller($this->account(42), $flood),
      7,
    );

    $this->assertNull($response);
  }

  /**
   * Creates a controller with mocked infrastructure.
   */
  private function controller(
    ?AccountInterface $account = NULL,
    ?FloodInterface $flood = NULL,
  ): AdminApplicantProfileController {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    return new AdminApplicantProfileController(
      $entity_type_manager,
      $account ?? $this->createMock(AccountInterface::class),
      $this->createMock(TenantScopedEntityQuery::class),
      new ApplicationDataProtectionService($entity_type_manager, $logger),
      $this->createMock(ApplicantScopeMutationLock::class),
      $this->createMock(Connection::class),
      $flood ?? $this->createMock(FloodInterface::class),
      $logger,
    );
  }

  /**
   * Creates a current-account mock with a stable ID.
   */
  private function account(int $uid): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    return $account;
  }

  /**
   * Invokes sensitiveRevealFloodResponse().
   */
  private function invokeSensitiveRevealFloodResponse(
    AdminApplicantProfileController $controller,
    int $workspace_id,
  ): ?JsonResponse {
    $method = new \ReflectionMethod($controller, 'sensitiveRevealFloodResponse');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $workspace_id);
  }

  /**
   * Invokes canRevealSensitiveApplicantData().
   */
  private function invokeCanRevealSensitiveApplicantData(
    AdminApplicantProfileController $controller,
  ): bool {
    $method = new \ReflectionMethod(
      $controller,
      'canRevealSensitiveApplicantData',
    );
    $method->setAccessible(TRUE);
    return (bool) $method->invoke($controller);
  }

  /**
   * Invokes canPermanentlyDeleteApplicantData().
   */
  private function invokeCanPermanentlyDeleteApplicantData(
    AdminApplicantProfileController $controller,
  ): bool {
    $method = new \ReflectionMethod(
      $controller,
      'canPermanentlyDeleteApplicantData',
    );
    $method->setAccessible(TRUE);
    return (bool) $method->invoke($controller);
  }

  /**
   * Invokes normalizeUpdates().
   *
   * @param \Drupal\beantragt_applicant\Controller\AdminApplicantProfileController $controller
   *   Controller under test.
   * @param array<string, mixed> $data
   *   Raw request data.
   *
   * @return array<string, string>|\Symfony\Component\HttpFoundation\JsonResponse
   *   Normalized field values or an error response.
   */
  private function invokeNormalizeUpdates(AdminApplicantProfileController $controller, array $data): array|JsonResponse {
    $method = new \ReflectionMethod($controller, 'normalizeUpdates');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $data);
  }

  /**
   * Asserts a response cannot be shared-cached.
   */
  private function assertPrivateNoStore(JsonResponse $response): void {
    $cache_control = (string) $response->headers->get('Cache-Control');

    $this->assertStringContainsString('private', $cache_control);
    $this->assertStringContainsString('no-store', $cache_control);
  }

}
