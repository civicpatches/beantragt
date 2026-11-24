<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant\Controller;

use Drupal\beantragt_applicant\Entity\ApplicantInterface;
use Drupal\beantragt_applicant\Service\ApplicantManager;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\ProfileConsentScope;
use Drupal\beantragt_application\ProfileConsentText;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides an allowlisted self-service API for the current citizen profile.
 */
final class CitizenApplicantProfileController implements ContainerInjectionInterface {

  private const PROFILE_UPDATE_FLOOD_EVENT = 'beantragt_applicant.citizen_profile_update';
  private const PROFILE_UPDATE_FLOOD_LIMIT = 20;
  private const PROFILE_UPDATE_FLOOD_WINDOW = 60;
  private const OWN_PROFILE_LIMIT = 50;

  /**
   * Identity fields returned only for the profile of a form's Stelle.
   */
  private const PREFILL_IDENTITY_FIELDS = [
    'firstName' => 'field_first_name',
    'lastName' => 'field_last_name',
    'birthdate' => 'field_birthdate',
    'gender' => 'field_gender',
    'nationality' => 'field_nationality',
    'maritalStatus' => 'field_marital_status',
  ];
  private const FIELD_MAP = [
    'fullName' => ['field' => 'field_full_name', 'max' => 255],
    'phone' => ['field' => 'field_phone', 'max' => 50],
    'address' => ['field' => 'field_address', 'max' => 500],
    'street' => ['field' => 'field_street', 'max' => 255],
    'postalCode' => ['field' => 'field_postal_code', 'max' => 20],
    'city' => ['field' => 'field_city', 'max' => 255],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
    private readonly ?ApplicantManager $applicantManager = NULL,
    private readonly ?TimeInterface $time = NULL,
    private readonly ?ConfigFactoryInterface $configFactory = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('flood'),
      $container->get('logger.channel.beantragt_applicant'),
      $container->get('beantragt_applicant.manager'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
    );
  }

  /**
   * Returns the current citizen's persisted profiles.
   *
   * Profiles are kept per organisation (or jurisdiction), so one account can
   * own several. "profiles" lists every own profile, each with its id and
   * Stelle, so consents can be shown and withdrawn per Stelle. "data" is the
   * profile to show or prefill: with ?form=<form uuid> the profile of the
   * Stelle that form belongs to, resolved like the submission and the
   * profile sync resolve it (NULL when the citizen has none there); without
   * the parameter the primary profile (most recently changed) for the
   * "Meine Daten" overview. Only the form's profile also carries first and
   * last name, date of birth, gender, nationality and marital status for the
   * prefill.
   */
  public function get(?Request $request = NULL): JsonResponse {
    $applicants = $this->loadOwnApplicants();
    if ($applicants instanceof JsonResponse) {
      return $applicants;
    }

    $profiles = array_map(fn(ApplicantInterface $applicant): array => $this->serializeApplicant($applicant), $applicants);
    $data = $profiles[0] ?? NULL;
    $form_id = $request?->query->get('form');
    if ($form_id !== NULL) {
      $selected = $this->profileForForm((string) $form_id, $applicants);
      $data = $selected instanceof ApplicantInterface
        ? $this->serializeApplicant($selected) + $this->prefillIdentity($selected)
        : NULL;
    }

    return $this->privateJson([
      'data' => $data,
      'profiles' => $profiles,
    ]);
  }

  /**
   * Returns the further identity values a form may prefill.
   *
   * First and last name (for forms that ask for them apart, as FIM forms
   * do), date of birth, gender, nationality and marital status are covered by
   * the contact consent (registered data, decision of 2026-10-21) and prefill
   * only the form of the Stelle the profile belongs to, so they are part of
   * the answer for ?form= only, never of the profile list.
   *
   * @return array{firstName: string, lastName: string, birthdate: string, gender: string, nationality: string, maritalStatus: string}
   *   Values keyed by contract name, empty strings when not stored.
   */
  private function prefillIdentity(ApplicantInterface $applicant): array {
    $values = [];
    foreach (self::PREFILL_IDENTITY_FIELDS as $name => $field_name) {
      $values[$name] = $this->optionalString($applicant, $field_name) ?? '';
    }
    return $values;
  }

