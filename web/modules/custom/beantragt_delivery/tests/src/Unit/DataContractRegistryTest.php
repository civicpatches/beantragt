<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\beantragt_delivery\Service\DataContractRegistry;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the operator-provisioned contract registry.
 */
#[CoversClass(DataContractRegistry::class)]
#[Group('beantragt_delivery')]
final class DataContractRegistryTest extends UnitTestCase {

  /**
   * Creates a registry rooted in the synthetic contract directory.
   */
  private function registry(?string $root = NULL, bool $demo = FALSE): DataContractRegistry {
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->with('beantragt_demo')->willReturn($demo);
    return new SyntheticDataContractRegistry($handler, $root ?? dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
  }

  /**
   * Unknown keys and versions cannot select a filesystem path.
   */
  public function testUnknownContractRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('integration.contract_not_found');
    $this->registry()->load('../../schema.json', '1.0');
  }

  /**
   * Corrupt bytes fail closed even when the same registry loaded them before.
   */
  public function testChecksumMismatchFailsClosed(): void {
    $root = sys_get_temp_dir() . '/beantragt-contract-' . bin2hex(random_bytes(8));
    $dir = $root . '/fim/S00000096/1.0';
    mkdir($dir, 0700, TRUE);
    $source = dirname(__DIR__, 2) . '/fixtures/synthetic-contracts/fim/S00000096/1.0';
    copy($source . '/manifest.json', $dir . '/manifest.json');
    copy($source . '/schema.json', $dir . '/schema.json');
    try {
      $registry = $this->registry($root);
      $this->assertSame('Synthetic nested delivery contract', $registry->load('fim:S00000096', '1.0')['title']);
      $registry->paths('fim:S00000096', '1.0');
      file_put_contents($dir . '/schema.json', '\n', FILE_APPEND);
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('integration.contract_checksum_mismatch');
      $registry->paths('fim:S00000096', '1.0');
    }
    finally {
      unlink($dir . '/schema.json');
      unlink($dir . '/manifest.json');
      rmdir($dir);
      rmdir(dirname($dir));
      rmdir(dirname($dir, 2));
      rmdir($root);
    }
  }

  /**
   * Operator files cannot replace a pinned schema and its manifest together.
   */
  public function testSelfConsistentReplacementFailsClosed(): void {
    $root = sys_get_temp_dir() . '/beantragt-contract-' . bin2hex(random_bytes(8));
    $dir = $root . '/fim/S00000096/1.0';
    mkdir($dir, 0700, TRUE);
    $uri = 'https://schema.fim.fitko.net/immutable/schemas/S00000096V1.0_2025-11-25-1764058858128.schema.json';
    $bytes = json_encode([
      '$schema' => 'https://json-schema.org/draft/2020-12/schema',
      '$id' => $uri,
      'type' => 'object',
    ], JSON_THROW_ON_ERROR);
    file_put_contents($dir . '/schema.json', $bytes);
    file_put_contents($dir . '/manifest.json', json_encode([
      'key' => 'fim:S00000096',
      'version' => '1.0',
      'sourceUri' => $uri,
      'sha256' => hash('sha256', $bytes),
    ], JSON_THROW_ON_ERROR));
    try {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('integration.contract_checksum_mismatch');
      (new DataContractRegistry(contractDirectory: $root))->load('fim:S00000096', '1.0');
    }
    finally {
      unlink($dir . '/schema.json');
      unlink($dir . '/manifest.json');
      rmdir($dir);
      rmdir(dirname($dir));
      rmdir(dirname($dir, 2));
      rmdir($root);
    }
  }

  /**
   * Local references flatten with explicit array and required semantics.
   */
  public function testManifestAndPaths(): void {
    $registry = $this->registry();
    $this->assertSame('fim:S00000096', $registry->list()[0]['key']);
    $this->assertNull($registry->list()[0]['serviceKey']);
    $paths = array_column($registry->paths('fim:S00000096', '1.0'), NULL, 'pointer');
    $this->assertSame('Synthetic nested delivery contract', $paths['']['title']);
    $this->assertNull($paths['']['description']);
    $this->assertSame(1, $paths['/GSynthetic078']['minItems']);
    $this->assertSame([$paths['/GSynthetic069']['title']], $paths['/GSynthetic069/FSynthetic068']['group_titles']);
    $this->assertNotEmpty($paths['/GSynthetic078/*/GSynthetic070/FSynthetic003']['group_titles']);
    $this->assertTrue($paths['/GSynthetic069']['required']);
    $this->assertFalse($paths['/GSynthetic069/FSynthetic068']['array']);
    $this->assertSame('string', $paths['/GSynthetic069/FSynthetic068']['type']);
    $this->assertTrue($paths['/GSynthetic078/*']['array']);
    $this->assertTrue($paths['/GSynthetic078/*']['required']);
    foreach ($paths as $path) {
      $this->assertSame([], $path['unsupported']);
      if ($path['type'] === 'array') {
        $this->assertNotEmpty($path['title']);
        $this->assertSame('items', $path['title_source']);
        $this->assertSame($paths[$path['pointer'] . '/*']['title'], $path['title']);
        $this->assertSame($paths[$path['pointer'] . '/*']['description'], $path['description']);
      }
    }
    $this->assertCount(19, array_filter($paths, static fn(array $path): bool => $path['type'] === 'array'));
  }

