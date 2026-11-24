<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit;

use Drupal\beantragt_application\ProfileConsentFields;
use Drupal\beantragt_application\ProfileConsentScope;
use Drupal\beantragt_application\ProfileConsentText;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Stelle resolution and the profile consent offer of a form.
 */
#[CoversClass(ProfileConsentScope::class)]
#[Group('beantragt_application')]
final class ProfileConsentScopeTest extends UnitTestCase {

  /**
   * Generic legacy IDs and ambiguous mappings do not establish applicant data.
   */
  public function testOnlyUnambiguousApplicantSourcesAreOffered(): void {
    $schema = [
      'auth' => ['collect' => ['fullName' => 'none', 'email' => 'none']],
      'sections' => [
        [
          'fields' => [
            ['id' => 'full_name', 'type' => 'text', 'label' => 'Name des Kindes'],
            ['id' => 'email', 'type' => 'email', 'label' => 'E-Mail einer weiteren Person'],
            ['id' => 'contact_mail', 'type' => 'email', 'crm_key' => 'person.email'],
            ['id' => 'phone_a', 'type' => 'tel', 'crm_key' => 'person.phone'],
            ['id' => 'phone_b', 'type' => 'tel', 'crm_key' => 'person.phone'],
          ],
        ],
        [
          'repeatable' => TRUE,
          'fields' => [
            ['id' => 'birthday', 'type' => 'date', 'crm_key' => 'person.birthdate'],
          ],
        ],
      ],
    ];
    $this->assertSame(['contact' => ['person.email'], 'bank' => []], ProfileConsentFields::byKind($schema));
    $this->assertSame(['person.email' => 'citizen@example.org'], ProfileConsentFields::extract(
      $schema,
      ['full_name' => 'Emil Beispiel', 'email' => 'emil@example.org', 'contact_mail' => 'citizen@example.org'],
      ['person.full_name', 'person.email', 'person.birthdate'],
    ));
  }

  /**
   * The form organisation is the Stelle; IBAN forms also offer bank consent.
   */
  public function testOrganisationFormOffersBothConsentsAndNamesOrganisation(): void {
    $jurisdiction = $this->group('jur', 3, 'Stadt Musterstadt');
    $organisation = $this->group('org', 4, 'Bürgerbüro', $jurisdiction);
    $form = $this->form($this->schema(TRUE), [
      'field_jurisdiction' => $jurisdiction,
      'field_organisation' => $organisation,
    ]);

    $this->assertSame([
      'profileSyncEligible' => TRUE,
      'bankConsentEligible' => TRUE,
      'consentStelle' => 'Bürgerbüro',
    ], ProfileConsentScope::offerForForm($form));
    $this->assertSame([
      'type' => 'org',
      'id' => 4,
      'uuid' => '6a3c2c8e-0000-4000-8000-000000000004',
      'label' => 'Bürgerbüro',
    ], ProfileConsentScope::forForm($form));
  }

  /**
   * Jurisdiction-direct forms name the jurisdiction; no IBAN, no bank consent.
   */
  public function testJurisdictionDirectFormNamesJurisdiction(): void {
    $jurisdiction = $this->group('jur', 3, 'Stadt Musterstadt');
    $form = $this->form($this->schema(), ['field_jurisdiction' => $jurisdiction]);

    $this->assertSame([
      'profileSyncEligible' => TRUE,
      'bankConsentEligible' => FALSE,
      'consentStelle' => 'Stadt Musterstadt',
    ], ProfileConsentScope::offerForForm($form));
  }

  /**
   * Legacy field_group references resolve like the submission API.
   */
  public function testLegacyGroupReferenceResolvesOrganisationOrJurisdiction(): void {
    $jurisdiction = $this->group('jur', 3, 'Stadt Musterstadt');
    $organisation = $this->group('org', 4, 'Bürgerbüro', $jurisdiction);

    $org_form = $this->form($this->schema(), ['field_group' => $organisation]);
    $groups = ProfileConsentScope::formGroups($org_form);
    $this->assertSame($organisation, $groups['routeOrganisation']);
    $this->assertSame($jurisdiction, $groups['jurisdiction']);
    $this->assertSame('Bürgerbüro', ProfileConsentScope::offerForForm($org_form)['consentStelle']);

    $jur_form = $this->form($this->schema(), ['field_group' => $jurisdiction]);
    $groups = ProfileConsentScope::formGroups($jur_form);
    $this->assertNull($groups['routeOrganisation']);
    $this->assertSame($jurisdiction, $groups['jurisdiction']);
    $this->assertSame('Stadt Musterstadt', ProfileConsentScope::offerForForm($jur_form)['consentStelle']);
  }

