<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Unit\Controller;

use Drupal\beantragt_applicant\Controller\CitizenApplicantProfileController;
use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the authenticated citizen applicant-profile boundary.
 */
#[CoversClass(CitizenApplicantProfileController::class)]
#[Group('beantragt_applicant')]
final class CitizenApplicantProfileControllerTest extends UnitTestCase {

  /**
   * Citizen updates accept only the public contact-field allowlist.
   */
  public function testNormalizeUpdatesAllowsOnlyContactFields(): void {
    $updates = $this->invokeNormalizeUpdates($this->controller(), [
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
   * Identity, ownership, and protected values cannot be changed here.
   */
  public function testNormalizeUpdatesRejectsIdentityAndProtectedFields(): void {
    foreach (['email', 'uid', 'iban', 'taxId', 'field_iban'] as $field) {
      $response = $this->invokeNormalizeUpdates($this->controller(), [
        $field => 'forbidden',
      ]);

      $this->assertInstanceOf(JsonResponse::class, $response);
      $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
      $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
      $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
  }

  /**
   * The profile list reads only the current account's profiles.
   *
   * Mutation probe: a profile the storage returns for another owner is
   * dropped even if the query were wrong.
   */
  public function testOwnProfilesLookupUsesCurrentAccountOnly(): void {
    $own = $this->applicantWithFields([], 7, 42);
    $foreign = $this->applicantWithFields([], 9, 43);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->once())->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->expects($this->once())->method('condition')->with('uid', 42)->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->expects($this->once())->method('range')->with(0, 50)->willReturnSelf();
    $query->expects($this->once())->method('execute')->willReturn([7 => 7, 9 => 9]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->expects($this->once())->method('loadMultiple')
      ->with([7 => 7, 9 => 9])
      ->willReturn([7 => $own, 9 => $foreign]);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('applicant')->willReturn($storage);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);

    $result = $this->invokeLoadOwnApplicants($this->controller($entity_type_manager, $account));
    $this->assertSame([$own], $result);
  }

  /**
   * An account with profiles at two Stellen sees both instead of a 409.
   */
  public function testGetListsEveryOwnProfile(): void {
    $first = $this->applicantWithFields([
      'field_email' => 'citizen@example.org',
      'field_consent_time' => '1781431200',
    ], 8, 42);
    $second = $this->applicantWithFields([
      'field_email' => 'citizen@example.org',
      'field_bank_consent_time' => '1781431100',
    ], 7, 42);
    [$entity_type_manager, $account] = $this->ownProfileLookup([8 => $first, 7 => $second]);

    $response = $this->controller($entity_type_manager, $account)->get();

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(8, $payload['data']['id']);
    $this->assertSame([8, 7], array_column($payload['profiles'], 'id'));
    $this->assertSame(gmdate(DATE_ATOM, 1781431200), $payload['profiles'][0]['consentAt']);
    $this->assertSame(gmdate(DATE_ATOM, 1781431100), $payload['profiles'][1]['bankConsentAt']);
  }

  /**
   * A form request without a profile for its Stelle prefills nothing.
   *
   * Mutation probe: falling back to the primary profile would hand another
   * Stelle's data to the form.
   */
  public function testGetForUnresolvableFormReturnsNoProfile(): void {
    $first = $this->applicantWithFields(['field_email' => 'citizen@example.org'], 8, 42);
    $second = $this->applicantWithFields(['field_email' => 'citizen@example.org'], 7, 42);
    [$entity_type_manager, $account] = $this->ownProfileLookup([8 => $first, 7 => $second]);
    $manager = $this->createMock(ApplicantManager::class);
    $manager->expects($this->never())->method('selectProfileForScope');

    $response = $this->controller($entity_type_manager, $account, NULL, $manager)
      ->get(new Request(['form' => 'not-a-uuid']));

    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertNull($payload['data']);
    $this->assertSame([8, 7], array_column($payload['profiles'], 'id'));
  }

  /**
   * Without a profile id, an account with several profiles cannot edit.
   */
  public function testPatchWithoutIdIsAmbiguousForSeveralProfiles(): void {
    $first = $this->applicantWithFields([], 8, 42);
    $second = $this->applicantWithFields([], 7, 42);
    $first->expects($this->never())->method('save');
    $second->expects($this->never())->method('save');
    [$entity_type_manager, $account] = $this->ownProfileLookup([8 => $first, 7 => $second]);

    $response = $this->controller($entity_type_manager, $account, $this->allowingFlood())
      ->patch($this->patchRequest(['data' => ['phone' => '030 23125 1']]));

    $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    $this->assertSame('errors.citizen.profileAmbiguous', json_decode((string) $response->getContent(), TRUE)['error']['code']);
  }

  /**
   * A profile id names the edited profile, never the most recent one.
   */
  public function testPatchWithIdEditsThatProfileOnly(): void {
    $first = $this->applicantWithFields([], 8, 42);
    $second = $this->applicantWithFields([], 7, 42);
    $first->expects($this->never())->method('save');
    $second->expects($this->once())->method('set')->with('field_phone', '030 23125 1');
    $second->expects($this->once())->method('save');
    [$entity_type_manager, $account] = $this->ownProfileLookup([8 => $first, 7 => $second]);

    $response = $this->controller($entity_type_manager, $account, $this->allowingFlood())
      ->patch($this->patchRequest(['id' => 7, 'data' => ['phone' => '030 23125 1']]));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(7, json_decode((string) $response->getContent(), TRUE)['data']['id']);
  }

  /**
   * With the manager, the edit goes through the locked fresh-copy update.
   *
   * Mutation probe: saving the instance loaded before the lock would write
   * back values a concurrent withdrawal cleared; the controller must hand
   * the change to ApplicantManager::updateOwnProfileFields() instead.
   */
  public function testPatchDelegatesToLockedManagerUpdate(): void {
    $own = $this->applicantWithFields([], 7, 42);
    $own->expects($this->never())->method('save');
    $own->expects($this->never())->method('set');
    $stored = $this->applicantWithFields(['field_phone' => '030 23125 1'], 7, 42);
    [$entity_type_manager, $account] = $this->ownProfileLookup([7 => $own]);
    $manager = $this->createMock(ApplicantManager::class);
    $manager->expects($this->once())->method('updateOwnProfileFields')
      ->with($own, ['field_phone' => '030 23125 1'])
      ->willReturn($stored);

    $response = $this->controller($entity_type_manager, $account, $this->allowingFlood(), $manager)
      ->patch($this->patchRequest(['id' => 7, 'data' => ['phone' => '030 23125 1']]));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertSame(7, json_decode((string) $response->getContent(), TRUE)['data']['id']);
  }

  /**
   * Registered identity values never appear in the profile list.
   *
   * Date of birth, gender, nationality and marital status are returned only
   * for the profile of a form's Stelle (prefill), not in "Meine Daten" list
   * entries or the primary profile.
   */
  public function testProfileListOmitsPrefillIdentity(): void {
    $own = $this->applicantWithFields([
      'field_email' => 'citizen@example.org',
      'field_first_name' => 'Erika',
      'field_birthdate' => '1980-01-31',
      'field_nationality' => 'deutsch',
    ], 8, 42);
    [$entity_type_manager, $account] = $this->ownProfileLookup([8 => $own]);

    $payload = json_decode((string) $this->controller($entity_type_manager, $account)->get()->getContent(), TRUE);

    $this->assertArrayNotHasKey('birthdate', $payload['data']);
    $this->assertArrayNotHasKey('firstName', $payload['data']);
    $this->assertArrayNotHasKey('nationality', $payload['profiles'][0]);
  }

  /**
   * The prefill block reads the name parts and registered identity fields.
   */
  public function testPrefillIdentityReadsRegisteredFields(): void {
    $applicant = $this->applicantWithFields([
      'field_first_name' => 'Erika',
      'field_last_name' => 'Musterfrau',
      'field_birthdate' => '1980-01-31',
      'field_gender' => 'weiblich',
      'field_nationality' => 'deutsch',
    ]);
    $controller = $this->controller();
    $method = new \ReflectionMethod($controller, 'prefillIdentity');

    $this->assertSame([
      'firstName' => 'Erika',
      'lastName' => 'Musterfrau',
      'birthdate' => '1980-01-31',
      'gender' => 'weiblich',
      'nationality' => 'deutsch',
      'maritalStatus' => '',
    ], $method->invoke($controller, $applicant));
  }

  /**
   * A profile id of another account is answered like a missing profile.
   */
  public function testPatchWithForeignIdReturnsNotFound(): void {
    $foreign = $this->applicantWithFields([], 9, 99);
    $foreign->expects($this->never())->method('save');
    [$entity_type_manager, $account] = $this->ownProfileLookup([9 => $foreign]);

    $response = $this->controller($entity_type_manager, $account, $this->allowingFlood())
      ->patch($this->patchRequest(['id' => 9, 'data' => ['phone' => '030 23125 1']]));

    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * The Stelle heading names organisation and jurisdiction of the profile.
   */
  public function testProfileScopeLabelNamesOrganisationAndJurisdiction(): void {
    $group = function (string $label): FieldItemListInterface {
      $entity = $this->createMock(EntityInterface::class);
      $entity->method('label')->willReturn($label);
      $items = $this->createMock(FieldItemListInterface::class);
      $items->method('isEmpty')->willReturn(FALSE);
      $items->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'entity' ? $entity : NULL);
      return $items;
    };
    $fields = ['field_organisation' => $group('Bürgerbüro'), 'field_jurisdiction' => $group('Stadt Musterstadt')];
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $applicant->method('get')->willReturnCallback(static fn(string $name): FieldItemListInterface => $fields[$name]);

    $method = new \ReflectionMethod(CitizenApplicantProfileController::class, 'profileScopeLabel');
    $this->assertSame('Bürgerbüro, Stadt Musterstadt', $method->invoke($this->controller(), $applicant));
  }

  /**
   * An account without profile gets an empty list, not an error.
   */
  public function testGetWithoutProfileReturnsEmptyList(): void {
    [$entity_type_manager, $account] = $this->ownProfileLookup([]);

    $payload = json_decode((string) $this->controller($entity_type_manager, $account)->get()->getContent(), TRUE);
    $this->assertNull($payload['data']);
    $this->assertSame([], $payload['profiles']);
  }

  /**
   * Direct citizen profile mutations are limited in Drupal.
   */
  public function testProfileUpdateFloodProtection(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_applicant.citizen_profile_update', 20, 60, '42')
      ->willReturn(FALSE);
    $flood->expects($this->never())->method('register');

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $response = $this->invokeProfileUpdateFloodResponse(
      $this->controller(account: $account, flood: $flood),
    );

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
  }

  /**
   * Allowed profile updates consume one Drupal flood slot.
   */
  public function testProfileUpdateRegistersFloodAttempt(): void {
    $flood = $this->createMock(FloodInterface::class);
    $flood->expects($this->once())
      ->method('isAllowed')
      ->with('beantragt_applicant.citizen_profile_update', 20, 60, '42')
      ->willReturn(TRUE);
    $flood->expects($this->once())
      ->method('register')
      ->with('beantragt_applicant.citizen_profile_update', 60, '42');

    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    $response = $this->invokeProfileUpdateFloodResponse(
      $this->controller(account: $account, flood: $flood),
    );

    $this->assertNull($response);
  }

  /**
   * The mutation route requires the dedicated permission and CSRF header.
   */
  public function testRoutesRequireSelfServicePermissionAndCsrf(): void {
    $routing = file_get_contents(dirname(__DIR__, 4) . '/beantragt_applicant.routing.yml');
    $this->assertIsString($routing);

    $this->assertStringContainsString('beantragt_applicant.citizen_profile_get:', $routing);
    $this->assertStringContainsString('beantragt_applicant.citizen_profile_patch:', $routing);
    $this->assertStringContainsString("_permission: 'manage own applicant profile'", $routing);
    $this->assertStringContainsString("_csrf_request_header_token: 'TRUE'", $routing);
    $this->assertStringContainsString("_auth: ['cookie']", $routing);
    $this->assertStringContainsString('no_cache: TRUE', $routing);
  }

  /**
   * The consent Stelle comes from the record of the consenting application.
   */
  public function testConsentScopeLabelReadsTheSourceApplicationRecord(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasProfileConsent')->willReturn(TRUE);
    $application->method('getProfileConsentRecord')->willReturn(['scope' => ['type' => 'org', 'label' => 'Bürgerbüro']]);
    $application->method('hasBankConsent')->willReturn(TRUE);
    $application->method('getBankConsentRecord')->willReturn(['scope' => ['type' => 'org', 'label' => 'Kasse']]);

    $controller = $this->controller($this->applicationStorageReturning('app-uuid', $application));
    $applicant = $this->applicantWithSource('app-uuid');

    $this->assertSame('Bürgerbüro', $this->invokeConsentScopeLabel($controller, $applicant, 'field_consent_source', FALSE));
    $this->assertSame('Kasse', $this->invokeConsentScopeLabel($controller, $applicant, 'field_bank_consent_source', TRUE));
  }

  /**
   * An application without the matching consent never names a Stelle.
   */
  public function testConsentScopeLabelIgnoresApplicationsWithoutConsent(): void {
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('hasProfileConsent')->willReturn(TRUE);
    $application->method('getProfileConsentRecord')->willReturn(['scope' => ['label' => 'Bürgerbüro']]);
    $application->method('hasBankConsent')->willReturn(FALSE);
    $application->method('getBankConsentRecord')->willReturn(['scope' => ['label' => 'Bürgerbüro']]);

    $controller = $this->controller($this->applicationStorageReturning('app-uuid', $application));

    $this->assertNull($this->invokeConsentScopeLabel($controller, $this->applicantWithSource('app-uuid'), 'field_bank_consent_source', TRUE));
  }

  /**
   * Without a consent source no application is loaded.
   */
  public function testConsentScopeLabelWithoutSourceLoadsNothing(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('hasField')->willReturn(FALSE);

    $this->assertNull($this->invokeConsentScopeLabel($this->controller($entity_type_manager), $applicant, 'field_consent_source', FALSE));
  }

  /**
   * The withdrawal route carries the same guards as the profile PATCH.
   */
  public function testWithdrawRouteUsesSelfServiceGuards(): void {
    $routes = Yaml::parseFile(dirname(__DIR__, 4) . '/beantragt_applicant.routing.yml');
    $route = $routes['beantragt_applicant.citizen_profile_consent_withdraw'] ?? NULL;
    $this->assertIsArray($route);
    $this->assertSame('/api/citizen/applicant-profile/{profile}/consent/{kind}/withdraw', $route['path']);
    $this->assertSame(['POST'], $route['methods']);
    $this->assertSame('manage own applicant profile', $route['requirements']['_permission'] ?? NULL);
    $this->assertSame('TRUE', $route['requirements']['_csrf_request_header_token'] ?? NULL);
    $this->assertSame('contact|bank', $route['requirements']['kind'] ?? NULL);
    $this->assertSame('\\d+', $route['requirements']['profile'] ?? NULL);
    $this->assertSame(['cookie'], $route['options']['_auth'] ?? NULL);
    $this->assertTrue($route['options']['no_cache'] ?? FALSE);
  }

  /**
   * Unknown kinds fail before any profile is read or changed.
   */
  public function testWithdrawRejectsUnknownKind(): void {
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->never())->method('getStorage');
    $manager = $this->createMock(ApplicantManager::class);
    $manager->expects($this->never())->method('withdrawConsent');

    $response = $this->controller($entity_type_manager, manager: $manager)->withdrawConsent('7', 'tax');
    $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
  }

  /**
   * Anonymous sessions cannot withdraw anything.
   */
  public function testWithdrawRequiresAnAccount(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(0);
    $manager = $this->createMock(ApplicantManager::class);
    $manager->expects($this->never())->method('withdrawConsent');

    $response = $this->controller(account: $account, flood: $this->allowingFlood(), manager: $manager)->withdrawConsent('7', 'contact');
    $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
  }

  /**
   * Unknown, malformed and foreign profiles get 404 and change nothing.
   *
   * Mutation probe: dropping the owner check lets account 42 withdraw the
   * consent of profile 9, which belongs to account 43.
   */
  public function testWithdrawWithoutOwnProfileReturnsNotFound(): void {
    $manager = $this->createMock(ApplicantManager::class);
    $manager->expects($this->never())->method('withdrawConsent');
    [$entity_type_manager, $account] = $this->ownProfileLookup([9 => $this->applicantWithFields([], 9, 43)]);

    foreach (['5', '9', '0', '7abc'] as $id) {
      $response = $this->controller($entity_type_manager, $account, $this->allowingFlood(), $manager)->withdrawConsent($id, 'bank');
      $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $id);
    }
  }

  /**
   * Each own profile is withdrawable on its own, one per Stelle.
   */
  public function testWithdrawWorksForEachOwnProfile(): void {
    $first = $this->applicantWithFields(['field_email' => 'citizen@example.org'], 7, 42);
    $second = $this->applicantWithFields(['field_email' => 'citizen@example.org'], 8, 42);
    [$entity_type_manager, $account] = $this->ownProfileLookup([7 => $first, 8 => $second]);
    $manager = $this->createMock(ApplicantManager::class);
    $withdrawn = [];
    $manager->expects($this->exactly(2))->method('withdrawConsent')->willReturnCallback(function (ApplicantInterface $profile, string $kind) use (&$withdrawn): array {
      $withdrawn[] = [(int) $profile->id(), $kind];
      return [];
    });
    $manager->method('loginConfirmedKeys')->willReturn([]);
    $controller = $this->controller($entity_type_manager, $account, $this->allowingFlood(), $manager);

    $this->assertSame(Response::HTTP_OK, $controller->withdrawConsent('8', 'contact')->getStatusCode());
    $this->assertSame(Response::HTTP_OK, $controller->withdrawConsent('7', 'bank')->getStatusCode());
    $this->assertSame([[8, 'contact'], [7, 'bank']], $withdrawn);
  }

  /**
   * The withdrawal changes only the account's own profile, never applications.
   */
  public function testWithdrawChangesOnlyTheOwnProfile(): void {
    $applicant = $this->applicantWithFields([
      'field_email' => 'citizen@example.org',
      'field_consent_revoked_time' => '1781431300',
    ], 7, 42);
    [$entity_type_manager, $account] = $this->ownProfileLookup([7 => $applicant]);
    $manager = $this->createMock(ApplicantManager::class);
    $manager->expects($this->once())->method('withdrawConsent')->with($applicant, 'contact', 1781431300)->willReturn(['field_phone']);
    $manager->method('loginConfirmedKeys')->willReturn([]);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1781431300);

    $response = $this->controller($entity_type_manager, $account, $this->allowingFlood(), $manager, $time)->withdrawConsent('7', 'contact');

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $data = json_decode((string) $response->getContent(), TRUE)['data'];
    $this->assertSame(gmdate(DATE_ATOM, 1781431300), $data['consentWithdrawnAt']);
    $this->assertNull($data['consentAt']);
    $this->assertNull($data['loginConfirmed']);
  }

