<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Plugin\Field;

use Drupal\beantragt_application\ProfileConsentOffer;
use Drupal\beantragt_application\ProfileConsentScope;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computes the profile consent offer of a form schema.
 *
 * Backs the read-only fields the public schema proxy reads through JSON:API.
 */
final class FormSchemaProfileConsentItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * Offer key per computed field name.
   */
  public const FIELD_KEYS = [
    'field_profile_sync_eligible' => 'profileSyncEligible',
    'field_bank_consent_eligible' => 'bankConsentEligible',
    'field_consent_stelle' => 'consentStelle',
    'field_profile_consent_offer' => 'offers',
  ];

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    $form = $this->getEntity();
    $key = self::FIELD_KEYS[$this->getName()] ?? NULL;
    if (!$form instanceof FormSchemaInterface || $key === NULL) {
      return;
    }
    $value = $key === 'offers'
      ? ProfileConsentOffer::projection($form, \Drupal::service('beantragt_application.applicant_profile_switch'))
      : ProfileConsentScope::offerForForm($form)[$key];
    if ($value === NULL) {
      return;
    }
    $this->list[0] = $this->createItem(0, $value);
  }

}
