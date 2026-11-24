<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Unit;

use Drupal\beantragt_schema\Routing\JsonApiRouteSubscriber;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests the JSON:API route subscriber.
 *
 * Every route below mirrors the defaults and requirements core's JSON:API
 * route builder gives it (core/modules/jsonapi/src/Routing/Routes.php).
 */
#[CoversClass(JsonApiRouteSubscriber::class)]
#[Group('beantragt_schema')]
final class JsonApiRouteSubscriberTest extends UnitTestCase {

  private const GATE = JsonApiRouteSubscriber::READ_GATE_REQUIREMENT;

  /**
   * Route kinds of contract table 2.1 with core's defaults and requirements.
   *
   * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, string>, 3: bool}>
   *   Route name, defaults, requirements, whether the gate applies.
   */
  public static function routeKinds(): array {
    $type = 'application--application';
    $base = ['_is_jsonapi' => TRUE, 'resource_type' => $type];
    $api_json = ['_content_type_format' => 'api_json', '_format' => 'api_json'];
    $controllers = [
      'get' => 'getRelationship',
      'post' => 'addToRelationshipData',
      'patch' => 'replaceRelationshipData',
      'delete' => 'removeFromRelationshipData',
    ];
    $relationship = static fn (string $method, string $operation): array => [
      "jsonapi.$type.uid.relationship.$method",
      $base + [
        '_on_relationship' => TRUE,
        'related' => 'uid',
        '_controller' => 'jsonapi.entity_resource:' . $controllers[$method],
      ],
      ['_csrf_request_header_token' => 'TRUE', '_jsonapi_relationship_route_access' => "uid.$operation"] + $api_json,
      TRUE,
    ];
    return [
      'collection GET' => [
        "jsonapi.$type.collection",
        $base + ['_controller' => 'jsonapi.entity_resource:getCollection'],
        ['_access' => 'TRUE'] + $api_json,
        TRUE,
      ],
      'related GET' => [
        "jsonapi.$type.uid.related",
        $base + ['_controller' => 'jsonapi.entity_resource:getRelated', 'related' => 'uid'],
        ['_jsonapi_relationship_route_access' => 'uid.view'] + $api_json,
        TRUE,
      ],
      'relationship GET' => $relationship('get', 'view'),
      'relationship POST' => $relationship('post', 'edit'),
      'relationship PATCH' => $relationship('patch', 'edit'),
      'relationship DELETE' => $relationship('delete', 'edit'),
      'file upload, existing entity' => [
        "jsonapi.$type.file_upload.existing_resource",
        $base + ['_controller' => 'jsonapi.file_upload:handleFileUploadForExistingResource'],
        ['_csrf_request_header_token' => 'TRUE', '_content_type_format' => 'bin', '_format' => 'api_json'],
        TRUE,
      ],
      'file upload, new entity' => [
        "jsonapi.$type.file_upload.new_resource",
        $base + ['_controller' => 'jsonapi.file_upload:handleFileUploadForNewResource'],
        ['_csrf_request_header_token' => 'TRUE', '_content_type_format' => 'bin', '_format' => 'api_json'],
        FALSE,
      ],
      'collection POST' => [
        "jsonapi.$type.collection.post",
        $base + ['_controller' => 'jsonapi.entity_resource:createIndividual'],
        ['_entity_create_access' => 'application:application', '_csrf_request_header_token' => 'TRUE'] + $api_json,
        FALSE,
      ],
      'individual GET' => [
        "jsonapi.$type.individual",
        $base + ['_controller' => 'jsonapi.entity_resource:getIndividual'],
        ['_access' => 'TRUE'] + $api_json,
        FALSE,
      ],
      'individual PATCH' => [
        "jsonapi.$type.individual.patch",
        $base + ['_controller' => 'jsonapi.entity_resource:patchIndividual'],
        ['_entity_access' => 'entity.update', '_csrf_request_header_token' => 'TRUE'] + $api_json,
        FALSE,
      ],
      'individual DELETE' => [
        "jsonapi.$type.individual.delete",
        $base + ['_controller' => 'jsonapi.entity_resource:deleteIndividual'],
        ['_entity_access' => 'entity.delete', '_csrf_request_header_token' => 'TRUE'] + $api_json,
        FALSE,
      ],
      'collection controller without the JSON:API flag' => [
        'example.collection',
        ['_controller' => 'jsonapi.entity_resource:getCollection'],
        ['_access' => 'TRUE'],
        FALSE,
      ],
      'relationship default without the JSON:API flag' => [
        'example.relationship',
        ['_on_relationship' => TRUE, '_controller' => 'example:relationship'],
        ['_access' => 'TRUE'],
        FALSE,
      ],
    ];
  }

