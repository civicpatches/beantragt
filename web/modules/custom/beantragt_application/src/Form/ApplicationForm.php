<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for the Application entity add/edit forms.
 */
class ApplicationForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $entity = $this->getEntity();
    $message_args = ['%label' => $entity->toLink()->toString()];

    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Application %label has been created.', $message_args));
    }
    else {
      $this->messenger()->addStatus($this->t('Application %label has been updated.', $message_args));
    }

    $collection = $entity->toUrl('collection');
    $form_state->setRedirectUrl($collection->access() ? $collection : $entity->toUrl());
    return $result;
  }

}
