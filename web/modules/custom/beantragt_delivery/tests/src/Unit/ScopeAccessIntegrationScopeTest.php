<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies that integrations always resolve to the form's jurisdiction.
 */
#[Group('beantragt_delivery')]
final class ScopeAccessIntegrationScopeTest extends UnitTestCase {

  /**
   * A form of an organisation keeps its jurisdiction's integrations.
   */
  public function testOrganisationFormUsesJurisdictionDefinitions(): void {
    $access = new ScopeAccess($this->createMock(EntityTypeManagerInterface::class));
    $city = $this->group('3', 'jur');
    $other_city = $this->group('30', 'jur');
    $office = $this->group('7', 'org', $city);
    $foreign_office = $this->group('70', 'org', $other_city);
    $city_form = $this->entity(ContentEntityInterface::class, ['field_jurisdiction' => $city]);
    $office_form = $this->entity(ContentEntityInterface::class, [
      'field_organisation' => $office,
      'field_jurisdiction' => $city,
    ]);

    self::assertSame(3, $access->formIntegrationScope($city_form));
    self::assertSame(3, $access->formIntegrationScope($office_form));
    self::assertSame(0, $access->formIntegrationScope($this->entity(ContentEntityInterface::class, [])));

    // The case sits in the organisation of its form: the city's rules apply.
    self::assertSame(3, $access->integrationScope($this->application($office, $office_form)));
    // A city form routed to an organisation of the same city.
    self::assertSame(3, $access->integrationScope($this->application($office, $city_form)));
    self::assertSame(3, $access->integrationScope($this->application($city, $city_form)));
    // A case of another city never borrows these rules.
    self::assertSame(0, $access->integrationScope($this->application($foreign_office, $office_form)));
    self::assertSame(0, $access->integrationScope($this->application($other_city, $city_form)));
  }

  /**
   * Builds a group of a bundle, optionally with a parent jurisdiction.
   */
  private function group(string $id, string $bundle, ?GroupInterface $parent = NULL): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $group->method('bundle')->willReturn($bundle);
    $fields = $parent ? ['field_jurisdiction' => $parent] : [];
    $group->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $group->method('get')->willReturnCallback(fn(string $name): FieldItemListInterface => $this->field($fields[$name] ?? NULL));
    return $group;
  }

  /**
   * Builds an application in a case scope for a form.
   */
  private function application(GroupInterface $case, ContentEntityInterface $form): ApplicationInterface {
    $field = $case->bundle() === 'org' ? 'field_organisation' : 'field_jurisdiction';
    return $this->entity(ApplicationInterface::class, [$field => $case, 'field_schema' => $form]);
  }

  /**
   * Builds an entity mock with entity reference fields.
   *
   * @param class-string<T> $class
   *   The interface to mock.
   * @param array<string, object> $fields
   *   Referenced entities keyed by field name.
   *
   * @return T
   *   The mock.
   *
   * @template T of object
   */
  private function entity(string $class, array $fields): object {
    $entity = $this->createMock($class);
    $entity->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($fields[$name]));
    $entity->method('get')->willReturnCallback(fn(string $name): FieldItemListInterface => $this->field($fields[$name] ?? NULL));
    return $entity;
  }

  /**
   * Builds a reference field list pointing at one entity or nothing.
   */
  private function field(?object $target): FieldItemListInterface {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($target === NULL);
    $field->method('__isset')->willReturn($target !== NULL);
    $field->method('__get')->willReturn($target);
    return $field;
  }

}
