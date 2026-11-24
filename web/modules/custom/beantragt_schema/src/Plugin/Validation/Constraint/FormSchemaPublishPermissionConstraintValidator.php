<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Plugin\Validation\Constraint;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Refuses visibility increases by accounts without the publish permission.
 */
final class FormSchemaPublishPermissionConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The permission that a visibility increase requires.
   */
  public const PERMISSION = 'publish form_schema entities';

  /**
   * Visibility level: not live.
   */
  public const LEVEL_NOT_LIVE = 0;

  /**
   * Visibility level: live for the intranet.
   */
  public const LEVEL_INTRANET = 1;

  /**
   * Visibility level: live for the internet.
   */
  public const LEVEL_INTERNET = 2;

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof FormSchemaPublishPermissionConstraint) {
      throw new UnexpectedTypeException($constraint, FormSchemaPublishPermissionConstraint::class);
    }
    if (!$value instanceof FormSchemaInterface) {
      return;
    }

    $new_level = self::visibilityLevel($value);
    if ($new_level === self::LEVEL_NOT_LIVE) {
      return;
    }
    if ($new_level <= $this->storedLevel($value)) {
      return;
    }
    if ($this->currentUser->hasPermission(self::PERMISSION)) {
      return;
    }

    // No atPath(): the violation must stay entity-level so that JSON:API
    // keeps it for requests that change only `status` or only the scope.
    $this->context->buildViolation($constraint->message)
      ->setParameter('@detail', FormSchemaPublishPermissionConstraint::DETAIL)
      ->addViolation();
  }

  /**
   * Returns the visibility level of a form schema in its current values.
   *
   * 0 = not live (published flag off or workflow status not published),
   * 1 = live for the intranet, 2 = live for the internet. Mirrors the public
   * API test (PublicFormApiController): all three values must agree.
   */
  public static function visibilityLevel(FormSchemaInterface $form): int {
    if (!$form->isPublished() || $form->getSchemaStatus() !== 'published') {
      return self::LEVEL_NOT_LIVE;
    }
    return $form->getPublicationScope() === 'internet' ? self::LEVEL_INTERNET : self::LEVEL_INTRANET;
  }

  /**
   * Returns the level of the stored form, 0 for a new one.
   */
  private function storedLevel(FormSchemaInterface $form): int {
    if ($form->isNew()) {
      return self::LEVEL_NOT_LIVE;
    }
    $original = $this->entityTypeManager
      ->getStorage('form_schema')
      ->loadUnchanged($form->id());
    if (!$original instanceof FormSchemaInterface) {
      return self::LEVEL_NOT_LIVE;
    }
    return self::visibilityLevel($original);
  }

}
