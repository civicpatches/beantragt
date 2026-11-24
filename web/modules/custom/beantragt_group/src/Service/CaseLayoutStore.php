<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Stores workspace defaults in existing tenant content, never deploy config.
 *
 * Organisations have no JSON field. Their defaults occupy a distinct group-ID
 * key in their parent jurisdiction's field_jur_config.staffForms.caseLayouts.
 * A jurisdiction workspace occupies its own key on its own entity.
 */
final class CaseLayoutStore {

  /**
   * Constructs the workspace layout store.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly JurisdictionConfigServiceInterface $configService,
  ) {}

  /**
   * Returns a valid stored layout, or NULL when the workspace has no default.
   */
  public function get(GroupInterface $workspace): ?array {
    $owner = $this->owner($workspace);
    $section = $this->configService->getSection($owner, 'staffForms');
    $layouts = $section['caseLayouts'] ?? NULL;
    $layout = is_array($layouts) ? ($layouts[(string) $workspace->id()] ?? NULL) : NULL;
    return CaseLayout::valid($layout) ? $layout : NULL;
  }

  /**
   * Sets or clears one workspace default while preserving all other settings.
   */
  public function set(GroupInterface $workspace, ?array $layout): void {
    if ($layout !== NULL && !CaseLayout::valid($layout)) {
      throw new \InvalidArgumentException('Invalid case layout.');
    }
    $owner = $this->owner($workspace);
    $this->configService->withLockedGroup($owner, function (GroupInterface $fresh) use ($workspace, $layout): void {
      $raw = $fresh->get(JurisdictionConfigService::CONFIG_FIELD)->value;
      if ($raw !== NULL && trim((string) $raw) !== ''
        && (!str_starts_with(ltrim((string) $raw), '{') || !is_array(json_decode((string) $raw, TRUE)))) {
        throw new \InvalidArgumentException('Invalid workspace settings.');
      }
      $section = $this->configService->getSection($fresh, 'staffForms');
      $layouts = $section['caseLayouts'] ?? [];
      if (!is_array($layouts)) {
        throw new \InvalidArgumentException('Invalid workspace layouts.');
      }
      if ($layout === NULL) {
        unset($layouts[(string) $workspace->id()]);
      }
      else {
        $layouts[(string) $workspace->id()] = $layout;
      }
      $section['caseLayouts'] = $layouts;
      $this->configService->updateSection($fresh, 'staffForms', $section);
    });
  }

  /**
   * Returns the entity holding the workspace's tenant settings.
   */
  private function owner(GroupInterface $workspace): GroupInterface {
    if ($workspace->bundle() === 'jur') {
      $id = $workspace->id();
    }
    else {
      $id = $workspace->hasField('field_jurisdiction')
        ? $workspace->get('field_jurisdiction')->target_id : NULL;
    }
    $owner = $id ? $this->entityTypeManager->getStorage('group')->loadUnchanged($id) : NULL;
    if (!$owner instanceof GroupInterface || $owner->bundle() !== 'jur'
      || !$owner->hasField(JurisdictionConfigService::CONFIG_FIELD)) {
      throw new \InvalidArgumentException('Workspace cannot store a case layout.');
    }
    return $owner;
  }

}