  /**
   * An unpublished organisation is skipped in favour of the jurisdiction.
   */
  public function testUnpublishedOrganisationFallsBackToJurisdiction(): void {
    $jurisdiction = $this->group('jur', 3, 'Stadt Musterstadt');
    $organisation = $this->group('org', 4, 'Bürgerbüro', $jurisdiction, FALSE);
    $form = $this->form($this->schema(), [
      'field_jurisdiction' => $jurisdiction,
      'field_organisation' => $organisation,
    ]);

    $this->assertSame('Stadt Musterstadt', ProfileConsentScope::offerForForm($form)['consentStelle']);
  }

  /**
   * The Stelle is the untranslated label, whatever the request language.
   */
  public function testStelleUsesUntranslatedGroupLabel(): void {
    $jurisdiction = $this->group('jur', 3, 'Stadt Musterstadt');
    $organisation = $this->group('org', 4, 'Bürgerbüro', $jurisdiction, TRUE, 'Citizen Services Office');
    $form = $this->form($this->schema(), [
      'field_jurisdiction' => $jurisdiction,
      'field_organisation' => $organisation,
    ]);

    $this->assertSame('Bürgerbüro', ProfileConsentScope::offerForForm($form)['consentStelle']);
  }

  /**
   * The Stelle is normalized exactly like the rendered consent text.
   */
  public function testStelleIsNormalizedLikeTheRenderedText(): void {
    $jurisdiction = $this->group('jur', 3, " Stadt  Musterstadt\u{00A0}");
    $form = $this->form($this->schema(), ['field_jurisdiction' => $jurisdiction]);

    $stelle = ProfileConsentScope::offerForForm($form)['consentStelle'];
    $this->assertSame('Stadt Musterstadt', $stelle);
    $this->assertSame('Stadt Musterstadt', ProfileConsentScope::forForm($form)['label'] ?? NULL);
    $this->assertSame(
      ProfileConsentText::render(ProfileConsentText::KIND_CONTACT, 'de', " Stadt  Musterstadt\u{00A0}"),
      str_replace(ProfileConsentText::PLACEHOLDER, $stelle, ProfileConsentText::templates(ProfileConsentText::KIND_CONTACT)['de']),
    );
  }

  /**
   * Cache dependencies include unpublished groups and the org jurisdiction.
   */
  public function testCacheableGroupsIncludeEveryReferencedGroup(): void {
    $jurisdiction = $this->group('jur', 3, 'Stadt Musterstadt');
    $organisation = $this->group('org', 4, 'Bürgerbüro', $jurisdiction, FALSE);
    $org_only = $this->form($this->schema(), ['field_organisation' => $organisation]);
    $ids = array_map(static fn(GroupInterface $group): int => (int) $group->id(), ProfileConsentScope::cacheableGroups($org_only));
    $this->assertSame([4, 3], $ids);

    $legacy_jurisdiction = $this->group('jur', 5, 'Landkreis', NULL, FALSE);
    $both = $this->form($this->schema(), [
      'field_organisation' => $organisation,
      'field_jurisdiction' => $jurisdiction,
      'field_group' => $legacy_jurisdiction,
    ]);
    $ids = array_map(static fn(GroupInterface $group): int => (int) $group->id(), ProfileConsentScope::cacheableGroups($both));
    $this->assertSame([4, 3, 5], $ids);

    $this->assertSame([], ProfileConsentScope::cacheableGroups($this->form($this->schema(), [])));
  }

  /**
   * Without a labelled, published group nothing is offered.
   */
  public function testFormWithoutStelleOffersNothing(): void {
    $none = [
      'profileSyncEligible' => FALSE,
      'bankConsentEligible' => FALSE,
      'consentStelle' => NULL,
    ];
    $this->assertSame($none, ProfileConsentScope::offerForForm($this->form($this->schema(TRUE), [])));

    $unlabelled = $this->group('jur', 3, '   ');
    $this->assertSame($none, ProfileConsentScope::offerForForm($this->form($this->schema(TRUE), ['field_jurisdiction' => $unlabelled])));

    $unpublished = $this->group('jur', 3, 'Stadt Musterstadt', NULL, FALSE);
    $this->assertSame($none, ProfileConsentScope::offerForForm($this->form($this->schema(TRUE), ['field_jurisdiction' => $unpublished])));
  }

