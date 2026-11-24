<?php

declare(strict_types=1);

namespace Drupal\beantragt_schema\Trait;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares flood handling for public cacheable catalogue responses.
 *
 * Anonymous public catalogue endpoints return cacheable responses on success.
 * A rate-limit denial is specific to one client, so it must never be stored
 * in the Internal Page Cache, the Dynamic Page Cache or a reverse proxy;
 * otherwise one exhausted client would lock every visitor out of the URL.
 *
 * The flood identifier is the Nuxt-proxied per-visitor identifier when the
 * request carries a valid service authorization header, otherwise the client
 * IP as resolved by Drupal's reverse proxy settings. Behind the UI server the
 * client IP is the UI container's address, so that fallback counts every
 * visitor against one budget; it is logged once per event and hour.
 *
 * Usage: classes using this trait must provide:
 * - a $flood property holding \Drupal\Core\Flood\FloodInterface;
 * - a $logger property holding \Psr\Log\LoggerInterface;
 * - a $pageCacheKillSwitch property holding
 *   \Drupal\Core\PageCache\ResponsePolicy\KillSwitch (service
 *   'page_cache_kill_switch');
 * - integer constants FLOOD_LIMIT (allowed events per window) and
 *   FLOOD_WINDOW (window length in seconds).
 * A missing member only fails on the rate-limited path, so cover the 429
 * branch of every new host class in its unit test.
 */
trait PublicApiFloodTrait {

  /**
   * Applies flood protection without caching an identifier-specific denial.
   */
  private function floodResponse(Request $request, string $event, ?int $limit = NULL): ?JsonResponse {
    $identifier = $this->trustedProxyFloodIdentifier($request);
    if ($identifier === NULL) {
      $identifier = $request->getClientIp() ?? 'unknown';
      $this->logClientAddressFallback($event);
    }
    if (!$this->flood->isAllowed($event, $limit ?? self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      // The plain (non-cacheable) JsonResponse is what keeps this 429 out of
      // page_cache and dynamic_page_cache: both skip responses that do not
      // implement CacheableResponseInterface, and FinishResponseSubscriber
      // then keeps the no-store header below for browsers and proxies. A
      // cacheable response would be stored by page_cache for cache_ttl_4xx
      // regardless of max-age, and Core would overwrite the header.
      // The kill switch is a no-op for this response type. It is kept as
      // defense in depth in case the denial ever becomes cacheable again.
      $this->pageCacheKillSwitch->trigger();
      $response = new JsonResponse([
        'error' => [
          'code' => 'rate_limit.exceeded',
          'message' => 'Too many requests. Please try again later.',
        ],
        'meta' => ['apiVersion' => '1.0'],
      ], Response::HTTP_TOO_MANY_REQUESTS);
      $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
      return $response;
    }

    $this->flood->register($event, self::FLOOD_WINDOW, $identifier);
    return NULL;
  }

  /**
   * Logs address fallback once per event and hour for serial requests.
   *
   * The guard is a flood entry keyed by the event, not by the caller, so no
   * script can fill the log. The message names the event only: no address,
   * no header value.
   */
  private function logClientAddressFallback(string $event): void {
    $guard = 'beantragt_schema.flood_fallback_warning';
    if (!$this->flood->isAllowed($guard, 1, 3600, $event)) {
      return;
    }
    // Check and register are intentionally non-atomic: parallel requests can
    // each log a warning. This harmless race is accepted.
    $this->flood->register($guard, 3600, $event);
    $this->logger->warning('Public API rate limit @event is counted per calling address: a request arrived without a valid service identifier of the UI server. Behind the UI server all visitors then share one budget.', ['@event' => $event]);
  }

  /**
   * Returns a Nuxt-proxied flood identifier after service-header validation.
   */
  private function trustedProxyFloodIdentifier(Request $request): ?string {
    $expected = $this->expectedServiceAuthorizationHeader();
    $provided = (string) $request->headers->get('X-Beantragt-Service-Authorization', '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
      return NULL;
    }

    $identifier = (string) $request->headers->get('X-Beantragt-Rate-Limit-Identifier', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $identifier)) {
      return NULL;
    }

    return 'proxy:' . $identifier;
  }

  /**
   * Builds the service secret value expected from the Nuxt proxy.
   */
  private function expectedServiceAuthorizationHeader(): string {
    $user = getenv('NUXT_DRUPAL_SERVICE_USER') ?: 'nuxt_service';
    $password = getenv('NUXT_DRUPAL_SERVICE_PASSWORD') ?: '';
    if ($password === '') {
      return '';
    }

    return 'Basic ' . base64_encode($user . ':' . $password);
  }

}
