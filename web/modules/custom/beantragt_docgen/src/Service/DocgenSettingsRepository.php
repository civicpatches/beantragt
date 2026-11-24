<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Service;

use Drupal\beantragt_application\Delivery\DeliveryRoute;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\group\Entity\GroupInterface;

/**
 * Per-form decision route, delivery offer and per-scope sender address.
 */
class DocgenSettingsRepository {

  public const ROUTE_NONE = 'none';
  public const ROUTE_TEMPLATE = 'template';
  public const ROUTE_CASE_SYSTEM = 'case_system';
  public const ROUTES = [self::ROUTE_NONE, self::ROUTE_TEMPLATE, self::ROUTE_CASE_SYSTEM];

  /**
   * Constructs the repository.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Cache tag of everything derived from a form's settings.
   *
   * The computed form_schema field field_delivery_offer and the public form
   * API depend on it.
   */
  public static function cacheTag(string $form_uuid): string {
    return 'beantragt_docgen_form:' . $form_uuid;
  }

  /**
   * Column definitions of the delivery offer (hook_schema and update 10001).
   *
   * @return array<string, array<string, mixed>>
   *   Schema API field definitions.
   */
  public static function deliveryFields(): array {
    return [
      // The column default is empty, not the JSON list "[]": Drupal turns
      // square brackets in DDL into identifier quotes. Both mean not
      // configured; saves always write a JSON list.
      'delivery_routes' => [
        'description' => 'JSON list of offered delivery routes; empty means not configured.',
        'type' => 'varchar_ascii',
        'length' => 64,
        'not null' => TRUE,
        'default' => '',
      ],
      'delivery_default' => [
        'description' => 'Preselected delivery route, one of the offered routes; empty only without routes.',
        'type' => 'varchar_ascii',
        'length' => 16,
        'not null' => TRUE,
        'default' => '',
      ],
    ];
  }

