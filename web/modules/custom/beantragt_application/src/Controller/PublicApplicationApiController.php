<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Controller;

use Drupal\beantragt_application\Delivery\DeliveryRoute;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\ProfileConsentOffer;
use Drupal\beantragt_application\ProfileConsentScope;
use Drupal\beantragt_application\ProfileConsentText;
use Drupal\beantragt_application\PublicSubmissionReceipt;
use Drupal\beantragt_application\Service\ApplicantProfileSwitch;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\beantragt_application\Service\EmailConfirmationTokenService;
use Drupal\beantragt_application\Service\PublicApplicationAttachmentClaimService;
use Drupal\beantragt_application\Service\PublicApplicationIdempotencyService;
use Drupal\beantragt_application\Service\PublicApplicationSubmissionValidator;
use Drupal\beantragt_compute_api\ComputedFieldEvaluatorInterface;
use Drupal\beantragt_roles_api\BeantragtRoles;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public v1 application submission API.
 */
final class PublicApplicationApiController extends ControllerBase {

  private const API_VERSION = '1.0';
  private const FLOOD_LIMIT = 10;
  private const FLOOD_WINDOW = 60;
  private const TARGET_FLOOD_LIMIT = 3;
  private const MAX_BODY_BYTES = 262144;
  private const IDENTITY_FULL_NAME_MAX_LENGTH = 200;
  private const IDENTITY_EMAIL_MAX_LENGTH = 254;
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
  private const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/u';

  /**
   * Locales a submission may carry; each needs a profile consent text.
   */
  public const SUBMISSION_LOCALES = [
    'de' => TRUE,
    'en' => TRUE,
  ];

  /**
   * Validates public submission payloads.
   */
  private readonly PublicApplicationSubmissionValidator $submissionValidator;

  /**
   * Handles public submission idempotency replay state.
   */
  private readonly PublicApplicationIdempotencyService $idempotencyService;

  /**
   * Validates and claims public attachment upload tokens.
   */
  private readonly PublicApplicationAttachmentClaimService $attachmentClaims;

  /**
   * Issues and dispatches public email confirmation tokens.
   */
  private readonly EmailConfirmationTokenService $emailConfirmationTokens;

  /**
   * Request time source for consent records.
   */
  private readonly TimeInterface $time;

  /**
   * Reads the jurisdiction switch for applicant profile reuse.
   */
  private readonly ApplicantProfileSwitch $applicantProfileSwitch;

  /**
   * Constructs the public application API controller.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    private readonly Connection $database,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
    TimeInterface $time,
    LockBackendInterface $lock,
    KeyValueStoreExpirableInterface $idempotencyStore,
    ConfigFactoryInterface $config_factory,
    private readonly ?ComputedFieldEvaluatorInterface $computedEvaluator = NULL,
    ?AttachmentStorageQuota $attachmentStorageQuota = NULL,
    private ?ApplicationDataProtectionService $dataProtection = NULL,
    ?PublicApplicationSubmissionValidator $submission_validator = NULL,
    ?PublicApplicationIdempotencyService $idempotency_service = NULL,
    ?PublicApplicationAttachmentClaimService $attachment_claims = NULL,
    ?EmailConfirmationTokenService $email_confirmation_tokens = NULL,
    ?ApplicantProfileSwitch $applicant_profile_switch = NULL,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->applicantProfileSwitch = $applicant_profile_switch ?? new ApplicantProfileSwitch();
    $this->submissionValidator = $submission_validator ?? new PublicApplicationSubmissionValidator();
    $this->idempotencyService = $idempotency_service ?? new PublicApplicationIdempotencyService($idempotencyStore, $lock, $time);
    $this->attachmentClaims = $attachment_claims ?? new PublicApplicationAttachmentClaimService($entity_type_manager, $config_factory, $time, $lock, $attachmentStorageQuota);
    $this->emailConfirmationTokens = $email_confirmation_tokens ?? new EmailConfirmationTokenService(
      $entity_type_manager,
      $database,
      $config_factory,
      $time,
      $logger,
      $this->dataProtection,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $idempotency_store = $container->get('keyvalue.expirable')->get('beantragt_application.public_api_idempotency');
    if (!$idempotency_store instanceof KeyValueStoreExpirableInterface) {
      throw new \LogicException('The public application idempotency store must be expirable.');
    }

    $module_handler = $container->get('module_handler');
    $computed_evaluator = $module_handler->moduleExists('beantragt_compute')
      ? $container->get('beantragt_compute.evaluator')
      : NULL;
    $data_protection = NULL;
    try {
      $data_protection = $container->get('beantragt_application.data_protection');
    }
    catch (ServiceNotFoundException) {
      $data_protection = NULL;
    }
    $email_confirmation_tokens = NULL;
    try {
      $email_confirmation_tokens = $container->get('beantragt_application.email_confirmation_tokens');
    }
    catch (ServiceNotFoundException) {
      $email_confirmation_tokens = NULL;
    }
    $applicant_profile_switch = $container->has('beantragt_application.applicant_profile_switch')
      ? $container->get('beantragt_application.applicant_profile_switch')
      : NULL;

    return new static(
          $container->get('entity_type.manager'),
          $container->get('database'),
          $container->get('flood'),
          $container->get('logger.channel.beantragt_application'),
          $container->get('datetime.time'),
          $container->get('lock'),
          $idempotency_store,
          $container->get('config.factory'),
          $computed_evaluator,
          $container->get('beantragt_application.attachment_storage_quota'),
          $data_protection,
          $container->get('beantragt_application.public_submission_validator'),
          $container->get('beantragt_application.public_idempotency'),
          $container->get('beantragt_application.public_attachment_claims'),
          $email_confirmation_tokens,
          $applicant_profile_switch,
      );
  }

  /**
   * Creates a public application from a published internet form.
   */
  public function createApplication(Request $request): JsonResponse {
    $body_result = $this->jsonBody($request);
    if ($body_result['error'] !== NULL) {
      $flood_response = $this->floodResponse($request);
      if ($flood_response !== NULL) {
        return $flood_response;
      }
      return $this->errorResponse(
            (string) $body_result['error']['code'],
            (string) $body_result['error']['message'],
            (int) $body_result['error']['status'],
        );
    }
    $body = $body_result['body'];

    $idempotency = $this->idempotencyService->context($request, $body);
    if ($idempotency['error'] !== NULL) {
      $flood_response = $this->floodResponse($request);
      if ($flood_response !== NULL) {
        return $flood_response;
      }
      return $this->errorResponse(
            (string) $idempotency['error']['code'],
            (string) $idempotency['error']['message'],
            (int) $idempotency['error']['status'],
        );
    }
    $attributes = $this->applicationAttributes($body);
    if ($attributes === NULL) {
      return $this->errorResponse('request.invalid_body', 'Request body must contain data.type=application and data.attributes.', Response::HTTP_BAD_REQUEST);
    }

    $form_uuid = $this->normalizeUuid($attributes['formId'] ?? NULL);
    if ($form_uuid === NULL) {
      return $this->errorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND);
    }
    if (($attributes['consentAccepted'] ?? NULL) !== TRUE) {
      return $this->errorResponse('consent.required', 'Consent must be accepted before submitting an application.', Response::HTTP_BAD_REQUEST);
    }
    if (!isset($attributes['data']) || !is_array($attributes['data']) || array_is_list($attributes['data'])) {
      return $this->errorResponse('application.data_missing', 'Application data must be a JSON object.', Response::HTTP_BAD_REQUEST);
    }
    $submission_locale = $this->normalizeSubmissionLocale($attributes['locale'] ?? NULL);
    if ($submission_locale === '') {
      return $this->errorResponse('locale.invalid', 'Submission locale is invalid.', Response::HTTP_BAD_REQUEST);
    }
    // Optional consents: anything but boolean TRUE means no consent, never 400.
    $profile_consent_requested = ($attributes['profileConsent'] ?? NULL) === TRUE;
    $bank_consent_requested = ($attributes['bankConsent'] ?? NULL) === TRUE;
    $consent_echo = [
      'contact' => $attributes['profileConsentOfferId'] ?? NULL,
      'bank' => $attributes['bankConsentOfferId'] ?? NULL,
    ];
    // Optional delivery route: anything outside the form offer falls back
    // to the form default, never 400.
    $requested_delivery_route = $attributes['deliveryRoute'] ?? NULL;

