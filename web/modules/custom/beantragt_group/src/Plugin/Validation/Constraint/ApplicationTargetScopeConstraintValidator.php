<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Plugin\Validation\Constraint;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_group\Service\ApplicationScopeAuthorizationInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates application target-scope changes.
 */
final class ApplicationTargetScopeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs an ApplicationTargetScopeConstraintValidator.
   */
  public function __construct(
    private readonly ApplicationScopeAuthorizationInterface $scopeAuthorization,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('beantragt_group.application_scope_authorization'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ApplicationTargetScopeConstraint
      || !$value instanceof EntityReferenceFieldItemListInterface) {
      return;
    }

    $application = $value->getEntity();
    if (!$application instanceof ApplicationInterface) {
      return;
    }

    $target = $this->scopeAuthorization->findUnauthorizedTarget($application, $this->currentUser);
    if ($target === NULL || $value->getName() !== $target['field_name']) {
      return;
    }

    $scope = $this->entityTypeManager->getStorage('group')->load($target['scope_id']);
    $scope_label = $scope?->label() ?: (string) $target['scope_id'];
    $this->context->buildViolation($constraint->message, ['@scope' => $scope_label])
      ->addViolation();
  }

}
