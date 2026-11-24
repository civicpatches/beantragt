<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

/**
 * Evaluates workspace invite permissions from a role plus jurisdiction scope.
 */
final class WorkspaceInviteAccess implements WorkspaceInviteAccessInterface {

  public const ROLE_ID = 'workspace_inviter';
  public const SCOPE_FIELD = 'field_scope_jurisdiction';

  public function __construct(
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function canIssue(UserInterface $issuer, GroupInterface $targetParent): bool {
    if (!$issuer->hasRole(self::ROLE_ID)) {
      return FALSE;
    }

    if (!$issuer->hasField(self::SCOPE_FIELD)) {
      return FALSE;
    }

    if ($targetParent->bundle() !== $this->jurisdictionGroupType()) {
      return FALSE;
    }

    $scopeId = $this->issuerScopeId($issuer);
    if ($scopeId === NULL) {
      // An empty scope is not a fail-open global grant. Only a platform
      // administrator may issue invites for every jurisdiction.
      return $issuer->hasPermission('administer beantragt groups');
    }

    $targetParentId = (int) $targetParent->id();
    if ($targetParentId <= 0) {
      return FALSE;
    }

    return in_array($targetParentId, $this->hierarchyResolver->getDescendantIds($scopeId), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function issuerScopeId(UserInterface $issuer): ?int {
    if (!$issuer->hasField(self::SCOPE_FIELD) || $issuer->get(self::SCOPE_FIELD)->isEmpty()) {
      return NULL;
    }

    $value = $issuer->get(self::SCOPE_FIELD)->getValue()[0]['target_id'] ?? NULL;
    $scopeId = is_numeric($value) ? (int) $value : 0;

    return $scopeId > 0 ? $scopeId : NULL;
  }

  /**
   * Returns the configured jurisdiction group bundle.
   */
  private function jurisdictionGroupType(): string {
    $configured = $this->configFactory->get('beantragt_group.settings')->get('jurisdiction_group_type');

    return is_string($configured) && $configured !== '' ? $configured : 'jur';
  }

}
