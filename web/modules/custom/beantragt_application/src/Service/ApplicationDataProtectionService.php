<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Service;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Exception\UnresolvableApplicationSchemaException;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;

/**
 * Protects sensitive values inside application.field_data.
 */
final class ApplicationDataProtectionService {

  /**
   * Marker used for encrypted JSON values.
   */
  public const ENCRYPTED_MARKER = '__beantragt_encrypted';

  /**
   * Marker used for redacted JSON values.
   */
  public const REDACTED_MARKER = '__beantragt_redacted';

  /**
   * State key for applications skipped by the historical data backfill.
   */
  public const BACKFILL_PENDING_STATE_KEY = 'beantragt_application.data_backfill_pending';

  /**
   * Environment variable containing the application data encryption key.
   */
  private const KEY_ENV = 'BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY';

  /**
   * Encryption envelope version.
   */
  private const VERSION = 1;

  /**
   * Cipher method.
   */
  private const CIPHER = 'aes-256-gcm';

  /**
   * CRM keys whose values identify or contact an applicant.
   */
  private const LINKABLE_IDENTITY_CRM_KEYS = [
    'person.full_name',
    'person.first_name',
    'person.last_name',
    'person.email',
    'person.phone',
    'person.address',
    'person.street',
    'person.house_number',
    'person.postal_code',
    'person.city',
  ];

  /**
   * Normalized field IDs whose values identify or contact an applicant.
   */
  private const LINKABLE_IDENTITY_FIELD_IDS = [
    'full_name',
    'first_name',
    'last_name',
    'name',
    'vorname',
    'nachname',
    'applicant_full_name',
    'given_name',
    'surname',
    'family_name',
    'familienname',
    'email',
    'e_mail',
    'mail',
    'applicant_email',
    'phone',
    'telephone',
    'telefon',
    'telefonnummer',
    'tel',
    'mobile',
    'mobil',
    'handy',
    'applicant_phone',
    'address',
    'adresse',
    'anschrift',
    'wohnanschrift',
    'applicant_address',
    'street',
    'strasse',
    'street_house_number',
    'strasse_hausnummer',
    'hausnummer',
    'house_number',
    'postal_code',
    'postcode',
    'zip',
    'plz',
    'postleitzahl',
    'city',
    'ort',
  ];

  /**
   * Field types that usually identify or contact an applicant.
   */
  private const LINKABLE_IDENTITY_TYPES = [
    'email',
    'tel',
    'phone',
  ];

  /**
   * Constructs the service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly ?ProtectedAccessLog $accessLog = NULL,
  ) {
  }

  /**
   * Records that a staff member received clear protected values.
   *
   * Writes to the beantragt_protected_access_log table (never the values);
   * the logger fallback only exists for hand-built instances in unit tests.
   */
  public function logProtectedReveal(ApplicationInterface $application, AccountInterface $account, string $channel): void {
    if ($this->accessLog instanceof ProtectedAccessLog) {
      $this->accessLog->record($application, $account, $channel);
      return;
    }
    $this->logger->notice(
      'Protected application data revealed: application @uuid (@reference) to user @uid via @channel.',
      [
        '@uuid' => $application->uuid(),
        '@reference' => $application->getReferenceNumber() ?: '-',
        '@uid' => (string) $account->id(),
        '@channel' => $channel,
      ],
    );
  }

  /**
   * Encrypts sensitive application field_data values in-place.
   */
  public function protectApplication(ApplicationInterface $application): void {
    if (!$application->hasField('field_data') || $application->get('field_data')->isEmpty()) {
      return;
    }

    $data_json = (string) $application->get('field_data')->value;
    $data = $this->decodeObject($data_json);
    if ($data === [] || $data === ['_anonymized' => TRUE]) {
      return;
    }

    $schema = PublicSubmission::protectedSchema($application, $this->entityTypeManager);
    if (!$schema instanceof FormSchemaInterface) {
      throw new UnresolvableApplicationSchemaException('Application field_data protection failed. Application schema is missing.');
    }

    $protected = $this->protectDataJson(
          $data_json,
          $schema->getSchema(),
      );
    if ($protected !== NULL && $protected !== $application->get('field_data')->value) {
      $application->set('field_data', $protected);
    }
  }

