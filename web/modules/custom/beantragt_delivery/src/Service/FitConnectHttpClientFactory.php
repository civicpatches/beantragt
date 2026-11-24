<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\Core\Http\ClientFactory;
use GuzzleHttp\ClientInterface;

/**
 * Builds the HTTP client used only for FIT-Connect calls.
 *
 * Hosts without general egress route FIT-Connect through an allowlisting
 * forward proxy. The proxy applies to this client alone, so other outbound
 * clients such as the RAG service keep their direct internal routes.
 */
final class FitConnectHttpClientFactory {

  public const PROXY_ENV = 'BEANTRAGT_FIT_CONNECT_PROXY';

  /**
   * Creates the client; an empty proxy variable means a direct connection.
   */
  public static function create(ClientFactory $factory): ClientInterface {
    return $factory->fromOptions(self::options(getenv(self::PROXY_ENV)));
  }

  /**
   * Returns Guzzle options for a validated proxy URL.
   *
   * @return array<string, mixed>
   *   Client options.
   */
  public static function options(string|false $proxy): array {
    $proxy = trim((string) $proxy);
    if ($proxy === '') {
      return [];
    }
    // Only a plain HTTP proxy without credentials or path, for example
    // http://fitconnect-egress:3128; TLS stays end to end through CONNECT.
    if (!preg_match('#^http://[a-z0-9]([a-z0-9.-]*[a-z0-9])?:[1-9][0-9]{0,4}$#', $proxy)) {
      throw new \InvalidArgumentException(self::PROXY_ENV . ' must look like http://host:port.');
    }
    return ['proxy' => ['http' => $proxy, 'https' => $proxy]];
  }

}
