<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Drupal\Core\Site\Settings;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\PublicSubmission;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\DocgenCaseExtensionInterface;
use Drupal\beantragt_docgen\ProtectedDocgenCaseExtensionInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Assembles the render data for one application and one staff member.
 *
 * Keys are exactly the catalog tags (VariableCatalog). Protected values are
 * only revealed when the person creating the draft may see protected
 * application data; the reveal is written to beantragt_protected_access_log
 * (channel docgen_render). Otherwise those placeholders stay empty and are
 * reported as protectedOmitted, so the draft shows the gap instead of a
 * value the person must not see. The rule is the staff API's: the
 * application's own schema decides (fail closed when it cannot be resolved),
 * never the schema of the current form.
 */
class RenderDataBuilder {

  /**
   * Permission that allows reading protected application values.
   */
  public const PROTECTED_PERMISSION = 'view protected application data';

  /**
   * Constructs the builder.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly DocgenSettingsRepository $settings,
    private readonly DocgenScopeAccess $scopeAccess,
    private readonly ?ApplicationDataProtectionService $dataProtection = NULL,
    private readonly ?DocgenCaseExtensionInterface $extensions = NULL,
    private readonly LoggerInterface $logger = new NullLogger(),
    private readonly ?RequestLetterValues $letterValues = NULL,
  ) {}

  /**
   * Builds render data.
   *
   * @return array{data: array<string, mixed>, protectedOmitted: string[], protectedValues: bool, dataSha256: string, schema: array<string, mixed>}
   *   Flat render data, omitted protected tags, whether clear protected
   *   values went into the data, the data hash and the decoded submitted
   *   schema.
   */
  public function build(ApplicationInterface $application, AccountInterface $account, ?array $inspection = NULL, bool $request_info = FALSE): array {
    $protection = $this->protection();
    $raw = json_decode((string) ($application->getData() ?? ''), TRUE);
    if (is_array($raw) && ($raw['_anonymized'] ?? NULL) === TRUE) {
      throw new DocgenApiException('docgen.application_anonymized', 409);
    }
    $schema_entity = PublicSubmission::submittedSchema($application, $this->entityTypeManager);
    $schema = $schema_entity instanceof FormSchemaInterface ? json_decode((string) $schema_entity->getSchema(), TRUE) : NULL;
    $schema = is_array($schema) ? $schema : [];
    $protected_ids = $protection->protectedFieldIds($schema);
    $catalog = VariableCatalog::build($schema, $protected_ids);
    $used = $inspection === NULL ? NULL : VariableCatalog::compare($inspection, $catalog);
    $used_tags = $used['used'] ?? NULL;
    $needs_protected_form = $used === NULL || array_filter($used['protected'], static fn(string $tag): bool => str_starts_with($tag, VariableCatalog::FORM_PREFIX)) !== [];
    $data = $this->systemValues($application, $account);
    // An empty required proof list is known without decrypting any recipient
    // data. Refuse this request before either protected-data reveal.
    if ($request_info && in_array('vorgang.fehlende_nachweise', $used_tags ?? [], TRUE)
      && !isset($data['vorgang.fehlende_nachweise'])) {
      throw new DocgenApiException('docgen.request_details_missing', 409);
    }
    $omitted = [];
    $redacted = FALSE;
    $protected_values = FALSE;

    $bulk_request = $request_info && $application->hasField('field_origin') && $application->get('field_origin')->value === 'bulk';
    $extra = [];
    $protected_system_tags = array_merge(array_column(array_filter($catalog, static fn(array $variable): bool => $variable['group'] === 'system' && $variable['protected']), 'tag'), ['vorgang.fehlende_nachweise']);
    if ($this->extensions instanceof ProtectedDocgenCaseExtensionInterface
      && ($bulk_request || $used_tags === NULL || array_intersect($used_tags, $protected_system_tags) !== [])) {
      $extra = DocgenCaseExtensions::providedValues($this->extensions->protectedSystemValues($application, $account), $this->extensions, $this->logger);
    }
    // Resolve the bulk addressee even if the template omits its placeholders.
    // Only inspected tags are allowed to leave in the renderer payload.
    if ($bulk_request && !isset($extra['empfaenger.name'], $extra['empfaenger.anschrift'])) {
      throw new DocgenApiException('docgen.request_details_missing', 409);
    }
    if ($used_tags !== NULL) {
      $extra = array_intersect_key($extra, array_flip($used_tags));
    }
    if ($extra !== []) {
      $data = array_replace($data, $extra);
      $protected_values = TRUE;
    }

    if ($protection->applicationHasSensitiveFields($application) && (!$needs_protected_form || !$account->hasPermission(self::PROTECTED_PERMISSION))) {
      // redactApplicationData() resolves the application's own schema and
      // redacts every field when it cannot; the markers tell what is gone.
      $values = $protection->redactApplicationData($application);
      $redacted = TRUE;
      foreach ($values as $id => $value) {
        if (is_array($value) && isset($value[ApplicationDataProtectionService::REDACTED_MARKER])) {
          unset($values[$id]);
          if (VariableCatalog::isUsableId((string) $id)) {
            $omitted[] = VariableCatalog::FORM_PREFIX . $id;
          }
        }
      }
      sort($omitted);
    }
    else {
      if ($bulk_request && $used_tags !== NULL) {
        // Other application fields may only be opened for their own tags.
        $requested = array_filter($raw ?? [], static fn($id): bool => in_array(VariableCatalog::FORM_PREFIX . $id, $used_tags, TRUE), ARRAY_FILTER_USE_KEY);
        $values = $protection->revealData($requested);
      }
      else {
        $values = $protection->revealDataJson($application->getData());
      }
      if ($protection->applicationHasSensitiveFields($application)) {
        $protection->logProtectedReveal($application, $account, 'docgen_render');
      }
    }

    $locale = 'de_DE';
    $fields = [];
    $repeatable = [];
    foreach (is_array($schema['sections'] ?? NULL) ? $schema['sections'] : [] as $section) {
      if (!is_array($section) || !is_string($section['id'] ?? NULL)) {
        continue;
      }
      $section_fields = [];
      foreach (is_array($section['fields'] ?? NULL) ? $section['fields'] : [] as $field) {
        if (is_array($field) && is_string($field['id'] ?? NULL) && VariableCatalog::isUsableId($field['id'])) {
          $section_fields[$field['id']] = [
            'type' => is_string($field['type'] ?? NULL) ? $field['type'] : 'text',
            'format' => is_string($field['format'] ?? NULL) ? $field['format'] : '',
          ];
        }
      }
      if (($section['repeatable'] ?? FALSE) === TRUE) {
        $repeatable[$section['id']] = $section_fields;
        continue;
      }
      $fields += $section_fields;
    }
    foreach ($fields as $id => $field) {
      $value = self::format($values[$id] ?? NULL, $field['type'], $field['format'], $locale);
      if ($value !== NULL && ($used_tags === NULL || in_array(VariableCatalog::FORM_PREFIX . $id, $used_tags, TRUE))) {
        $data[VariableCatalog::FORM_PREFIX . $id] = $value;
        $protected_values = $protected_values || (!$redacted && in_array($id, $protected_ids, TRUE));
      }
    }
    foreach ($repeatable as $section_id => $section_fields) {
      $loop_tag = VariableCatalog::FORM_PREFIX . $section_id;
      if ($used_tags !== NULL && !in_array($loop_tag, $used_tags, TRUE)) {
        continue;
      }
      $protected_loop = array_intersect(array_keys($section_fields), $protected_ids) !== [];
      // The catalog protects the loop itself: its row count is data too.
      if ($redacted && $protected_loop) {
        $omitted[] = $loop_tag;
        continue;
      }
      $items = $values[$section_id] ?? NULL;
      if (!is_array($items) || !array_is_list($items)) {
        continue;
      }
      $rows = [];
      foreach (array_slice($items, 0, 200) as $item) {
        if (!is_array($item)) {
          continue;
        }
        $row = [];
        foreach ($section_fields as $id => $field) {
          if ($used_tags !== NULL && !in_array(VariableCatalog::FORM_PREFIX . $section_id . '.' . $id, $used_tags, TRUE)) {
            continue;
          }
          $value = $redacted && in_array($id, $protected_ids, TRUE) ? NULL : self::format($item[$id] ?? NULL, $field['type'], $field['format'], $locale);
          if ($value !== NULL) {
            $row[$id] = $value;
            $protected_values = $protected_values || (!$redacted && in_array($id, $protected_ids, TRUE));
          }
        }
        $rows[] = $row;
      }
      $data[$loop_tag] = $rows;
      $protected_values = $protected_values || ($protected_loop && $rows !== []);
    }

    if ($used_tags !== NULL) {
      $data = array_intersect_key($data, array_flip($used_tags));
    }
    return [
      'data' => $data,
      'protectedOmitted' => $omitted,
      'protectedValues' => $protected_values,
      'dataSha256' => self::hash($data),
      'schema' => $schema,
    ];
  }