  /**
   * Picks the own profile kept for the Stelle of a form.
   *
   * The form's groups are resolved like the public submission stores them on
   * the application (route organisation, else the default organisation of
   * the jurisdiction; field_group normalized to that organisation, else the
   * jurisdiction), and the profile is matched with the precedence of the
   * profile sync, so a form prefills only from the profile its consent and
   * withdrawal apply to.
   *
   * @param string $form_uuid
   *   UUID of the form schema.
   * @param list<\Drupal\beantragt_applicant\Entity\ApplicantInterface> $applicants
   *   Own profiles, primary first.
   */
  private function profileForForm(string $form_uuid, array $applicants): ?ApplicantInterface {
    if ($applicants === [] || $this->applicantManager === NULL || !Uuid::isValid($form_uuid)) {
      return NULL;
    }
    try {
      $forms = $this->entityTypeManager->getStorage('form_schema')->loadByProperties(['uuid' => $form_uuid]);
    }
    catch (\Throwable) {
      return NULL;
    }
    $form = reset($forms);
    if (!$form instanceof ContentEntityInterface) {
      return NULL;
    }

    $groups = ProfileConsentScope::submissionGroups(
      $form,
      $this->entityTypeManager,
      fn(): string => $this->configFactory instanceof ConfigFactoryInterface
        ? (string) ($this->configFactory->get('beantragt_group.settings')->get('default_organisation_slug') ?? '')
        : '',
    );
    // Saving a scoped entity normalizes field_group to the organisation, else
    // the jurisdiction (beantragt_group presave), so the application the sync
    // reads never keeps the form's legacy group; the scope mirrors that.
    $scope = [];
    foreach (['field_jurisdiction' => 'jurisdiction', 'field_organisation' => 'organisation'] as $field_name => $key) {
      if ($groups[$key] instanceof EntityInterface) {
        $scope[$field_name] = ['target_id' => (int) $groups[$key]->id()];
      }
    }
    $group = $groups['organisation'] ?? $groups['jurisdiction'];
    if ($group instanceof EntityInterface) {
      $scope['field_group'] = ['target_id' => (int) $group->id()];
    }
    return $this->applicantManager->selectProfileForScope($applicants, $scope);
  }

  /**
   * Updates allowlisted basic fields on the current citizen's own profile.
   */
  public function patch(Request $request): JsonResponse {
    $flood_response = $this->profileUpdateFloodResponse();
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data']) || $payload['data'] === []) {
      return $this->error('errors.citizen.profileSaveFailed', Response::HTTP_BAD_REQUEST);
    }

    $updates = $this->normalizeUpdates($payload['data']);
    if ($updates instanceof JsonResponse) {
      return $updates;
    }

    // The profile is named by id, like the withdrawal: an account owns one
    // profile per Stelle, and "most recently changed" moves with every sync
    // and withdrawal. Without an id only an account with exactly one profile
    // can be edited.
    $profile_id = $payload['id'] ?? NULL;
    if ($profile_id !== NULL) {
      if (!is_int($profile_id) && !(is_string($profile_id) && ctype_digit($profile_id))) {
        return $this->error('errors.citizen.profileSaveFailed', Response::HTTP_BAD_REQUEST);
      }
      $applicant = $this->loadOwnApplicant((string) $profile_id);
      if ($applicant instanceof JsonResponse) {
        return $applicant;
      }
    }
    else {
      $applicants = $this->loadOwnApplicants();
      if ($applicants instanceof JsonResponse) {
        return $applicants;
      }
      if (count($applicants) > 1) {
        return $this->error('errors.citizen.profileAmbiguous', Response::HTTP_CONFLICT);
      }
      $applicant = $applicants[0] ?? NULL;
    }
    if (!$applicant instanceof ApplicantInterface) {
      return $this->error('errors.citizen.profileNotFound', Response::HTTP_NOT_FOUND);
    }

