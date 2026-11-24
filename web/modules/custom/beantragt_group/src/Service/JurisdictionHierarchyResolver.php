<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves jurisdiction hierarchy relationships.
 *
 * Ported from markaspot_group's hardened hierarchy resolver and adjusted to
 * Beantragt config names. Traversals validate the configured jurisdiction
 * group type and fail closed on cycles or excessive hierarchy depth.
 */
class JurisdictionHierarchyResolver implements JurisdictionHierarchyResolverInterface {

  /**
   * The generic parent-tree resolver.
   *
   * @var \Drupal\beantragt_group\Service\ParentTreeResolver|null
   */
  protected ?ParentTreeResolver $treeResolver = NULL;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a JurisdictionHierarchyResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
    protected ConfigFactoryInterface $configFactory,
  ) {
    $this->logger = $loggerFactory->get('beantragt_group');
  }

  /**
   * {@inheritdoc}
   */
  public function getRootJurisdictionId(int $groupId): ?int {
    return $this->getTreeResolver()->getRootId(
      $groupId,
      'resolve root jurisdiction',
      'resolve parent jurisdiction',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isChildJurisdiction(int $groupId): bool {
    return $this->getTreeResolver()->hasParent(
      $groupId,
      'check child jurisdiction',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getAllRootJurisdictionIds(): array {
    return $this->getTreeResolver()
      ->getAllRootIds($this->getJurisdictionGroupType());
  }

  /**
   * {@inheritdoc}
   */
  public function getDescendantIds(int $groupId): array {
    return $this->getTreeResolver()->getDescendantIds(
      $groupId,
      'load descendant jurisdictions',
      'load child jurisdiction',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getTermJurisdictionIds(int $groupId): array {
    $rootId = $this->getRootJurisdictionId($groupId);
    if ($rootId === NULL) {
      return [];
    }
    return $this->getDescendantIds($rootId);
  }

  /**
   * {@inheritdoc}
   */
  public function isSameOrDescendantById(int $candidateId, int $scopeId): bool {
    if ($candidateId <= 0 || $scopeId <= 0) {
      return FALSE;
    }

    if ($candidateId === $scopeId) {
      return TRUE;
    }

    return in_array($candidateId, $this->getDescendantIds($scopeId), TRUE);
  }

  /**
   * Gets the generic parent-tree resolver for the jurisdiction axis.
   *
   * @return \Drupal\beantragt_group\Service\ParentTreeResolver
   *   The configured parent-tree resolver.
   */
  protected function getTreeResolver(): ParentTreeResolver {
    if ($this->treeResolver === NULL) {
      $this->treeResolver = new ParentTreeResolver(
        $this->entityTypeManager,
        $this->database,
        $this->logger,
        'field_parent_jurisdiction',
        fn(mixed $group): bool => $this->isJurisdictionGroup($group),
        'jurisdiction',
        ParentTreeResolver::ROOT_FAIL_CLOSED,
        FALSE,
      );
    }

    return $this->treeResolver;
  }

  /**
   * Logs when hierarchy code receives a non-jurisdiction group.
   *
   * @param string $operation
   *   The operation being attempted.
   * @param int $groupId
   *   The group ID.
   * @param string $bundle
   *   The actual group bundle.
   */
  protected function logWrongBundle(string $operation, int $groupId, string $bundle): void {
    $this->logger->warning(
      'Expected jurisdiction group while trying to @operation, got @bundle group @id.',
      [
        '@operation' => $operation,
        '@bundle' => $bundle,
        '@id' => $groupId,
      ]
    );
  }

  /**
   * Gets the configured jurisdiction group type.
   *
   * @return string
   *   The configured jurisdiction group type machine name.
   */
  protected function getJurisdictionGroupType(): string {
    return $this->configFactory
      ->get('beantragt_group.settings')
      ->get('jurisdiction_group_type') ?: 'jur';
  }

  /**
   * Checks whether a group uses the configured jurisdiction type.
   *
   * @param mixed $group
   *   The candidate group entity.
   *
   * @return bool
   *   TRUE when the group bundle is the configured jurisdiction type.
   */
  protected function isJurisdictionGroup(mixed $group): bool {
    return is_object($group)
      && method_exists($group, 'bundle')
      && $group->bundle() === $this->getJurisdictionGroupType();
  }

}