  /**
   * System placeholder values.
   *
   * @return array<string, string>
   *   Values keyed by tag; empty values are left out (reported as missing).
   */
  private function systemValues(ApplicationInterface $application, AccountInterface $account): array {
    $scope = $this->scopeAccess->scopeOf($application);
    $status = (string) $application->getWorkflowStatus();
    $status_id = $application->getWorkflowStatusId();
    if ($status_id !== NULL && $this->entityTypeManager->hasDefinition('workflow_status')) {
      $entity = $this->entityTypeManager->getStorage('workflow_status')->load($status_id);
      if ($entity instanceof EntityInterface && (string) $entity->label() !== '') {
        $status = (string) $entity->label();
      }
    }
    $jurisdiction = $application->get('field_jurisdiction')->entity;
    $values = [
      'jurisdiction.name' => $jurisdiction instanceof GroupInterface ? (string) $jurisdiction->label() : '',
      'vorgang.aktenzeichen' => (string) ($application->getReferenceNumber() ?? ''),
      'vorgang.eingang' => $this->date($application->getCreatedTime()),
      'vorgang.status' => $status,
      'organisation.name' => $scope instanceof GroupInterface ? (string) $scope->label() : '',
      'organisation.anschrift' => $this->settings->senderAddress($scope),
      'bearbeitung.name' => self::clerkName((string) $account->getDisplayName()),
      'heute' => $this->date($this->time->getRequestTime()),
    ];
    if ($this->extensions !== NULL) {
      $extra = DocgenCaseExtensions::providedValues($this->extensions->systemValues($application, $account), $this->extensions, $this->logger);
      // Ordinary extensions must never smuggle protected catalog values past
      // the authorization and audit boundary, including future system tags.
      foreach (VariableCatalog::build([], []) as $variable) {
        if ($variable['protected'] || str_starts_with($variable['tag'], 'empfaenger.')) {
          unset($extra[$variable['tag']]);
        }
      }
      $values = array_replace($values, $extra);
    }
    $values += $this->letterValues?->values($application) ?? [];
    $values += ['empfaenger.anrede' => 'Sehr geehrte Damen und Herren,'];
    return array_filter($values, static fn (string $value): bool => trim($value) !== '');
  }