    try {
      // The manager applies the edit to a copy loaded inside the profile
      // lock, so it never writes back values that a withdrawal or sync
      // changed after this request loaded the profile.
      if ($this->applicantManager instanceof ApplicantManager) {
        $applicant = $this->applicantManager->updateOwnProfileFields($applicant, $updates);
      }
      else {
        foreach ($updates as $field_name => $value) {
          $applicant->set($field_name, $value);
        }
        $applicant->save();
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Citizen profile update failed for uid @uid: @message', [
        '@uid' => (int) $this->currentUser->id(),
        '@message' => $exception->getMessage(),
      ]);
      return $this->error('errors.citizen.profileSaveFailed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $this->logger->notice('Citizen uid @uid updated own applicant profile fields @fields.', [
      '@uid' => (int) $this->currentUser->id(),
      '@fields' => implode(', ', array_keys($updates)),
    ]);

    return $this->privateJson(['data' => $this->serializeApplicant($applicant)]);
  }

  /**
   * Withdraws one consent (contact or bank) on the citizen's own profile.
   *
   * Withdrawal clears the profile values covered by the consent and
   * prevents consent given at or before withdrawal from syncing values back
   * into the profile (Art. 7(3) GDPR).
   * Applications and their consent records stay unchanged as proof (Art. 7(1)
   * GDPR). Identity values a login confirmed are kept; deleting them is the
   * job of the complete deletion. The profile is named by id, because an
   * account owns one profile per Stelle; a profile of another account is
   * answered like a missing one.
   */
  public function withdrawConsent(string $profile, string $kind): JsonResponse {
    if (!in_array($kind, ProfileConsentText::kinds(), TRUE)) {
      return $this->error('errors.citizen.consentWithdrawFailed', Response::HTTP_NOT_FOUND);
    }
    $flood_response = $this->profileUpdateFloodResponse();
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $applicant = $this->loadOwnApplicant($profile);
    if ($applicant instanceof JsonResponse) {
      return $applicant;
    }
    if (!$applicant instanceof ApplicantInterface || $this->applicantManager === NULL) {
      return $this->error('errors.citizen.profileNotFound', Response::HTTP_NOT_FOUND);
    }

    try {
      $cleared = $this->applicantManager->withdrawConsent($applicant, $kind, $this->now());
      // The withdrawal worked on a copy loaded inside the profile lock; show
      // the stored profile values.
      $stored = $this->entityTypeManager->getStorage('applicant')->loadUnchanged($applicant->id());
      if ($stored instanceof ApplicantInterface) {
        $applicant = $stored;
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('Consent withdrawal failed for uid @uid: @class', [
        '@uid' => (int) $this->currentUser->id(),
        '@class' => $exception::class,
      ]);
      return $this->error('errors.citizen.consentWithdrawFailed', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $this->logger->notice('Citizen uid @uid withdrew the @kind consent; fields cleared: @fields.', [
      '@uid' => (int) $this->currentUser->id(),
      '@kind' => $kind,
      '@fields' => $cleared === [] ? 'none' : implode(', ', $cleared),
    ]);

    return $this->privateJson(['data' => $this->serializeApplicant($applicant)]);
  }

  /**
   * Returns the current request time.
   */
  private function now(): int {
    return $this->time instanceof TimeInterface ? $this->time->getRequestTime() : time();
  }

  /**
   * Loads every applicant owned by the current account, primary first.
   *
   * Primary is the most recently changed profile, ties broken by the higher
   * id, so the order is stable between GET and PATCH.
   *
   * @return list<\Drupal\beantragt_applicant\Entity\ApplicantInterface>|\Symfony\Component\HttpFoundation\JsonResponse
   *   Own profiles, or an error response for anonymous requests.
   */
  private function loadOwnApplicants(): array|JsonResponse {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return $this->error('errors.citizen.notAuthenticated', Response::HTTP_UNAUTHORIZED);
    }

    $storage = $this->entityTypeManager->getStorage('applicant');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->sort('changed', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, self::OWN_PROFILE_LIMIT)
      ->execute();

    $applicants = [];
    foreach ($ids === [] ? [] : $storage->loadMultiple($ids) as $applicant) {
      if ($applicant instanceof ApplicantInterface && (int) $applicant->getOwnerId() === $uid) {
        $applicants[] = $applicant;
      }
    }
    return $applicants;
  }

  /**
   * Loads one applicant by id when the current account owns it.
   *
   * @return \Drupal\beantragt_applicant\Entity\ApplicantInterface|\Symfony\Component\HttpFoundation\JsonResponse|null
   *   The own profile, an error response for anonymous requests, or NULL
   *   when the id is unknown or belongs to another account.
   */
  private function loadOwnApplicant(string $id): ApplicantInterface|JsonResponse|null {
    $uid = (int) $this->currentUser->id();
    if ($uid <= 0) {
      return $this->error('errors.citizen.notAuthenticated', Response::HTTP_UNAUTHORIZED);
    }
    if (!ctype_digit($id) || (int) $id <= 0) {
      return NULL;
    }

    $applicant = $this->entityTypeManager->getStorage('applicant')->load((int) $id);
    return $applicant instanceof ApplicantInterface && (int) $applicant->getOwnerId() === $uid
      ? $applicant
      : NULL;
  }

  /**
   * Normalizes and validates requested field updates.
   *
   * @param array<string, mixed> $data
   *   Request data.
   *
   * @return array<string, string>|\Symfony\Component\HttpFoundation\JsonResponse
   *   Field updates keyed by entity field name, or an error response.
   */
  private function normalizeUpdates(array $data): array|JsonResponse {
    $updates = [];
    foreach ($data as $key => $value) {
      if (!isset(self::FIELD_MAP[$key]) || (!is_string($value) && $value !== NULL)) {
        return $this->error('errors.citizen.profileSaveFailed', Response::HTTP_BAD_REQUEST);
      }

      $normalized = trim((string) ($value ?? ''));
      $definition = self::FIELD_MAP[$key];
      if (mb_strlen($normalized) > $definition['max']) {
        return $this->error('errors.citizen.profileSaveFailed', Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      $updates[$definition['field']] = $normalized;
    }

    return $updates;
  }

  /**
   * Limits profile mutations at the authoritative Drupal boundary.
   */
  private function profileUpdateFloodResponse(): ?JsonResponse {
    $identifier = (string) ((int) $this->currentUser->id());
    if (!$this->flood->isAllowed(
      self::PROFILE_UPDATE_FLOOD_EVENT,
      self::PROFILE_UPDATE_FLOOD_LIMIT,
      self::PROFILE_UPDATE_FLOOD_WINDOW,
      $identifier,
    )) {
      return $this->error('errors.rateLimit.tooManyRequests', Response::HTTP_TOO_MANY_REQUESTS);
    }

    $this->flood->register(
      self::PROFILE_UPDATE_FLOOD_EVENT,
      self::PROFILE_UPDATE_FLOOD_WINDOW,
      $identifier,
    );
    return NULL;
  }

  /**
   * Serializes the citizen-editable profile contract.
   */
  private function serializeApplicant(ApplicantInterface $applicant): array {
    return [
      'id' => (int) $applicant->id(),
      'scopeLabel' => $this->profileScopeLabel($applicant),
      'email' => (string) ($applicant->get('field_email')->value ?? ''),
      'fullName' => (string) ($applicant->get('field_full_name')->value ?? ''),
      'phone' => (string) ($applicant->get('field_phone')->value ?? ''),
      'address' => (string) ($applicant->get('field_address')->value ?? ''),
      'street' => (string) ($applicant->get('field_street')->value ?? ''),
      'postalCode' => (string) ($applicant->get('field_postal_code')->value ?? ''),
      'city' => (string) ($applicant->get('field_city')->value ?? ''),
      'createdAt' => gmdate(DATE_ATOM, $applicant->getCreatedTime()),
      'consentAt' => $this->consentTime($applicant, 'field_consent_time'),
      'consentTextVersion' => $this->optionalString($applicant, 'field_consent_text_version'),
      'consentSource' => $this->optionalString($applicant, 'field_consent_source'),
      'consentScopeLabel' => $this->consentScopeLabel($applicant, 'field_consent_source', FALSE),
      'bankConsentAt' => $this->consentTime($applicant, 'field_bank_consent_time'),
      'bankConsentTextVersion' => $this->optionalString($applicant, 'field_bank_consent_text_version'),
      'bankConsentSource' => $this->optionalString($applicant, 'field_bank_consent_source'),
      'bankConsentScopeLabel' => $this->consentScopeLabel($applicant, 'field_bank_consent_source', TRUE),
      'consentWithdrawnAt' => $this->consentTime($applicant, 'field_consent_revoked_time'),
      'bankConsentWithdrawnAt' => $this->consentTime($applicant, 'field_bank_consent_revoked_time'),
      'loginConfirmed' => $this->loginConfirmation($applicant),
    ];
  }

  /**
   * Describes identity values a sign-in confirmed, NULL when there are none.
   *
   * @return array{provider: string, providerLabel: string, at: ?string, fields: list<string>}|null
   *   Provider, its label, login time and the confirmed profile fields in
   *   the contract names (email, fullName, firstName, lastName, birthdate).
   */
  private function loginConfirmation(ApplicantInterface $applicant): ?array {
    $provider = $this->optionalString($applicant, 'field_login_confirmed_provider');
    if ($provider === NULL || $this->applicantManager === NULL) {
      return NULL;
    }
    $names = [
      'person.email' => 'email',
      'person.full_name' => 'fullName',
      'person.first_name' => 'firstName',
      'person.last_name' => 'lastName',
      'person.birthdate' => 'birthdate',
    ];
    $fields = [];
    foreach ($this->applicantManager->loginConfirmedKeys($applicant) as $crm_key) {
      if (isset($names[$crm_key])) {
        $fields[] = $names[$crm_key];
      }
    }
    if ($fields === []) {
      return NULL;
    }
    return [
      'provider' => $provider,
      'providerLabel' => $this->providerLabel($provider),
      'at' => $this->consentTime($applicant, 'field_login_confirmed_time'),
      'fields' => $fields,
    ];
  }

  /**
   * Returns the configured label of a sign-in provider, else its id.
   */
  private function providerLabel(string $provider): string {
    if ($this->configFactory instanceof ConfigFactoryInterface) {
      $label = $this->configFactory->get('beantragt_sso.settings')->get('providers.' . $provider . '.label');
      if (is_string($label) && trim($label) !== '') {
        return trim($label);
      }
    }
    return $provider;
  }

  /**
   * Returns the label of the Stelle a profile belongs to.
   *
   * Read from the profile's own scope, not from a consent record, so a
   * profile keeps its heading after a withdrawal cleared the consent source.
   * Organisation and jurisdiction are both named ("Bürgerbüro, Stadt
   * Musterstadt"): a form addressed to the jurisdiction names the
   * jurisdiction in its consent text, while the profile is kept at the
   * organisation that receives such forms.
   */
  private function profileScopeLabel(ApplicantInterface $applicant): ?string {
    $labels = [];
    foreach (['field_organisation', 'field_jurisdiction'] as $field_name) {
      if (!$applicant->hasField($field_name) || $applicant->get($field_name)->isEmpty()) {
        continue;
      }
      $group = $applicant->get($field_name)->entity;
      if ($group instanceof EntityInterface) {
        $label = trim((string) $group->label());
        if ($label !== '' && !in_array($label, $labels, TRUE)) {
          $labels[] = $label;
        }
      }
    }
    return $labels === [] ? NULL : implode(', ', $labels);
  }

  /**
   * Reads the organisation or jurisdiction label named by a consent.
   *
   * The label lives in the consent record of the application that carried
   * the latest consent. Only the label leaves this method.
   */
  private function consentScopeLabel(ApplicantInterface $applicant, string $source_field, bool $bank): ?string {
    $source = $this->optionalString($applicant, $source_field);
    if ($source === NULL) {
      return NULL;
    }
    try {
      $applications = $this->entityTypeManager->getStorage('application')->loadByProperties(['uuid' => $source]);
    }
    catch (\Throwable) {
      return NULL;
    }
    $application = reset($applications);
    if (!$application instanceof ApplicationInterface) {
      return NULL;
    }
    $given = $bank ? $application->hasBankConsent() : $application->hasProfileConsent();
    if (!$given) {
      return NULL;
    }
    $record = $bank ? $application->getBankConsentRecord() : $application->getProfileConsentRecord();
    $label = is_array($record['scope'] ?? NULL) ? ($record['scope']['label'] ?? NULL) : NULL;
    return is_string($label) && $label !== '' ? $label : NULL;
  }

  /**
   * Formats a consent timestamp field, NULL when absent.
   */
  private function consentTime(ApplicantInterface $applicant, string $field_name): ?string {
    if (!$applicant->hasField($field_name) || $applicant->get($field_name)->isEmpty()) {
      return NULL;
    }
    $time = (int) $applicant->get($field_name)->value;
    return $time > 0 ? gmdate(DATE_ATOM, $time) : NULL;
  }

  /**
   * Reads an optional string field, NULL when absent or empty.
   */
  private function optionalString(ApplicantInterface $applicant, string $field_name): ?string {
    if (!$applicant->hasField($field_name) || $applicant->get($field_name)->isEmpty()) {
      return NULL;
    }
    $value = (string) $applicant->get($field_name)->value;
    return $value !== '' ? $value : NULL;
  }

  /**
   * Creates a private JSON error response.
   */
  private function error(string $code, int $status): JsonResponse {
    return $this->privateJson([
      'error' => [
        'code' => $code,
        'message' => $code,
      ],
    ], $status);
  }

  /**
   * Creates a no-store JSON response for citizen profile data.
   *
   * @param array<string, mixed> $data
   *   Response payload.
   * @param int $status
   *   HTTP response status code.
   */
  private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
