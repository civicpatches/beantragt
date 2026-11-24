<?php

declare(strict_types=1);

namespace Drupal\beantragt_application;

use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\beantragt_application\Service\PublicApplicationSubmissionValidator;

/**
 * Identifies applicant data a public form can offer to save in a profile.
 */
final class ProfileConsentFields {

  private const ADDRESS_PARTS = ['person.street', 'person.postal_code', 'person.city'];

  /**
   * Returns the eligible fields and their source definitions in schema order.
   *
   * Only applicant CRM mappings count. Older untagged identity inputs with
   * dedicated IDs and supplemental identity collected at submission also count.
   * Repeatable sections and computed or protected inputs never contribute.
   *
   * @return list<array<string, mixed>>
   *   Source definitions usable by the CRM extractor.
   */
  public static function sources(array $schema): array {
    if (ApplicationDataProtectionService::schemaRequiresProtectedIdentityLinkage($schema)) {
      return [];
    }

    $validator = new PublicApplicationSubmissionValidator();
    $sources = [];
    $auth = $validator->normalizeAuth($schema['auth'] ?? NULL);
    $has_name = FALSE;
    $has_email = FALSE;
    $has_first_name = FALSE;
    $has_last_name = FALSE;
    foreach ((array) ($schema['sections'] ?? []) as $section) {
      if (!is_array($section)) {
        continue;
      }
      foreach ((array) ($section['fields'] ?? []) as $field) {
        if (!is_array($field) || !is_string($field['id'] ?? NULL) || $validator->normalizeField($field) === NULL) {
          continue;
        }
        $id = trim((string) preg_replace('/[^a-z0-9]+/u', '_', strtolower($field['id'])), '_');
        $label = mb_strtolower(trim((string) ($field['label'] ?? '')));
        $key = $field['crm_key'] ?? NULL;
        $is_name = $key === 'person.full_name'
          || in_array($id, ['full_name', 'applicant_full_name', 'antragsteller_name'], TRUE);
        $is_email = $key === 'person.email' || in_array($id, ['email', 'e_mail', 'applicant_email'], TRUE);
        // An identity input already on the form suppresses the corresponding
        // supplemental question, even if that field cannot feed the profile.
        $has_name = $has_name || $is_name
          || in_array($label, ['name', 'vollstaendiger name', 'vollständiger name', 'ihr name'], TRUE)
          || preg_match('/^(name der antragsteller|antragsteller.*name)/u', $label) === 1;
        $has_email = $has_email || $is_email
          || in_array($label, ['e-mail', 'e-mail-adresse', 'email', 'email address'], TRUE);
        $has_first_name = $has_first_name || $key === 'person.first_name'
          || in_array($id, [
            'given_name',
            'first_name',
            'firstname',
            'vorname',
            'applicant_given_name',
            'applicant_first_name',
          ], TRUE)
          || in_array($label, ['vorname', 'given name', 'first name'], TRUE);
        $has_last_name = $has_last_name || $key === 'person.last_name'
          || in_array($id, [
            'family_name',
            'last_name',
            'lastname',
            'surname',
            'nachname',
            'familienname',
            'applicant_family_name',
            'applicant_last_name',
          ], TRUE)
          || in_array($label, ['familienname', 'nachname', 'family name', 'last name', 'surname'], TRUE);
        if (($section['repeatable'] ?? FALSE) === TRUE || isset($field['computed'])
          || $field['type'] === 'file' || Application::isSensitiveSchemaField($field)
          || in_array($field['privacy']['kind'] ?? NULL, ['special_category', 'criminal_offense'], TRUE)) {
          continue;
        }
        if ($key === NULL || $key === '') {
          // Generic IDs can be used for another person's details. The
          // untagged exception is limited to explicit applicant identity.
          $key = match ($id) {
            'applicant_full_name', 'antragsteller_name' => 'person.full_name',
            'applicant_email' => 'person.email',
            default => NULL,
          };
        }
        if (in_array($key, Application::APPLICANT_SYNC_CRM_KEYS, TRUE) || $key === ApplicantContactKeys::HOUSE_NUMBER) {
          $field['crm_key'] = $key;
          $sources[] = $field;
        }
      }
    }

    foreach ([
      ['fullName', 'applicant_full_name', 'person.full_name', $has_name || ($has_first_name && $has_last_name)],
      ['email', 'applicant_email', 'person.email', $has_email],
    ] as [$requirement, $id, $key, $has_field]) {
      if (!$has_field && !in_array($auth['collect'][$requirement] ?? 'required', [FALSE, 'none'], TRUE)) {
        $sources[] = ['id' => $id, 'crm_key' => $key];
      }
    }
    $counts = array_count_values(array_column($sources, 'crm_key'));
    return array_values(array_filter($sources, static fn (array $source): bool => $counts[$source['crm_key']] === 1));
  }

  /**
   * Returns stable CRM field lists per consent kind.
   *
   * @return array{contact: list<string>, bank: list<string>}
   *   Field keys in the order used in the rendered offers.
   */
  public static function byKind(array $schema): array {
    $keys = [];
    foreach (self::sources($schema) as $field) {
      $key = $field['crm_key'];
      $keys[] = $key;
      if ($key === 'person.address' && ($field['type'] ?? NULL) === 'address') {
        array_push($keys, ...self::ADDRESS_PARTS);
      }
    }
    $keys = array_unique($keys);
    if (!in_array('person.street', $keys, TRUE)) {
      $keys = array_diff($keys, [ApplicantContactKeys::HOUSE_NUMBER]);
    }
    $bank = in_array('person.iban', $keys, TRUE) ? array_intersect(ApplicationInterface::BANK_CONSENT_CRM_KEYS, $keys) : [];
    return [
      'contact' => array_values(array_diff($keys, ApplicationInterface::BANK_CONSENT_CRM_KEYS)),
      'bank' => array_values($bank),
    ];
  }

  /**
   * Extracts only approved sources from the submitted schema and data.
   *
   * @param array $schema
   *   The submitted form schema.
   * @param array $data
   *   Stored public submission data.
   * @param list<string> $fields
   *   CRM keys covered by the consent records.
   *
   * @return array
   *   Approved profile CRM values.
   */
  public static function extract(array $schema, array $data, array $fields): array {
    $crm = Application::extractCrmDataFromSubmittedData(
      $data,
      ['sections' => [['fields' => self::sources($schema)]]],
      FALSE,
    );
    return Application::filterCrmDataForApplicantSync(array_intersect_key($crm, array_flip($fields)));
  }

}
