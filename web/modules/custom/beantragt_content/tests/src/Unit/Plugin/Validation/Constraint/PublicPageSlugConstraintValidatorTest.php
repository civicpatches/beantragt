<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_content\Unit\Plugin\Validation\Constraint;

use Drupal\beantragt_content\Plugin\Validation\Constraint\PublicPageSlugConstraint;
use Drupal\beantragt_content\Plugin\Validation\Constraint\PublicPageSlugConstraintValidator;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Tests public page slug validation.
 */
#[CoversClass(PublicPageSlugConstraintValidator::class)]
#[Group('beantragt_content')]
final class PublicPageSlugConstraintValidatorTest extends UnitTestCase {

  /**
   * Node storage mock.
   */
  private EntityStorageInterface $nodeStorage;

  /**
   * Group storage mock.
   */
  private EntityStorageInterface $groupStorage;

  /**
   * Tests a unique routeable public slug is accepted.
   */
  public function testUniquePublicSlugIsAccepted(): void {
    $node = $this->pageNode(77, 'datenschutz');
    $this->mockPublishedPages([$node]);

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = $this->validator();
    $validator->initialize($context);
    $validator->validate($node, new PublicPageSlugConstraint());
  }

  /**
   * Tests duplicate public slugs are rejected within one jurisdiction.
   */
  public function testDuplicatePublicSlugIsRejected(): void {
    $constraint = new PublicPageSlugConstraint();
    $node = $this->pageNode(77, 'datenschutz');
    $duplicate = $this->pageNode(78, 'datenschutz');
    $this->mockPublishedPages([$node, $duplicate]);

    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('setParameter')
      ->with('%slug', 'datenschutz')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_public_slug')
      ->willReturnSelf();
    $builder->expects($this->once())->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->duplicateMessage)
      ->willReturn($builder);

