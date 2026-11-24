<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Site\Settings;
use Drupal\beantragt_delivery\Exception\IntegrationException;

/**
 * Loads checksum-pinned data contracts provisioned by the operator.
 */
class DataContractRegistry {

  /**
   * Trusted contract identities and SHA-256 of the exact published bytes.
   */
  private const CONTRACTS = [
    'fim:S00000096' => [
      '1.0' => [
        'directory' => 'fim/S00000096/1.0',
        'sourceUri' => 'https://schema.fim.fitko.net/immutable/schemas/S00000096V1.0_2025-11-25-1764058858128.schema.json',
        'sha256' => '72b42b4278ceaa4e5550f3146353ef5f9f25612bb696e38bc143581e1bd9153f',
      ],
    ],
    'fim:S05000039' => [
      '1.1' => [
        'directory' => 'fim/S05000039/1.1',
        'sourceUri' => 'https://schema.fim.fitko.net/immutable/schemas/S05000039V1.1_2025-11-25-1764059070479.schema.json',
        'sha256' => '590bb4c0a1af445cbb2f6ea404e4d0fae7c3a68c005191f8d7fc0f4885e0b6d3',
      ],
    ],
    'fim:wohngeld-demo' => [
      '0.1' => [
        'directory' => 'fim/wohngeld-demo/0.1',
        'sourceUri' => 'urn:beantragt:demo:wohngeld-demo:0.1',
        'sha256' => '285ed8c9e25d105bccfab6d3d9a08e727c8ceab923e0badd7a1e2c02e145a722',
      ],
    ],
    'fim:vereinszuschuss-demo' => [
      '0.1' => [
        'directory' => 'fim/vereinszuschuss-demo/0.1',
        'sourceUri' => 'urn:beantragt:demo:vereinszuschuss-demo:0.1',
        'sha256' => '66bd3182b89695f2a755fd06a608c3e5e8844bd78563d7fb56a591ae018a3eec',
      ],
    ],
  ];

  /**
   * Demo placeholder contracts, listed only while beantragt_demo is enabled.
   */
  private const DEMO_CONTRACTS = ['fim:wohngeld-demo', 'fim:vereinszuschuss-demo'];

  /**
   * Flattened paths cached by verified schema content.
   */
  private array $pathCache = [];

  /**
   * Constructs the registry.
   */
  public function __construct(
    private readonly ?ModuleHandlerInterface $moduleHandler = NULL,
    private readonly ?string $contractDirectory = NULL,
    private readonly ?string $defaultContractDirectory = NULL,
  ) {}

  /**
   * Lists verified manifests, exposing demo placeholders only in demos.
   */
  public function list(): array {
    $contracts = [];
    foreach (self::CONTRACTS as $key => $versions) {
      if (in_array($key, self::DEMO_CONTRACTS, TRUE) && !$this->moduleHandler?->moduleExists('beantragt_demo')) {
        continue;
      }
      foreach (array_keys($versions) as $version) {
        $directory = $this->directory($key, $version);
        if ($directory === NULL || !is_dir($directory)) {
          continue;
        }
        $contracts[] = $this->verifiedContract($directory, $key, $version)['manifest'];
      }
    }
    return $contracts;
  }

  /**
   * Loads a schema, verifying its exact bytes on every load.
   */
  public function load(string $key, string $version): array {
    $directory = $this->directory($key, $version);
    if ($directory === NULL) {
      throw new IntegrationException('integration.contract_unavailable');
    }
    return $this->verifiedContract($directory, $key, $version)['schema'];
  }

  /**
   * Pairs a manifest with the schema bytes it verifies.
   *
   * @return array{manifest: array, schema: array}
   *   Verified metadata and schema.
   */
  private function verifiedContract(string $directory, string $key, string $version): array {
    $manifest = $this->manifest($directory, $key, $version);
    $pin = $this->contractMetadata($key, $version);
    $bytes = $this->readContractFile($directory . '/schema.json');
    if (!hash_equals($pin['sha256'], $manifest['sha256']) || !hash_equals($pin['sha256'], hash('sha256', $bytes))) {
      throw new \RuntimeException('integration.contract_checksum_mismatch');
    }
    $schema = json_decode($bytes, TRUE, 128, JSON_THROW_ON_ERROR);
    if (!is_array($schema) || ($schema['$schema'] ?? NULL) !== 'https://json-schema.org/draft/2020-12/schema' || $manifest['sourceUri'] !== $pin['sourceUri'] || ($schema['$id'] ?? NULL) !== $pin['sourceUri']) {
      throw new \RuntimeException('integration.contract_invalid');
    }
    return ['manifest' => $manifest, 'schema' => $schema];
  }

  /**
   * Flattens property paths; an asterisk denotes an array item template.
   *
   * Required includes ancestor requirements. Local_required applies only within
   * the immediate parent. Array is also true for descendants of an array.
   */
  public function paths(string $key, string $version): array {
    $schema = $this->load($key, $version);
    $cache_key = hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
    if (!isset($this->pathCache[$cache_key])) {
      $paths = [];
      $this->walk($schema, $schema, '', TRUE, TRUE, FALSE, $paths);
      $this->pathCache[$cache_key] = $paths;
    }
    return $this->pathCache[$cache_key];
  }