  /**
   * The clerk name for the notice, or '' when the account has none.
   *
   * Passwordless accounts are named by their email address, SSO accounts
   * "sso.<provider>.<hash>". Neither is a name, and an email address must not
   * end up in a notice to the applicant: the placeholder stays empty and is
   * reported as missing, so the clerk fills it in the draft.
   */
  public static function clerkName(string $display_name): string {
    $name = trim($display_name);
    if ($name === '' || str_contains($name, '@') || str_starts_with(strtolower($name), 'sso.') || str_starts_with(strtolower($name), 'agent.')) {
      return '';
    }
    return $name;
  }

  /**
   * Does the application hold protected values (fail closed)?
   */
  public function hasProtectedData(ApplicationInterface $application): bool {
    return $this->protection()->applicationHasSensitiveFields($application);
  }

  /**
   * Requires the protected-data permission for a document with clear values.
   *
   * A draft drafted by a person who may see protected values carries them in
   * clear text; whoever downloads it reads them. The download needs the
   * same permission and is logged like any other reveal.
   */
  public function authorizeProtectedRead(ApplicationInterface $application, AccountInterface $account, string $channel): void {
    $protection = $this->protection();
    if (!$account->hasPermission(self::PROTECTED_PERMISSION)) {
      throw new DocgenApiException('docgen.access_denied', 403, ['reason' => 'protected_values']);
    }
    $protection->logProtectedReveal($application, $account, $channel);
  }

  /**
   * The data protection service; without it nothing is rendered.
   */
  private function protection(): ApplicationDataProtectionService {
    // The service is optional only for kernel tests that enable modules one
    // by one. In a site it always exists (module dependency); without it the
    // protected-field rule cannot be applied, so nothing is revealed.
    return $this->dataProtection ?? throw new DocgenApiException('docgen.internal_error', 500);
  }

  /**
   * Formats a date in the site timezone as DD.MM.YYYY.
   */
  private function date(int $timestamp): string {
    return $timestamp > 0 ? $this->dateFormatter->format($timestamp, 'custom', 'd.m.Y') : '';
  }

