<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit\Delivery;

use Drupal\beantragt_application\Delivery\DeliveryRoute;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Route choice at intake and the decoding of the computed form offer.
 */
#[Group('beantragt_application')]
final class DeliveryRouteTest extends UnitTestCase {

  /**
   * The offer of a form with portal and post, portal preselected.
   */
  private const OFFER = ['configured' => TRUE, 'routes' => ['portal', 'postal'], 'default' => 'portal'];

  /**
   * Choice matrix; never throws.
   */
  #[DataProvider('choices')]
  public function testChoose(?array $offer, mixed $requested, string $source, array $expected): void {
    self::assertSame($expected, DeliveryRoute::choose($offer, $requested, $source));
  }

  /**
   * Offer, requested route, request source, expected choice.
   */
  public static function choices(): array {
    $offered = ['portal', 'postal'];
    $postal_only = ['configured' => TRUE, 'routes' => ['postal'], 'default' => 'postal'];
    $unconfigured = self::choice('portal', 'form_default', [], 'not_configured');
    $not_offered = self::choice('portal', 'form_default', $offered, 'not_offered');
    $not_requested = self::choice('portal', 'form_default', $offered, 'not_requested');
    $postal_default = self::choice('postal', 'form_default', ['postal'], 'not_requested');
    return [
      'no offer' => [NULL, 'postal', 'public_submit', $unconfigured],
      'unconfigured' => [['configured' => FALSE], 'postal', 'public_submit', $unconfigured],
      'absent' => [self::OFFER, NULL, 'public_submit', $not_requested],
      'offered' => [self::OFFER, 'postal', 'public_submit', self::choice('postal', 'public_submit', $offered, NULL)],
      'offered from manifest' => [self::OFFER, 'postal', 'manifest', self::choice('postal', 'manifest', $offered, NULL)],
      'not offered' => [self::OFFER, 'fax', 'public_submit', $not_offered],
      'non-string' => [self::OFFER, 42, 'public_submit', $not_offered],
      'array' => [self::OFFER, ['postal'], 'public_submit', $not_offered],
      'bundid filtered out' => [self::OFFER, 'bundid', 'public_submit', $not_offered],
      'postal default' => [$postal_only, NULL, 'manifest', $postal_default],
    ];
  }

  /**
   * An expected choice.
   *
   * @return array{route: string, source: string, offered: string[], reason: ?string}
   *   The choice.
   */
  private static function choice(string $route, string $source, array $offered, ?string $reason): array {
    return ['route' => $route, 'source' => $source, 'offered' => $offered, 'reason' => $reason];
  }

  /**
   * Valid offers decode; anything malformed is NULL.
   */
  #[DataProvider('offers')]
  public function testDecodeOffer(?string $json, ?array $expected): void {
    self::assertSame($expected, DeliveryRoute::decodeOffer($json));
  }

  /**
   * JSON value, expected decoded offer.
   */
  public static function offers(): array {
    return [
      'null' => [NULL, NULL],
      'empty' => ['', NULL],
      'not json' => ['{configured', NULL],
      'list' => ['[]', NULL],
      'no flag' => ['{"routes":["portal"],"default":"portal"}', NULL],
      'string flag' => ['{"configured":"true","routes":["portal"],"default":"portal"}', NULL],
      'unconfigured' => ['{"configured":false}', ['configured' => FALSE]],
      'unconfigured ignores extra keys' => ['{"configured":false,"routes":["postal"]}', ['configured' => FALSE]],
      'configured' => ['{"configured":true,"routes":["portal","postal"],"default":"portal"}', self::OFFER],
      'no routes' => ['{"configured":true,"routes":[],"default":""}', NULL],
      'unknown route' => ['{"configured":true,"routes":["portal","fax"],"default":"portal"}', NULL],
      'duplicate route' => ['{"configured":true,"routes":["portal","portal"],"default":"portal"}', NULL],
      'default outside routes' => ['{"configured":true,"routes":["portal"],"default":"postal"}', NULL],
      'missing default' => ['{"configured":true,"routes":["portal"]}', NULL],
      'routes as object' => ['{"configured":true,"routes":{"a":"portal"},"default":"portal"}', NULL],
    ];
  }

  /**
   * The intake writes the route and exactly one route_chosen event.
   */
  public function testCreateValues(): void {
    $values = DeliveryRoute::createValues(DeliveryRoute::choose(self::OFFER, 'postal', 'public_submit'), 1762500000);
    self::assertSame('postal', $values['field_delivery_route']);
    self::assertSame([[
      'event' => 'route_chosen',
      'at' => 1762500000,
      'route' => 'postal',
      'source' => 'public_submit',
      'offered' => ['portal', 'postal'],
      'reason' => NULL,
    ],
    ], json_decode($values['field_delivery_record'], TRUE));
  }

}
