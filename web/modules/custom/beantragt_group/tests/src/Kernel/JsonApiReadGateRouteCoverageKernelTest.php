<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Kernel;

use Drupal\beantragt_schema\Routing\JsonApiRouteSubscriber;
use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;

/**
 * Every JSON:API route of the shipped module set falls into a known kind.
 *
 * Reads the final router, after every route-alter subscriber of the shipped
 * extension set has run and the access checks are compiled. A route kind
 * that core or contrib adds later fits no row and fails this test instead of
 * shipping without the read gate.
 */
#[Group('beantragt_group')]
#[Group('beantragt_schema')]
final class JsonApiReadGateRouteCoverageKernelTest extends GroupKernelTestBase {

  private const GATE_SERVICE = 'beantragt_schema.jsonapi_read_gate';

  /**
   * The shipped extension set, written out.
   *
   * The standard profile's install list, the modules of
   * recipes/beantragt_core/recipe.yml, scripts/setup-local.sh and
   * scripts/install-public-demo.sh with their dependencies, and every
   * directory under web/modules/custom except beantragt_demo. Kept by hand;
   * testModuleListCoversInstallSources() fails when a source enables more.
   * jsonapi_extras is on disk (composer.json) but enabled by none of them,
   * so it is not in the list.
   *
   * @var string[]
   */
  protected static $modules = [
    // Core: the standard profile and dependencies. Sites installed on 11.3
    // keep toolbar, shortcut, history, comment and search; fresh 11.4 installs
    // add navigation with layout_builder and layout_discovery.
    'announcements_feed', 'automated_cron', 'basic_auth', 'big_pipe', 'block',
    'block_content', 'breakpoint', 'ckeditor5', 'comment', 'config',
    'contextual', 'datetime', 'dblog', 'dynamic_page_cache', 'editor', 'field',
    'field_ui', 'file', 'filter', 'help', 'history', 'image', 'jsonapi',
    'language', 'layout_builder', 'layout_discovery', 'link',
    'menu_link_content', 'menu_ui', 'mysql', 'navigation', 'node',
    'options', 'page_cache', 'path', 'path_alias', 'search', 'serialization',
    'shortcut', 'system', 'taxonomy', 'text', 'toolbar', 'user', 'views',
    'views_ui',
    // Contrib.
    'encrypt', 'entity', 'field_encrypt', 'flexible_permissions', 'group',
    'json_form_widget', 'key', 'real_aes', 'select2', 'select_or_other',
    // Custom, every directory except beantragt_demo.
    'beantragt_applicant', 'beantragt_application',
    'beantragt_comment', 'beantragt_compute', 'beantragt_compute_api',
    'beantragt_content', 'beantragt_delivery',
    'beantragt_docgen', 'beantragt_form_schema_group', 'beantragt_group',
    'beantragt_maintenance', 'beantragt_nachreichung',
    'beantragt_notification', 'beantragt_passwordless', 'beantragt_roles',
    'beantragt_roles_api', 'beantragt_schema', 'beantragt_sso',
    'beantragt_status', 'beantragt_workflow', 'beantragt_workflow_status',
  ];

  /**
   * Route kinds of contract table 2.1 and whether the gate applies.
   */
  private const KINDS = [
    'entry point' => FALSE,
    'collection GET' => TRUE,
    'related GET' => TRUE,
    'relationship' => TRUE,
    'file upload, existing entity' => TRUE,
    'file upload, new entity' => FALSE,
    'collection POST' => FALSE,
    'individual' => FALSE,
  ];

