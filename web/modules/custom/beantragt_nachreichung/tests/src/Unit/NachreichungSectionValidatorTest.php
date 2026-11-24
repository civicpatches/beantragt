<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_nachreichung\Unit;

use Drupal\beantragt_nachreichung\Service\NachreichungSectionValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Section "nachreichung": coverSheet is a bool, nothing else is allowed.
 */
#[Group('beantragt_nachreichung')]
final class NachreichungSectionValidatorTest extends UnitTestCase {

  /**
   * Valid and invalid sections.
   */
  public function testValidation(): void {
    $validator = new NachreichungSectionValidator(NULL);
    $this->assertSame('nachreichung', $validator->sectionName());
    $this->assertSame([], $validator->validateSection([]));
    $this->assertSame([], $validator->validateSection(['coverSheet' => TRUE]));
    $this->assertSame([], $validator->validateSection(['coverSheet' => FALSE]));
    foreach ([1, 'true', NULL, []] as $wrong) {
      $this->assertSame(['nachreichung.coverSheet must be true or false.'], $validator->validateSection(['coverSheet' => $wrong]));
    }
    $foreign = ['coverSheet' => TRUE, 'holidayRegion' => 'DE-NW'];
    $this->assertSame(['Unknown nachreichung setting.'], $validator->validateSection($foreign));
  }

}
