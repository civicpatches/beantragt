<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the nginx access log filter for token-bearing routes.
 */
#[Group('beantragt_group')]
final class WorkspaceInviteAccessLogTest extends UnitTestCase {

  /**
   * Invite and status tokens are excluded, while ordinary paths are logged.
   */
  public function testAccessLogFilterExcludesTokenPaths(): void {
    $config = (string) file_get_contents(dirname(__DIR__, 7) . '/docker/nginx.conf');
    self::assertStringContainsString('access_log /var/log/nginx/access.log combined if=$log_public_status;', $config);
    self::assertSame(1, preg_match('/map \$request_uri \$log_public_status \{([^}]+)\}/', $config, $map));
    self::assertStringContainsString('default 1;', $map[1]);
    preg_match_all('/~\*(\S+) 0;/', $map[1], $rules);

    foreach ([
      '/api/workspace-invites/verify/example-token' => TRUE,
      '//api//workspace-invites//claim/example-token?source=demo' => TRUE,
      '/api/v1/status/example-token' => TRUE,
      '/api/application-status/example-token' => TRUE,
      '/api/workspace-invites' => FALSE,
      '/api/workspace-invites/123' => FALSE,
    ] as $uri => $excluded) {
      $matches = array_filter($rules[1], static fn (string $rule): bool => preg_match('#' . $rule . '#i', $uri) === 1);
      self::assertSame($excluded, $matches !== [], $uri);
    }
  }

}