  /**
   * The profile names the login that confirmed identity values.
   */
  public function testSerializationNamesLoginConfirmedValues(): void {
    $applicant = $this->applicantWithFields([
      'field_full_name' => 'Max Mustermann',
      'field_login_confirmed_provider' => 'bundid_mock',
      'field_login_confirmed_time' => '1781431200',
    ]);
    $manager = $this->createMock(ApplicantManager::class);
    $manager->method('loginConfirmedKeys')->with($applicant)->willReturn(['person.full_name', 'person.birthdate']);
    $config = $this->createMock(Config::class);
    $config->method('get')->with('providers.bundid_mock.label')->willReturn('BundID Demo');
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('beantragt_sso.settings')->willReturn($config);

    $controller = new CitizenApplicantProfileController(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(AccountInterface::class),
      $this->createMock(FloodInterface::class),
      $this->createMock(LoggerInterface::class),
      $manager,
      NULL,
      $config_factory,
    );
    $method = new \ReflectionMethod($controller, 'serializeApplicant');
    $data = $method->invoke($controller, $applicant);

    $this->assertSame([
      'provider' => 'bundid_mock',
      'providerLabel' => 'BundID Demo',
      'at' => gmdate(DATE_ATOM, 1781431200),
      'fields' => ['fullName', 'birthdate'],
    ], $data['loginConfirmed']);
  }

