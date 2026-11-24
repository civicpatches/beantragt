<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\beantragt_group\Service\BoundaryGeoJsonValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests jurisdiction boundary GeoJSON normalization and validation.
 */
#[CoversClass(BoundaryGeoJsonValidator::class)]
#[Group('beantragt_group')]
final class BoundaryGeoJsonValidatorTest extends UnitTestCase {

  private const SQUARE_RING = [
    [0.0, 0.0],
    [10.0, 0.0],
    [10.0, 10.0],
    [0.0, 10.0],
    [0.0, 0.0],
  ];

  /**
   * NULL and an empty collection clear the boundary safely.
   */
  public function testEmptyValuesClearBoundary(): void {
    $this->assertNull(BoundaryGeoJsonValidator::validate(NULL)['normalized']);
    $result = BoundaryGeoJsonValidator::validate([
      'type' => 'FeatureCollection',
      'features' => [],
    ]);
    $this->assertTrue($result['valid']);
    $this->assertNull($result['normalized']);
  }

  /**
   * Polygon input is wrapped and open rings are closed.
   */
  public function testPolygonIsNormalizedAndOpenRingIsClosed(): void {
    $result = BoundaryGeoJsonValidator::validate([
      'type' => 'Polygon',
      'coordinates' => [
        [
          [0, 0], [10, 0], [10, 10], [0, 10],
        ],
      ],
    ]);

    $this->assertTrue($result['valid']);
    $this->assertSame('FeatureCollection', $result['normalized']['type']);
    $ring = $result['normalized']['features'][0]['geometry']['coordinates'][0];
    $this->assertCount(5, $ring);
    $this->assertSame($ring[0], $ring[4]);
  }

  /**
   * MultiPolygon and interior rings are preserved.
   */
  public function testMultiPolygonAndHolesArePreserved(): void {
    $hole = [[3.0, 3.0], [7.0, 3.0], [7.0, 7.0], [3.0, 7.0], [3.0, 3.0]];
    $result = BoundaryGeoJsonValidator::validate([
      'type' => 'MultiPolygon',
      'coordinates' => [
        [self::SQUARE_RING, $hole],
        [[[20, 20], [25, 20], [25, 25], [20, 25], [20, 20]]],
      ],
    ]);

    $this->assertTrue($result['valid']);
    $coordinates = $result['normalized']['features'][0]['geometry']['coordinates'];
    $this->assertCount(2, $coordinates);
    $this->assertSame($hole, $coordinates[0][1]);
  }

  /**
   * Only Polygon and MultiPolygon are accepted.
   */
  public function testUnsupportedGeometryIsRejected(): void {
    $result = BoundaryGeoJsonValidator::validate([
      'type' => 'Feature',
      'properties' => [],
      'geometry' => ['type' => 'Point', 'coordinates' => [5, 5]],
    ]);

    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Point', (string) $result['error']);

    $non_string_type = BoundaryGeoJsonValidator::validate([
      'type' => 'Feature',
      'properties' => [],
      'geometry' => ['type' => [], 'coordinates' => []],
    ]);
    $this->assertFalse($non_string_type['valid']);
    $this->assertStringContainsString('valid geometry', (string) $non_string_type['error']);
  }

  /**
   * Coordinates use GeoJSON [longitude, latitude] bounds.
   */
  public function testOutOfRangeCoordinatesAreRejected(): void {
    $result = BoundaryGeoJsonValidator::validate([
      'type' => 'Polygon',
      'coordinates' => [[[0, 0], [181, 0], [10, 10], [0, 10], [0, 0]]],
    ]);

    $this->assertFalse($result['valid']);
  }

  /**
   * Feature and property caps protect the settings payload.
   */
  public function testComplexityCapsAreEnforced(): void {
    $features = array_fill(0, BoundaryGeoJsonValidator::MAX_FEATURES + 1, [
      'type' => 'Feature',
      'properties' => [],
      'geometry' => ['type' => 'Polygon', 'coordinates' => [self::SQUARE_RING]],
    ]);
    $too_many = BoundaryGeoJsonValidator::validate([
      'type' => 'FeatureCollection',
      'features' => $features,
    ]);
    $this->assertFalse($too_many['valid']);

    $properties = ['note' => str_repeat('a', BoundaryGeoJsonValidator::MAX_PROPERTIES_BYTES)];
    $oversized_properties = BoundaryGeoJsonValidator::validate([
      'type' => 'Feature',
      'properties' => $properties,
      'geometry' => ['type' => 'Polygon', 'coordinates' => [self::SQUARE_RING]],
    ]);
    $this->assertFalse($oversized_properties['valid']);

    $too_many_vertices = BoundaryGeoJsonValidator::validate([
      'type' => 'Feature',
      'properties' => [],
      'geometry' => [
        'type' => 'Polygon',
        'coordinates' => [array_fill(0, BoundaryGeoJsonValidator::MAX_VERTICES + 1, [0, 0])],
      ],
    ]);
    $this->assertFalse($too_many_vertices['valid']);
    $this->assertStringContainsString('vertices', (string) $too_many_vertices['error']);
  }

}
