<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_application\Delivery\DeliveryRoute;

/**
 * Effective route of one provided response (pure rules, no services).
 *
 * The application stores the route the citizen chose or staff set; each
 * provided response gets the route it actually takes, fixed at provision.
 * Stage 1 sends every decision by post (decision_postal_only); stage 2
 * replaces only that branch, the three route-level reasons before it stay.
 */
final class DeliveryRules {

  /**
   * Why a response goes by post although the route says otherwise.
   */
  public const REASONS = ['chosen_postal', 'no_digital_option', 'postbox_unavailable', 'decision_postal_only'];

  /**
   * Returns the effective route and the reason for it.
   *
   * @param string $chosenRoute
   *   Stored route of the application ('' or unknown counts as portal).
   * @param bool $isDecision
   *   Whether the response is a decision.
   * @param bool $isBulk
   *   Whether the application comes from bulk intake.
   * @param bool $postboxAvailable
   *   Whether a BundID postbox transport is available for the scope.
   *
   * @return array{route: string, reason: ?string}
   *   The route and why it differs from the chosen route (NULL when not).
   */
  public static function effectiveRoute(string $chosenRoute, bool $isDecision, bool $isBulk, bool $postboxAvailable): array {
    $chosen = in_array($chosenRoute, DeliveryRoute::ROUTES, TRUE) ? $chosenRoute : DeliveryRoute::PORTAL;
    $reason = match (TRUE) {
      $chosen === DeliveryRoute::POSTAL => 'chosen_postal',
      $isBulk => 'no_digital_option',
      $chosen === DeliveryRoute::BUNDID && !$postboxAvailable => 'postbox_unavailable',
      $isDecision => 'decision_postal_only',
      default => NULL,
    };
    return ['route' => $reason === NULL ? $chosen : DeliveryRoute::POSTAL, 'reason' => $reason];
  }

  /**
   * Whether a case-system outcome makes the response a decision.
   *
   * Everything but a filing receipt is a decision; a reply without outcome
   * counts as one (fail closed).
   */
  public static function isDecisionResponse(?string $outcome): bool {
    return $outcome !== 'filed';
  }

}
