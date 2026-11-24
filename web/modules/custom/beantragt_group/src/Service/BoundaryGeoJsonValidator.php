<?php

declare(strict_types=1);

namespace Drupal\beantragt_group\Service;

/**
 * Validates and normalizes jurisdiction boundary GeoJSON.
 *
 * This is a close port of Mark-a-Spot's BoundaryGeoJsonValidator. It accepts
 * FeatureCollection, Feature, Polygon, MultiPolygon, or NULL and always
 * normalizes stored geometry to a FeatureCollection. Open rings are closed;
 * unsupported or oversized geometry is rejected before persistence.
 */
final class BoundaryGeoJsonValidator {

  public const MAX_FEATURES = 25;

  public const MAX_VERTICES = 100000;

  public const MAX_PAYLOAD_BYTES = 2 * 1024 * 1024;

  public const MAX_PROPERTIES_BYTES = 4096;

  /**
   * Validates and normalizes a decoded boundary value.
   *
   * @return array{valid: bool, error: string|null, normalized: array|null}
   *   Validation result and normalized FeatureCollection.
   */
  public static function validate(mixed $data): array {
    if ($data === NULL) {
      return ['valid' => TRUE, 'error' => NULL, 'normalized' => NULL];
    }

    if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) {
      return self::invalid('boundary must be a GeoJSON object with a type.');
    }

    switch ($data['type']) {
      case 'FeatureCollection':
        if (!isset($data['features']) || !is_array($data['features'])) {
          return self::invalid('FeatureCollection must have a features array.');
        }
        $features = $data['features'];
        break;

      case 'Feature':
        $features = [$data];
        break;

      case 'Polygon':
      case 'MultiPolygon':
        $features = [
          [
            'type' => 'Feature',
            'properties' => [],
            'geometry' => $data,
          ],
        ];
        break;

      default:
        return self::invalid(sprintf(
          "Unsupported GeoJSON type '%s'. Expected FeatureCollection, Feature, Polygon, or MultiPolygon.",
          $data['type'],
        ));
    }

    if (count($features) > self::MAX_FEATURES) {
      return self::invalid(sprintf(
        'boundary may contain at most %d features (found %d).',
        self::MAX_FEATURES,
        count($features),
      ));
    }

    $normalized_features = [];
    $vertex_count = 0;
    foreach ($features as $index => $feature) {
      if (!is_array($feature) || ($feature['type'] ?? NULL) !== 'Feature') {
        return self::invalid("Feature at index $index must be a GeoJSON Feature.");
      }

      $geometry = $feature['geometry'] ?? NULL;
      if (!is_array($geometry) || !is_string($geometry['type'] ?? NULL)) {
        return self::invalid("Feature at index $index is missing a valid geometry.");
      }

      $geometry_type = $geometry['type'];
      if (!in_array($geometry_type, ['Polygon', 'MultiPolygon'], TRUE)) {
        return self::invalid(sprintf(
          "Feature at index %d has unsupported geometry type '%s'. Only Polygon and MultiPolygon are allowed.",
          $index,
          $geometry_type,
        ));
      }

      $geometry_result = self::validateGeometry($geometry_type, $geometry['coordinates'] ?? NULL, $index);
      if ($geometry_result['error'] !== NULL) {
        return self::invalid($geometry_result['error']);
      }

      $vertex_count += $geometry_result['vertexCount'];
      if ($vertex_count > self::MAX_VERTICES) {
        return self::invalid(sprintf('boundary may contain at most %d vertices in total.', self::MAX_VERTICES));
      }

      $properties = is_array($feature['properties'] ?? NULL) ? $feature['properties'] : [];
      $properties_size = strlen(json_encode($properties) ?: '');
      if ($properties_size > self::MAX_PROPERTIES_BYTES) {
        return self::invalid(sprintf(
          'Feature at index %d has properties larger than %d bytes (found %d).',
          $index,
          self::MAX_PROPERTIES_BYTES,
          $properties_size,
        ));
      }

      $normalized_features[] = [
        'type' => 'Feature',
        'properties' => $properties,
        'geometry' => [
          'type' => $geometry_type,
          'coordinates' => $geometry_result['coordinates'],
        ],
      ];
    }

