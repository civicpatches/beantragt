<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Plugin\Field\FormSchemaProfileConsentItemList;
use Drupal\beantragt_schema\Controller\PublicFormApiController;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the computed profile consent fields on form schemas.
 */
#[Group('beantragt_application')]
final class FormSchemaProfileConsentFieldsKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));
  }

  /**
   * The fields are computed, read-only and have no storage.
   */
  public function testFieldsAreComputedWithoutStorage(): void {
    $field_manager = $this->container->get('entity_field.manager');
    $definitions = $field_manager->getFieldDefinitions('form_schema', 'form_schema');
    $storage_definitions = $field_manager->getFieldStorageDefinitions('form_schema');
    foreach (array_keys(FormSchemaProfileConsentItemList::FIELD_KEYS) as $field_name) {
      $this->assertArrayHasKey($field_name, $definitions);
      $this->assertTrue($definitions[$field_name]->isComputed());
      $this->assertTrue($definitions[$field_name]->isReadOnly());
      $this->assertArrayNotHasKey($field_name, $storage_definitions);
    }
    $changes = $this->container->get('entity.definition_update_manager')->getChangeList();
    $this->assertArrayNotHasKey('form_schema', $changes);
  }

  /**
   * Saved forms expose the offer and follow later group label changes.
   */
  public function testSavedFormsExposeOfferAndFollowLabelChanges(): void {
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Stadt Musterstadt',
      'field_slug' => 'musterstadt',
    ]);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Bürgerbüro',
      'field_slug' => 'citizen-services',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);

    $org_form = $this->createForm($jurisdiction, $organisation, TRUE);
    $this->assertOffer($org_form, TRUE, TRUE, 'Bürgerbüro');

    $jur_form = $this->createForm($jurisdiction, NULL, FALSE);
    $this->assertOffer($jur_form, TRUE, FALSE, 'Stadt Musterstadt');

    $organisation->set('label', 'Bürgerservice');
    $organisation->save();
    $this->assertOffer($org_form, TRUE, TRUE, 'Bürgerservice');
  }

  /**
   * JSON:API normalizations of the offer carry the cache tags of its groups.
   *
   * The current user bypasses the group scope, so the group access hook adds
   * no group tags; every group tag here comes from the consent field access.
   */
  public function testJsonApiNormalizationDependsOnConsentGroups(): void {
    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Stadt Musterstadt',
      'field_slug' => 'musterstadt',
      'field_jur_config' => json_encode(['applicant_profile' => ['enabled' => TRUE, 'bankConsent' => TRUE]], JSON_THROW_ON_ERROR),
    ]);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Bürgerbüro',
      'field_slug' => 'citizen-services',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
    ]);
    $expected_tags = ['group:' . $organisation->id(), 'group:' . $jurisdiction->id()];

    $org_form = $this->createForm($jurisdiction, $organisation, TRUE);
    $normalization = $this->normalizeConsentFields($org_form);
    $this->assertSame('Bürgerbüro', $normalization->getNormalization()['attributes']['field_consent_stelle']);
    $offer = json_decode($normalization->getNormalization()['attributes']['field_profile_consent_offer'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['person.iban'], $offer['de']['bank']['fields']);
    $this->assertSame(['person.email', 'person.full_name'], $offer['de']['contact']['fields']);
    $this->assertStringContainsString('bei der Dienststelle „Bürgerbüro“', $offer['de']['contact']['text']);
    $this->assertNotSame($offer['de']['contact']['id'], $offer['en']['contact']['id']);
    $public_forms = PublicFormApiController::create($this->container);
    $public_form = $public_forms->getForm(Request::create('/api/v1/forms/' . $org_form->uuid()), $org_form->uuid());
    $this->assertSame(200, $public_form->getStatusCode());
    $public_data = json_decode((string) $public_form->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($offer, $public_data['data']['profileConsentOffers']);
    $this->assertEmpty(array_diff($expected_tags, $normalization->getCacheTags()));

    // An unpublished organisation falls back to the jurisdiction, and both
    // groups stay dependencies so a rename or republication reaches the
    // projection.
    $organisation->setUnpublished()->save();
    $org_form = $this->reload($org_form);
    $normalization = $this->normalizeConsentFields($org_form);
    $this->assertSame('Stadt Musterstadt', $normalization->getNormalization()['attributes']['field_consent_stelle']);
    $changed = json_decode($normalization->getNormalization()['attributes']['field_profile_consent_offer'], TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertNotSame($offer['de']['contact']['id'], $changed['de']['contact']['id']);
    $this->assertStringContainsString('bei „Stadt Musterstadt“', $changed['de']['contact']['text']);
    $this->assertEmpty(array_diff($expected_tags, $normalization->getCacheTags()));

    $jur_form = $this->createForm($jurisdiction, NULL, FALSE);
    $normalization = $this->normalizeConsentFields($jur_form);
    $this->assertSame('Stadt Musterstadt', $normalization->getNormalization()['attributes']['field_consent_stelle']);
    $this->assertContains('group:' . $jurisdiction->id(), $normalization->getCacheTags());
  }

  /**
   * Normalizes the consent fields of a form through JSON:API.
   */
  private function normalizeConsentFields(FormSchemaInterface $form): CacheableNormalization {
    $resource_type = $this->container->get('jsonapi.resource_type.repository')->get('form_schema', 'form_schema');
    $this->assertInstanceOf(ResourceType::class, $resource_type);
    $normalization = $this->container->get('jsonapi.serializer')->normalize(
      ResourceObject::createFromEntity($resource_type, $form),
      'api_json',
      [
        'account' => $this->container->get('current_user'),
        'resource_type' => $resource_type,
        'sparse_fieldset' => [
          $resource_type->getTypeName() => array_keys(FormSchemaProfileConsentItemList::FIELD_KEYS),
        ],
      ],
    );
    $this->assertInstanceOf(CacheableNormalization::class, $normalization);
    return $normalization;
  }

  /**
   * Reloads a form without the static entity cache.
   */
  private function reload(FormSchemaInterface $form): FormSchemaInterface {
    $storage = $this->entityTypeManager()->getStorage('form_schema');
    $storage->resetCache([$form->id()]);
    $loaded = $storage->load($form->id());
    $this->assertInstanceOf(FormSchemaInterface::class, $loaded);
    return $loaded;
  }

  /**
   * Asserts the computed values of a freshly loaded form.
   */
  private function assertOffer(FormSchemaInterface $form, bool $profile, bool $bank, string $stelle): void {
    $loaded = $this->reload($form);
    $this->assertSame($profile, (bool) $loaded->get('field_profile_sync_eligible')->value);
    $this->assertSame($bank, (bool) $loaded->get('field_bank_consent_eligible')->value);
    $this->assertSame($stelle, $loaded->get('field_consent_stelle')->value);
  }

  /**
   * Creates a published internet form for a jurisdiction or organisation.
   */
  private function createForm(GroupInterface $jurisdiction, ?GroupInterface $organisation, bool $with_iban): FormSchemaInterface {
    $fields = [
      [
        'id' => 'email',
        'type' => 'email',
        'label' => 'E-Mail',
        'crm_key' => 'person.email',
        'privacy' => ['kind' => 'contact'],
      ],
    ];
    if ($with_iban) {
      $fields[] = [
        'id' => 'iban',
        'type' => 'text',
        'label' => 'IBAN',
        'crm_key' => 'person.iban',
        'privacy' => ['kind' => 'financial'],
      ];
    }
    $values = [
      'label' => 'Consent offer',
      'field_schema' => json_encode([
        'title' => 'Consent offer',
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Main',
            'fields' => $fields,
          ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'published',
      'field_publication_scope' => 'internet',
      'field_jurisdiction' => ['target_id' => $jurisdiction->id()],
      'status' => TRUE,
    ];
    if ($organisation instanceof GroupInterface) {
      $values['field_organisation'] = ['target_id' => $organisation->id()];
    }
    $form = $this->entityTypeManager()->getStorage('form_schema')->create($values);
    $form->save();
    assert($form instanceof FormSchemaInterface);
    return $form;
  }

}
