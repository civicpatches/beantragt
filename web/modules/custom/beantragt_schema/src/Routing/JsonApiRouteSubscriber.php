<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\jsonapi\Routing\Routes;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Restricts JSON:API discovery and the list-type JSON:API routes.
 *
 * The entry point needs the site configuration permission. Collection,
 * related, relationship and existing-entity upload routes of every resource
 * type carry the read gate as their first requirement: only staff, the
 * service account and platform administrators reach their controllers. The
 * routes are recognised by the defaults core's JSON:API route builder sets
 * (core/modules/jsonapi/src/Routing/Routes.php), never by name or path, so
 * resource types added later are gated on the next router rebuild.
 *
 * Individual routes, the create route and the upload for a new entity stay
 * as core builds them.
 */
final class JsonApiRouteSubscriber extends RouteSubscriberBase {

  /**
   * Route requirement of the read gate.
   *
   * @see \Drupal\beantragt_schema\Access\JsonApiReadGateAccessCheck
   */
  public const READ_GATE_REQUIREMENT = '_beantragt_jsonapi_read_gate';

  /**
   * Route default core puts on every JSON:API route (Routes.php).
   */
  private const JSONAPI_ROUTE_FLAG = '_is_jsonapi';

  /**
   * Route default core puts on every relationship route, all methods.
   */
  private const RELATIONSHIP_ROUTE_FLAG = '_on_relationship';

  /**
   * Resource types that cannot be listed through JSON:API.
   */
  private const DISABLED_COLLECTION_TYPES = [
    'applicant',
    'application_attachment',
    'application_comment',
    'bulk_batch',
    'bulk_item',
    'bulk_case',
    'docgen_template',
    'file',
    'group_relationship',
    'user',
  ];

  /**
   * Controllers of the gated route kinds other than relationship routes.
   */
  private const GATED_CONTROLLERS = [
    'jsonapi.entity_resource:getCollection',
    'jsonapi.entity_resource:getRelated',
    'jsonapi.file_upload:handleFileUploadForExistingResource',
  ];

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $entry_point = $collection->get('jsonapi.resource_list');
    if ($entry_point) {
      $requirements = $entry_point->getRequirements();
      unset($requirements['_access']);
      $entry_point->setRequirements($requirements);
      $entry_point->setRequirement('_permission', 'administer site configuration');
    }

    foreach ($collection->all() as $route) {
      $resource_type = $route->getDefault(Routes::RESOURCE_TYPE_KEY);
      if (self::isCollectionRoute($route) && is_string($resource_type)
        && in_array(explode('--', $resource_type, 2)[0], self::DISABLED_COLLECTION_TYPES, TRUE)) {
        $route->setRequirement('_access', 'FALSE');
      }
      if (self::isGatedRoute($route)) {
        // First key: access checks are compiled in requirement order and the
        // first refusal's reason is the one printed. setRequirement() would
        // append the key at the end.
        $route->setRequirements([self::READ_GATE_REQUIREMENT => 'TRUE'] + $route->getRequirements());
      }
    }
  }

  /**
   * Identifies the collection controller whose SQL queries are guarded.
   */
  public static function isCollectionRoute(?Route $route): bool {
    return self::isJsonApiRoute($route)
      && $route?->getDefault('_controller') === 'jsonapi.entity_resource:getCollection';
  }

  /**
   * Identifies JSON:API routes independently of their controller.
   */
  public static function isJsonApiRoute(?Route $route): bool {
    return $route?->getDefault(self::JSONAPI_ROUTE_FLAG) === TRUE;
  }

  /**
   * Returns TRUE for the JSON:API route kinds behind the read gate.
   */
  public static function isGatedRoute(Route $route): bool {
    if (!self::isJsonApiRoute($route)) {
      return FALSE;
    }
    if ($route->getDefault(self::RELATIONSHIP_ROUTE_FLAG) === TRUE) {
      return TRUE;
    }
    return in_array($route->getDefault('_controller'), self::GATED_CONTROLLERS, TRUE);
  }

}
