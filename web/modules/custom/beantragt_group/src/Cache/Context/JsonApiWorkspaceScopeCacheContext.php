<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Cache\Context;

use Drupal\beantragt_group\Service\JsonApiGroupQueryAccess;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Varies collection responses by the workspace IDs used in their queries.
 */
final class JsonApiWorkspaceScopeCacheContext implements CacheContextInterface {

  public function __construct(private readonly JsonApiGroupQueryAccess $queryAccess) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel(): TranslatableMarkup {
    return new TranslatableMarkup('JSON:API workspace scope');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext(): string {
    $scopes = $this->queryAccess->staffWorkspaceIds();
    foreach ($scopes as &$ids) {
      sort($ids, SORT_NUMERIC);
    }
    return hash('sha256', serialize($scopes));
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata(): CacheableMetadata {
    return (new CacheableMetadata())->setCacheTags([
      'group_list', 'group_relationship_list', 'config:group_type_list', 'config:group_role_list',
    ]);
  }

}