  /**
   * Table definitions for hook_schema().
   *
   * @return array<string, array<string, mixed>>
   *   Schema API definitions.
   */
  public static function schema(): array {
    return [
      'beantragt_docgen_form' => [
        'description' => 'Decision route, allowed decision templates and delivery offer per form (form schema UUID).',
        'fields' => [
          'form_uuid' => ['type' => 'varchar_ascii', 'length' => 36, 'not null' => TRUE],
          'decision_route' => [
            'type' => 'varchar_ascii',
            'length' => 16,
            'not null' => TRUE,
            'default' => self::ROUTE_NONE,
          ],
          'template_families' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
          'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
          'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ] + self::deliveryFields(),
        'primary key' => ['form_uuid'],
      ],
      'beantragt_docgen_scope' => [
        'description' => 'Docgen settings per jurisdiction or organisation group.',
        'fields' => [
          'group_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
          'sender_address' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
          'changed' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        ],
        'primary key' => ['group_id'],
      ],
    ];
  }

  /**
   * Returns the decision route, template families and delivery offer.
   *
   * @return array{route: string, templates: string[], changed: int, delivery: array{routes: string[], default: string}}
   *   Settings; route "none" and no delivery routes when nothing is
   *   configured.
   */
  public function formSettings(string $form_uuid): array {
    $row = $this->database->select('beantragt_docgen_form', 'f')->fields('f')
      ->condition('form_uuid', $form_uuid)->execute()->fetchAssoc();
    if ($row === FALSE) {
      return [
        'route' => self::ROUTE_NONE,
        'templates' => [],
        'changed' => 0,
        'delivery' => ['routes' => [], 'default' => ''],
      ];
    }
    $families = json_decode((string) ($row['template_families'] ?? '[]'), TRUE);
    return [
      'route' => in_array($row['decision_route'], self::ROUTES, TRUE) ? $row['decision_route'] : self::ROUTE_NONE,
      'templates' => is_array($families) ? array_values(array_filter($families, 'is_string')) : [],
      'changed' => (int) $row['changed'],
      'delivery' => self::decodeDelivery($row['delivery_routes'] ?? NULL, $row['delivery_default'] ?? NULL),
    ];
  }

  /**
   * Stores the given settings of a form; NULL leaves a setting unchanged.
   *
   * A new row without a route gets the column default "none".
   *
   * @param string $form_uuid
   *   Form schema UUID.
   * @param string|null $route
   *   One of self::ROUTES, or NULL.
   * @param string[]|null $families
   *   Template family UUIDs, or NULL.
   * @param int $uid
   *   The person saving.
   * @param array{routes: string[], default: string}|null $delivery
   *   Offered delivery routes and the preselected one, or NULL.
   *
   * @throws \InvalidArgumentException
   *   For an unknown decision route, a delivery route outside
   *   DeliveryRoute::ROUTES, duplicate routes or a default outside them.
   */
  public function saveFormSettings(string $form_uuid, ?string $route, ?array $families, int $uid, ?array $delivery = NULL): void {
    if ($route !== NULL && !in_array($route, self::ROUTES, TRUE)) {
      throw new \InvalidArgumentException('Unknown decision route.');
    }
    $fields = [
      'uid' => $uid,
      'changed' => $this->time->getRequestTime(),
    ];
    if ($route !== NULL) {
      $fields['decision_route'] = $route;
    }
    if ($families !== NULL) {
      $fields['template_families'] = json_encode(array_values(array_unique($families)), JSON_THROW_ON_ERROR);
    }
    if ($delivery !== NULL) {
      $routes = self::validDeliveryRoutes($delivery);
      $fields['delivery_routes'] = json_encode($routes, JSON_THROW_ON_ERROR);
      $fields['delivery_default'] = $routes === [] ? '' : $delivery['default'];
    }
    $this->database->merge('beantragt_docgen_form')
      ->key('form_uuid', $form_uuid)
      ->fields($fields)
      ->execute();
    $this->cacheTagsInvalidator->invalidateTags([self::cacheTag($form_uuid)]);
  }

  /**
   * Validates an offer and returns its routes in DeliveryRoute::ROUTES order.
   *
   * @return string[]
   *   The offered routes.
   *
   * @throws \InvalidArgumentException
   *   When the offer is malformed.
   */
  private static function validDeliveryRoutes(array $delivery): array {
    $routes = $delivery['routes'] ?? NULL;
    $default = $delivery['default'] ?? NULL;
    if (!is_array($routes) || !array_is_list($routes) || !is_string($default)) {
      throw new \InvalidArgumentException('Invalid delivery offer.');
    }
    foreach ($routes as $route) {
      if (!is_string($route) || !in_array($route, DeliveryRoute::ROUTES, TRUE)) {
        throw new \InvalidArgumentException('Unknown delivery route.');
      }
    }
    if (count(array_unique($routes)) !== count($routes)) {
      throw new \InvalidArgumentException('Duplicate delivery route.');
    }
    if ($routes === [] ? $default !== '' : !in_array($default, $routes, TRUE)) {
      throw new \InvalidArgumentException('The default delivery route must be one of the offered routes.');
    }
    return array_values(array_intersect(DeliveryRoute::ROUTES, $routes));
  }

  /**
   * Decodes the stored delivery columns, dropping anything unknown.
   *
   * @return array{routes: string[], default: string}
   *   Offered routes in DeliveryRoute::ROUTES order and the default.
   */
  private static function decodeDelivery(mixed $routes_json, mixed $default): array {
    $routes = is_string($routes_json) ? json_decode($routes_json, TRUE) : NULL;
    $routes = is_array($routes) ? array_values(array_intersect(DeliveryRoute::ROUTES, array_filter($routes, 'is_string'))) : [];
    if ($routes === []) {
      return ['routes' => [], 'default' => ''];
    }
    $valid = is_string($default) && in_array($default, $routes, TRUE);
    return ['routes' => $routes, 'default' => $valid ? $default : $routes[0]];
  }

  /**
   * Adds a template family to the allowed list of a form.
   */
  public function allowTemplate(string $form_uuid, string $family, int $uid): void {
    $settings = $this->formSettings($form_uuid);
    $families = $settings['templates'];
    $families[] = $family;
    $route = $settings['route'] === self::ROUTE_NONE ? self::ROUTE_TEMPLATE : $settings['route'];
    $this->saveFormSettings($form_uuid, $route, $families, $uid);
  }

  /**
   * Removes the settings of a deleted form.
   */
  public function deleteForm(string $form_uuid): void {
    $this->database->delete('beantragt_docgen_form')->condition('form_uuid', $form_uuid)->execute();
    $this->cacheTagsInvalidator->invalidateTags([self::cacheTag($form_uuid)]);
  }

  /**
   * Returns the sender address for a scope, falling back to the jurisdiction.
   */
  public function senderAddress(?GroupInterface $group): string {
    if (!$group instanceof GroupInterface) {
      return '';
    }
    $address = $this->ownSenderAddress((int) $group->id());
    if ($address !== '' || $group->bundle() !== 'org' || !$group->hasField('field_jurisdiction')) {
      return $address;
    }
    $parent = $group->get('field_jurisdiction')->entity ?? NULL;
    return $parent instanceof GroupInterface ? $this->ownSenderAddress((int) $parent->id()) : '';
  }

  /**
   * Returns the sender address stored for exactly this group.
   */
  public function ownSenderAddress(int $group_id): string {
    $value = $this->database->select('beantragt_docgen_scope', 's')->fields('s', ['sender_address'])
      ->condition('group_id', $group_id)->execute()->fetchField();
    return is_string($value) ? $value : '';
  }

  /**
   * Stores the sender address of a group (empty string clears it).
   */
  public function setSenderAddress(int $group_id, string $address): void {
    $this->database->merge('beantragt_docgen_scope')
      ->key('group_id', $group_id)
      ->fields(['sender_address' => $address, 'changed' => $this->time->getRequestTime()])
      ->execute();
  }

  /**
   * Removes the settings of a deleted group.
   */
  public function deleteScope(int $group_id): void {
    $this->database->delete('beantragt_docgen_scope')->condition('group_id', $group_id)->execute();
  }

}
