<?php

declare(strict_types=1);

namespace Drupal\beantragt_content\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates routeable public page slugs.
 */
final class PublicPageSlugConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Public page route slug shape.
   */
  private const PUBLIC_SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,126}[a-z0-9])?$/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof PublicPageSlugConstraint) {
      return;
    }
    if (!$value instanceof NodeInterface || $value->bundle() !== 'page') {
      return;
    }
    if (!$value->hasField('field_public_slug')) {
      return;
    }

    $jurisdiction_id = $this->targetId($value, 'field_jurisdiction');
    if ($jurisdiction_id === NULL) {
      return;
    }
    $organisation_id = $this->targetId($value, 'field_organisation');
    if ($organisation_id !== NULL && !$this->organisationBelongsToJurisdiction($organisation_id, $jurisdiction_id)) {
      $this->context->buildViolation($constraint->organisationScopeMessage)
        ->atPath('field_organisation')
        ->addViolation();
      return;
    }

    $page_key = $this->pageKey($value);
    if ($page_key !== NULL && $page_key !== 'content' && $this->hasDuplicatePageKey($value, $jurisdiction_id, $organisation_id, $page_key)) {
      $this->context->buildViolation($constraint->duplicateKeyMessage)
        ->setParameter('%key', $page_key)
        ->atPath('field_page_key')
        ->addViolation();
    }

    foreach ($this->publishedTranslations($value) as $translation) {
      $slug = $this->publicSlug($translation);
      if ($slug === NULL) {
        if ($this->footerVisible($translation)) {
          $this->context->buildViolation($constraint->missingMessage)
            ->atPath('field_public_slug')
            ->addViolation();
        }
        continue;
      }

      if (preg_match(self::PUBLIC_SLUG_PATTERN, $slug) !== 1) {
        $this->context->buildViolation($constraint->invalidMessage)
          ->setParameter('%slug', $slug)
          ->atPath('field_public_slug')
          ->addViolation();
        continue;
      }

      if ($this->hasDuplicateSlug($value, $jurisdiction_id, $organisation_id, $slug)) {
        $this->context->buildViolation($constraint->duplicateMessage)
          ->setParameter('%slug', $slug)
          ->atPath('field_public_slug')
          ->addViolation();
      }
    }
  }

  /**
   * Returns all published translations for a page, including source.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Published translations.
   */
  private function publishedTranslations(NodeInterface $node): array {
    $translations = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      if (!$node->hasTranslation($langcode)) {
        continue;
      }
      $translation = $node->getTranslation($langcode);
      if ($translation->isPublished()) {
        $translations[$langcode] = $translation;
      }
    }

    if ($translations === [] && $node->isPublished()) {
      $translations[$node->language()->getId()] = $node;
    }

    return array_values($translations);
  }

  /**
   * Checks whether another published page in the jurisdiction owns the slug.
   */
  private function hasDuplicateSlug(NodeInterface $node, int $jurisdiction_id, ?int $organisation_id, string $slug): bool {
    $storage = $this->nodeStorage();
    $query = $this->publishedPageScopeQuery($jurisdiction_id, $organisation_id);

    $ids = $query->execute();
    if (!$ids) {
      return FALSE;
    }

    /** @var array<int|string, \Drupal\node\NodeInterface> $loaded */
    $loaded = $storage->loadMultiple($ids);
    $own_id = (string) $node->id();
    foreach ($loaded as $candidate) {
      if ((string) $candidate->id() === $own_id) {
        continue;
      }
      foreach ($this->publishedTranslations($candidate) as $translation) {
        if ($this->publicSlug($translation) === $slug) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Checks whether another published page in the scope owns the stable key.
   */
  private function hasDuplicatePageKey(NodeInterface $node, int $jurisdiction_id, ?int $organisation_id, string $page_key): bool {
    $query = $this->publishedPageScopeQuery($jurisdiction_id, $organisation_id)
      ->condition('field_page_key', $page_key);

    $ids = $query->execute();
    if (!$ids) {
      return FALSE;
    }

    $own_id = (string) $node->id();
    foreach ($ids as $id) {
      if ((string) $id !== $own_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Builds a published page query scoped to jurisdiction and optional org.
   */
  private function publishedPageScopeQuery(int $jurisdiction_id, ?int $organisation_id): QueryInterface {
    $query = $this->nodeStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
      ->condition('status', 1)
      ->condition('field_jurisdiction.target_id', $jurisdiction_id);
    if ($organisation_id === NULL) {
      $query->notExists('field_organisation');
    }
    else {
      $query->condition('field_organisation.target_id', $organisation_id);
    }

    return $query;
  }

  /**
   * Checks that an organisation belongs to the selected jurisdiction.
   */
  private function organisationBelongsToJurisdiction(int $organisation_id, int $jurisdiction_id): bool {
    $organisation = $this->entityTypeManager->getStorage('group')->load($organisation_id);
    if (!$organisation instanceof GroupInterface) {
      return FALSE;
    }
    if ($organisation->bundle() !== 'org') {
      return FALSE;
    }

    return $this->targetIdFromEntity($organisation, 'field_jurisdiction') === $jurisdiction_id;
  }

  /**
   * Gets node storage.
   */
  private function nodeStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('node');
  }

  /**
   * Reads and normalizes field_public_slug.
   */
  private function publicSlug(NodeInterface $node): ?string {
    $value = $this->scalarValue($node, 'field_public_slug');
    $slug = strtolower(trim($value ?? ''));
    return $slug !== '' ? $slug : NULL;
  }

  /**
   * Reads and normalizes the stable page key.
   */
  private function pageKey(NodeInterface $node): ?string {
    $value = $this->scalarValue($node, 'field_page_key');
    $key = strtolower(trim($value ?? ''));
    return $key !== '' ? $key : NULL;
  }

  /**
   * Returns whether a page is marked for footer navigation.
   */
  private function footerVisible(NodeInterface $node): bool {
    $value = $this->scalarValue($node, 'field_footer_visible');
    return $value === NULL || $value === '' || !in_array(strtolower(trim($value)), ['0', 'false', 'no'], TRUE);
  }

  /**
   * Reads an entity-reference target id.
   */
  private function targetId(NodeInterface $node, string $field_name): ?int {
    return $this->targetIdFromEntity($node, $field_name);
  }

  /**
   * Reads an entity-reference target id from a fieldable entity.
   */
  private function targetIdFromEntity(object $entity, string $field_name): ?int {
    if (!method_exists($entity, 'hasField') || !method_exists($entity, 'get') || !$entity->hasField($field_name)) {
      return NULL;
    }
    $field = $entity->get($field_name);
    if (!is_object($field) || !method_exists($field, 'isEmpty') || $field->isEmpty()) {
      return NULL;
    }

    $item = method_exists($field, 'first') ? $field->first() : NULL;
    $value = is_object($item) && isset($item->target_id)
      ? $item->target_id
      : ($field->target_id ?? NULL);
    if ($value === NULL || $value === '') {
      return NULL;
    }

    return (int) $value;
  }

  /**
   * Reads a scalar field value.
   */
  private function scalarValue(NodeInterface $node, string $field_name): ?string {
    if (!$node->hasField($field_name)) {
      return NULL;
    }
    $field = $node->get($field_name);
    if ($field->isEmpty()) {
      return NULL;
    }

    $item = $field->first();
    $value = is_object($item) && isset($item->value)
      ? $item->value
      : ($field->value ?? NULL);

    return $value === NULL ? NULL : (string) $value;
  }

}
