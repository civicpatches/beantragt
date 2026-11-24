<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Plugin\EntityReferenceSelection;

use Drupal\beantragt_schema\Service\FormSchemaWorkspaceScope;
use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Accepts workspace references authorized by the form workspace policy.
 */
#[EntityReferenceSelection(
  id: 'beantragt_form_workspace:group',
  label: new TranslatableMarkup('Form workspace'),
  entity_types: ['group'],
  group: 'beantragt_form_workspace',
  weight: 0,
)]
final class FormWorkspaceSelection extends DefaultSelection {

  /**
   * The form workspace policy.
   */
  protected FormSchemaWorkspaceScope $workspaceScope;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->workspaceScope = $container->get('beantragt_schema.workspace_scope');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids): array {
    $valid = parent::validateReferenceableEntities($ids);
    $entity = $this->configuration['entity'] ?? NULL;
    $missing = array_diff($ids, $valid);
    if (!$missing || !$entity || (!$entity->isNew() && $entity->getEntityTypeId() !== 'form_schema_group')
      || $this->workspaceScope->accountIsExempt($this->currentUser)) {
      return $valid;
    }
    // Group's generic view query may omit an org for a municipality admin
    // who also holds a membership there. The workspace constraint checks the
    // whole assignment, including whether a municipality needs an org.
    $options = $this->workspaceScope->workspaceOptions($this->currentUser);
    $selectable = [];
    foreach ($this->configuration['target_bundles'] as $bundle) {
      $selectable += $bundle === 'jur' ? $options['jurisdictions'] : $options['organisations'];
    }
    return array_merge($valid, array_intersect($missing, array_keys($selectable)));
  }

}
