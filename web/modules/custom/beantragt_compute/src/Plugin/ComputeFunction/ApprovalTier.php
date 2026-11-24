<?php

declare(strict_types=1);

namespace Drupal\beantragt_compute\Plugin\ComputeFunction;

use Drupal\beantragt_compute\Attribute\ComputeFunction;
use Drupal\beantragt_compute\ComputeFunctionInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Derives the approval tier machine value from a net EUR amount.
 *
 * Return values are stable machine identifiers consumed by the workflow router.
 * Display-label mapping is the frontend's responsibility.
 *
 * Tier boundaries:
 *   < 500          => 'team_lead'
 *   500 .. 5000    => 'department_head'   (both endpoints inclusive)
 *   > 5000         => 'management'
 *
 * Non-numeric or missing input is treated as the lowest tier ('team_lead').
 *
 * This plugin is intentionally PURE and DI-free: it extends PluginBase and adds
 * no constructor, so the DefaultPluginManager ContainerFactory can instantiate
 * it via `new static($configuration, $plugin_id, $plugin_definition)`. A
 * compute function that genuinely needs services (e.g. a reference-data lookup)
 * must implement ContainerFactoryPluginInterface and a create() factory; see
 * the contract note on ComputeFunctionInterface. Logging of the non-numeric
 * fallback lives in ComputedFieldEvaluator, which holds the logger.
 */
#[ComputeFunction(
  id: 'approvalTier',
  label: new TranslatableMarkup('Approval tier by amount'),
  arity: 1,
)]
class ApprovalTier extends PluginBase implements ComputeFunctionInterface {

  /**
   * Tier returned for amounts below 500 EUR (also the safe default).
   */
  public const TIER_TEAM_LEAD = 'team_lead';

  /**
   * Tier returned for amounts between 500 and 5000 EUR inclusive.
   */
  public const TIER_DEPARTMENT_HEAD = 'department_head';

  /**
   * Tier returned for amounts above 5000 EUR.
   */
  public const TIER_MANAGEMENT = 'management';

  /**
   * {@inheritdoc}
   *
   * @param array $args
   *   $args[0]: the net EUR amount (numeric string, int, or float).
   * @param array $context
   *   Unused; reserved for future extension.
   *
   * @return string
   *   One of 'team_lead', 'department_head', or 'management'. Non-numeric or
   *   missing input returns 'team_lead' (the lowest tier, fail-safe).
   */
  public function compute(array $args, array $context): string {
    $raw = $args[0] ?? NULL;

    if ($raw === NULL || $raw === '' || !is_numeric($raw)) {
      return self::TIER_TEAM_LEAD;
    }

    $amount = (float) $raw;

    if ($amount < 500.0) {
      return self::TIER_TEAM_LEAD;
    }

    if ($amount <= 5000.0) {
      return self::TIER_DEPARTMENT_HEAD;
    }

    return self::TIER_MANAGEMENT;
  }

}
