<?php

declare(strict_types=1);

namespace Drupal\beantragt_nachreichung\Code;

use Drupal\beantragt_application\Service\ApplicationDataProtectionService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Keyed hashes of cover sheet identifiers (Variant A: no clear copy).
 *
 * The key comes from the application data key material by HKDF with its own
 * info per key version (ApplicationDataProtectionService). A key check value
 * (KCV) per version in State turns a silent change of the key material into
 * a refusal to issue and an error in the status report, instead of making
 * every printed sheet unknown.
 */
class CoverCodeHasher {

  public const STATE_KEY = 'beantragt_nachreichung.key_checks';

  /**
   * Constructs the hasher.
   */
  public function __construct(
    private readonly ApplicationDataProtectionService $protection,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Lookup hash of a canonical identifier for a key version.
   */
  public function hash(string $canonical, int $version): string {
    return $this->protection->coverCodeLookupHash($canonical, $version);
  }

  /**
   * Key version for new identifiers (config key_version, 1 to 99).
   */
  public function currentVersion(): int {
    $version = $this->configFactory->get('beantragt_nachreichung.settings')->get('key_version');
    $version = is_numeric($version) ? (int) $version : 1;
    if ($version < 1 || $version > 99) {
      throw new \RuntimeException('Invalid cover code key version.');
    }
    return $version;
  }

  /**
   * Key check value of a version.
   */
  public function keyCheck(int $version): string {
    return $this->protection->coverCodeKeyCheck($version);
  }

  /**
   * Refuses to issue when the key material of a version changed.
   *
   * The first use of a version stores its KCV.
   */
  public function guard(int $version): void {
    $checks = $this->state->get(self::STATE_KEY, []);
    $checks = is_array($checks) ? $checks : [];
    $current = $this->keyCheck($version);
    $stored = $checks[$version] ?? NULL;
    if ($stored === NULL) {
      $checks[$version] = $current;
      $this->state->set(self::STATE_KEY, $checks);
      return;
    }
    if (!is_string($stored) || !hash_equals($stored, $current)) {
      $this->logger->error('Cover code key check failed for version @version.', ['@version' => (string) $version]);
      throw new \RuntimeException('Cover code key check failed.');
    }
  }

  /**
   * TRUE when identifiers of a version can still be resolved.
   *
   * The key material is there and its KCV equals the stored one (a version
   * without a stored KCV counts as resolvable).
   */
  public function resolvable(int $version): bool {
    try {
      $current = $this->keyCheck($version);
    }
    catch (\Throwable) {
      return FALSE;
    }
    $checks = $this->state->get(self::STATE_KEY, []);
    $stored = is_array($checks) ? ($checks[$version] ?? NULL) : NULL;
    return $stored === NULL || (is_string($stored) && hash_equals($stored, $current));
  }

}
