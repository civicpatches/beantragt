<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Plugin\Validation\Constraint;

use Drupal\beantragt_group\Service\JurisdictionConfigBlobValidator;
use Drupal\beantragt_group\Service\JurisdictionConfigValidator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates jurisdiction settings regardless of the write transport.
 */
final class JurisdictionSettingsSafetyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly JurisdictionConfigBlobValidator $blobValidator,
    private readonly JurisdictionConfigValidator $valueValidator,
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('beantragt_group.jurisdiction_config_blob_validator'),
      $container->get('beantragt_group.jurisdiction_config_validator'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof GroupInterface || !$constraint instanceof JurisdictionSettingsSafetyConstraint) {
      return;
    }
    if ($value->bundle() === 'org' && $value->hasField('field_retention_days')) {
      $this->validateOrganisationRetention($value, $constraint);
      return;
    }
    if (!$value->hasField('field_jur_config')) {
      return;
    }

    $source = $value->getUntranslated();
    $raw = $source->get('field_jur_config')->value ?? NULL;
    foreach ($this->blobValidator->validateRaw($raw) as $error) {
      $this->context->buildViolation($constraint->configMessage, ['@error' => $error])
        ->atPath('field_jur_config')
        ->addViolation();
    }

    if ($source->hasField('field_custom_css')) {
      $css = (string) ($source->get('field_custom_css')->value ?? '');
      $error = $this->valueValidator->validateCustomCss($css);
      if ($error !== NULL) {
        $this->context->buildViolation($constraint->cssMessage, ['@error' => $error])
          ->atPath('field_custom_css')
          ->addViolation();
      }
    }

    foreach (
      [
        'field_platform_name' => 'platformName',
        'field_email_footer' => 'emailFooter',
        'field_legal_notice' => 'legalNotice',
        'field_privacy_policy' => 'privacyPolicy',
      ] as $fieldName => $apiKey
    ) {
      if (!$source->hasField($fieldName)) {
        continue;
      }
      $fieldValue = (string) ($source->get($fieldName)->value ?? '');
      $error = $this->valueValidator->validateGeneralValue($apiKey, $fieldValue);
      if ($error !== NULL) {
        $this->context->buildViolation($constraint->fieldMessage, [
          '@field' => $fieldName,
          '@error' => $error,
        ])->atPath($fieldName)->addViolation();
      }
    }

    if ($this->retentionChanged($value) && !$this->currentUser->hasPermission('administer beantragt retention policies')) {
      $this->context->buildViolation($constraint->retentionPermissionMessage)
        ->atPath('field_jur_config')
        ->addViolation();
    }
  }

  /**
   * Protects direct organisation writes with the same retention permission.
   */
  private function validateOrganisationRetention(GroupInterface $group, JurisdictionSettingsSafetyConstraint $constraint): void {
    $field = $group->getUntranslated()->get('field_retention_days');
    foreach ($field->validate() as $violation) {
      $this->context->buildViolation($violation->getMessage())
        ->atPath('field_retention_days')
        ->addViolation();
    }
    $original = $this->originalGroup($group);
    $previous = $original?->getUntranslated()->get('field_retention_days')->value ?? NULL;
    if ((string) $field->value !== (string) $previous && !$this->currentUser->hasPermission('administer beantragt retention policies')) {
      $this->context->buildViolation($constraint->retentionPermissionMessage)
        ->atPath('field_retention_days')
        ->addViolation();
    }
  }

  /**
   * Checks whether an existing jurisdiction's retention section changed.
   */
  private function retentionChanged(GroupInterface $group): bool {
    if ($group->isNew()) {
      return $this->retentionSection($group) !== [];
    }

    $original = $this->originalGroup($group);
    if ($original === NULL || !$original->hasField('field_jur_config')) {
      return FALSE;
    }

    return $this->retentionSection($group) !== $this->retentionSection($original);
  }

  /**
   * Loads the stored group before changes, when available.
   */
  private function originalGroup(GroupInterface $group): ?GroupInterface {
    if ($group->isNew()) {
      return NULL;
    }
    $original = $group->getOriginal()
      ?? $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
    return $original instanceof GroupInterface ? $original : NULL;
  }

  /**
   * Extracts a stable retention subsection from one jurisdiction.
   */
  private function retentionSection(GroupInterface $group): array {
    $raw = $group->getUntranslated()->get('field_jur_config')->value ?? '';
    if (!is_string($raw) || $raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    return is_array($decoded['retention'] ?? NULL) ? $decoded['retention'] : [];
  }

}