  /**
   * Builds a JSON PATCH request.
   */
  private function patchRequest(array $payload): Request {
    return new Request([], [], [], [], [], [], (string) json_encode($payload));
  }

  /**
   * Builds a flood service that allows the request.
   */
  private function allowingFlood(): FloodInterface {
    $flood = $this->createMock(FloodInterface::class);
    $flood->method('isAllowed')->willReturn(TRUE);
    return $flood;
  }

  /**
   * Builds the applicant lookup for account 42.
   *
   * @param array<int, \Drupal\beantragt_applicant\Entity\ApplicantInterface> $profiles
   *   Profiles owned by account 42, keyed by ID.
   *
   * @return array{0: \Drupal\Core\Entity\EntityTypeManagerInterface, 1: \Drupal\Core\Session\AccountInterface}
   *   Entity type manager and account.
   */
  private function ownProfileLookup(array $profiles): array {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->with('uid', 42)->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $owned = array_filter($profiles, static fn(ApplicantInterface $profile): bool => (int) $profile->getOwnerId() === 42);
    $query->method('execute')->willReturn(array_combine(array_keys($owned), array_keys($owned)));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('load')->willReturnCallback(static fn(int|string $id) => $profiles[(int) $id] ?? NULL);
    $storage->method('loadMultiple')->willReturnCallback(static fn(array $ids): array => array_intersect_key($profiles, $ids));
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    // Only the applicant storage may be touched: applications and their
    // consent records stay unchanged.
    $entity_type_manager->method('getStorage')->with('applicant')->willReturn($storage);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(42);
    return [$entity_type_manager, $account];
  }