    $validator = $this->validator();
    $validator->initialize($context);
    $validator->validate($node, $constraint);
  }

  /**
   * Tests jurisdiction and organisation pages may share slugs.
   */
  public function testDuplicatePublicSlugIsAllowedAcrossOrganisationScopes(): void {
    $node = $this->pageNode(77, 'datenschutz', organisationId: 11);
    $this->mockOrganisation(11, 3);
    $conditions = [];
    $this->mockPublishedPages([$node], $conditions);

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = $this->validator();
    $validator->initialize($context);
    $validator->validate($node, new PublicPageSlugConstraint());

    $this->assertContains(['field_organisation.target_id', 11], $conditions);
  }

  /**
   * Tests duplicate stable page keys are rejected within one scope.
   */
  public function testDuplicateStablePageKeyIsRejectedWithinScope(): void {
    $constraint = new PublicPageSlugConstraint();
    $node = $this->pageNode(77, 'datenschutz', pageKey: 'privacy');
    $duplicate = $this->pageNode(78, 'datenschutz-buergerbuero', pageKey: 'privacy');
    $this->mockPublishedPages([$node, $duplicate]);

    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('setParameter')
      ->with('%key', 'privacy')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_page_key')
      ->willReturnSelf();
    $builder->expects($this->once())->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->duplicateKeyMessage)
      ->willReturn($builder);

    $validator = $this->validator();
    $validator->initialize($context);
    $validator->validate($node, $constraint);
  }

  /**
   * Tests an organisation page must reference an org in its jurisdiction.
   */
  public function testOrganisationMustBelongToPageJurisdiction(): void {
    $constraint = new PublicPageSlugConstraint();
    $node = $this->pageNode(77, 'datenschutz', organisationId: 11);
    $this->mockOrganisation(11, 4);

    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_organisation')
      ->willReturnSelf();
    $builder->expects($this->once())->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->organisationScopeMessage)
      ->willReturn($builder);

    $validator = $this->validator();
    $validator->initialize($context);
    $validator->validate($node, $constraint);
  }

  /**
   * Tests footer-visible published pages require a public slug.
   */
  public function testFooterVisiblePublishedPageRequiresPublicSlug(): void {
    $constraint = new PublicPageSlugConstraint();
    $node = $this->pageNode(77, '');

    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_public_slug')
      ->willReturnSelf();
    $builder->expects($this->once())->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->missingMessage)
      ->willReturn($builder);

    $validator = $this->validator();
    $validator->initialize($context);
    $validator->validate($node, $constraint);
  }

  /**
   * Tests hidden pages may omit the slug.
   */
  public function testHiddenPublishedPageMayOmitPublicSlug(): void {
    $node = $this->pageNode(77, '', footerVisible: FALSE);

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->never())->method('buildViolation');

    $validator = $this->validator();
    $validator->initialize($context);
    $validator->validate($node, new PublicPageSlugConstraint());
  }

  /**
   * Tests invalid route slug shape is rejected.
   */
  public function testInvalidPublicSlugIsRejected(): void {
    $constraint = new PublicPageSlugConstraint();
    $node = $this->pageNode(77, 'bad slug');

    $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
    $builder->expects($this->once())
      ->method('setParameter')
      ->with('%slug', 'bad slug')
      ->willReturnSelf();
    $builder->expects($this->once())
      ->method('atPath')
      ->with('field_public_slug')
      ->willReturnSelf();
    $builder->expects($this->once())->method('addViolation');

    $context = $this->createMock(ExecutionContextInterface::class);
    $context->expects($this->once())
      ->method('buildViolation')
      ->with($constraint->invalidMessage)
      ->willReturn($builder);

    $validator = $this->validator();
    $validator->initialize($context);
    $validator->validate($node, $constraint);
  }

  /**
   * Creates the validator under test.
   */
  private function validator(): PublicPageSlugConstraintValidator {
    if (!isset($this->nodeStorage)) {
      $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    }
    if (!isset($this->groupStorage)) {
      $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    }
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')
      ->willReturnCallback(
        fn (string $entity_type_id): EntityStorageInterface => match ($entity_type_id) {
          'node' => $this->nodeStorage,
          'group' => $this->groupStorage,
          default => throw new \InvalidArgumentException(sprintf('Unexpected storage "%s".', $entity_type_id)),
        },
      );

    return new PublicPageSlugConstraintValidator($entity_type_manager);
  }

  /**
   * Wires published page lookup.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Nodes returned by the query.
   * @param array|null $conditions
   *   Receives the conditions the query was called with, when given.
   */
  private function mockPublishedPages(array $nodes, ?array &$conditions = NULL): void {
    $ids = [];
    $loaded = [];
    foreach ($nodes as $node) {
      $id = (string) $node->id();
      $ids[] = $id;
      $loaded[$id] = $node;
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->with(FALSE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(
      static function (...$args) use (&$conditions, $query): QueryInterface {
        if ($conditions !== NULL && isset($args[0], $args[1]) && is_string($args[0])) {
          $conditions[] = [$args[0], $args[1]];
        }
        return $query;
      }
    );
    $query->method('notExists')->willReturnSelf();
    $query->method('execute')->willReturn($ids);

    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $this->nodeStorage->method('getQuery')->willReturn($query);
    $this->nodeStorage->method('loadMultiple')->with($ids)->willReturn($loaded);
  }

  /**
   * Wires organisation lookup.
   */
  private function mockOrganisation(int $organisation_id, int $jurisdiction_id): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('org');
    $group->method('hasField')
      ->with('field_jurisdiction')
      ->willReturn(TRUE);
    $group->method('get')
      ->with('field_jurisdiction')
      ->willReturn($this->targetField($jurisdiction_id));

    $this->groupStorage = $this->createMock(EntityStorageInterface::class);
    $this->groupStorage->method('load')
      ->with($organisation_id)
      ->willReturn($group);
  }

  /**
   * Builds a page node mock.
   */
  private function pageNode(
    int $id,
    string $slug,
    bool $footerVisible = TRUE,
    bool $published = TRUE,
    ?int $organisationId = NULL,
    string $pageKey = 'content',
  ): NodeInterface {
    $fields = [
      'field_page_key' => $this->fieldValue($pageKey),
      'field_public_slug' => $this->fieldValue($slug),
      'field_footer_visible' => $this->fieldValue($footerVisible ? '1' : '0'),
      'field_jurisdiction' => $this->targetField(3),
    ];
    if ($organisationId !== NULL) {
      $fields['field_organisation'] = $this->targetField($organisationId);
    }

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('bundle')->willReturn('page');
    $node->method('isPublished')->willReturn($published);
    $node->method('language')->willReturn($this->language('de'));
    $node->method('getTranslationLanguages')->willReturn(['de' => $this->language('de')]);
    $node->method('hasTranslation')->with('de')->willReturn(TRUE);
    $node->method('getTranslation')->with('de')->willReturn($node);
    $node->method('hasField')->willReturnCallback(static fn (string $field): bool => isset($fields[$field]));
    $node->method('get')->willReturnCallback(static fn (string $field): FieldItemListInterface => $fields[$field]);

    return $node;
  }

  /**
   * Builds a scalar field.
   */
  private function fieldValue(string $value): FieldItemListInterface {
    $item = new \stdClass();
    $item->value = $value;

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($value === '');
    $field->method('first')->willReturn($item);
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'value' ? $value : NULL,
    );
    return $field;
  }

  /**
   * Builds an entity reference field.
   */
  private function targetField(int $target_id): FieldItemListInterface {
    $item = new \stdClass();
    $item->target_id = $target_id;

    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(FALSE);
    $field->method('first')->willReturn($item);
    $field->method('__get')->willReturnCallback(
      static fn (string $property): mixed => $property === 'target_id' ? $target_id : NULL,
    );
    return $field;
  }

  /**
   * Builds a language mock.
   */
  private function language(string $langcode): LanguageInterface {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    return $language;
  }

}
