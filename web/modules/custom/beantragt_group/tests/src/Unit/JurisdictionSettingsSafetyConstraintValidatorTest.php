<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\beantragt_group\Plugin\Validation\Constraint\JurisdictionSettingsSafetyConstraint;
use Drupal\beantragt_group\Plugin\Validation\Constraint\JurisdictionSettingsSafetyConstraintValidator;
use Drupal\beantragt_group\Service\JurisdictionConfigBlobValidator;
use Drupal\beantragt_group\Service\JurisdictionConfigValidator;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests the native jurisdiction settings entity validation boundary.
 */
#[CoversClass(JurisdictionSettingsSafetyConstraintValidator::class)]
#[Group('beantragt_group')]
final class JurisdictionSettingsSafetyConstraintValidatorTest extends UnitTestCase {

  /**
   * Unknown JSON sections cannot enter through the native entity form.
   */
  public function testInvalidConfigurationIsRejected(): void {
    $constraint = new JurisdictionSettingsSafetyConstraint();
    $context = $this->violationContext(
      $constraint->configMessage,
      ['@error' => 'Unknown section: unsupported.'],
      'field_jur_config',
    );

    $validator = $this->validator($this->account(FALSE));
    $validator->initialize($context);
    $validator->validate(
      $this->group(['field_jur_config' => '{"unsupported":{}}']),
      $constraint,
    );
  }

  /**
   * Unsafe custom CSS cannot bypass the Settings API checks.
   */
  public function testUnsafeCustomCssIsRejected(): void {
    $constraint = new JurisdictionSettingsSafetyConstraint();
    $context = $this->violationContext(
      $constraint->cssMessage,
      ['@error' => 'CSS must not contain @import directives.'],
      'field_custom_css',
    );

    $validator = $this->validator($this->account(FALSE));
    $validator->initialize($context);
    $validator->validate(
      $this->group([
        'field_jur_config' => '{}',
        'field_custom_css' => '@import "https://example.org/theme.css";',
      ]),
      $constraint,
    );
  }

  /**
   * Retention changes still require the dedicated restricted permission.
   */
  public function testRetentionChangeRequiresPermission(): void {
    $constraint = new JurisdictionSettingsSafetyConstraint();
    $context = $this->violationContext(
      $constraint->retentionPermissionMessage,
      [],
      'field_jur_config',
    );
    $original = $this->group([
      'field_jur_config' => '{"retention":{"dataRetentionDays":30}}',
    ]);
    $group = $this->group(
      ['field_jur_config' => '{"retention":{"dataRetentionDays":365}}'],
      FALSE,
      $original,
    );

    $validator = $this->validator($this->account(FALSE));
    $validator->initialize($context);
    $validator->validate($group, $constraint);
  }

  /**
   * Explicit retention on a new jurisdiction requires the restricted right.
   */
  public function testNewJurisdictionRetentionRequiresPermission(): void {
    $constraint = new JurisdictionSettingsSafetyConstraint();
    $context = $this->violationContext(
      $constraint->retentionPermissionMessage,
      [],
      'field_jur_config',
    );

    $validator = $this->validator($this->account(FALSE));
    $validator->initialize($context);
    $validator->validate(
      $this->group([
        'field_jur_config' => '{"retention":{"dataRetentionDays":30}}',
      ]),
      $constraint,
    );
  }

  /**
   * A valid applicant profile switch passes the native entity boundary.
   */
  public function testApplicantProfileSectionIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects(self::never())->method('buildViolation');

    $validator = $this->validator($this->account(FALSE));
    $validator->initialize($context);
    $validator->validate(
      $this->group([
        'field_jur_config' => '{"applicant_profile":{"enabled":true,"scope":"jurisdiction"}}',
      ]),
      new JurisdictionSettingsSafetyConstraint(),
    );
  }

  /**
   * An invalid applicant profile scope cannot bypass the Settings API.
   */
  public function testInvalidApplicantProfileScopeIsRejected(): void {
    $constraint = new JurisdictionSettingsSafetyConstraint();
    $context = $this->violationContext(
      $constraint->configMessage,
      ['@error' => 'applicant_profile.scope: scope must be one of: organisation, jurisdiction.'],
      'field_jur_config',
    );

    $validator = $this->validator($this->account(FALSE));
    $validator->initialize($context);
    $validator->validate(
      $this->group([
        'field_jur_config' => '{"applicant_profile":{"enabled":true,"scope":"everyone"}}',
      ]),
      $constraint,
    );
  }

  /**
   * Builds the validator with real value validation and mocked infrastructure.
   */
  private function validator(AccountInterface $account): JurisdictionSettingsSafetyConstraintValidator {
    $value_validator = new JurisdictionConfigValidator(new EmailValidator());

    return new JurisdictionSettingsSafetyConstraintValidator(
      new JurisdictionConfigBlobValidator($value_validator),
      $value_validator,
      $account,
      $this->createMock(EntityTypeManagerInterface::class),
    );
  }

  /**
   * Creates a jurisdiction carrying the requested field values.
   *
   * @param array<string, string> $values
   *   Field values keyed by machine name.
   * @param bool $is_new
   *   Whether the mocked jurisdiction is new.
   * @param \Drupal\group\Entity\GroupInterface|null $original
   *   Original jurisdiction for change comparisons.
   */
  private function group(array $values, bool $is_new = TRUE, ?GroupInterface $original = NULL): GroupInterface {
    $fields = [];
    foreach ($values as $field_name => $value) {
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('__get')->willReturnCallback(
        static fn (string $property): mixed => $property === 'value' ? $value : NULL,
      );
      $item->method('__isset')->willReturnCallback(
        static fn (string $property): bool => $property === 'value',
      );
      $field = $this->getMockBuilder(FieldItemList::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['first'])
        ->getMock();
      $field->method('first')->willReturn($item);
      $fields[$field_name] = $field;
    }

    $group = $this->createMock(GroupInterface::class);
    $group->method('getUntranslated')->willReturnSelf();
    $group->method('hasField')->willReturnCallback(
      static fn (string $field_name): bool => array_key_exists($field_name, $fields),
    );
    $group->method('get')->willReturnCallback(
      static fn (string $field_name): FieldItemList => $fields[$field_name],
    );
    $group->method('isNew')->willReturn($is_new);
    $group->method('getOriginal')->willReturn($original);

    return $group;
  }

  /**
   * Creates an account with or without retention administration permission.
   */
  private function account(bool $can_manage_retention): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')
      ->with('administer beantragt retention policies')
      ->willReturn($can_manage_retention);

    return $account;
  }

  /**
   * Expects one violation at the requested entity field path.
   *
   * @param string $message
   *   Expected violation message.
   * @param array<string, string> $parameters
   *   Expected translation parameters.
   * @param string $path
   *   Expected field path.
   */
  private function violationContext(string $message, array $parameters, string $path): ExecutionContextInterface {
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects(self::once())->method('atPath')->with($path)->willReturnSelf();
    $builder->expects(self::once())->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects(self::once())
      ->method('buildViolation')
      ->with($message, $parameters)
      ->willReturn($builder);

    return $context;
  }

}