  /**
   * Reads trusted metadata without constructing a path from caller input.
   */
  private function manifest(string $directory, string $key, string $version): array {
    $manifest = json_decode($this->readContractFile($directory . '/manifest.json'), TRUE, 32, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['key'] ?? NULL) !== $key || ($manifest['version'] ?? NULL) !== $version || !is_string($manifest['sha256'] ?? NULL) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256']) || !is_string($manifest['sourceUri'] ?? NULL)) {
      throw new \RuntimeException('integration.contract_invalid');
    }
    return $manifest;
  }

  /**
   * Returns the trusted identity and digest of a registered contract.
   *
   * @return array{sourceUri: string, sha256: string}
   *   The expected source URI and SHA-256.
   */
  protected function contractMetadata(string $key, string $version): array {
    return self::CONTRACTS[$key][$version];
  }

  /**
   * Resolves only registered contract paths below the operator directory.
   */
  private function directory(string $key, string $version): ?string {
    $relative = self::CONTRACTS[$key][$version]['directory'] ?? NULL;
    if ($relative === NULL) {
      throw new \InvalidArgumentException('integration.contract_not_found');
    }
    // Private installations retain their existing local contract directory.
    // An explicit operator path never falls back to those files.
    $root = $this->contractDirectory
      ?? Settings::get('beantragt_fim_contract_directory', getenv('BEANTRAGT_FIM_CONTRACT_DIRECTORY') ?: $this->defaultContractDirectory);
    if (!is_string($root) || !str_starts_with($root, '/')) {
      return NULL;
    }
    return rtrim($root, '/') . '/' . $relative;
  }

  /**
   * Reads a regular local manifest or schema file, failing closed on absence.
   */
  private function readContractFile(string $path): string {
    $bytes = is_file($path) && is_readable($path) ? @file_get_contents($path) : FALSE;
    if ($bytes === FALSE) {
      throw new IntegrationException('integration.contract_unavailable');
    }
    return $bytes;
  }

  /**
   * Expands local definitions and records unsupported syntax.
   */
  private function walk(array $node, array $root, string $pointer, bool $required, bool $local_required, bool $array, array &$paths, array $references = [], int $depth = 0, array $groups = []): void {
    if ($depth > 64 || count($paths) >= 4096) {
      throw new \RuntimeException('integration.contract_invalid');
    }
    $safe = [
      '$id', '$schema', '$defs', '$comment', '$ref', 'title', 'description',
      'type', 'properties', 'required', 'enum', 'items', 'minItems', 'maxItems',
      'minLength', 'maxLength', 'format', 'pattern', 'minimum', 'maximum',
      'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf',
    ];
    $unsupported = array_values(array_diff(array_keys($node), $safe));
    if (isset($node['$ref'])) {
      $ref = $node['$ref'];
      $siblings = array_diff(array_keys($node), ['$ref', 'title', 'description', '$comment']);
      if (!is_string($ref) || !str_starts_with($ref, '#/$defs/') || in_array($ref, $references, TRUE) || $siblings !== []) {
        $unsupported[] = '$ref';
      }
      else {
        $target = $root;
        foreach (explode('/', substr($ref, 2)) as $part) {
          $target = is_array($target) ? ($target[str_replace(['~1', '~0'], ['/', '~'], $part)] ?? NULL) : NULL;
        }
        if (!is_array($target)) {
          throw new \RuntimeException('integration.contract_invalid');
        }
        unset($node['$ref']);
        $references[] = $ref;
        $this->walk(array_replace($target, $node), $root, $pointer, $required, $local_required, $array, $paths, $references, $depth + 1, $groups);
        return;
      }
    }
    $type = $node['type'] ?? NULL;
    $array = $array || $type === 'array';
    $item = $type === 'array' ? ($node['items'] ?? []) : [];
    $item_refs = [];
    while (isset($item['$ref']) && is_string($item['$ref']) && str_starts_with($item['$ref'], '#/$defs/') && !in_array($item['$ref'], $item_refs, TRUE)) {
      $item_refs[] = $item['$ref'];
      $resolved = $root;
      foreach (explode('/', substr($item['$ref'], 2)) as $part) {
        $resolved = is_array($resolved) ? ($resolved[str_replace(['~1', '~0'], ['/', '~'], $part)] ?? []) : [];
      }
      unset($item['$ref']);
      $item = array_replace($resolved, $item);
    }
    $paths[] = [
      'pointer' => $pointer,
      'type' => $type,
      'required' => $required,
      'local_required' => $local_required,
      'enum' => $node['enum'] ?? NULL,
      'array' => $array,
      'format' => $node['format'] ?? NULL,
      'pattern' => $node['pattern'] ?? NULL,
      'title' => $node['title'] ?? $item['title'] ?? NULL,
      'title_source' => !isset($node['title']) && isset($item['title']) ? 'items' : NULL,
      'description' => $node['description'] ?? $item['description'] ?? NULL,
      'group_titles' => $groups,
      'minItems' => $type === 'array' ? ($node['minItems'] ?? 0) : NULL,
      'maxItems' => $type === 'array' ? ($node['maxItems'] ?? NULL) : NULL,
      'unsupported' => array_values(array_unique($unsupported)),
    ];
    $group_pointer = str_ends_with($pointer, '/*') ? substr($pointer, 0, -2) : $pointer;
    if (is_string($node['title'] ?? NULL) && preg_match('~/G[^/]+$~', $group_pointer)) {
      $groups[] = $node['title'];
    }
    foreach ($node['properties'] ?? [] as $name => $child) {
      if (!is_array($child)) {
        throw new \RuntimeException('integration.contract_invalid');
      }
      $local = in_array($name, $node['required'] ?? [], TRUE);
      $this->walk($child, $root, $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $name), $required && $local, $local, $array, $paths, $references, $depth + 1, $groups);
    }
    if ($type === 'array' && is_array($node['items'] ?? NULL)) {
      $this->walk($node['items'], $root, $pointer . '/*', $required && ($node['minItems'] ?? 0) > 0, TRUE, TRUE, $paths, $references, $depth + 1, $groups);
    }
  }

}
