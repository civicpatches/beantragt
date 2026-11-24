<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_applicant\Unit\Install;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Process\Process;

/**
 * Tests the applicant encryption requirements on a fresh installation.
 *
 * @group beantragt_applicant
 */
final class RequirementsTest extends UnitTestCase {

  /**
   * The original encryption-key environment value.
   */
  private string|false $originalKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalKey = getenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    require_once dirname(__DIR__, 4) . '/beantragt_applicant.install';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalKey === FALSE) {
      putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY');
    }
    else {
      putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=' . $this->originalKey);
    }
    parent::tearDown();
  }

  /**
   * A valid fresh-install key passes before module services exist.
   */
  public function testValidInstallKeyPassesWithoutContainerService(): void {
    $project_root = dirname(DRUPAL_ROOT);
    $autoload_file = $project_root . '/vendor/autoload.php';
    $install_file = dirname(__DIR__, 4) . '/beantragt_applicant.install';
    $script = sprintf(
      <<<'PHP'
require %s;
require %s;
if (beantragt_applicant_requirements('install') !== []) {
  fwrite(STDERR, "Valid install key was rejected.\n");
  exit(3);
}
fwrite(STDOUT, 'ok');
PHP,
      var_export($autoload_file, TRUE),
      var_export($install_file, TRUE),
    );
    $process = new Process(
      [PHP_BINARY, '-r', $script],
      $project_root,
      ['BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY' => str_repeat('a', 64)],
    );

    $process->run();

    $this->assertTrue(
      $process->isSuccessful(),
      $process->getErrorOutput() . $process->getOutput(),
    );
    $this->assertSame('ok', $process->getOutput());
  }

  /**
   * An invalid fresh-install key still fails closed.
   */
  public function testInvalidInstallKeyFailsClosed(): void {
    putenv('BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY=too-short');

    $requirements = beantragt_applicant_requirements('install');

    $this->assertSame(
      RequirementSeverity::Error,
      $requirements['beantragt_applicant_encryption_key']['severity'],
    );
  }

}