    $form = $this->loadPublicFormByUuid($form_uuid);
    if (!$form instanceof FormSchemaInterface) {
      return $this->errorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND);
    }

    $context = $this->submissionContext($form);
    if ($context === NULL) {
      return $this->errorResponse('form.not_found', 'Form not found.', Response::HTTP_NOT_FOUND);
    }

    $trusted_owner_id = $this->trustedSubmittedOwnerId($request, $attributes['ownerUuid'] ?? NULL);
    $confirmation_required = $trusted_owner_id === NULL;
    if (($context['auth']['mode'] ?? 'anonymous') === 'required' && $trusted_owner_id === NULL) {
      return $this->errorResponse('auth.required', 'This form requires authenticated submission.', Response::HTTP_UNAUTHORIZED);
    }

    $idempotency = $this->idempotencyService->scopedContext(
      $idempotency,
      $this->idempotencyScope($request),
      $form_uuid,
      $trusted_owner_id,
    );
    $idempotency_response = $this->idempotencyReplayResponse($idempotency);
    if ($idempotency_response !== NULL) {
      return $idempotency_response;
    }

    $flood_response = $this->floodResponse($request);
    if ($flood_response !== NULL) {
      return $flood_response;
    }

    $bounded = $this->submissionValidator->boundedSubmittedData($attributes['data']);
    if ($bounded['error'] !== NULL) {
      return $this->errorResponse(
            (string) $bounded['error']['code'],
            (string) $bounded['error']['message'],
            (int) $bounded['error']['status'],
            $bounded['error']['details'] ?? [],
        );
    }
    $bounded_data = $this->applyComputedSubmittedData($bounded['data'], $context);

    $attachment_validation = $this->attachmentClaims->validateClaims($attributes['attachments'] ?? NULL, $form, $context, $trusted_owner_id);
    if ($attachment_validation['error'] !== NULL) {
      return $this->errorResponse(
            (string) $attachment_validation['error']['code'],
            (string) $attachment_validation['error']['message'],
            (int) $attachment_validation['error']['status'],
            $attachment_validation['error']['details'] ?? [],
        );
    }

    $validation = $this->submissionValidator->sanitizeAndValidateData($bounded_data, $context, $attachment_validation['byField']);
    if ($validation['error'] !== NULL) {
      return $this->errorResponse(
            (string) $validation['error']['code'],
            (string) $validation['error']['message'],
            (int) $validation['error']['status'],
            $validation['error']['details'] ?? [],
        );
    }
    $sanitized_data = $validation['data'];

    $identity = $this->normalizeIdentity($attributes['identity'] ?? NULL);
    $full_name_match = $this->firstStringValueMatch($sanitized_data, $context['fullNameFieldIds']);
    $email_match = $this->firstStringValueMatch($sanitized_data, $context['emailFieldIds']);
    $submitted_full_name = $full_name_match['value'] ?? $identity['fullName'] ?? NULL;
    $schema_email = $this->normalizeEmail($email_match['value'] ?? NULL);
    $applicant_email = $schema_email ?? ($identity['email'] ?? NULL);

    if ($this->isRequiredRequirement($context['auth']['collect']['fullName'] ?? NULL) && $submitted_full_name === NULL) {
      return $this->errorResponse('identity.full_name_required', 'Applicant full name is required.', Response::HTTP_BAD_REQUEST);
    }
    if ($this->isRequiredRequirement($context['auth']['collect']['email'] ?? NULL) && $applicant_email === NULL) {
      return $this->errorResponse('identity.email_required', 'Applicant email is required.', Response::HTTP_BAD_REQUEST);
    }
    if ($submitted_full_name !== NULL && mb_strlen($submitted_full_name) > self::IDENTITY_FULL_NAME_MAX_LENGTH) {
      return $this->errorResponse('identity.full_name_too_long', 'Applicant full name is too long.', Response::HTTP_BAD_REQUEST);
    }
    if ($applicant_email !== NULL && strlen($applicant_email) > self::IDENTITY_EMAIL_MAX_LENGTH) {
      return $this->errorResponse('identity.email_too_long', 'Applicant email is too long.', Response::HTTP_BAD_REQUEST);
    }
    if ($applicant_email !== NULL && preg_match(self::EMAIL_PATTERN, $applicant_email) !== 1) {
      return $this->errorResponse('identity.email_invalid', 'Applicant email is invalid.', Response::HTTP_BAD_REQUEST);
    }
    if ($confirmation_required && $applicant_email === NULL) {
      return $this->errorResponse('identity.email_required', 'Applicant email is required for public email confirmation.', Response::HTTP_BAD_REQUEST);
    }

    $idempotency_lock_name = $this->idempotencyService->acquireLock($idempotency);
    if ($idempotency_lock_name === FALSE) {
      return $this->errorResponse('idempotency.in_progress', 'An application submission with this idempotency key is still in progress.', Response::HTTP_CONFLICT);
    }
    try {
      $idempotency_response = $this->idempotencyReplayResponse($idempotency);
      if ($idempotency_response !== NULL) {
        return $idempotency_response;
      }

      $target_flood_response = $this->targetFloodResponse($request, $form_uuid, $applicant_email);
      if ($target_flood_response !== NULL) {
        return $target_flood_response;
      }

      $owner_id = $trusted_owner_id ?? $this->publicSubmissionOwnerId();
      if ($owner_id === NULL) {
        return $this->errorResponse('application.owner_unavailable', 'Application owner is not configured.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      $sensitive_ids = is_array($context['sensitiveFieldIds']) ? $context['sensitiveFieldIds'] : [];
      $full_name_source_sensitive = isset($full_name_match['fieldId']) && isset($sensitive_ids[$full_name_match['fieldId']]);
      $email_source_sensitive = isset($email_match['fieldId']) && isset($sensitive_ids[$email_match['fieldId']]);

      if ($submitted_full_name !== NULL && !$context['hasCrmFullNameField'] && !$full_name_source_sensitive) {
        $sanitized_data['applicant_full_name'] = $submitted_full_name;
      }
      if ($schema_email !== NULL && !$context['hasCrmEmailField'] && !$email_source_sensitive) {
        $sanitized_data['applicant_email'] = $applicant_email;
      }

      $status_token = $this->publicStatusToken();
      $confirmation_url = NULL;
      $confirmation_values = $this->emailConfirmationTokens->confirmedCreateValues();
      if ($confirmation_required) {
        $pending_confirmation = $this->emailConfirmationTokens->pendingCreateValues();
        $confirmation_values = $pending_confirmation['values'];
        $confirmation_url = $this->emailConfirmationTokens->confirmationFrontendUrl($pending_confirmation['token']);
        if ($confirmation_url === NULL) {
          return $this->errorResponse('confirmation.url_unavailable', 'Confirmation link could not be generated.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
      }

      $attachment_lock_names = $this->attachmentClaims->acquireClaimLocks($attachment_validation['entities']);
      if ($attachment_lock_names === NULL) {
        return $this->errorResponse('attachment.claim_in_progress', 'Attachment claim is already in progress.', Response::HTTP_CONFLICT);
      }
      $tenant_quota_lock_name = $this->attachmentClaims->acquireTenantQuotaLock($context, $attachment_validation['entities']);
      if ($tenant_quota_lock_name === FALSE) {
        $this->attachmentClaims->releaseLocks($attachment_lock_names);
        return $this->errorResponse('attachment.tenant_storage_quota_locked', 'Tenant attachment storage quota is currently being checked.', Response::HTTP_CONFLICT);
      }
      $transaction = NULL;
      $reference_number = NULL;
      $response_data = [];
      $application = NULL;
      try {
        // Reload past the entity cache: an analysis callback may have written
        // the attachment between the first validation and the lock.
        $locked_attachment_validation = $this->attachmentClaims->validateClaims($attributes['attachments'] ?? NULL, $form, $context, $trusted_owner_id, TRUE);
        if ($locked_attachment_validation['error'] !== NULL) {
          return $this->errorResponse(
            (string) $locked_attachment_validation['error']['code'],
            (string) $locked_attachment_validation['error']['message'],
            (int) $locked_attachment_validation['error']['status'],
            $locked_attachment_validation['error']['details'] ?? [],
          );
        }
        $attachment_validation = $locked_attachment_validation;

        $transaction = $this->database->startTransaction();
        $application_values = $this->applicationCreateValues($form, $context, $sanitized_data, $applicant_email, $status_token, $owner_id, $submission_locale, $confirmation_values)
          + $this->profileConsentValues($form, $context, $submission_locale, $profile_consent_requested, $bank_consent_requested, $consent_echo)
          + $this->deliveryValues($form, $requested_delivery_route);
        $application = $this->entityTypeManager->getStorage('application')->create($application_values);
        if (!$application instanceof ApplicationInterface) {
          throw new EntityStorageException('Application storage returned an invalid entity.');
        }
        $application->setSubmitStatusUrl($this->publicStatusFrontendUrl($form, $context, $status_token));
        $application->save();
        $this->attachmentClaims->claim($this->attachmentClaims->visibleEntities($attachment_validation['entities'], $sanitized_data), $application, TRUE);
        $reference_number = $application->getReferenceNumber();
        if ($reference_number === NULL || trim($reference_number) === '') {
          throw new EntityStorageException('Application reference number was not generated.');
        }
        $response_data = [
          'id' => (string) $application->uuid(),
          'type' => 'application',
          'formId' => (string) $form->uuid(),
          'referenceNumber' => $reference_number,
          'statusToken' => $status_token,
          'statusUrl' => '/api/v1/status/' . rawurlencode($status_token) . '?schemaId=' . rawurlencode((string) $form->uuid()),
          'receipt' => PublicSubmissionReceipt::build($application),
        ];
        $top_level = [
          'confirmation' => [
            'required' => $confirmation_required,
          ],
        ];
        $this->idempotencyService->storeResponse($idempotency, $response_data, Response::HTTP_CREATED, $top_level);
        unset($transaction);
      }
      catch (\JsonException | \InvalidArgumentException) {
        $this->rollBack($transaction);
        $this->logger->warning('Public application v1 submission validation failed for form @form.', ['@form' => $form->uuid()]);
        return $this->errorResponse('application.save_failed', 'Application could not be saved.', Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      catch (EntityStorageException) {
        $this->rollBack($transaction);
        $this->logger->error('Public application v1 storage failed for form @form.', ['@form' => $form->uuid()]);
        return $this->errorResponse('application.save_failed', 'Application could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }
      catch (\Throwable) {
        $this->rollBack($transaction);
        $this->logger->error('Public application v1 submission failed for form @form.', ['@form' => $form->uuid()]);
        return $this->errorResponse('application.save_failed', 'Application could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }
      finally {
        $this->attachmentClaims->releaseLock($tenant_quota_lock_name);
        $this->attachmentClaims->releaseLocks($attachment_lock_names);
      }

      if ($confirmation_required) {
        $this->emailConfirmationTokens->dispatchConfirmationRequest($application, $confirmation_url, $applicant_email);
      }

      return $this->successResponse(
        $response_data,
        Response::HTTP_CREATED,
        FALSE,
        [
          'confirmation' => [
            'required' => $confirmation_required,
          ],
        ],
      );
    }
    finally {
      $this->idempotencyService->releaseLock($idempotency_lock_name);
    }
  }

  /**
   * Extracts the v1 JSON:API-like attributes object.
   *
   * @return array<string, mixed>|null
   *   Request attributes or NULL when the envelope is invalid.
   */
  private function applicationAttributes(array $body): ?array {
    $data = $body['data'] ?? NULL;
    if (!is_array($data) || array_is_list($data)) {
      return NULL;
    }
    if (($data['type'] ?? NULL) !== 'application') {
      return NULL;
    }
    $attributes = $data['attributes'] ?? NULL;
    return is_array($attributes) && !array_is_list($attributes) ? $attributes : NULL;
  }

  /**
   * Builds the replay scope for public application idempotency.
   */
  private function idempotencyScope(Request $request): string {
    return $this->trustedProxyFloodIdentifier($request)
      ?? 'ip:' . ($request->getClientIp() ?? 'unknown');
  }

  /**
   * Returns a stored idempotency response or conflict for a reused key.
   */
  private function idempotencyReplayResponse(array $idempotency): ?JsonResponse {
    $result = $this->idempotencyService->replay($idempotency);
    if ($result['error'] !== NULL) {
      return $this->errorResponse(
        (string) $result['error']['code'],
        (string) $result['error']['message'],
        (int) $result['error']['status'],
        $result['error']['details'] ?? [],
      );
    }
    if (!is_array($result['response']) || !is_int($result['status'])) {
      return NULL;
    }

    $top_level = is_array($result['topLevel'] ?? NULL) ? $result['topLevel'] : [];
    return $this->successResponse($result['response'] + ['receipt' => NULL], $result['status'], TRUE, $top_level);
  }

  /**
   * Loads a public form schema by UUID.
   */
  private function loadPublicFormByUuid(string $uuid): ?FormSchemaInterface {
    $matches = $this->entityTypeManager->getStorage('form_schema')->loadByProperties(['uuid' => $uuid]);
    if (count($matches) !== 1) {
      return NULL;
    }
    $form = reset($matches);
    if (!$form instanceof FormSchemaInterface) {
      return NULL;
    }
    return $form->isPublished()
        && $form->getSchemaStatus() === 'published'
        && $form->getPublicationScope() === 'internet'
        ? $form
        : NULL;
  }

  /**
   * Builds submission context from the stored form schema.
   *
   * @return array<string, mixed>|null
   *   Submission context or NULL when the schema cannot be parsed.
   */
  private function submissionContext(FormSchemaInterface $form): ?array {
    $schema = $this->decodeObjectJson($form->getSchema());
    if ($schema === NULL) {
      return NULL;
    }

    $fields = [];
    $full_name_ids = [];
    $email_ids = [];
    $sensitive_ids = [];
    $has_crm_full_name = FALSE;
    $has_crm_email = FALSE;
    $protect_identity_linkage = ApplicationDataProtectionService::schemaRequiresProtectedIdentityLinkage($schema);
    foreach (($schema['sections'] ?? []) as $section) {
      if (!is_array($section)) {
        continue;
      }
      foreach (($section['fields'] ?? []) as $field) {
        if (!is_array($field) || !is_string($field['id'] ?? NULL)) {
          continue;
        }
        $normalized = $this->submissionValidator->normalizeField($field);
        if ($normalized === NULL) {
          continue;
        }
        $fields[] = $normalized;
        if ($this->isSensitiveSchemaField($field)) {
          $sensitive_ids[(string) $field['id']] = TRUE;
        }
        if (($field['crm_key'] ?? NULL) === 'person.full_name') {
          $has_crm_full_name = TRUE;
        }
        if (($field['crm_key'] ?? NULL) === 'person.email') {
          $has_crm_email = TRUE;
        }
        if ($this->isFullNameField($field)) {
          $full_name_ids[] = (string) $field['id'];
        }
        if ($this->isApplicantEmailField($field) && !$this->isSensitiveSchemaField($field)) {
          $email_ids[] = (string) $field['id'];
        }
      }
    }
    if ($fields === []) {
      return NULL;
    }

    $auth = $this->submissionValidator->normalizeAuth($schema['auth'] ?? NULL);
    if ($full_name_ids === [] && !array_key_exists('fullName', $auth['collect'])) {
      $auth['collect']['fullName'] = 'required';
    }
    if ($email_ids === [] && !array_key_exists('email', $auth['collect'])) {
      $auth['collect']['email'] = 'required';
    }

    $groups = ProfileConsentScope::submissionGroups(
      $form,
      $this->entityTypeManager,
      fn(): string => (string) ($this->config('beantragt_group.settings')->get('default_organisation_slug') ?? ''),
    );
    $legacy_group = $groups['group'];
    $route_organisation = $groups['routeOrganisation'];
    $organisation = $groups['organisation'];
    $jurisdiction = $groups['jurisdiction'];

    return [
      'auth' => $auth,
      'schemaDefinition' => $schema,
      'fields' => $fields,
      'fullNameFieldIds' => $full_name_ids,
      'emailFieldIds' => $email_ids,
      'sensitiveFieldIds' => $sensitive_ids,
      'protectIdentityLinkage' => $protect_identity_linkage,
      'hasCrmFullNameField' => $has_crm_full_name,
      'hasCrmEmailField' => $has_crm_email,
      'jurisdiction' => $jurisdiction,
      'organisation' => $organisation,
      'routeOrganisation' => $route_organisation,
      'group' => $legacy_group,
    ];
  }

  /**
   * Applies server-authoritative computed field values before validation.
   *
   * @param array<string, mixed> $data
   *   Bounded submitted data.
   * @param array<string, mixed> $context
   *   Submission context.
   *
   * @return array<string, mixed>
   *   Submitted data with every declared computed field overwritten.
   */
  private function applyComputedSubmittedData(array $data, array $context): array {
    $schema = $context['schemaDefinition'] ?? NULL;
    if (!is_array($schema)) {
      return $data;
    }

    $computed_ids = $this->computedFieldIds($schema);
    if ($computed_ids === []) {
      return $data;
    }

    $computed = $this->computedEvaluator?->evaluate($schema, $data) ?? [];
    foreach ($computed_ids as $field_id) {
      $data[$field_id] = $computed[$field_id] ?? NULL;
    }
    return $data;
  }

  /**
   * Returns every field id declared as computed in a schema definition.
   *
   * @return string[]
   *   Computed field ids.
   */
  private function computedFieldIds(array $schema): array {
    if ($this->computedEvaluator instanceof ComputedFieldEvaluatorInterface) {
      return $this->computedEvaluator->computedFieldIds($schema);
    }

    $ids = [];
    $seen = [];
    foreach (($schema['sections'] ?? []) as $section) {
      if (!is_array($section)) {
        continue;
      }
      foreach (($section['fields'] ?? []) as $field) {
        if (!is_array($field) || !isset($field['computed']) || !is_string($field['id'] ?? NULL) || $field['id'] === '') {
          continue;
        }
        if (isset($seen[$field['id']])) {
          continue;
        }
        $seen[$field['id']] = TRUE;
        $ids[] = $field['id'];
      }
    }
    return $ids;
  }

  /**
   * Builds application entity create values.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   Public form schema.
   * @param array<string, mixed> $context
   *   Submission context.
   * @param array<string, mixed> $data
   *   Sanitized application data.
   * @param string|null $email
   *   Normalized applicant email.
   * @param string $status_token
   *   Plaintext public status token returned once after creation.
   * @param int $owner_id
   *   Service account owner id.
   * @param string|null $submission_locale
   *   Submission locale stored in workflow attributes.
   * @param array<string, mixed> $confirmation_values
   *   Email confirmation field values.
   *
   * @return array<string, mixed>
   *   Entity create values.
   */
  private function applicationCreateValues(FormSchemaInterface $form, array $context, array $data, ?string $email, string $status_token, int $owner_id, ?string $submission_locale, array $confirmation_values = []): array {
    $values = [
      'field_schema' => ['target_id' => (int) $form->id()],
      'field_data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
      'field_public_status_token_hash' => hash('sha256', $status_token),
      'uid' => $owner_id,
      'status' => 1,
    ];
    foreach ($confirmation_values as $field_name => $value) {
      $values[$field_name] = $value;
    }
    if ($email !== NULL) {
      if ((bool) ($context['protectIdentityLinkage'] ?? FALSE)) {
        $email_hash = $this->dataProtection()->applicantEmailLookupHash($email);
        if ($email_hash !== NULL) {
          $values['field_applicant_email_hash'] = $email_hash;
        }
      }
      else {
        $values['field_applicant_email'] = $email;
      }
    }
    if ($submission_locale !== NULL) {
      $values['field_workflow_attributes'] = json_encode(
        ['submissionLocale' => $submission_locale],
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
      );
    }
    foreach ([
      'field_jurisdiction' => $context['jurisdiction'],
      'field_organisation' => $context['organisation'],
      'field_group' => $context['group'],
    ] as $field_name => $group) {
      if ($group instanceof GroupInterface) {
        $values[$field_name] = ['target_id' => (int) $group->id()];
      }
    }
    return $values;
  }

  /**
   * Builds the consent field values for a new application.
   *
   * The submitted ID must match the current offer built from the same form
   * revision, office, locale, fields and version. Optional consent that no
   * longer matches does not prevent the application being filed.
   *
   * @param \Drupal\beantragt_schema\Entity\FormSchemaInterface $form
   *   Public form used for validating the application.
   * @param array<string, mixed> $context
   *   Submission context.
   * @param string|null $submission_locale
   *   Submission locale, NULL when the client sent none.
   * @param bool $profile_requested
   *   Whether the client sent profileConsent: true.
   * @param bool $bank_requested
   *   Whether the client sent bankConsent: true.
   * @param array{contact: mixed, bank: mixed} $echo
   *   IDs echoed by the client for the selected offers.
   *
   * @return array<string, mixed>
   *   Entity create values for the consent fields.
   */
  private function profileConsentValues(FormSchemaInterface $form, array $context, ?string $submission_locale, bool $profile_requested, bool $bank_requested, array $echo): array {
    $values = [
      'field_profile_consent' => FALSE,
      'field_bank_consent' => FALSE,
    ];
    if (!$profile_requested || !is_string($echo['contact'])) {
      return $values;
    }
    $offers = ProfileConsentOffer::build($form, $context['schemaDefinition'], $context, $this->applicantProfileSwitch, $submission_locale ?? ProfileConsentText::DEFAULT_LOCALE);
    if ($offers['contact'] === NULL || !hash_equals($offers['contact']['id'], $echo['contact'])) {
      return $values;
    }

    $values['field_profile_consent'] = TRUE;
    $values['field_profile_consent_record'] = $this->consentRecordJson($offers['contact'], $context);
    if ($bank_requested && is_string($echo['bank']) && $offers['bank'] !== NULL && hash_equals($offers['bank']['id'], $echo['bank'])) {
      $values['field_bank_consent'] = TRUE;
      $values['field_bank_consent_record'] = $this->consentRecordJson($offers['bank'], $context);
    }
    return $values;
  }

  /**
   * Delivery route and its route_chosen event for a public submission.
   *
   * The offer is the computed form_schema field of beantragt_docgen, which
   * this module does not depend on; without it the form is unconfigured.
   *
   * @return array{field_delivery_route: string, field_delivery_record: string}
   *   Entity create values.
   */
  private function deliveryValues(FormSchemaInterface $form, mixed $requested): array {
    $offer = DeliveryRoute::decodeOffer($form->hasField('field_delivery_offer') ? $form->get('field_delivery_offer')->value : NULL);
    return DeliveryRoute::createValues(DeliveryRoute::choose($offer, $requested, 'public_submit'), $this->time->getRequestTime());
  }

  /**
   * Encodes the exact accepted offer and the application scope at submission.
   */
  private function consentRecordJson(array $offer, array $context): string {
    return json_encode($offer + [
      'time' => $this->time->getRequestTime(),
      'source' => 'public_submit',
      'profileScope' => [
        'field_jurisdiction' => (int) $context['jurisdiction']?->id(),
        'field_organisation' => (int) $context['organisation']?->id(),
        'field_group' => (int) ($context['organisation'] ?? $context['jurisdiction'] ?? $context['group'])?->id(),
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /**
   * Builds the public frontend status URL for the initial confirmation mail.
   */
  private function publicStatusFrontendUrl(FormSchemaInterface $form, array $context, string $status_token): ?string {
    $base_url = $this->frontendBaseUrl();
    if ($base_url === '') {
      return NULL;
    }

    $jurisdiction_slug = $this->groupSlug($context['jurisdiction'] ?? NULL);
    if ($jurisdiction_slug === NULL) {
      return NULL;
    }

    $path = '/' . rawurlencode($jurisdiction_slug);
    $route_organisation = $context['routeOrganisation'] ?? NULL;
    $organisation_slug = $route_organisation instanceof GroupInterface ? $this->groupSlug($route_organisation) : NULL;
    if ($organisation_slug !== NULL) {
      $path .= '/' . rawurlencode($organisation_slug);
    }

    $form_uuid = $form->uuid();
    if (!is_string($form_uuid) || $form_uuid === '') {
      return NULL;
    }

    return $base_url . $path . '/form/' . rawurlencode($form_uuid) . '/status/' . rawurlencode($status_token);
  }

  /**
   * Lazily resolves application data protection.
   */
  private function dataProtection(): ApplicationDataProtectionService {
    if (!$this->dataProtection instanceof ApplicationDataProtectionService) {
      $this->dataProtection = new ApplicationDataProtectionService($this->entityTypeManager, $this->logger);
    }
    return $this->dataProtection;
  }

  /**
   * Resolves the configured frontend base URL for mail links.
   */
  private function frontendBaseUrl(): string {
    $base_url = trim((string) ($this->config('beantragt_group.settings')->get('frontend_base_url') ?? ''));
    if ($base_url === '') {
      return '';
    }

    $parts = parse_url($base_url);
    if (!is_array($parts)
      || empty($parts['scheme'])
      || empty($parts['host'])
      || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], TRUE)) {
      return '';
    }

    return rtrim($base_url, '/');
  }

  /**
   * Returns a safe public group slug.
   */
  private function groupSlug(mixed $group): ?string {
    if (!$group instanceof GroupInterface
      || !$group->hasField('field_slug')
      || $group->get('field_slug')->isEmpty()) {
      return NULL;
    }

    $slug = strtolower(trim((string) $group->get('field_slug')->value));
    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/', $slug) === 1 ? $slug : NULL;
  }

  /**
   * Resolves the configured service account as owner for anonymous submissions.
   */
  private function publicSubmissionOwnerId(): ?int {
    $service_user = trim((string) getenv('NUXT_DRUPAL_SERVICE_USER'));
    if ($service_user === '') {
      $this->logger->error('Public application v1 owner is not configured.');
      return NULL;
    }

    $matches = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $service_user]);
    if (count($matches) !== 1) {
      $this->logger->error('Public application v1 owner "@name" is not unique or does not exist.', ['@name' => $service_user]);
      return NULL;
    }
    $user = reset($matches);
    if (!BeantragtRoles::isServiceAccount($user)) {
      $this->logger->error('Public application v1 owner "@name" is not a service account.', ['@name' => $service_user]);
      return NULL;
    }
    $owner_id = (int) $user->id();
    return $owner_id > 0 ? $owner_id : NULL;
  }

  /**
   * Normalizes the optional submission locale.
   *
   * @return string|null
   *   Supported locale, NULL when omitted, or an empty string when invalid.
   */
  private function normalizeSubmissionLocale(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    if (!is_string($value)) {
      return '';
    }
    $langcode = strtolower(trim($value));
    return isset(self::SUBMISSION_LOCALES[$langcode]) ? $langcode : '';
  }

  /**
   * Reads JSON body as an object array.
   *
   * @return array{body: array<string, mixed>, error: array<string, mixed>|null}
   *   Decoded object and optional error.
   */
  private function jsonBody(Request $request): array {
    $content_length = $request->headers->get('Content-Length');
    if (is_string($content_length) && ctype_digit($content_length) && (int) $content_length > self::MAX_BODY_BYTES) {
      return [
        'body' => [],
        'error' => [
          'code' => 'request.too_large',
          'message' => 'Request body is too large.',
          'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        ],
      ];
    }

    $content = $request->getContent();
    if (strlen($content) > self::MAX_BODY_BYTES) {
      return [
        'body' => [],
        'error' => [
          'code' => 'request.too_large',
          'message' => 'Request body is too large.',
          'status' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        ],
      ];
    }
    $content = trim($content);
    if ($content === '') {
      return [
        'body' => [],
        'error' => [
          'code' => 'request.invalid_json',
          'message' => 'Request body must be valid JSON.',
          'status' => Response::HTTP_BAD_REQUEST,
        ],
      ];
    }
    try {
      $body = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [
        'body' => [],
        'error' => [
          'code' => 'request.invalid_json',
          'message' => 'Request body must be valid JSON.',
          'status' => Response::HTTP_BAD_REQUEST,
        ],
      ];
    }
    if (!is_array($body) || array_is_list($body)) {
      return [
        'body' => [],
        'error' => [
          'code' => 'request.invalid_json',
          'message' => 'Request body must be valid JSON.',
          'status' => Response::HTTP_BAD_REQUEST,
        ],
      ];
    }
    return ['body' => $body, 'error' => NULL];
  }

  /**
   * Decodes stored JSON as an object array.
   *
   * @return array<string, mixed>|null
   *   Decoded object or NULL.
   */
  private function decodeObjectJson(?string $json): ?array {
    if ($json === NULL || trim($json) === '') {
      return NULL;
    }
    try {
      $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($decoded) && !array_is_list($decoded) ? $decoded : NULL;
  }

  /**
   * Normalizes supplemental identity input.
   *
   * @return array{fullName?: string, email?: string}
   *   Normalized identity values.
   */
  private function normalizeIdentity(mixed $identity): array {
    if (!is_array($identity) || array_is_list($identity)) {
      return [];
    }
    $out = [];
    if (is_string($identity['fullName'] ?? NULL)) {
      $full_name = trim($identity['fullName']);
      if ($full_name !== '') {
        $out['fullName'] = $full_name;
      }
    }
    $email = $this->normalizeEmail($identity['email'] ?? NULL);
    if ($email !== NULL) {
      $out['email'] = $email;
    }
    return $out;
  }

  /**
   * Reads first non-empty string value and source from a submitted data map.
   *
   * @return array{value: string, fieldId: string}|null
   *   First non-empty value and source field id.
   */
  private function firstStringValueMatch(array $data, array $field_ids): ?array {
    foreach ($field_ids as $field_id) {
      if (is_string($data[$field_id] ?? NULL) && trim($data[$field_id]) !== '') {
        return [
          'value' => trim($data[$field_id]),
          'fieldId' => (string) $field_id,
        ];
      }
    }
    return NULL;
  }

  /**
   * Normalizes an email input.
   */
  private function normalizeEmail(mixed $email): ?string {
    if (!is_string($email)) {
      return NULL;
    }
    $normalized = strtolower(trim($email));
    return $normalized !== '' ? $normalized : NULL;
  }

  /**
   * Checks whether a form auth collection requirement is required.
   */
  private function isRequiredRequirement(mixed $value): bool {
    return $value === TRUE || $value === 'required';
  }

  /**
   * Detects full-name fields from schema metadata.
   */
  private function isFullNameField(array $field): bool {
    if (($field['crm_key'] ?? NULL) === 'person.full_name') {
      return TRUE;
    }
    $id = $this->normalizedFieldId($field['id'] ?? NULL);
    if (in_array($id, ['full_name', 'applicant_full_name', 'antragsteller_name'], TRUE)) {
      return TRUE;
    }
    $label = mb_strtolower(trim((string) ($field['label'] ?? '')));
    return in_array($label, ['name', 'vollstaendiger name', 'vollständiger name', 'ihr name'], TRUE)
        || preg_match('/^name der antragsteller/u', $label) === 1
        || preg_match('/^antragsteller.*name/u', $label) === 1;
  }

  /**
   * Detects email fields from schema metadata.
   */
  private function isApplicantEmailField(array $field): bool {
    if (($field['crm_key'] ?? NULL) === 'person.email') {
      return TRUE;
    }
    $id = $this->normalizedFieldId($field['id'] ?? NULL);
    return in_array($id, ['email', 'e_mail', 'applicant_email'], TRUE);
  }

  /**
   * Returns TRUE when a schema field is marked sensitive.
   */
  private function isSensitiveSchemaField(array $field): bool {
    $sensitive = $field['sensitive'] ?? FALSE;
    if (is_bool($sensitive)) {
      return $sensitive;
    }
    if (is_string($sensitive) || is_int($sensitive)) {
      return filter_var($sensitive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === TRUE;
    }
    return FALSE;
  }

  /**
   * Normalizes field id-like text for heuristics.
   */
  private function normalizedFieldId(mixed $value): string {
    return trim((string) preg_replace('/[^a-z0-9]+/u', '_', mb_strtolower((string) $value)), '_');
  }

  /**
   * Applies flood protection to public submission creates.
   */
  private function floodResponse(Request $request): ?JsonResponse {
    $identifier = $this->trustedProxyFloodIdentifier($request)
        ?? ($request->getClientIp() ?? 'unknown');
    if (!$this->flood->isAllowed('beantragt_application.public_create', self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      return $this->errorResponse('rate_limit.exceeded', 'Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register('beantragt_application.public_create', self::FLOOD_WINDOW, $identifier);
    return NULL;
  }

  /**
   * Applies target-level flood protection for a form and applicant target.
   */
  private function targetFloodResponse(Request $request, string $form_uuid, ?string $email): ?JsonResponse {
    $target = $email !== NULL
        ? hash('sha256', $email)
        : 'ip:' . ($this->trustedProxyFloodIdentifier($request) ?? ($request->getClientIp() ?? 'unknown'));
    $identifier = hash('sha256', $form_uuid . ':' . $target);
    if (!$this->flood->isAllowed('beantragt_application.public_create_target', self::TARGET_FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      return $this->errorResponse('rate_limit.exceeded', 'Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
    }
    $this->flood->register('beantragt_application.public_create_target', self::FLOOD_WINDOW, $identifier);
    return NULL;
  }

  /**
   * Resolves a citizen owner passed by the trusted Nuxt proxy.
   */
  private function trustedSubmittedOwnerId(Request $request, mixed $owner_uuid): ?int {
    $uuid = $this->normalizeUuid($owner_uuid);
    if ($uuid === NULL || !$this->isTrustedServiceRequest($request)) {
      return NULL;
    }

    $matches = $this->entityTypeManager->getStorage('user')->loadByProperties(['uuid' => $uuid]);
    if (count($matches) !== 1) {
      return NULL;
    }
    $user = reset($matches);
    if (!in_array('authenticated', $user->getRoles(), TRUE)
      || BeantragtRoles::isStaffAccount($user)
      || BeantragtRoles::isServiceAccount($user)) {
      return NULL;
    }
    $owner_id = (int) $user->id();
    return $owner_id > 0 ? $owner_id : NULL;
  }

  /**
   * Returns a Nuxt-proxied flood identifier after service-header validation.
   */
  private function trustedProxyFloodIdentifier(Request $request): ?string {
    if (!$this->isTrustedServiceRequest($request)) {
      return NULL;
    }

    $identifier = (string) $request->headers->get('X-Beantragt-Rate-Limit-Identifier', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $identifier)) {
      return NULL;
    }

    return 'proxy:' . $identifier;
  }

  /**
   * Returns TRUE when the request was signed by the Nuxt service proxy.
   */
  private function isTrustedServiceRequest(Request $request): bool {
    $expected = $this->expectedServiceAuthorizationHeader();
    $provided = (string) $request->headers->get('X-Beantragt-Service-Authorization', '');
    return $expected !== '' && hash_equals($expected, $provided);
  }

  /**
   * Builds the service secret value expected from the Nuxt proxy.
   */
  private function expectedServiceAuthorizationHeader(): string {
    $user = getenv('NUXT_DRUPAL_SERVICE_USER') ?: 'nuxt_service';
    $password = getenv('NUXT_DRUPAL_SERVICE_PASSWORD') ?: '';
    if ($password === '') {
      return '';
    }

    return 'Basic ' . base64_encode($user . ':' . $password);
  }

  /**
   * Rolls back an open transaction.
   */
  private function rollBack(?Transaction $transaction): void {
    if ($transaction instanceof Transaction) {
      $transaction->rollBack();
    }
  }

  /**
   * Builds a random public status token.
   */
  private function publicStatusToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  /**
   * Normalizes public UUID inputs.
   */
  private function normalizeUuid(mixed $uuid): ?string {
    if (!is_string($uuid)) {
      return NULL;
    }
    $uuid = strtolower(trim($uuid));
    return preg_match(self::UUID_PATTERN, $uuid) === 1 ? $uuid : NULL;
  }

  /**
   * Builds a v1 success response.
   */
  private function successResponse(array $data, int $status, bool $idempotency_replayed = FALSE, array $top_level = []): JsonResponse {
    $payload = [
      'data' => $data,
    ];
    foreach ($top_level as $key => $value) {
      $payload[$key] = $value;
    }
    $payload['meta'] = [
      'apiVersion' => self::API_VERSION,
      'resource' => 'application',
    ];
    if ($idempotency_replayed) {
      $payload['meta']['idempotency'] = [
        'replayed' => TRUE,
      ];
    }

    $response = $this->noStore(new JsonResponse($payload, $status));
    if ($idempotency_replayed) {
      $response->headers->set('Idempotency-Replayed', 'true');
    }
    return $response;
  }

  /**
   * Builds a v1 error response.
   */
  private function errorResponse(string $code, string $message, int $status, array $details = []): JsonResponse {
    $error = [
      'code' => $code,
      'message' => $message,
    ];
    if ($details !== []) {
      $error['details'] = $details;
    }
    return $this->noStore(
          new JsonResponse(
              [
                'error' => $error,
                'meta' => [
                  'apiVersion' => self::API_VERSION,
                ],
              ], $status
          )
      );
  }

  /**
   * Applies private no-store headers.
   */
  private function noStore(JsonResponse $response): JsonResponse {
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
