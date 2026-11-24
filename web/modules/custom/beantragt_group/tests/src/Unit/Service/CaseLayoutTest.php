<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit\Service;

use Drupal\beantragt_group\Service\CaseLayout;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the complete, portable case layout and internal route contracts.
 */
#[CoversClass(CaseLayout::class)]
#[Group('beantragt_group')]
final class CaseLayoutTest extends UnitTestCase {

  /**
   * Complete layouts include optional pro sections even in an open build.
   */
  public function testStrictCompletePartition(): void {
    $layout = ['version' => 1, 'left' => CaseLayout::SECTIONS, 'right' => []];
    $this->assertTrue(CaseLayout::valid($layout));
    $moved = $layout;
    $moved['right'][] = array_shift($moved['left']);
    $this->assertTrue(CaseLayout::valid($moved));
    foreach ([
      NULL,
      [],
      $layout + ['extra' => TRUE],
      array_replace($layout, ['version' => '1']),
      array_replace($layout, ['version' => 2]),
      array_replace($layout, ['left' => array_slice(CaseLayout::SECTIONS, 1)]),
      array_replace($layout, ['right' => ['attachments']]),
      array_replace($layout, ['left' => ['data', ...array_slice(CaseLayout::SECTIONS, 1)]]),
      array_replace($layout, ['left' => ['unknown', ...array_slice(CaseLayout::SECTIONS, 1)]]),
      array_replace($layout, ['left' => [FALSE, ...array_slice(CaseLayout::SECTIONS, 1)]]),
      array_replace($layout, ['left' => ['key' => 'attachments', ...array_slice(CaseLayout::SECTIONS, 1)]]),
    ] as $invalid) {
      $this->assertFalse(CaseLayout::valid($invalid));
    }
  }

  /**
   * Native jurisdiction editing shares the same staff settings validator.
   */
  public function testWorkspaceStorageValidation(): void {
    $validator = new CaseLayout();
    $layout = ['version' => 1, 'left' => CaseLayout::SECTIONS, 'right' => []];
    $this->assertSame('staffForms', $validator->sectionName());
    $this->assertSame([], $validator->validateSection(['caseLayouts' => [12 => $layout]]));
    $this->assertSame([], $validator->validateSection(['caseLayouts' => []]));
    foreach ([[], ['unknown' => []], ['caseLayouts' => ['bad' => $layout]], ['caseLayouts' => [12 => []]]] as $invalid) {
      $this->assertNotEmpty($validator->validateSection($invalid));
    }
  }

  /**
   * Both mutation paths use session authentication and Drupal's CSRF gate.
   */
  public function testInternalRoutesRequireCookiesAndCsrf(): void {
    $routes = Yaml::parseFile(__DIR__ . '/../../../../beantragt_group.routing.yml');
    foreach (['case_layout_get', 'case_layout_patch', 'case_layout_workspace_patch'] as $id) {
      $route = $routes['beantragt_group.' . $id];
      $this->assertSame(['cookie'], $route['options']['_auth']);
      $this->assertSame('TRUE', $route['requirements']['_user_is_logged_in']);
      $this->assertTrue($route['options']['no_cache']);
      if ($route['methods'] === ['PATCH']) {
        $this->assertSame('TRUE', $route['requirements']['_csrf_request_header_token']);
      }
    }
  }

}
