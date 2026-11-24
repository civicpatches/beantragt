<?php

declare(strict_types=1);

namespace Drupal\beantragt_application\Delivery;

/**
 * Delivery route of an application: values, record limits and the choice.
 *
 * The route says how the authority answers: in the citizen portal with an
 * email notice, by post, or into the BundID postbox. The form offer comes
 * from beantragt_docgen as the computed form_schema field
 * field_delivery_offer; this class only reads its JSON, so the public
 * submission and the bulk intake need no docgen or delivery class.
 */
final class DeliveryRoute {

  public const PORTAL = 'portal';
  public const POSTAL = 'postal';
  public const BUNDID = 'bundid';

  /**
   * Every route, in the order forms and the UI list them.
   */
  public const ROUTES = [self::PORTAL, self::POSTAL, self::BUNDID];

  /**
   * Where a route_chosen or route_changed event came from.
   */
  public const SOURCES = ['public_submit', 'manifest', 'form_default', 'staff'];

  /**
   * How a route change request reached the authority.
   */
  public const CHANNELS = ['letter', 'phone', 'email', 'in_person', 'other'];

  /**
   * Event cap of field_delivery_record for changes away from post.
   *
   * A change toward postal is never refused, so a record can end with one
   * event more (RECORD_LIMIT + 1): after it only changes away from post
   * remain, and those stop at the cap.
   */
  public const RECORD_LIMIT = 50;

  /**
   * Decodes the computed form offer.
   *
   * @param string|null $json
   *   Value of form_schema.field_delivery_offer.
   *
   * @return array{configured: bool, routes?: string[], default?: string}|null
   *   {"configured": false}, or the offer with at least one known route and
   *   a default among them; NULL for anything else.
   */
  public static function decodeOffer(?string $json): ?array {
    if ($json === NULL || $json === '') {
      return NULL;
    }
    try {
      $offer = json_decode($json, TRUE, 8, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    if (!is_array($offer) || !is_bool($offer['configured'] ?? NULL)) {
      return NULL;
    }
    if ($offer['configured'] === FALSE) {
      return ['configured' => FALSE];
    }
    $routes = $offer['routes'] ?? NULL;
    $default = $offer['default'] ?? NULL;
    if (!is_array($routes) || $routes === [] || !array_is_list($routes) || !is_string($default)) {
      return NULL;
    }
    foreach ($routes as $route) {
      if (!is_string($route) || !in_array($route, self::ROUTES, TRUE)) {
        return NULL;
      }
    }
    if (count(array_unique($routes)) !== count($routes) || !in_array($default, $routes, TRUE)) {
      return NULL;
    }
    return ['configured' => TRUE, 'routes' => $routes, 'default' => $default];
  }

  /**
   * Chooses the route of a new application from the form offer.
   *
   * Never throws: a route outside the offer, a non-string or a route the
   * public offer filtered out (bundid without transport) falls back to the
   * form default.
   *
   * @param array|null $offer
   *   Result of self::decodeOffer().
   * @param mixed $requested
   *   The route the client sent, NULL when it sent none.
   * @param string $requestSource
   *   Either public_submit or manifest.
   *
   * @return array{route: string, source: string, offered: string[], reason: ?string}
   *   The route, where it came from, the routes on offer and why the
   *   request was not taken (not_configured, not_requested, not_offered).
   */
  public static function choose(?array $offer, mixed $requested, string $requestSource): array {
    if ($offer === NULL || ($offer['configured'] ?? FALSE) !== TRUE) {
      return ['route' => self::PORTAL, 'source' => 'form_default', 'offered' => [], 'reason' => 'not_configured'];
    }
    $routes = array_values($offer['routes'] ?? []);
    $default = (string) ($offer['default'] ?? self::PORTAL);
    if ($requested === NULL) {
      return ['route' => $default, 'source' => 'form_default', 'offered' => $routes, 'reason' => 'not_requested'];
    }
    if (is_string($requested) && in_array($requested, $routes, TRUE)) {
      return ['route' => $requested, 'source' => $requestSource, 'offered' => $routes, 'reason' => NULL];
    }
    return ['route' => $default, 'source' => 'form_default', 'offered' => $routes, 'reason' => 'not_offered'];
  }

  /**
   * The route_chosen event of a choice, as stored in field_delivery_record.
   *
   * @param array{route: string, source: string, offered: string[], reason: ?string} $choice
   *   Result of self::choose().
   * @param int $time
   *   When the application was received.
   *
   * @return array<string, mixed>
   *   The event.
   */
  public static function chosenEvent(array $choice, int $time): array {
    return [
      'event' => 'route_chosen',
      'at' => $time,
      'route' => $choice['route'],
      'source' => $choice['source'],
      'offered' => $choice['offered'],
      'reason' => $choice['reason'],
    ];
  }

  /**
   * Entity values for a new application from a choice.
   *
   * @param array{route: string, source: string, offered: string[], reason: ?string} $choice
   *   Result of self::choose().
   * @param int $time
   *   When the application was received.
   *
   * @return array{field_delivery_route: string, field_delivery_record: string}
   *   Create values.
   */
  public static function createValues(array $choice, int $time): array {
    return [
      'field_delivery_route' => $choice['route'],
      'field_delivery_record' => json_encode([self::chosenEvent($choice, $time)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];
  }

}
