<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Unit;

use Drupal\beantragt_application\WorkflowStatusResolver;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests workflow status resolution for application entities.
 *
 * @group beantragt_application
 */
class WorkflowStatusResolverTest extends UnitTestCase {

  /**
   * Machine names are read from the referenced workflow_status entity.
   */
  public function testMachineNameReadsReferencedStatus(): void {
    $application = $this->applicationWithStatus($this->workflowStatusEntity('returned', TRUE));

    $this->assertSame('returned', WorkflowStatusResolver::machineName($application));
  }

  /**
   * Missing status references fall back to "new".
   */
  public function testMachineNameFallsBackWhenReferenceIsMissing(): void {
    $application = $this->createMock(EntityInterface::class);

    $this->assertSame('new', WorkflowStatusResolver::machineName($application));
  }

  /**
   * Terminality is read from the referenced status entity.
   */
  public function testIsTerminalReadsReferencedStatusFlag(): void {
    $terminal = $this->applicationWithStatus($this->workflowStatusEntity('approved', TRUE));
    $nonTerminal = $this->applicationWithStatus($this->workflowStatusEntity('returned', FALSE));

    $this->assertTrue(WorkflowStatusResolver::isTerminal($terminal));
    $this->assertFalse(WorkflowStatusResolver::isTerminal($nonTerminal));
  }

  /**
   * Builds an application whose status field resolves to a status entity.
   */
  private function applicationWithStatus(FieldableEntityInterface $status): FieldableEntityInterface {
    $status_field = $this->createMock(FieldItemListInterface::class);
    $status_field->method('isEmpty')->willReturn(FALSE);
    $status_field->method('__get')->willReturnCallback(
      static fn (string $name): ?FieldableEntityInterface => $name === 'entity' ? $status : NULL
    );

    $application = $this->createMock(FieldableEntityInterface::class);
    $application->method('hasField')->with('field_workflow_status')->willReturn(TRUE);
    $application->method('get')->with('field_workflow_status')->willReturn($status_field);
    return $application;
  }

  /**
   * Builds a workflow_status-like fieldable entity.
   */
  private function workflowStatusEntity(string $machine_name, bool $terminal): FieldableEntityInterface {
    $status = $this->createMock(FieldableEntityInterface::class);
    $status->method('hasField')->willReturnCallback(
      static fn (string $name): bool => in_array($name, ['field_machine_name', 'field_is_terminal'], TRUE)
    );
    $status->method('get')->willReturnCallback(function (string $name) use ($machine_name, $terminal): FieldItemListInterface {
      return match ($name) {
        'field_machine_name' => $this->fieldWithValue($machine_name),
        'field_is_terminal' => $this->fieldWithValue($terminal ? '1' : '0'),
        default => $this->fieldWithValue(NULL),
      };
    });
    return $status;
  }

  /**
   * Builds a field item list whose first item exposes a scalar value.
   */
  private function fieldWithValue(mixed $value): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn([['value' => $value]]);
    return $field;
  }

}
