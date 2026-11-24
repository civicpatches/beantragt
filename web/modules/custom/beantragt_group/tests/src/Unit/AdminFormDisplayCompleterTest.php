<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_group\Unit;

use Drupal\beantragt_group\Service\AdminFormDisplayCompleter;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests completion of workspace entity form displays.
 */
#[Group('beantragt_group')]
final class AdminFormDisplayCompleterTest extends UnitTestCase {

  /**
   * Adds only editable, configurable fields with a default widget.
   */
  public function testCompleteAddsMissingConfigurableFields(): void {
    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->method('getFieldDefinitions')->with('group', 'jur')->willReturn([
      'field_platform_name' => $this->fieldDefinition('string'),
      'field_existing' => $this->fieldDefinition('email'),
      'field_computed' => $this->fieldDefinition('string', computed: TRUE),
      'field_read_only' => $this->fieldDefinition('string', read_only: TRUE),
      'field_hidden' => $this->fieldDefinition('string', configurable: FALSE),
      'field_without_widget' => $this->fieldDefinition('unsupported'),
    ]);

    $field_type_manager = $this->createMock(FieldTypePluginManagerInterface::class);
    $field_type_manager->method('getDefinition')->willReturnMap([
      ['string', FALSE, ['default_widget' => 'string_textfield']],
      ['unsupported', FALSE, []],
    ]);

    $display = $this->createMock(EntityFormDisplayInterface::class);
    $display->method('getMode')->willReturn('default');
    $display->method('getComponent')->willReturnCallback(
      static fn (string $name): ?array => $name === 'field_existing'
        ? ['type' => 'email_default']
        : NULL,
    );
    $display->expects(self::once())
      ->method('setComponent')
      ->with('field_platform_name', ['type' => 'string_textfield'])
      ->willReturnSelf();

    $completer = new AdminFormDisplayCompleter($field_manager, $field_type_manager);

    self::assertSame(1, $completer->complete($display, 'group', 'jur'));
  }

  /**
   * Ignores entity types and bundles outside the workspace model.
   */
  public function testCompleteIgnoresUnsupportedDisplays(): void {
    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->expects(self::never())->method('getFieldDefinitions');
    $field_type_manager = $this->createMock(FieldTypePluginManagerInterface::class);
    $display = $this->createMock(EntityFormDisplayInterface::class);
    $display->method('getMode')->willReturn('default');

    $completer = new AdminFormDisplayCompleter($field_manager, $field_type_manager);

    self::assertSame(0, $completer->complete($display, 'application', 'application'));
    self::assertSame(0, $completer->complete($display, 'group', 'other'));
  }

  /**
   * Leaves restricted non-default form modes unchanged.
   */
  public function testCompleteIgnoresNonDefaultFormModes(): void {
    $field_manager = $this->createMock(EntityFieldManagerInterface::class);
    $field_manager->expects(self::never())->method('getFieldDefinitions');
    $field_type_manager = $this->createMock(FieldTypePluginManagerInterface::class);
    $display = $this->createMock(EntityFormDisplayInterface::class);
    $display->method('getMode')->willReturn('restricted');

    $completer = new AdminFormDisplayCompleter($field_manager, $field_type_manager);

    self::assertSame(0, $completer->complete($display, 'group', 'jur'));
  }

  /**
   * Creates a field definition mock.
   */
  private function fieldDefinition(
    string $type,
    bool $computed = FALSE,
    bool $read_only = FALSE,
    bool $configurable = TRUE,
  ): FieldDefinitionInterface {
    $field = $this->createMock(FieldDefinitionInterface::class);
    $field->method('getType')->willReturn($type);
    $field->method('isComputed')->willReturn($computed);
    $field->method('isReadOnly')->willReturn($read_only);
    $field->method('isDisplayConfigurable')->with('form')->willReturn($configurable);

    return $field;
  }

}
