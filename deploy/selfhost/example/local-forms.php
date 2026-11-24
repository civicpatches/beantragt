<?php

/**
 * @file
 * Publishes the residents parking form for the local example.
 */

declare(strict_types=1);

use Drupal\group\Entity\GroupInterface;

require_once __DIR__ . '/lib/parking-form.php';

if (getenv('BEANTRAGT_EXAMPLE_DATA') !== '1') {
  throw new RuntimeException('The local example data is disabled.');
}

$manager = \Drupal::entityTypeManager();
$jurisdictions = $manager->getStorage('group')->loadByProperties(['type' => 'jur', 'field_slug' => 'musterstadt']);
$jurisdiction = reset($jurisdictions);
if (count($jurisdictions) !== 1 || !$jurisdiction instanceof GroupInterface) {
  throw new RuntimeException('Expected one Musterstadt jurisdiction.');
}
beantragt_product_smoke_ensure_parking_form($manager, $jurisdiction);