  /**
   * Linkage-protected forms, sensitive IBANs and broken JSON fail closed.
   */
  public function testIneligibleSchemasFailClosed(): void {
    $jurisdiction = $this->group('jur', 3, 'Stadt Musterstadt');
    $none = [
      'profileSyncEligible' => FALSE,
      'bankConsentEligible' => FALSE,
      'consentStelle' => NULL,
    ];

    $protected = json_decode($this->schema(TRUE), TRUE, 512, JSON_THROW_ON_ERROR);
    $protected['sections'][0]['fields'][] = [
      'id' => 'health_note',
      'type' => 'text',
      'label' => 'Health note',
      'sensitive' => TRUE,
    ];
    $this->assertSame($none, ProfileConsentScope::offerForForm(
      $this->form((string) json_encode($protected), ['field_jurisdiction' => $jurisdiction]),
    ));

    $sensitive_iban = json_decode($this->schema(TRUE), TRUE, 512, JSON_THROW_ON_ERROR);
    $sensitive_iban['sections'][0]['fields'][1]['sensitive'] = TRUE;
    // A sensitive IBAN makes the whole form linkage-protected.
    $this->assertSame($none, ProfileConsentScope::offerForForm(
      $this->form((string) json_encode($sensitive_iban), ['field_jurisdiction' => $jurisdiction]),
    ));

    foreach (['', '{broken', '[]', NULL] as $broken) {
      $this->assertSame($none, ProfileConsentScope::offerForForm(
        $this->form($broken, ['field_jurisdiction' => $jurisdiction]),
      ));
    }
  }

  /**
   * Builds a form schema JSON with a contact field and an optional IBAN.
   */
  private function schema(bool $with_iban = FALSE): string {
    $fields = [
      [
        'id' => 'email',
        'type' => 'email',
        'label' => 'E-Mail',
        'crm_key' => 'person.email',
      ],
    ];
    if ($with_iban) {
      $fields[] = [
        'id' => 'iban',
        'type' => 'text',
        'label' => 'IBAN',
        'crm_key' => 'person.iban',
      ];
    }
    return (string) json_encode([
      'title' => 'Form',
      'sections' => [
        [
          'id' => 'main',
          'title' => 'Main',
          'fields' => $fields,
        ],
      ],
    ]);
  }

  /**
   * Builds a form schema mock with group references.
   *
   * @param string|null $schema
   *   Stored schema JSON.
   * @param array<string, \Drupal\group\Entity\GroupInterface> $references
   *   Referenced groups keyed by field name.
   */
  private function form(?string $schema, array $references): FormSchemaInterface {
    $fields = [];
    foreach (['field_jurisdiction', 'field_organisation', 'field_group'] as $field_name) {
      $fields[$field_name] = isset($references[$field_name])
        ? $this->reference($references[$field_name])
        : $this->emptyField();
    }
    $form = $this->createMock(FormSchemaInterface::class);
    $form->method('getSchema')->willReturn($schema);
    $form->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $form->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $fields[$field]);
    return $form;
  }

  /**
   * Builds a group mock whose translated label may differ from the source.
   */
  private function group(string $bundle, int $id, string $label, ?GroupInterface $jurisdiction = NULL, bool $published = TRUE, ?string $translated_label = NULL): GroupInterface {
    $fields = [
      'field_jurisdiction' => $jurisdiction instanceof GroupInterface ? $this->reference($jurisdiction) : $this->emptyField(),
    ];
    $source = $this->createMock(GroupInterface::class);
    $source->method('label')->willReturn($label);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    $group->method('id')->willReturn($id);
    $group->method('uuid')->willReturn(sprintf('6a3c2c8e-0000-4000-8000-%012d', $id));
    $group->method('label')->willReturn($translated_label ?? $label);
    $group->method('isPublished')->willReturn($published);
    $group->method('getUntranslated')->willReturn($source);
    $group->method('hasField')->willReturnCallback(static fn(string $field): bool => isset($fields[$field]));
    $group->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $fields[$field]);
    return $group;
  }

  /**
   * Builds an entity reference field list.
   */
  private function reference(object $entity): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('__get')->willReturnCallback(
      static fn(string $property): mixed => $property === 'entity' ? $entity : NULL,
    );
    return $field;
  }

  /**
   * Builds an empty field list.
   */
  private function emptyField(): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);
    return $field;
  }

}
