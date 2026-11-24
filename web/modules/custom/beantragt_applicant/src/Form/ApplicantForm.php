<?php

declare(strict_types=1);

namespace Drupal\beantragt_applicant\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for applicant entity add/edit forms.
 */
class ApplicantForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $entity = $this->getEntity();
    $message_args = ['%label' => $entity->toLink()->toString()];

    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Applicant %label has been created.', $message_args));
    }
    else {
      $this->messenger()->addStatus($this->t('Applicant %label has been updated.', $message_args));
    }

    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $result;
  }

}
