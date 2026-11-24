<?php

declare(strict_types=1);

namespace Drupal\beantragt_form_schema_group\Plugin\Validation\Constraint;

use Drupal\beantragt_form_schema_group\Entity\FormSchemaGroupInterface;
use Drupal\beantragt_schema\Exception\WorkspaceCreateDenied;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates personal assignments of a form schema group's workspace and forms.
 */
final class FormSchemaGroupCreateScopeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the form schema group workspace validator.
   */
  public function __construct(private readonly AccountInterface $account) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('current_user'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof FormSchemaGroupInterface || !$constraint instanceof FormSchemaGroupCreateScopeConstraint) {
      return;
    }
    try {
      $message = $value->workspaceScopeViolation($this->account);
    }
    catch (WorkspaceCreateDenied $denial) {
      $this->context->buildViolation($constraint->message, ['@detail' => $denial->getMessage()])
        ->setCode($denial->reason)
        ->addViolation();
      return;
    }
    if ($message !== NULL) {
      $this->context->buildViolation($constraint->message, ['@detail' => $message])->addViolation();
    }
  }

}