  /**
   * Derives protected identity side-channel fields for application storage.
   */
  public function protectApplicationIdentityLinkage(ApplicationInterface $application): void {
    if (!$this->applicationHasProtectedIdentityLinkage($application)) {
      return;
    }

    $email = $this->protectedApplicantEmail($application);
    if ($email !== NULL && $application->hasField('field_applicant_email_hash')) {
      $email_hash = $this->applicantEmailLookupHash($email);
      if ($email_hash !== NULL) {
        $application->set('field_applicant_email_hash', $email_hash);
      }
    }

    if ($application->hasField('field_applicant_email')) {
      $application->set('field_applicant_email', NULL);
    }
    if ($application->hasField('field_crm_data')) {
      $application->set('field_crm_data', NULL);
    }
  }

  /**
   * Encrypts sensitive values in decoded field_data JSON.
   */
  public function protectDataJson(?string $data_json, ?string $schema_json): ?string {
    if ($data_json === NULL || trim($data_json) === '') {
      return $data_json;
    }
    $data = $this->decodeObject($data_json);
    if ($data === NULL) {
      throw new \RuntimeException('Application field_data protection failed. Application data must be a JSON object.');
    }

    $schema = $this->decodeObject($schema_json);
    if ($schema === NULL) {
      throw new UnresolvableApplicationSchemaException('Application field_data protection failed. Application schema is not valid JSON.');
    }

    $protected = $this->protectData($data, $schema);
    return $protected === $data
      ? $data_json
      : json_encode($protected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Decrypts encrypted values in field_data JSON.
   *
   * @return array<string, mixed>
   *   Decrypted application data.
   */
  public function revealDataJson(?string $data_json): array {
    $data = $this->decodeObject($data_json);
    if ($data === NULL) {
      return [];
    }

    return $this->revealData($data);
  }

  /**
   * Redacts encrypted values in field_data JSON.
   *
   * @return array<string, mixed>
   *   Redacted application data.
   */
  public function redactDataJson(?string $data_json): array {
    $data = $this->decodeObject($data_json);
    if ($data === NULL) {
      return [];
    }

    return $this->redactData($data);
  }

  /**
   * Redacts an application's protected field_data values by submitted schema.
   *
   * @return array<string, mixed>
   *   Redacted application data. If the schema cannot be resolved, all fields
   *   are redacted fail-closed.
   */
  public function redactApplicationData(ApplicationInterface $application): array {
    $data = $this->decodeObject($application->getData());
    if ($data === NULL) {
      return [];
    }

    $schema = PublicSubmission::protectedSchema($application, $this->entityTypeManager);
    if (!$schema instanceof FormSchemaInterface) {
      return $this->redactAllData($data);
    }

    $schema_data = $this->decodeObject($schema->getSchema());
    if ($schema_data === NULL) {
      return $this->redactAllData($data);
    }

    foreach ($this->protectedFieldIds($schema_data) as $field_id) {
      if (array_key_exists($field_id, $data)) {
        $data[$field_id] = [
          self::REDACTED_MARKER => TRUE,
        ];
      }
    }

    return $this->redactData($data);
  }

  /**
   * Encrypts sensitive values in application data.
   *
   * @param array<string, mixed> $data
   *   Submitted application data.
   * @param array<string, mixed> $schema
   *   Decoded form schema.
   *
   * @return array<string, mixed>
   *   Application data with sensitive values encrypted.
   */
  public function protectData(array $data, array $schema): array {
    foreach ($this->protectedFieldIds($schema) as $field_id) {
      if (!array_key_exists($field_id, $data) || $this->isEmptySensitiveValue($data[$field_id])) {
        continue;
      }
      if ($this->isEncryptedValue($data[$field_id])) {
        continue;
      }
      $data[$field_id] = $this->encryptValue($data[$field_id]);
    }
    return $data;
  }

  /**
   * Decrypts encrypted field_data values.
   *
   * @param array<string, mixed> $data
   *   Application data.
   *
   * @return array<string, mixed>
   *   Application data with encrypted values decrypted.
   */
  public function revealData(array $data): array {
    foreach ($data as $key => $value) {
      if ($this->isEncryptedValue($value)) {
        $data[$key] = $this->decryptValue($value);
      }
    }
    return $data;
  }

  /**
   * Redacts encrypted field_data values.
   *
   * @param array<string, mixed> $data
   *   Application data.
   *
   * @return array<string, mixed>
   *   Application data with encrypted values replaced by placeholders.
   */
  public function redactData(array $data): array {
    foreach ($data as $key => $value) {
      if ($this->isEncryptedValue($value)) {
        $data[$key] = [
          self::REDACTED_MARKER => TRUE,
        ];
      }
    }
    return $data;
  }

  /**
   * Redacts every submitted field.
   *
   * @param array<string, mixed> $data
   *   Application data.
   *
   * @return array<string, mixed>
   *   Fully redacted application data.
   */
  private function redactAllData(array $data): array {
    return array_fill_keys(
          array_keys($data), [
            self::REDACTED_MARKER => TRUE,
          ]
      );
  }

  /**
   * Checks whether a value is an encrypted field_data envelope.
   */
  public function isEncryptedValue(mixed $value): bool {
    return is_array($value)
        && ($value[self::ENCRYPTED_MARKER] ?? FALSE) === TRUE
        && ($value['v'] ?? NULL) === self::VERSION
        && ($value['alg'] ?? NULL) === self::CIPHER
        && is_string($value['iv'] ?? NULL)
        && is_string($value['tag'] ?? NULL)
        && is_string($value['ciphertext'] ?? NULL);
  }

  /**
   * Returns the sensitive field IDs from a decoded form schema.
   *
   * @param array<string, mixed> $schema
   *   Decoded form schema.
   *
   * @return string[]
   *   Sensitive field IDs.
   */
  public function sensitiveFieldIds(array $schema): array {
    $ids = [];
    foreach (self::schemaFields($schema) as $field) {
      $field_id = $field['id'] ?? NULL;
      if (is_string($field_id) && $field_id !== ''
        && (self::isSensitiveSchemaField($field) || ApplicationDataEditValidator::requiresFinancialProtection($field))) {
        $ids[] = $field_id;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Returns field IDs that must be encrypted in field_data.
   *
   * This includes sensitive and recognised financial fields. For forms with
   * non-file facts marked sensitive, it also protects linked identity fields.
   *
   * @param array<string, mixed> $schema
   *   Decoded form schema.
   *
   * @return string[]
   *   Protected field IDs.
   */
  public function protectedFieldIds(array $schema): array {
    $ids = $this->sensitiveFieldIds($schema);
    if (!self::schemaRequiresProtectedIdentityLinkage($schema)) {
      return $ids;
    }

    $ids = array_merge($ids, self::linkableIdentityFieldIds($schema));
    $ids[] = 'applicant_full_name';
    $ids[] = 'applicant_email';
    return array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
  }

  /**
   * Returns TRUE when the application's schema has protected field_data values.
   */
  public function applicationHasSensitiveFields(ApplicationInterface $application): bool {
    $schema = PublicSubmission::protectedSchema($application, $this->entityTypeManager);
    if (!$schema instanceof FormSchemaInterface) {
      return TRUE;
    }

    return $this->schemaHasSensitiveFieldsJson($schema->getSchema());
  }

  /**
   * Requires reveal access even when only the stored data shows protection.
   */
  public function applicationRequiresProtectedReveal(ApplicationInterface $application): bool {
    if ($this->applicationHasSensitiveFields($application)) {
      return TRUE;
    }
    foreach ($this->decodeObject($application->getData()) ?? [] as $value) {
      if ($this->isEncryptedValue($value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns TRUE when a schema JSON marks at least one field sensitive.
   */
  public function schemaHasSensitiveFieldsJson(?string $schema_json): bool {
    $schema = $this->decodeObject($schema_json);
    if ($schema === NULL) {
      return TRUE;
    }

    return $this->sensitiveFieldIds($schema) !== [];
  }

  /**
   * Returns TRUE when identity/contact values must be protected with the data.
   */
  public function applicationHasProtectedIdentityLinkage(ApplicationInterface $application): bool {
    $schema = PublicSubmission::protectedSchema($application, $this->entityTypeManager);
    if (!$schema instanceof FormSchemaInterface) {
      return TRUE;
    }

    return $this->schemaHasProtectedIdentityLinkageJson($schema->getSchema());
  }

  /**
   * Returns TRUE when a schema JSON needs identity/contact linkage protection.
   */
  public function schemaHasProtectedIdentityLinkageJson(?string $schema_json): bool {
    $schema = $this->decodeObject($schema_json);
    if ($schema === NULL) {
      return TRUE;
    }

    return self::schemaRequiresProtectedIdentityLinkage($schema);
  }

  /**
   * Returns TRUE when a schema has sensitive non-file facts.
   *
   * File uploads are sensitive by default, but a file-only form should not lose
   * applicant list/search behavior just because upload metadata is protected.
   *
   * @param array<string, mixed> $schema
   *   Decoded form schema.
   */
  public static function schemaRequiresProtectedIdentityLinkage(array $schema): bool {
    foreach (self::schemaFields($schema) as $field) {
      if (self::isSensitiveSchemaField($field) && ($field['type'] ?? NULL) !== 'file') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds a keyed lookup hash for applicant email comparisons.
   */
  public function applicantEmailLookupHash(?string $email): ?string {
    $email = $this->normalizeEmail($email);
    if ($email === NULL) {
      return NULL;
    }

    return hash_hmac('sha256', $email, $this->lookupHashKey());
  }

  /**
   * Keyed lookup hash of a cover sheet identifier (canonical form).
   *
   * The key is derived from the same material as the email lookup, with its
   * own HKDF info per key version, so an identifier is never stored in
   * clear and a hash says nothing without the key.
   */
  public function coverCodeLookupHash(string $canonical, int $key_version): string {
    return hash_hmac('sha256', 'BA1:' . $canonical, $this->coverCodeKey($key_version));
  }

  /**
   * Key check value of a cover code key version (16 hex characters).
   *
   * Stored per version; a different value means the key material changed and
   * printed identifiers of that version can no longer be resolved.
   */
  public function coverCodeKeyCheck(int $key_version): string {
    return substr(hash_hmac('sha256', 'beantragt_cover_code_key_check', $this->coverCodeKey($key_version)), 0, 16);
  }

  /**
   * Derives the cover code lookup key of a version (1 to 99).
   */
  private function coverCodeKey(int $key_version): string {
    if ($key_version < 1 || $key_version > 99) {
      throw new \InvalidArgumentException('Cover code key version must be between 1 and 99.');
    }
    return hash_hkdf('sha256', $this->keyMaterial(), 32, 'beantragt_cover_code_lookup_v' . $key_version);
  }

  /**
   * Checks cleartext legacy email and hashed protected email storage.
   */
  public function applicationApplicantEmailMatches(FieldableEntityInterface $application, string $email): bool {
    $email = $this->normalizeEmail($email);
    if ($email === NULL) {
      return FALSE;
    }

    if ($application->hasField('field_applicant_email_hash') && !$application->get('field_applicant_email_hash')->isEmpty()) {
      $stored_hash = trim((string) $application->get('field_applicant_email_hash')->value);
      $submitted_hash = $this->applicantEmailLookupHash($email);
      if ($stored_hash !== '' && $submitted_hash !== NULL && hash_equals($stored_hash, $submitted_hash)) {
        return TRUE;
      }
    }

    if ($application->hasField('field_applicant_email') && !$application->get('field_applicant_email')->isEmpty()) {
      $stored_email = $this->normalizeEmail((string) $application->get('field_applicant_email')->value);
      return $stored_email !== NULL && hash_equals($stored_email, $email);
    }

    return FALSE;
  }

  /**
   * Resolves an applicant email from clear storage or encrypted field_data.
   */
  public function protectedApplicantEmail(ApplicationInterface $application): ?string {
    if ($application->hasField('field_applicant_email') && !$application->get('field_applicant_email')->isEmpty()) {
      $email = $this->normalizeEmail((string) $application->get('field_applicant_email')->value);
      if ($email !== NULL) {
        return $email;
      }
    }

    $data = $this->revealDataJson($application->getData());
    if ($data === []) {
      return NULL;
    }

    $schema = PublicSubmission::protectedSchema($application, $this->entityTypeManager);
    $schema_data = $schema instanceof FormSchemaInterface ? $this->decodeObject($schema->getSchema()) : NULL;
    $field_ids = $schema_data === NULL
      ? ['applicant_email', 'email', 'e_mail']
      : self::emailIdentityFieldIds($schema_data);

    foreach ($field_ids as $field_id) {
      $email = $this->normalizeEmail($data[$field_id] ?? NULL);
      if ($email !== NULL) {
        return $email;
      }
    }

    return NULL;
  }

  /**
   * Encrypts extracted analysis values, retaining the readable result metadata.
   *
   * @param array<string, mixed> $result
   *   A result object, not the surrounding story or metadata record.
   *
   * @return array<string, mixed>
   *   Result with encrypted values, including booleans, numbers and NULL.
   */
  public function protectAnalysisResult(array $result): array {
    // Validate configuration even for an empty or already protected result.
    $this->key();
    foreach ($result['extracted'] ?? [] as $index => $item) {
      if (is_array($item) && array_key_exists('value', $item)
        && !$this->isEncryptedValue($item['value'])) {
        $result['extracted'][$index]['value'] = $this->encryptValue($item['value'], 32);
      }
    }
    return $result;
  }

  /**
   * Reveals extracted values after the caller's attachment access check.
   *
   * @param array<string, mixed> $result
   *   A stored result object.
   *
   * @return array<string, mixed>
   *   Result with original value types restored.
   */
  public function revealAnalysisResult(array $result): array {
    foreach ($result['extracted'] ?? [] as $index => $item) {
      if (is_array($item) && $this->isEncryptedValue($item['value'] ?? NULL)) {
        $result['extracted'][$index]['value'] = $this->decryptValue($item['value']);
      }
    }
    return $result;
  }

  /**
   * Encrypts a JSON-serializable field value.
   */
  private function encryptValue(mixed $value, int $padding_block = 0): array {
    try {
      $plaintext = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
      if ($padding_block > 0) {
        $padded_length = (intdiv(strlen($plaintext), $padding_block) + 1) * $padding_block;
        $plaintext = str_pad($plaintext, $padded_length, ' ');
      }
      $key = $this->key();
      $iv_length = openssl_cipher_iv_length(self::CIPHER);
      if ($iv_length <= 0) {
        throw new \RuntimeException('Unable to determine encryption IV length.');
      }

      $iv = random_bytes($iv_length);
      $tag = '';
      $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16,
        );
      if ($ciphertext === FALSE) {
        throw new \RuntimeException('OpenSSL encryption returned false.');
      }

      return [
        self::ENCRYPTED_MARKER => TRUE,
        'v' => self::VERSION,
        'alg' => self::CIPHER,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($ciphertext),
      ];
    }
    catch (\Throwable $exception) {
      $this->logger->error(
            'Application field_data encryption failed: @message', [
              '@message' => $exception->getMessage(),
            ]
        );
      throw new \RuntimeException('Application field_data encryption failed. Refusing to store plaintext sensitive data.', 0, $exception);
    }
  }

  /**
   * Decrypts an encrypted JSON-serializable field value.
   */
  private function decryptValue(array $value): mixed {
    try {
      $iv = base64_decode((string) $value['iv'], TRUE);
      $tag = base64_decode((string) $value['tag'], TRUE);
      $ciphertext = base64_decode((string) $value['ciphertext'], TRUE);
      if ($iv === FALSE || $tag === FALSE || $ciphertext === FALSE) {
        throw new \RuntimeException('Encrypted value contains invalid base64 data.');
      }

      $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );
      if ($plaintext === FALSE) {
        throw new \RuntimeException('Encrypted value authentication failed.');
      }

      return json_decode($plaintext, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $exception) {
      $this->logger->error(
            'Application field_data decryption failed: @message', [
              '@message' => $exception->getMessage(),
            ]
        );
      throw new \RuntimeException('Application field_data decryption failed.', 0, $exception);
    }
  }

  /**
   * Resolves the application data encryption key.
   */
  private function key(): string {
    $material = $this->keyMaterial();
    return hash_hkdf('sha256', $material, 32, 'beantragt_application_field_data_v1');
  }

  /**
   * Resolves the keyed lookup hash secret.
   */
  private function lookupHashKey(): string {
    $material = $this->keyMaterial();
    return hash_hkdf('sha256', $material, 32, 'beantragt_application_email_lookup_v1');
  }

  /**
   * Reads key material from the environment.
   */
  protected function keyMaterial(): string {
    $raw = getenv(self::KEY_ENV);
    $raw = is_string($raw) ? trim($raw) : '';
    if ($raw === '') {
      throw new \RuntimeException(self::KEY_ENV . ' is not configured.');
    }

    if (str_starts_with($raw, 'base64:')) {
      $decoded = base64_decode(substr($raw, 7), TRUE);
      if ($decoded === FALSE) {
        throw new \RuntimeException(self::KEY_ENV . ' is not valid base64 key material.');
      }
      $raw = $decoded;
    }
    elseif (strlen($raw) >= 64 && strlen($raw) % 2 === 0 && ctype_xdigit($raw)) {
      $decoded = hex2bin($raw);
      if ($decoded === FALSE) {
        throw new \RuntimeException(self::KEY_ENV . ' is not valid hex key material.');
      }
      $raw = $decoded;
    }

    if (strlen($raw) < 32) {
      throw new \RuntimeException(self::KEY_ENV . ' must provide at least 32 bytes of key material.');
    }
    return $raw;
  }

  /**
   * Decodes a JSON object.
   *
   * @return array<string, mixed>|null
   *   Decoded object, or NULL when the input is empty or invalid.
   */
  private function decodeObject(?string $json): ?array {
    if ($json === NULL || trim($json) === '') {
      return NULL;
    }

    try {
      $decoded = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($decoded) && str_starts_with(ltrim($json), '{') ? $decoded : NULL;
  }

  /**
   * Determines whether a schema field is marked as sensitive.
   */
  private static function isSensitiveSchemaField(array $field): bool {
    $default_sensitive = ($field['type'] ?? NULL) === 'file';
    if (!array_key_exists('sensitive', $field)) {
      return $default_sensitive;
    }

    $sensitive = $field['sensitive'];
    if (is_bool($sensitive)) {
      return $sensitive;
    }
    if (is_string($sensitive) || is_int($sensitive)) {
      $parsed = filter_var($sensitive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      return $parsed ?? $default_sensitive;
    }
    return $default_sensitive;
  }

  /**
   * Checks whether a sensitive value can be left unencrypted.
   */
  private function isEmptySensitiveValue(mixed $value): bool {
    return $value === NULL || $value === '' || $value === [];
  }

  /**
   * Returns all normalized schema fields.
   *
   * @return array<int, array<string, mixed>>
   *   Field definitions.
   */
  private static function schemaFields(array $schema): array {
    $fields = [];
    $sections = is_array($schema['sections'] ?? NULL) ? $schema['sections'] : [];
    foreach ($sections as $section) {
      if (!is_array($section)) {
        continue;
      }
      foreach ((is_array($section['fields'] ?? NULL) ? $section['fields'] : []) as $field) {
        if (is_array($field)) {
          $fields[] = $field;
        }
      }
    }
    return $fields;
  }

  /**
   * Returns schema field IDs that carry linkable identity/contact data.
   *
   * @return string[]
   *   Field IDs.
   */
  private static function linkableIdentityFieldIds(array $schema): array {
    $ids = [];
    foreach (self::schemaFields($schema) as $field) {
      $field_id = $field['id'] ?? NULL;
      if (!is_string($field_id) || $field_id === '') {
        continue;
      }
      if (self::isLinkableIdentityField($field)) {
        $ids[] = $field_id;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Returns candidate applicant email fields from schema plus synthetic keys.
   *
   * @return string[]
   *   Field IDs.
   */
  private static function emailIdentityFieldIds(array $schema): array {
    $ids = ['applicant_email', 'email', 'e_mail'];
    foreach (self::schemaFields($schema) as $field) {
      $field_id = $field['id'] ?? NULL;
      if (!is_string($field_id) || $field_id === '') {
        continue;
      }
      $normalized_id = self::normalizedFieldId($field_id);
      if (($field['crm_key'] ?? NULL) === 'person.email'
        || ($field['type'] ?? NULL) === 'email'
        || in_array($normalized_id, ['email', 'e_mail', 'mail', 'applicant_email'], TRUE)) {
        $ids[] = $field_id;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Returns TRUE when a schema field identifies or contacts an applicant.
   */
  private static function isLinkableIdentityField(array $field): bool {
    if (in_array($field['crm_key'] ?? NULL, self::LINKABLE_IDENTITY_CRM_KEYS, TRUE)) {
      return TRUE;
    }
    if (in_array($field['type'] ?? NULL, self::LINKABLE_IDENTITY_TYPES, TRUE)) {
      return TRUE;
    }

    $normalized_id = self::normalizedFieldId($field['id'] ?? NULL);
    if (in_array($normalized_id, self::LINKABLE_IDENTITY_FIELD_IDS, TRUE)) {
      return TRUE;
    }

    $label = self::normalizedFieldId($field['label'] ?? '');
    return in_array($label, [
      'name',
      'vorname',
      'nachname',
      'familienname',
      'e_mail',
      'email',
      'telefon',
      'telefonnummer',
      'handy',
      'mobil',
      'mobile',
      'anschrift',
      'adresse',
      'strasse',
      'strasse_hausnummer',
      'hausnummer',
      'plz',
      'postleitzahl',
    ], TRUE)
      || preg_match('/^name_der_antragsteller/u', $label) === 1
      || preg_match('/^antragsteller.*name/u', $label) === 1
      || preg_match('/^(telefon|mobile|mobil|handy)/u', $label) === 1
      || preg_match('/(anschrift|adresse|strasse|hausnummer|postleitzahl|plz)/u', $label) === 1;
  }

  /**
   * Normalizes field id-like text for heuristics.
   */
  private static function normalizedFieldId(mixed $value): string {
    $normalized = mb_strtolower((string) $value);
    $normalized = strtr($normalized, [
      'ä' => 'ae',
      'ö' => 'oe',
      'ü' => 'ue',
      'ß' => 'ss',
    ]);
    return trim((string) preg_replace('/[^a-z0-9]+/u', '_', $normalized), '_');
  }

  /**
   * Normalizes email values.
   */
  private function normalizeEmail(mixed $email): ?string {
    if (!is_string($email)) {
      return NULL;
    }
    $normalized = strtolower(trim($email));
    if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === FALSE) {
      return NULL;
    }
    return $normalized;
  }

}
