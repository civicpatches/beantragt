<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_compute\Kernel;

use Drupal\beantragt_compute\ComputeFunctionInterface;
use Drupal\beantragt_compute\ComputeFunctionManager;
use Drupal\beantragt_compute\Plugin\ComputeFunction\ApprovalTier;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the ComputeFunction plugin manager against the real factory path.
 *
 * Unit tests construct plugins directly or mock the manager, which masks the
 * DefaultPluginManager ContainerFactory instantiation
 * (`new static($configuration, $plugin_id, $plugin_definition)`). This Kernel
 * test resolves the REAL manager service and calls createInstance(), exercising
 * exactly the runtime path that ComputedFieldEvaluator::evaluate() uses.
 *
 * @group beantragt_compute
 * @coversDefaultClass \Drupal\beantragt_compute\ComputeFunctionManager
 */
final class ComputeFunctionManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The plugin manager and ApprovalTier carry no entity or config dependency,
   * but beantragt_compute declares beantragt_application + beantragt_schema in
   * its info.yml, and beantragt_application's services pull in
   * beantragt_workflow_status (status_manager). All four are listed so the test
   * container compiles cleanly while still installing the real, declared graph.
   */
  protected static $modules = [
    'beantragt_workflow_status',
    'beantragt_schema',
    'beantragt_compute_api',
    'beantragt_application',
    'beantragt_compute',
  ];

  /**
   * The compute function plugin manager service.
   */
  private ComputeFunctionManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->container->get('plugin.manager.beantragt_compute_function');
  }

  /**
   * Verifies the complete registry of function IDs used by FormSchema and UI.
   *
   * @covers ::__construct
   */
  public function testRegisteredFunctionIds(): void {
    $this->assertInstanceOf(ComputeFunctionManager::class, $this->manager);
    $function_ids = array_keys($this->manager->getDefinitions());
    sort($function_ids, SORT_STRING);
    $this->assertSame(['approvalTier'], $function_ids);
  }

  /**
   * CreateInstance() succeeds via the real ContainerFactory (Bug A regression).
   *
   * Before the fix, ApprovalTier's constructor expected a
   * LoggerChannelFactoryInterface, so the ContainerFactory's
   * `new static($configuration, ...)` passed an empty array where a service was
   * expected and threw a TypeError, failing every application save with a
   * computed field. This exercises the factory end to end.
   *
   * @covers ::createInstance
   */
  public function testCreateInstanceResolvesAndComputes(): void {
    $plugin = $this->manager->createInstance('approvalTier');
    $this->assertInstanceOf(ComputeFunctionInterface::class, $plugin);
    $this->assertInstanceOf(ApprovalTier::class, $plugin);

    // > 5000 -> management.
    $this->assertSame('management', $plugin->compute([6000], []));
    // < 500 -> team_lead (also the non-numeric / low fallback).
    $this->assertSame('team_lead', $plugin->compute([400], []));
  }

}
