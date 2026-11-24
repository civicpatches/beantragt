<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit;

use Drupal\beantragt_application\Entity\Application;
use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests application CRM data filtering.
 */
#[CoversClass(Application::class)]
#[Group('beantragt_application')]
class ApplicationCrmDataTest extends UnitTestCase {

  /**
   * Application CRM storage keeps only contact keys.
   */
  public function testApplicationStorageKeepsOnlyContactAllowlist(): void {
    $filtered = Application::filterCrmDataForApplicationStorage(
          [
            'person.full_name' => 'Max Mustermann',
            'person.email' => '  Max@Example.Test  ',
            'person.phone' => '+49 30 23125 12345',
            'person.street' => 'Musterstrasse 1',
            'person.birthdate' => '1970-01-01',
            'person.gender' => 'diverse',
            'person.iban' => 'DE89370400440532013000',
            'person.tax_id' => '12345678901',
            'health.disease' => 'IfSG-Meldung',
          ]
      );

    $this->assertSame('Max Mustermann', $filtered['person.full_name']);
    $this->assertSame('max@example.test', $filtered['person.email']);
    $this->assertSame('+49 30 23125 12345', $filtered['person.phone']);
    $this->assertSame('Musterstrasse 1', $filtered['person.street']);
    $this->assertArrayNotHasKey('person.birthdate', $filtered);
    $this->assertArrayNotHasKey('person.gender', $filtered);
    $this->assertArrayNotHasKey('person.iban', $filtered);
    $this->assertArrayNotHasKey('person.tax_id', $filtered);
    $this->assertArrayNotHasKey('health.disease', $filtered);
  }

  /**
   * Sensitive schema fields are excluded from CRM extraction.
   */
  public function testSensitiveSchemaFieldsAreSkippedFromCrmExtraction(): void {
    $crm_data = Application::extractCrmDataFromSubmittedData(
          [
            'email' => 'max@example.test',
            'phone' => '+49 30 23125 12345',
            'health_issue' => 'IfSG-Meldung',
            'iban' => 'DE89370400440532013000',
          ], [
            'sections' => [
          [
            'fields' => [
          ['id' => 'email', 'crm_key' => 'person.email'],
          ['id' => 'phone', 'crm_key' => 'person.phone', 'sensitive' => 'true'],
          ['id' => 'health_issue', 'crm_key' => 'health.disease', 'sensitive' => TRUE],
          ['id' => 'iban', 'crm_key' => 'person.iban', 'sensitive' => 1],
            ],
          ],
            ],
          ]
      );

    $this->assertSame('max@example.test', $crm_data['person.email']);
    $this->assertArrayNotHasKey('person.phone', $crm_data);
    $this->assertArrayNotHasKey('health.disease', $crm_data);
    $this->assertArrayNotHasKey('person.iban', $crm_data);
  }

  /**
   * Sensitive supplemental identity fields are not re-added from field_data.
   */
  public function testSensitiveSupplementalIdentityFieldsAreNotReadded(): void {
    $crm_data = Application::extractCrmDataFromSubmittedData(
          [
            'applicant_full_name' => 'Erika Mustermann',
            'applicant_email' => 'erika@example.test',
          ], [
            'sections' => [
          [
            'fields' => [
          ['id' => 'applicant_full_name', 'crm_key' => 'person.full_name', 'sensitive' => TRUE],
          ['id' => 'applicant_email', 'crm_key' => 'person.email', 'sensitive' => TRUE],
            ],
          ],
            ],
          ]
      );

    $this->assertArrayNotHasKey('person.full_name', $crm_data);
    $this->assertArrayNotHasKey('person.email', $crm_data);
  }

  /**
   * Applicant CRM sync keeps bank keys for the bank gate but drops the tax ID.
   */
  public function testApplicantCrmSyncKeepsBankKeysAndNeverTheTaxId(): void {
    $crm_data = Application::extractCrmDataFromSubmittedData(
          [
            'full_name' => 'Max Mustermann',
            'email' => 'max@example.test',
            'iban' => 'DE89370400440532013000',
            'account_holder' => 'Max Mustermann',
            'tax_id' => '12345678901',
            'street' => 'Musterstrasse 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            'health_issue' => 'IfSG-Meldung',
          ], [
            'sections' => [
          [
            'fields' => [
          ['id' => 'full_name', 'crm_key' => 'person.full_name'],
          ['id' => 'email', 'crm_key' => 'person.email'],
          ['id' => 'iban', 'crm_key' => 'person.iban'],
          ['id' => 'account_holder', 'crm_key' => 'person.account_holder'],
          ['id' => 'tax_id', 'crm_key' => 'person.tax_id'],
          ['id' => 'street', 'crm_key' => 'person.street'],
          ['id' => 'postal_code', 'crm_key' => 'person.postal_code'],
          ['id' => 'city', 'crm_key' => 'person.city'],
          ['id' => 'health_issue', 'crm_key' => 'health.disease'],
            ],
          ],
            ],
          ]
      );
    $crm_data = Application::filterCrmDataForApplicantSync($crm_data);

    $this->assertSame('Max Mustermann', $crm_data['person.full_name']);
    $this->assertSame('max@example.test', $crm_data['person.email']);
    $this->assertSame('DE89370400440532013000', $crm_data['person.iban']);
    $this->assertSame('Max Mustermann', $crm_data['person.account_holder']);
    $this->assertArrayNotHasKey('person.tax_id', $crm_data);
    $this->assertSame('Musterstrasse 1', $crm_data['person.street']);
    $this->assertSame('12345', $crm_data['person.postal_code']);
    $this->assertSame('Musterstadt', $crm_data['person.city']);
    $this->assertArrayNotHasKey('health.disease', $crm_data);
  }