  /**
   * Resource types that must carry at least one gated route.
   */
  private const REQUIRED_TYPES = [
    'application--application',
    'applicant--applicant',
    'application_comment--application_comment',
    'application_attachment--application_attachment',
    'form_schema--form_schema',
    'form_schema_group--form_schema_group',
    'workflow_status--workflow_status',
    'user--user',
    'file--file',
    'group--jur',
    'group--org',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The shipped dblog keeps whatever the rebuild logs.
    $this->installSchema('dblog', ['watchdog']);
    // The menu tree is rebuilt after the router and reads menu link content.
    $this->installEntitySchema('menu_link_content');
    // Group types and their fields give group--jur and group--org routes.
    $this->installConfig(['user', 'beantragt_group']);
    // The kernel test route provider builds the router on its first lookup.
    // Enter through the provider so Navigation can look up its menu routes
    // during that build without starting a second one.
    $this->container->get('router.route_provider')->getRouteByName('jsonapi.group--jur.collection');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Errors logged while building the router would hide a broken route set.
    $errors = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['type', 'message', 'variables'])
      ->condition('severity', 3, '<=')
      ->execute()
      ->fetchAll();
    parent::tearDown();
    $this->assertSame([], array_map(
      static fn (object $row): string => $row->type . ': ' . strtr($row->message, array_map('strval', unserialize($row->variables, ['allowed_classes' => FALSE]) ?: [])),
      $errors,
    ));
  }

  /**
   * The written-out list covers every custom module directory.
   */
  public function testModuleListCoversEveryCustomModule(): void {
    $directories = array_filter(
      scandir(DRUPAL_ROOT . '/modules/custom') ?: [],
      static fn (string $name): bool => !str_starts_with($name, '.')
        && $name !== 'beantragt_demo'
        && is_file(DRUPAL_ROOT . "/modules/custom/$name/$name.info.yml"),
    );
    $this->assertNotSame([], $directories);
    $this->assertSame([], array_values(array_diff($directories, static::$modules)), 'Custom modules missing from the shipped set.');
  }

  /**
   * The list contains everything the install sources enable, dependencies too.
   */
  public function testModuleListCoversInstallSources(): void {
    $repository = dirname(DRUPAL_ROOT);
    $sources = [
      'standard profile' => Yaml::decode((string) file_get_contents(DRUPAL_ROOT . '/core/profiles/standard/standard.info.yml'))['install'] ?? [],
      'beantragt_core recipe' => Yaml::decode((string) file_get_contents($repository . '/recipes/beantragt_core/recipe.yml'))['install'] ?? [],
      'self-host installer' => $this->shellList($repository . '/deploy/selfhost/install.sh', '/drush pm:enable --yes [^\n]*\n(.*?)\n\n/s'),
    ];
    $wanted = [];
    foreach ($sources as $source => $names) {
      $this->assertNotSame([], $names, "Nothing parsed from $source.");
      $wanted = [...$wanted, ...$names];
    }
    $modules = $this->container->get('extension.list.module')->getList();
    $missing = [];
    while ($wanted !== []) {
      $name = array_pop($wanted);
      if ($name === 'beantragt_demo' || isset($missing[$name])) {
        continue;
      }
      $missing[$name] = !in_array($name, static::$modules, TRUE);
      $this->assertArrayHasKey($name, $modules, "$name is not on disk.");
      foreach (array_keys($modules[$name]->requires ?? []) as $dependency) {
        $wanted[] = (string) $dependency;
      }
    }
    $this->assertSame([], array_keys(array_filter($missing)), 'Enabled by an install source but missing from the shipped set.');
  }

  /**
   * Every route under the JSON:API base path or controller carries the flag.
   *
   * Subscriber and coverage test both recognise JSON:API routes by the
   * _is_jsonapi default. A route that runs a JSON:API controller or sits
   * under /jsonapi without it would be neither gated nor seen.
   */
  public function testJsonApiPathsAndControllersCarryTheFlag(): void {
    $base = rtrim((string) $this->container->getParameter('jsonapi.base_path'), '/');
    $this->assertSame('/jsonapi', $base);
    $seen = 0;
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      $controller = (string) $route->getDefault('_controller');
      $path = $route->getPath();
      if ($path === $base || str_starts_with($path, $base . '/')
        || str_starts_with($controller, 'jsonapi.')
        || str_starts_with(ltrim($controller, '\\'), 'Drupal\\jsonapi\\')) {
        $this->assertTrue($route->getDefault('_is_jsonapi') === TRUE, "$name ($path, $controller) lacks _is_jsonapi.");
        $seen++;
      }
    }
    $this->assertGreaterThan(100, $seen);
  }

  /**
   * Every JSON:API route fits exactly one kind; gated kinds check first.
   */
  public function testEveryJsonApiRouteFitsOneKind(): void {
    $gated_types = [];
    $counts = array_fill_keys(array_keys(self::KINDS), 0);
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      if ($route->getDefault('_is_jsonapi') !== TRUE) {
        $this->assertNotContains(self::GATE_SERVICE, $route->getOption('_access_checks') ?? [], $name);
        continue;
      }
      $kinds = $this->kindsOf((string) $name, $route);
      $this->assertCount(1, $kinds, $name . ' fits ' . count($kinds) . ' kinds: ' . implode(', ', $kinds));
      $kind = $kinds[0];
      $counts[$kind]++;
      $checks = $route->getOption('_access_checks') ?? [];
      if (self::KINDS[$kind]) {
        $this->assertSame(self::GATE_SERVICE, $checks[0] ?? NULL, $name . ' must check the gate first.');
        $this->assertSame(JsonApiRouteSubscriber::READ_GATE_REQUIREMENT, array_key_first($route->getRequirements()), $name);
        $gated_types[] = (string) $route->getDefault('resource_type');
      }
      else {
        $this->assertNotContains(self::GATE_SERVICE, $checks, $name . ' must not carry the gate.');
        $this->assertArrayNotHasKey(JsonApiRouteSubscriber::READ_GATE_REQUIREMENT, $route->getRequirements(), $name);
      }
    }

    foreach ($counts as $kind => $count) {
      $this->assertGreaterThan(0, $count, "No route of kind '$kind' in the router.");
    }
    foreach (self::REQUIRED_TYPES as $type) {
      $this->assertContains($type, $gated_types, "No gated route for $type.");
    }
  }

  /**
   * No bundle of the shipped set is translatable.
   *
   * A translated organisation can have a relationship PATCH route only when
   * a shipped bundle enables translations. The content_translation module
   * and bundle-info alter hooks can set the flag checked here.
   */
  public function testNoBundleIsTranslatable(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('content_translation'));
    $translatable = [];
    $bundle_info = $this->container->get('entity_type.bundle.info');
    foreach ($this->container->get('entity_type.manager')->getDefinitions() as $entity_type_id => $definition) {
      foreach ($bundle_info->getBundleInfo($entity_type_id) as $bundle => $info) {
        if (!empty($info['translatable'])) {
          $translatable[] = "$entity_type_id.$bundle";
        }
      }
    }
    $this->assertSame([], $translatable);
  }

  /**
   * Reads module names out of a shell list in an install script.
   *
   * @return string[]
   *   Module names.
   */
  private function shellList(string $file, string $pattern): array {
    $this->assertFileExists($file);
    preg_match_all($pattern, (string) file_get_contents($file), $blocks);
    $names = [];
    foreach ($blocks[1] as $block) {
      preg_match_all('/\b[a-z][a-z0-9_]*\b/', $block, $tokens);
      $names = [...$names, ...$tokens[0]];
    }
    return array_values(array_unique($names));
  }

  /**
   * Returns the kinds of contract table 2.1 a JSON:API route matches.
   *
   * @return string[]
   *   Matching kind labels.
   */
  private function kindsOf(string $name, Route $route): array {
    $controller = (string) $route->getDefault('_controller');
    $matches = [
      'entry point' => $name === 'jsonapi.resource_list',
      'collection GET' => $controller === 'jsonapi.entity_resource:getCollection',
      'related GET' => $controller === 'jsonapi.entity_resource:getRelated',
      'relationship' => $route->getDefault('_on_relationship') === TRUE,
      'file upload, existing entity' => $controller === 'jsonapi.file_upload:handleFileUploadForExistingResource',
      'file upload, new entity' => $controller === 'jsonapi.file_upload:handleFileUploadForNewResource',
      'collection POST' => $controller === 'jsonapi.entity_resource:createIndividual',
      'individual' => in_array($controller, [
        'jsonapi.entity_resource:getIndividual',
        'jsonapi.entity_resource:patchIndividual',
        'jsonapi.entity_resource:deleteIndividual',
      ], TRUE),
    ];
    return array_keys(array_filter($matches));
  }

}
