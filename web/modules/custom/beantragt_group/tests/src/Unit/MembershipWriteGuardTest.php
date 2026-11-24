<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins who may write memberships and site roles, and who calls them.
 *
 * Memberships require explicit grants. Entity access checks do not cover
 * programmatic saves, so this test lists every authorized writer and the
 * callers of writers that take an account or a group.
 *
 * A write is any of: ->addMember(, ->addRole(, ->addRelationship(, a create
 * of a group relationship through storage or the entity class,
 * set('group_roles', set('roles', and a 'roles' key in an account create.
 * A new one anywhere in non-test code under web/modules/custom or in the PHP
 * files under scripts/ fails this test and needs a contract that names
 * person, group and role.
 */
#[Group('beantragt_group')]
final class MembershipWriteGuardTest extends UnitTestCase {

  /**
   * The approved writers: file below web/modules/custom => functions.
   *
   * @var array<string, list<string>>
   */
  private const WRITERS = [
    // The grant service: the one product path that grants a
    // Fachadministration (site role and admin membership).
    'beantragt_group/src/Service/FachadministrationGrant.php' => ['grant'],
    // The invitation claim: member roles of one target group only.
    'beantragt_group/src/Controller/WorkspaceInviteController.php' => ['grantInviteRoles'],
    // The demo login: the persona's site roles except department_admin.
    // Memberships it writes only through the grant service.
    'beantragt_demo/src/Service/DemoOtpService.php' => ['authenticateDemoUser', 'prepareDemoUser'],
    // The service account and its role.
    'beantragt_application/beantragt_application.install' => ['_beantragt_application_ensure_service_account'],
    // An account created at login: role "authenticated" only.
    'beantragt_passwordless/src/Service/OtpService.php' => ['authenticateUser'],
    // An account created at SSO login: role "authenticated" only.
    'beantragt_sso/src/Service/SsoIdentityLinker.php' => ['resolveOrCreateUser'],
  ];

  /**
   * Who may call an approved writer that takes an account or a group.
   *
   * Callee => sorted "file below web/modules/custom::function" entries.
   *
   * @var array<string, list<string>>
   */
  private const CALLERS = [
    'grantInviteRoles' => [
      'beantragt_group/src/Controller/WorkspaceInviteController.php::claimInvite',
    ],
    'grantMunicipality' => [
      'beantragt_demo/src/Service/DemoOtpService.php::ensureDeclaredScope',
      'beantragt_group/src/Commands/FachadministrationCommands.php::grant',
    ],
    'grantOffice' => [
      'beantragt_demo/src/Service/DemoOtpService.php::ensureDeclaredScope',
      'beantragt_group/src/Commands/FachadministrationCommands.php::grant',
    ],
  ];

  /**
   * The approved writers among the scripts: file below scripts/ => functions.
   *
   * @var array<string, list<string>>
   */
  private const SCRIPT_WRITERS = [];

  /**
   * Only the approved writers write memberships or roles.
   */
  public function testOnlyTheNamedWritersWriteMembershipsOrRoles(): void {
    $expected = self::WRITERS;
    // The optional demo module is absent from the public source tree.
    if (!is_dir($this->root . '/modules/custom/beantragt_demo')) {
      unset($expected['beantragt_demo/src/Service/DemoOtpService.php']);
    }
    $this->assertSame($this->sorted($expected), $this->findWriters($this->root . '/modules/custom'));
  }

  /**
   * Only the approved callers call the approved writers.
   *
   * A sweep that used the uid 1 helper for every group would appear here as
   * an unapproved caller.
   */
  public function testOnlyTheNamedCallersCallTheWriters(): void {
    $expected = self::CALLERS;
    if (!is_dir($this->root . '/modules/custom/beantragt_demo')) {
      foreach ($expected as $callee => $callers) {
        $expected[$callee] = array_values(array_filter($callers, static fn(string $caller): bool => !str_starts_with($caller, 'beantragt_demo/')));
      }
    }
    $this->assertSame($expected, $this->findCallers($this->root . '/modules/custom', array_keys(self::CALLERS)));
  }

  /**
   * Only the approved scripts write memberships or roles.
   */
  public function testOnlyTheNamedScriptsWriteMembershipsOrRoles(): void {
    $this->assertSame($this->sorted(self::SCRIPT_WRITERS), $this->findWriters(dirname($this->root) . '/scripts'));
  }

  /**
   * The example workspace is the only public fixture that writes roles.
   */
  public function testExampleWorkspaceMembershipWriters(): void {
    $expected = [
      'lib/workspace.php' => ['beantragt_demo_ensure_group_role'],
      'workspace.php' => ['(file level)'],
    ];
    $this->assertSame($expected, $this->findWriters(dirname($this->root) . '/deploy/selfhost/example'));
  }

  /**
   * Both group types keep the creation wizard.
   *
   * With the wizard flag a programmatic group create adds nobody, so an
   * office created by the product has no members, not even its creator.
   */
  public function testGroupTypesKeepTheCreatorWizard(): void {
    foreach (['jur', 'org'] as $type) {
      $config = Yaml::parseFile(dirname(__DIR__, 3) . '/config/install/group.type.' . $type . '.yml');
      $this->assertTrue($config['creator_wizard'] ?? NULL, $type);
    }
  }

  /**
   * The scan sees every spelling and every caller, and skips tests.
   *
   * Guards the guard: fixture files outside the approved lists must show up.
   */
  public function testTheScanSeesEverySpelling(): void {
    $fixture = sys_get_temp_dir() . '/membership-write-guard-' . bin2hex(random_bytes(4));
    $files = [
      'm/src/Members.php' => "<?php\nclass Members {\n  public function one(\$g, \$u) {\n    \$g->addMember(\$u);\n  }\n  public function two(\$g, \$u) {\n    \$g->addRelationship(\$u, 'group_membership');\n  }\n}\n",
      'm/m.module' => "<?php\nfunction m_role(\$u) {\n  \$u->addRole('x');\n}\nfunction m_set(\$u, \$r) {\n  \$r->set('group_roles', ['org-admin']);\n  \$u->set('roles', ['x']);\n}\n",
      'm/m.install' => "<?php\nfunction m_create() {\n  \\Drupal::entityTypeManager()->getStorage('group_relationship')->create([]);\n  \$user = User::create([\n    'name' => 'n',\n    'roles' => ['x'],\n  ]);\n}\nfunction m_plain() {\n  \$user = User::create([\n    'name' => 'n',\n  ]);\n}\nfunction m_caller(\$g, \$u) {\n  _beantragt_group_ensure_demo_admin_group_role(\$g, \$u);\n}\n",
      'm/tests/src/WriterTest.php' => "<?php\n\$g->addMember(\$u);\n_beantragt_group_ensure_demo_admin_group_role(\$g, \$u);\n",
    ];
    foreach ($files as $path => $content) {
      @mkdir(dirname($fixture . '/' . $path), 0700, TRUE);
      file_put_contents($fixture . '/' . $path, $content);
    }
    try {
      $this->assertSame([
        'm/m.install' => ['m_create'],
        'm/m.module' => ['m_role', 'm_set'],
        'm/src/Members.php' => ['one', 'two'],
      ], $this->findWriters($fixture));
      $this->assertSame(
        ['_beantragt_group_ensure_demo_admin_group_role' => ['m/m.install::m_caller']],
        $this->findCallers($fixture, ['_beantragt_group_ensure_demo_admin_group_role']),
      );
    }
    finally {
      foreach (array_keys($files) as $path) {
        unlink($fixture . '/' . $path);
      }
      foreach (['/m/tests/src', '/m/tests', '/m/src', '/m', ''] as $dir) {
        rmdir($fixture . $dir);
      }
    }
  }

  /**
   * Finds non-test PHP files that write, with the enclosing functions.
   *
   * @return array<string, list<string>>
   *   Relative path => sorted names of the enclosing functions, sorted.
   */
  private function findWriters(string $base): array {
    $found = [];
    foreach ($this->phpFiles($base) as $relative => $lines) {
      $function = NULL;
      $in_account_create = FALSE;
      foreach ($lines as $line) {
        if ($this->isComment($line)) {
          continue;
        }
        if (preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(/', $line, $match)) {
          $function = $match[1];
        }
        $write = preg_match('/->add(?:Member|Role|Relationship)\(/', $line)
          || preg_match('/getStorage\(\s*[\'"]group_relationship[\'"]\s*\)->create\(|Group(?:Relationship|Membership)::create\(/', $line)
          || preg_match('/->set\(\s*[\'"](?:group_)?roles[\'"]/', $line);
        // A 'roles' key inside an account create, which may span lines.
        if (preg_match('/User::create\(|getStorage\(\s*[\'"]user[\'"]\s*\)->create\(|user\w*->create\(/i', $line)) {
          $in_account_create = TRUE;
        }
        if ($in_account_create && preg_match('/[\'"]roles[\'"]\s*=>/', $line)) {
          $write = TRUE;
        }
        if ($in_account_create && preg_match('/\]\s*\)/', $line)) {
          $in_account_create = FALSE;
        }
        if ($write) {
          $found[$relative][] = $function ?? '(file level)';
        }
      }
    }
    return $this->sorted($found);
  }

  /**
   * Finds the callers of the given functions in non-test PHP files.
   *
   * @param string $base
   *   The folder to scan.
   * @param list<string> $callees
   *   Function or method names.
   *
   * @return array<string, list<string>>
   *   Callee => sorted "relative path::enclosing function" entries.
   */
  private function findCallers(string $base, array $callees): array {
    $found = array_fill_keys($callees, []);
    foreach ($this->phpFiles($base) as $relative => $lines) {
      $function = NULL;
      foreach ($lines as $line) {
        if ($this->isComment($line)) {
          continue;
        }
        if (preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(/', $line, $match)) {
          $function = $match[1];
          continue;
        }
        foreach ($callees as $callee) {
          if (preg_match('/(?<![\w$])' . preg_quote($callee, '/') . '\s*\(/', $line)) {
            $found[$callee][] = $relative . '::' . ($function ?? '(file level)');
          }
        }
      }
    }
    foreach ($found as $callee => $callers) {
      $callers = array_values(array_unique($callers));
      sort($callers);
      $found[$callee] = $callers;
    }
    ksort($found);
    return $found;
  }

  /**
   * TRUE for a comment line: a docblock or a line comment.
   */
  private function isComment(string $line): bool {
    return (bool) preg_match('#^\s*(?:\*|/\*|//|\#)#', $line);
  }

  /**
   * The non-test PHP files below a folder, as lines, keyed by relative path.
   *
   * @return array<string, list<string>>
   *   Relative path => lines.
   */
  private function phpFiles(string $base): array {
    if (!is_dir($base)) {
      return [];
    }
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($base) + 1);
      if (str_contains('/' . $relative, '/tests/')
        || str_contains('/' . $relative, '/node_modules/')
        || !preg_match('/\.(php|module|install|inc|theme)$/', $relative)) {
        continue;
      }
      $files[$relative] = file($file->getPathname()) ?: [];
    }
    ksort($files);
    return $files;
  }

  /**
   * Sorts a file => functions map by file and each function list.
   *
   * @param array<string, list<string>> $map
   *   The map.
   *
   * @return array<string, list<string>>
   *   The sorted map, functions unique.
   */
  private function sorted(array $map): array {
    foreach ($map as $file => $functions) {
      $functions = array_values(array_unique($functions));
      sort($functions);
      $map[$file] = $functions;
    }
    ksort($map);
    return $map;
  }

}