  /**
   * A second provisioned contract exposes its own typed fields.
   */
  public function testScalarContract(): void {
    $registry = $this->registry();
    $this->assertSame(['fim:S00000096', 'fim:S05000039'], array_column($registry->list(), 'key'));
    $schema = $registry->load('fim:S05000039', '1.1');
    $this->assertSame('Synthetic scalar delivery contract', $schema['title']);
    $paths = array_column($registry->paths('fim:S05000039', '1.1'), NULL, 'pointer');
    $this->assertSame('integer', $paths['/syntheticCount']['type']);
    $this->assertSame('boolean', $paths['/syntheticFlag']['type']);
    $this->assertTrue($paths['/syntheticFlag']['required']);
  }

  /**
   * A missing operator directory lists nothing and cannot validate a binding.
   */
  public function testMissingContractFailsClosed(): void {
    $registry = $this->registry(sys_get_temp_dir() . '/beantragt-missing-' . bin2hex(random_bytes(8)));
    $this->assertSame([], $registry->list());
    $this->expectException(IntegrationException::class);
    $this->expectExceptionMessage('integration.contract_unavailable');
    $registry->load('fim:S00000096', '1.0');
  }

  /**
   * Listing is demo-only, but existing pinned bindings remain loadable.
   */
  public function testDemoVisibilityDoesNotInvalidatePinnedBindings(): void {
    $normal = $this->registry();
    $demo = $this->registry(demo: TRUE);
    self::assertSame(['fim:S00000096', 'fim:S05000039'], array_column($normal->list(), 'key'));
    self::assertSame(['fim:S00000096', 'fim:S05000039', 'fim:wohngeld-demo', 'fim:vereinszuschuss-demo'], array_column($demo->list(), 'key'));
    foreach (['fim:wohngeld-demo', 'fim:vereinszuschuss-demo'] as $key) {
      self::assertSame($demo->load($key, '0.1'), $normal->load($key, '0.1'));
      self::assertSame($demo->paths($key, '0.1'), $normal->paths($key, '0.1'));
    }
  }

  /**
   * A broken placeholder pin fails closed when listed in a demo.
   */
  public function testDemoListingVerifiesPlaceholderChecksum(): void {
    $root = sys_get_temp_dir() . '/beantragt-contract-' . bin2hex(random_bytes(8));
    $copied = [];
    foreach (['S00000096/1.0', 'S05000039/1.1', 'wohngeld-demo/0.1', 'vereinszuschuss-demo/0.1'] as $relative) {
      $dir = $root . '/fim/' . $relative;
      mkdir($dir, 0700, TRUE);
      foreach (['manifest.json', 'schema.json'] as $file) {
        copy(dirname(__DIR__, 2) . '/fixtures/synthetic-contracts/fim/' . $relative . '/' . $file, $dir . '/' . $file);
        $copied[] = $dir . '/' . $file;
      }
    }
    try {
      self::assertCount(2, $this->registry($root)->list());
      file_put_contents($root . '/fim/vereinszuschuss-demo/0.1/schema.json', "\n", FILE_APPEND);
      // Outside a demo the placeholder is not listed, its bytes are not read.
      self::assertCount(2, $this->registry($root)->list());
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('integration.contract_checksum_mismatch');
      $this->registry($root, TRUE)->list();
    }
    finally {
      array_map('unlink', $copied);
      foreach (['S00000096/1.0', 'S05000039/1.1', 'wohngeld-demo/0.1', 'vereinszuschuss-demo/0.1'] as $relative) {
        rmdir($root . '/fim/' . $relative);
        rmdir(dirname($root . '/fim/' . $relative));
      }
      rmdir($root . '/fim');
      rmdir($root);
    }
  }

}
