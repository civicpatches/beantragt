<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Plugin\Field;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computes the public delivery offer of a form schema.
 *
 * Backs the read-only form_schema field field_delivery_offer: JSON
 * {"configured": false} or {"configured": true, "routes": [...],
 * "default": "..."} with only the routes that work today. The public schema
 * route reads it through JSON:API, the public form API and the citizen
 * submission read it from the entity. Field access adds the cache tag of
 * the form's docgen settings.
 */
final class FormSchemaDeliveryOfferItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    $form = $this->getEntity();
    if (!$form instanceof FormSchemaInterface) {
      return;
    }
    // Computed item lists are typed data plugins without injection.
    $offer = \Drupal::service('beantragt_docgen.form_templates')->deliveryOffer($form);
    $this->list[0] = $this->createItem(0, json_encode($offer, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

}
