<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Plugin\Validation\Constraint;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Exception\WorkspaceCreateDenied;
use Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates form schema workspace scope against the acting account.
 */
final class FormSchemaWorkspaceScopeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a FormSchemaWorkspaceScopeConstraintValidator.
   */
  public function __construct(
    private readonly FormSchemaWorkspaceScope $workspaceScope,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('beantragt_schema.workspace_scope'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof FormSchemaWorkspaceScopeConstraint) {
      throw new UnexpectedTypeException($constraint, FormSchemaWorkspaceScopeConstraint::class);
    }
    if (!$value instanceof FormSchemaInterface) {
      return;
    }

    try {
      $message = $this->workspaceScope->violationMessage($value, $this->currentUser);
    }
    catch (WorkspaceCreateDenied $denial) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@detail', $denial->getMessage())
        ->setCode($denial->reason)
        ->addViolation();
      return;
    }
    if ($message === NULL) {
      return;
    }

    $this->context->buildViolation($constraint->message)
      ->setParameter('@detail', $message)
      ->addViolation();
  }

}