  /**
   * Builds an applicant mock with string field values.
   */
  private function applicantWithFields(array $fields, int $id = 1, int $owner = 0): ApplicantInterface {
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('id')->willReturn($id);
    $applicant->method('getOwnerId')->willReturn($owner);
    $applicant->method('hasField')->willReturn(TRUE);
    $applicant->method('getCreatedTime')->willReturn(1781431000);
    $applicant->method('get')->willReturnCallback(function (string $name) use ($fields): FieldItemListInterface {
      $value = $fields[$name] ?? NULL;
      $items = $this->createMock(FieldItemListInterface::class);
      $items->method('isEmpty')->willReturn($value === NULL || $value === '');
      $items->method('__get')->willReturnCallback(static fn(string $property): mixed => $property === 'value' ? $value : NULL);
      return $items;
    });
    return $applicant;
  }

  /**
   * Builds an entity type manager whose application storage finds one UUID.
   */
  private function applicationStorageReturning(string $uuid, ApplicationInterface $application): EntityTypeManagerInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->with(['uuid' => $uuid])->willReturn([5 => $application]);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('application')->willReturn($storage);
    return $entity_type_manager;
  }

  /**
   * Builds an applicant whose consent source fields hold one UUID.
   */
  private function applicantWithSource(string $uuid): ApplicantInterface {
    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('isEmpty')->willReturn(FALSE);
    $items->method('__get')->with('value')->willReturn($uuid);
    $applicant = $this->createMock(ApplicantInterface::class);
    $applicant->method('hasField')->willReturn(TRUE);
    $applicant->method('get')->willReturn($items);
    return $applicant;
  }

  /**
   * Invokes consentScopeLabel().
   */
  private function invokeConsentScopeLabel(
    CitizenApplicantProfileController $controller,
    ApplicantInterface $applicant,
    string $source_field,
    bool $bank,
  ): ?string {
    $method = new \ReflectionMethod($controller, 'consentScopeLabel');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $applicant, $source_field, $bank);
  }

  /**
   * Creates a controller with mocked infrastructure.
   */
  private function controller(
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
    ?AccountInterface $account = NULL,
    ?FloodInterface $flood = NULL,
    ?ApplicantManager $manager = NULL,
    ?TimeInterface $time = NULL,
  ): CitizenApplicantProfileController {
    return new CitizenApplicantProfileController(
      $entity_type_manager ?? $this->createMock(EntityTypeManagerInterface::class),
      $account ?? $this->createMock(AccountInterface::class),
      $flood ?? $this->createMock(FloodInterface::class),
      $this->createMock(LoggerInterface::class),
      $manager,
      $time,
    );
  }

  /**
   * Invokes profileUpdateFloodResponse().
   */
  private function invokeProfileUpdateFloodResponse(
    CitizenApplicantProfileController $controller,
  ): ?JsonResponse {
    $method = new \ReflectionMethod($controller, 'profileUpdateFloodResponse');
    $method->setAccessible(TRUE);
    return $method->invoke($controller);
  }

  /**
   * Invokes normalizeUpdates().
   */
  private function invokeNormalizeUpdates(
    CitizenApplicantProfileController $controller,
    array $data,
  ): array|JsonResponse {
    $method = new \ReflectionMethod($controller, 'normalizeUpdates');
    $method->setAccessible(TRUE);
    return $method->invoke($controller, $data);
  }

  /**
   * Invokes loadOwnApplicants().
   */
  private function invokeLoadOwnApplicants(
    CitizenApplicantProfileController $controller,
  ): array|JsonResponse {
    $method = new \ReflectionMethod($controller, 'loadOwnApplicants');
    $method->setAccessible(TRUE);
    return $method->invoke($controller);
  }

}