  /**
   * A separate house number joins the street for the profile only.
   *
   * Round trip of a form that asks for street and number apart: prefill split
   * "Marktplatz 1" into "Marktplatz" and "1", the submission must give the
   * profile its stored line back, so the address unit stays as stored.
   */
  public function testApplicantSyncFoldsHouseNumberIntoStreet(): void {
    $schema = [
      'sections' => [
        [
          'fields' => [
            ['id' => 'street', 'crm_key' => 'person.street'],
            ['id' => 'house_number', 'crm_key' => 'person.house_number'],
            ['id' => 'postal_code', 'crm_key' => 'person.postal_code'],
            ['id' => 'city', 'crm_key' => 'person.city'],
          ],
        ],
      ],
    ];
    $data = [
      'street' => 'Marktplatz',
      'house_number' => '1',
      'postal_code' => '12345',
      'city' => 'Musterstadt',
    ];
    $crm_data = Application::extractCrmDataFromSubmittedData($data, $schema);

    $sync = Application::filterCrmDataForApplicantSync($crm_data);
    $this->assertSame('Marktplatz 1', $sync['person.street']);
    $this->assertSame('12345', $sync['person.postal_code']);
    $this->assertSame('Musterstadt', $sync['person.city']);
    $this->assertArrayNotHasKey('person.house_number', $sync);

    // The application copy keeps the submitted street as entered.
    $storage = Application::filterCrmDataForApplicationStorage($crm_data);
    $this->assertSame('Marktplatz', $storage['person.street']);
    $this->assertArrayNotHasKey('person.house_number', $storage);

    // A street that already ends with the number is not extended again.
    $typed_twice = Application::filterCrmDataForApplicantSync(
      Application::extractCrmDataFromSubmittedData(['street' => 'Marktplatz 1'] + $data, $schema),
    );
    $this->assertSame('Marktplatz 1', $typed_twice['person.street']);

    // A number without a street invents no street.
    $number_only = Application::filterCrmDataForApplicantSync(
      Application::extractCrmDataFromSubmittedData(['street' => ' '] + $data, $schema),
    );
    $this->assertSame('', trim((string) ($number_only['person.street'] ?? '')));
    $this->assertArrayNotHasKey('person.house_number', $number_only);
  }

  /**
   * Structured addresses project scalar CRM values for applicant sync.
   */
  public function testStructuredAddressProjectsApplicantCrmParts(): void {
    $crm_data = Application::extractCrmDataFromSubmittedData(
      [
        'address' => [
          'street' => 'Rathausstraße',
          'houseNumber' => '1',
          'postcode' => '12345',
          'city' => 'Musterstadt',
          'formatted' => 'Rathausstraße 1, 12345 Musterstadt',
          'lat' => 50.5432,
          'lng' => 7.6543,
        ],
      ],
      [
        'sections' => [
          [
            'fields' => [
              [
                'id' => 'address',
                'type' => 'address',
                'crm_key' => 'person.address',
              ],
            ],
          ],
        ],
      ],
    );

    $this->assertSame('Rathausstraße 1, 12345 Musterstadt', $crm_data['person.address']);
    $this->assertSame('Rathausstraße', $crm_data['person.street']);
    $this->assertSame('12345', $crm_data['person.postal_code']);
    $this->assertSame('Musterstadt', $crm_data['person.city']);
    foreach ($crm_data as $value) {
      $this->assertIsString($value);
    }
  }

  /**
   * Sensitive profile fields never reach applicant sync.
   */
  public function testSensitiveProfileFieldsDoNotReachApplicantSync(): void {
    $crm_data = Application::extractCrmDataFromSubmittedData(
          [
            'full_name' => 'Max Mustermann',
            'iban' => 'DE89370400440532013000',
          ], [
            'sections' => [
          [
            'fields' => [
          ['id' => 'full_name', 'crm_key' => 'person.full_name'],
          ['id' => 'iban', 'crm_key' => 'person.iban', 'sensitive' => TRUE],
            ],
          ],
            ],
          ]
      );
    $crm_data = Application::filterCrmDataForApplicantSync($crm_data);

    $this->assertSame('Max Mustermann', $crm_data['person.full_name']);
    $this->assertArrayNotHasKey('person.iban', $crm_data);
  }

