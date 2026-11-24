<?php

namespace Drupal\beantragt_application_revision_test\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Records the parent revision flag at field presave time.
 */
#[FieldType(id: 'application_revision_probe', label: new TranslatableMarkup('Revision probe'))]
final class RevisionProbeItem extends StringItem {

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    parent::preSave();
    \Drupal::state()->set('application_revision_probe.new_revision', $this->getEntity()->isNewRevision());
  }

}
