<?php

declare(strict_types=1);

namespace Drupal\beantragt_form_schema_group\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for form_schema_group add and edit forms.
 */
class FormSchemaGroupForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the form schema group %label.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirect('entity.form_schema_group.collection');
    return $result;
  }

}