  /**
   * Gated kinds get the gate first and keep the rest; others are untouched.
   *
   * @param string $name
   *   Route name.
   * @param array<string, mixed> $defaults
   *   Route defaults.
   * @param array<string, string> $requirements
   *   Route requirements.
   * @param bool $gated
   *   Whether the gate applies.
   */
  #[DataProvider('routeKinds')]
  public function testRouteKind(string $name, array $defaults, array $requirements, bool $gated): void {
    $route = new Route('/jsonapi/example', $defaults, $requirements);
    $collection = new RouteCollection();
    $collection->add($name, $route);

    $this->alter($collection);

    if (!$gated) {
      $this->assertSame($requirements, $route->getRequirements());
      $this->assertFalse(JsonApiRouteSubscriber::isGatedRoute($route));
      return;
    }
    $this->assertTrue(JsonApiRouteSubscriber::isGatedRoute($route));
    $this->assertSame([self::GATE => 'TRUE'] + $requirements, $route->getRequirements());
    $this->assertSame(self::GATE, array_key_first($route->getRequirements()));

    // A second rebuild changes nothing.
    $this->alter($collection);
    $this->assertSame([self::GATE => 'TRUE'] + $requirements, $route->getRequirements());
  }

  /**
   * User, file and membership reads require an individual resource.
   */
  public function testRestrictedCollectionsKeepIndividualRoutes(): void {
    $collection = new RouteCollection();
    $types = [
      'file--file',
      'user--user',
      'group_relationship--org-group_membership',
      'group_relationship--jur-group_membership',
    ];
    foreach ($types as $type) {
      $defaults = ['_is_jsonapi' => TRUE, 'resource_type' => $type];
      $collection->add("$type.collection", new Route("/jsonapi/$type", $defaults + [
        '_controller' => 'jsonapi.entity_resource:getCollection',
      ], ['_access' => 'TRUE']));
      $collection->add("$type.individual", new Route("/jsonapi/$type/{entity}", $defaults + [
        '_controller' => 'jsonapi.entity_resource:getIndividual',
      ], ['_access' => 'TRUE']));
    }
    $this->alter($collection);
    foreach ($collection as $name => $route) {
      $this->assertSame(str_ends_with($name, '.collection')
        ? [self::GATE => 'TRUE', '_access' => 'FALSE']
        : ['_access' => 'TRUE'], $route->getRequirements(), $name);
    }
  }

  /**
   * The entry point needs the permission and does not carry the gate.
   */
  public function testResourceListRouteRequiresAdminPermission(): void {
    $collection = new RouteCollection();
    $entry_point = new Route('/jsonapi', [
      '_is_jsonapi' => TRUE,
      '_controller' => '\Drupal\jsonapi\Controller\EntryPoint::index',
    ], ['_access' => 'TRUE']);
    $collection->add('jsonapi.resource_list', $entry_point);

    $this->alter($collection);

    $this->assertSame(['_permission' => 'administer site configuration'], $entry_point->getRequirements());
  }

  /**
   * Without the entry point, routes outside JSON:API stay untouched.
   */
  public function testMissingResourceListRouteIsIgnored(): void {
    $collection = new RouteCollection();
    $collection->add('example.route', new Route('/example', [], ['_access' => 'TRUE']));

    $this->alter($collection);

    $this->assertSame(['_access' => 'TRUE'], $collection->get('example.route')?->getRequirements());
  }

  /**
   * Runs the subscriber on a collection.
   */
  private function alter(RouteCollection $collection): void {
    (new JsonApiRouteSubscriber())->onAlterRoutes(new RouteBuildEvent($collection));
  }

}
