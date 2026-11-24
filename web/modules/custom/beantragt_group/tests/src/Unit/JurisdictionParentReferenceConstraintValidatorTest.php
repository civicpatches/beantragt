<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\beantragt_group\Plugin\Validation\Constraint\JurisdictionParentReferenceConstraint;
use Drupal\beantragt_group\Plugin\Validation\Constraint\JurisdictionParentReferenceConstraintValidator;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests jurisdiction parent reference validation.
 *
 * @group beantragt_group
 * @coversDefaultClass \Drupal\beantragt_group\Plugin\Validation\Constraint\JurisdictionParentReferenceConstraintValidator
 */
final class JurisdictionParentReferenceConstraintValidatorTest extends UnitTestCase {

  /**
   * Tests a jurisdiction cannot reference itself as parent.
   *
   * @covers ::validate
   */
  public function testSelfParentReferenceIsRejected(): void {
    $constraint = new JurisdictionParentReferenceConstraint();
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_parent_jurisdiction')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->selfReferenceMessage)
      ->willReturn($builder);

    $jurisdiction = $this->jurisdiction(7);
    $this->setParent($jurisdiction, $jurisdiction);

    $validator = new JurisdictionParentReferenceConstraintValidator($this->mockConfigFactory());
    $validator->initialize($context);
    $validator->validate($jurisdiction, $constraint);
  }

  /**
   * Tests a different parent jurisdiction is accepted.
   *
   * @covers ::validate
   */
  public function testDifferentParentReferenceIsAccepted(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $jurisdiction = $this->jurisdiction(7);
    $this->setParent($jurisdiction, $this->jurisdiction(1));

    $validator = new JurisdictionParentReferenceConstraintValidator($this->mockConfigFactory());
    $validator->initialize($context);
    $validator->validate($jurisdiction, new JurisdictionParentReferenceConstraint());
  }

  /**
   * The parent bundle is validated even before a jurisdiction has an ID.
   *
   * @covers ::validate
   */
  #[DataProvider('jurisdictionIdProvider')]
  public function testNonJurisdictionParentIsRejected(int $jurisdiction_id): void {
    $constraint = new JurisdictionParentReferenceConstraint();
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_parent_jurisdiction')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->parentTypeMessage)
      ->willReturn($builder);

    $parent = $this->createMock(GroupInterface::class);
    $parent->method('bundle')->willReturn('org');
    $parent->method('id')->willReturn('9');

    $jurisdiction = $this->jurisdiction($jurisdiction_id);
    $this->setParent($jurisdiction, $parent);

    $validator = new JurisdictionParentReferenceConstraintValidator($this->mockConfigFactory());
    $validator->initialize($context);
    $validator->validate($jurisdiction, $constraint);
  }

  /**
   * Supplies the jurisdiction id for each case.
   *
   * @return array<string, array{int}>
   *   Named test cases.
   */
  public static function jurisdictionIdProvider(): array {
    return [
      'existing jurisdiction' => [7],
      'new jurisdiction' => [0],
    ];
  }

  /**
   * Tests indirect circular parent references are rejected.
   *
   * @covers ::validate
   */
  public function testCircularParentReferenceIsRejected(): void {
    $constraint = new JurisdictionParentReferenceConstraint();
    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_parent_jurisdiction')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->circularReferenceMessage)
      ->willReturn($builder);

    $jurisdiction = $this->jurisdiction(7);
    $parent = $this->jurisdiction(8);
    $this->setParent($jurisdiction, $parent);
    $this->setParent($parent, $jurisdiction);

    $validator = new JurisdictionParentReferenceConstraintValidator($this->mockConfigFactory());
    $validator->initialize($context);
    $validator->validate($jurisdiction, $constraint);
  }

  /**
   * Tests non-jurisdiction groups are ignored.
   *
   * @covers ::validate
   */
  public function testNonJurisdictionGroupIsIgnored(): void {
    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');

    $validator = new JurisdictionParentReferenceConstraintValidator($this->mockConfigFactory());
    $validator->initialize($context);
    $validator->validate($group, new JurisdictionParentReferenceConstraint());
  }

  /**
   * Creates a jurisdiction group.
   */
  private function jurisdiction(int $id): GroupInterface {
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('id')->willReturn((string) $id);
    $jurisdiction->method('bundle')->willReturn('jur');
    return $jurisdiction;
  }

  /**
   * Builds a config factory mock returning the default jurisdiction bundle.
   */
  private function mockConfigFactory(): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->with('jurisdiction_group_type')
      ->willReturn('jur');

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('beantragt_group.settings')
      ->willReturn($config);

    return $factory;
  }

  /**
   * Sets a parent field mock on a jurisdiction group.
   */
  private function setParent(GroupInterface $jurisdiction, GroupInterface $parent): void {
    $field = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('referencedEntities')->willReturn([$parent]);

    $jurisdiction->method('hasField')
      ->with('field_parent_jurisdiction')
      ->willReturn(TRUE);
    $jurisdiction->method('get')
      ->with('field_parent_jurisdiction')
      ->willReturn($field);
  }

}