    if ($normalized_features === []) {
      return ['valid' => TRUE, 'error' => NULL, 'normalized' => NULL];
    }

    return [
      'valid' => TRUE,
      'error' => NULL,
      'normalized' => [
        'type' => 'FeatureCollection',
        'features' => $normalized_features,
      ],
    ];
  }

  /**
   * Builds an invalid result.
   */
  private static function invalid(string $message): array {
    return ['valid' => FALSE, 'error' => $message, 'normalized' => NULL];
  }

  /**
   * Builds a geometry validation error.
   */
  private static function geometryError(string $message): array {
    return ['error' => $message, 'coordinates' => NULL, 'vertexCount' => 0];
  }

  /**
   * Validates Polygon or MultiPolygon coordinates.
   */
  private static function validateGeometry(string $type, mixed $coordinates, int $feature_index): array {
    if (!is_array($coordinates) || $coordinates === []) {
      return self::geometryError("Feature at index $feature_index has empty coordinates.");
    }

    if ($type === 'Polygon') {
      return self::validatePolygonRings($coordinates, $feature_index);
    }

    $normalized_polygons = [];
    $vertex_count = 0;
    foreach ($coordinates as $polygon_index => $rings) {
      if (!is_array($rings)) {
        return self::geometryError("Feature at index $feature_index has an invalid polygon at position $polygon_index.");
      }
      $result = self::validatePolygonRings($rings, $feature_index);
      if ($result['error'] !== NULL) {
        return $result;
      }
      $normalized_polygons[] = $result['coordinates'];
      $vertex_count += $result['vertexCount'];
    }

    return ['error' => NULL, 'coordinates' => $normalized_polygons, 'vertexCount' => $vertex_count];
  }

  /**
   * Validates and closes the rings of one Polygon.
   */
  private static function validatePolygonRings(array $rings, int $feature_index): array {
    if ($rings === []) {
      return self::geometryError("Feature at index $feature_index must have at least an exterior ring.");
    }

    $normalized_rings = [];
    $vertex_count = 0;
    foreach ($rings as $ring_index => $ring) {
      if (!is_array($ring) || $ring === []) {
        return self::geometryError("Feature at index $feature_index, ring $ring_index must be a non-empty array of positions.");
      }

      $normalized_positions = [];
      foreach ($ring as $position_index => $position) {
        $validated = self::validatePosition($position);
        if ($validated === NULL) {
          return self::geometryError("Feature at index $feature_index, ring $ring_index has an invalid coordinate at position $position_index.");
        }
        $normalized_positions[] = $validated;
      }

      $first = $normalized_positions[0];
      $last = $normalized_positions[count($normalized_positions) - 1];
      if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
        $normalized_positions[] = $first;
      }
      if (count($normalized_positions) < 4) {
        return self::geometryError("Feature at index $feature_index, ring $ring_index must have at least 4 positions once closed.");
      }

      $normalized_rings[] = $normalized_positions;
      $vertex_count += count($normalized_positions);
    }

    return ['error' => NULL, 'coordinates' => $normalized_rings, 'vertexCount' => $vertex_count];
  }

  /**
   * Validates one [longitude, latitude] position.
   *
   * @return array{0: float, 1: float}|null
   *   Normalized coordinate pair.
   */
  private static function validatePosition(mixed $position): ?array {
    if (!is_array($position) || count($position) < 2) {
      return NULL;
    }

    [$lng, $lat] = array_values($position);
    if (!is_numeric($lng) || !is_numeric($lat)) {
      return NULL;
    }
    $lng = (float) $lng;
    $lat = (float) $lat;
    if (!is_finite($lng) || !is_finite($lat) || $lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
      return NULL;
    }

    return [$lng, $lat];
  }

}