  /**
   * Sensitive non-file facts require identity linkage protection.
   */
  public function testSensitiveNonFileFactsRequireIdentityLinkageProtection(): void {
    $schema = [
      'sections' => [
        [
          'fields' => [
            ['id' => 'full_name', 'crm_key' => 'person.full_name'],
            ['id' => 'email', 'crm_key' => 'person.email'],
            ['id' => 'diagnosis', 'type' => 'text', 'sensitive' => TRUE],
          ],
        ],
      ],
    ];

    $this->assertTrue(ApplicationDataProtectionService::schemaRequiresProtectedIdentityLinkage($schema));
  }

  /**
   * Sensitive file metadata alone does not disable CRM linkage.
   */
  public function testSensitiveFileOnlySchemaDoesNotRequireIdentityLinkageProtection(): void {
    $schema = [
      'sections' => [
        [
          'fields' => [
            ['id' => 'full_name', 'crm_key' => 'person.full_name'],
            ['id' => 'evidence', 'type' => 'file'],
          ],
        ],
      ],
    ];

    $this->assertFalse(ApplicationDataProtectionService::schemaRequiresProtectedIdentityLinkage($schema));
  }

  /**
   * Supplemental applicant fields can feed CRM when the schema lacks tags.
   */
  public function testSupplementalIdentityIsAddedToCrmData(): void {
    $crm_data = Application::addSupplementalIdentityToCrmData(
          [], [
            'dog_name' => 'Wauzi',
            'applicant_full_name' => '  Erika Mustermann  ',
            'applicant_email' => '  Erika.Mustermann@Example.Org  ',
          ]
      );

    $this->assertSame('Erika Mustermann', $crm_data['person.full_name']);
    $this->assertSame('erika.mustermann@example.org', $crm_data['person.email']);
  }

  /**
   * Real CRM-tagged fields win over supplemental fallback fields.
   */
  public function testSupplementalIdentityDoesNotOverrideSchemaCrmData(): void {
    $crm_data = Application::addSupplementalIdentityToCrmData(
          [
            'person.full_name' => 'Schema Name',
            'person.email' => '  Schema@Example.Org  ',
          ], [
            'applicant_full_name' => 'Fallback Name',
            'applicant_email' => 'fallback@example.org',
          ]
      );

    $this->assertSame('Schema Name', $crm_data['person.full_name']);
    $this->assertSame('schema@example.org', $crm_data['person.email']);
  }

  /**
   * Invalid CRM-tagged emails are removed before supplemental fallback runs.
   */
  public function testInvalidSchemaCrmEmailFallsBackToSupplementalEmail(): void {
    $crm_data = Application::addSupplementalIdentityToCrmData(
          [
            'person.email' => 'not-an-email',
          ], [
            'applicant_email' => 'fallback@example.org',
          ]
      );

    $this->assertSame('fallback@example.org', $crm_data['person.email']);
  }

  /**
   * Invalid CRM-tagged emails are not stored on application.field_crm_data.
   */
  public function testFilterCrmDataRemovesInvalidEmail(): void {
    $filtered = Application::filterCrmDataForApplicationStorage(
          [
            'person.email' => 'not-an-email',
          ]
      );

    $this->assertArrayNotHasKey('person.email', $filtered);
  }

  /**
   * Field data must stay object-shaped at the entity boundary.
   */
  public function testFieldDataShapeRejectsNonObjectJson(): void {
    $method = new \ReflectionMethod(Application::class, 'assertValidFieldDataJsonShape');
    $method->setAccessible(TRUE);

    $method->invoke(NULL, '{"destination":"Dunkelstadt"}');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The field_data value must be a JSON object.');
    $method->invoke(NULL, '["Dunkelstadt"]');
  }

  /**
   * Existing field data changes require a schema-revision CRM refresh.
   */
  public function testExistingFieldDataChangesRequireExplicitCrmRefresh(): void {
    $requires_refresh = new \ReflectionMethod(Application::class, 'fieldDataChangeRequiresExplicitCrmRefresh');
    $requires_refresh->setAccessible(TRUE);
    $auto_extract = new \ReflectionMethod(Application::class, 'shouldAutoExtractCrmDataOnPreSave');
    $auto_extract->setAccessible(TRUE);

    $this->assertTrue($requires_refresh->invoke(NULL, FALSE, TRUE, FALSE));
    $this->assertFalse($requires_refresh->invoke(NULL, FALSE, TRUE, TRUE));
    $this->assertFalse($requires_refresh->invoke(NULL, FALSE, FALSE, FALSE));
    $this->assertFalse($requires_refresh->invoke(NULL, TRUE, TRUE, FALSE));

    $this->assertTrue($auto_extract->invoke(NULL, TRUE, FALSE));
    $this->assertFalse($auto_extract->invoke(NULL, FALSE, FALSE));
    $this->assertFalse($auto_extract->invoke(NULL, TRUE, TRUE));
  }

  /**
   * Invalid synthetic applicant emails are not persisted as CRM email.
   */
  public function testSupplementalIdentityIgnoresInvalidEmail(): void {
    $crm_data = Application::addSupplementalIdentityToCrmData(
          [], [
            'applicant_full_name' => 'Erika Mustermann',
            'applicant_email' => 'not-an-email',
          ]
      );

    $this->assertSame('Erika Mustermann', $crm_data['person.full_name']);
    $this->assertArrayNotHasKey('person.email', $crm_data);
  }

}