  /**
   * Turns a stored field value into template text, or NULL when empty.
   */
  public static function format(mixed $value, string $type, string $format = '', string $locale = 'de_DE'): ?string {
    if ($value === NULL || $value === '' || $value === []) {
      return NULL;
    }
    if (is_array($value) && isset($value[ApplicationDataProtectionService::REDACTED_MARKER])) {
      return NULL;
    }
    if ($type === 'address' && is_array($value)) {
      $part = static fn(string $key): string => is_string($value[$key] ?? NULL) ? trim(preg_replace('/\s+/u', ' ', $value[$key]) ?? '') : '';
      $street = trim($part('street') . ' ' . $part('houseNumber'));
      $locality = trim($part('postcode') . ' ' . $part('city'));
      $address = implode(', ', array_filter([$street, $locality, $part('country')], static fn(string $part): bool => $part !== ''));
      // Prefer structured postal parts, never join coordinates or metadata.
      $address = $address !== '' ? $address : $part('formatted');
      return $address !== '' ? mb_substr($address, 0, 10000) : NULL;
    }
    if ($type === 'number' && ($format === '' || $format === 'currency')
      && (is_int($value) || is_float($value) || (is_string($value) && is_numeric(str_replace(',', '.', $value))))) {
      // Match the public parser: a lone three-digit suffix is ambiguous.
      // Keep legacy strings as stored rather than changing their quantity.
      if (is_string($value) && preg_match('/^[+-]?([0-9]{1,3})[.,][0-9]{3}$/D', trim($value), $group)
        && trim($group[1], '0') !== '') {
        return mb_substr($value, 0, 10000);
      }
      return self::formatNumber($value, $format === 'currency', $locale);
    }
    if (is_bool($value)) {
      return $value ? 'Ja' : 'Nein';
    }
    if (is_int($value) || is_float($value)) {
      return (string) $value;
    }
    if (is_string($value)) {
      if ($type === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $match)) {
        return $match[3] . '.' . $match[2] . '.' . $match[1];
      }
      return mb_substr($value, 0, 10000);
    }
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $item) {
        if (is_array($item)) {
          // Upload metadata names the file; other objects join their texts.
          $item = $item['filename'] ?? $item['name'] ?? implode(' ', array_filter($item, 'is_scalar'));
        }
        $text = self::format($item, 'text');
        if ($text !== NULL) {
          $parts[] = $text;
        }
      }
      return $parts === [] ? NULL : implode(', ', $parts);
    }
    return NULL;
  }

  /**
   * Localizes canonical decimal strings without a lossy float conversion.
   */
  private static function formatNumber(int|float|string $value, bool $currency, string $locale): ?string {
    if (is_float($value) && !is_finite($value)) {
      return NULL;
    }
    $raw = is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : str_replace(',', '.', trim((string) $value));
    // Bound expansion of scientific notation before constructing BigDecimal.
    if (strlen($raw) > 10000 || (preg_match('/[eE]([+-]?[0-9]+)$/D', $raw, $exponent) && abs((int) $exponent[1]) > 10000)) {
      return NULL;
    }
    try {
      $number = BigDecimal::of($raw);
      if ($currency) {
        $number = $number->toScale(2, RoundingMode::HalfUp);
      }
    }
    catch (MathException) {
      return NULL;
    }
    $plain = (string) $number->abs();
    if (strlen($plain) > 10000) {
      return NULL;
    }
    [$whole, $fraction] = array_pad(explode('.', $plain, 2), 2, '');
    $formatter = new \NumberFormatter($locale, $currency ? \NumberFormatter::CURRENCY : \NumberFormatter::DECIMAL);
    if ($currency) {
      $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, 'EUR');
    }
    // ICU supplies locale symbols, grouping and affixes, never the quantity.
    if ($formatter->getAttribute(\NumberFormatter::GROUPING_USED) && ($currency || strlen($whole) >= 5)) {
      $size = max(1, (int) $formatter->getAttribute(\NumberFormatter::GROUPING_SIZE));
      $secondary = (int) $formatter->getAttribute(\NumberFormatter::SECONDARY_GROUPING_SIZE);
      $groups = [];
      while (strlen($whole) > $size) {
        array_unshift($groups, substr($whole, -$size));
        $whole = substr($whole, 0, -$size);
        $size = $secondary > 0 ? $secondary : $size;
      }
      array_unshift($groups, $whole);
      $whole = implode((string) $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL), $groups);
    }
    $decimal = $currency ? \NumberFormatter::MONETARY_SEPARATOR_SYMBOL : \NumberFormatter::DECIMAL_SEPARATOR_SYMBOL;
    $text = $whole . ($fraction !== '' ? $formatter->getSymbol($decimal) . $fraction : '');
    $prefix = $number->isNegative() ? \NumberFormatter::NEGATIVE_PREFIX : \NumberFormatter::POSITIVE_PREFIX;
    $suffix = $number->isNegative() ? \NumberFormatter::NEGATIVE_SUFFIX : \NumberFormatter::POSITIVE_SUFFIX;
    return $formatter->getTextAttribute($prefix) . $text . $formatter->getTextAttribute($suffix);
  }

  /**
   * Server-keyed fingerprint of canonical JSON (sorted keys) of render data.
   */
  public static function hash(array $data): string {
    $key = hash_hmac('sha256', 'beantragt_docgen.render_data.v1', Settings::getHashSalt(), TRUE);
    return hash_hmac('sha256', json_encode(self::canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $key);
  }

  /**
   * Sorts object keys recursively; lists keep their order.
   */
  private static function canonical(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }
    if (!array_is_list($value)) {
      ksort($value, SORT_STRING);
    }
    return array_map(self::canonical(...), $value);
  }

}
