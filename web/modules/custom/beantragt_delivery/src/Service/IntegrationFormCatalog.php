<?php

declare(strict_types=1);

namespace Drupal\beantragt_delivery\Service;

use Drupal\beantragt_delivery\Exception\IntegrationException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides bounded source metadata from explicitly authorized form revisions.
 */
final class IntegrationFormCatalog {

  /**
   * Constructs the source catalog.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $manager,
    private readonly Connection $database,
    private readonly ScopeAccess $access,
    private readonly AccountProxyInterface $account,
  ) {}

  /**
   * Resolves a current form's administrative scope without leaking foreign IDs.
   *
   * The scope is the form's jurisdiction, also for a form of an organisation.
   */
  public function scope(string $uuid): int {
    $form = $this->current($uuid);
    $scope = $this->access->formIntegrationScope($form);
    if (!$this->access->allowsAdministration($scope, $this->account)) {
      throw new IntegrationException('integration.form_schema_not_found', 404);
    }
    return $scope;
  }

  /**
   * Lists 200 scoped forms and at most 100 revision summaries per form.
   */
  public function list(int $scope): array {
    if (!$this->access->allowsAdministration($scope, $this->account)) {
      throw new IntegrationException('integration.scope_not_found', 404);
    }
    $storage = $this->manager->getStorage('form_schema');
    $query = $this->scoped($storage->getQuery()->accessCheck(FALSE), $scope);
    $total = (int) (clone $query)->count()->execute();
    $ids = $query->sort('changed', 'DESC')->sort('uuid')->range(0, 200)->execute();
    $data = [];
    foreach ($storage->loadMultiple($ids) as $form) {
      if (!$form instanceof ContentEntityInterface) {
        throw new IntegrationException('integration.form_schema_invalid');
      }
      $revisions = $this->scoped($storage->getQuery()->accessCheck(FALSE)->allRevisions(), $scope)
        ->condition('id', $form->id());
      $revision_total = (int) (clone $revisions)->count()->execute();
      $revision_ids = array_keys($revisions->sort('revision_id', 'DESC')->range(0, 100)->execute());
      $summaries = [];
      if ($revision_ids !== []) {
        $table = $this->manager->getDefinition('form_schema')->getRevisionTable();
        $rows = $this->database->select($table, 'r')->fields('r', ['revision_id', 'changed'])
          ->condition('revision_id', $revision_ids, 'IN')->orderBy('revision_id', 'DESC')->execute();
        foreach ($rows as $row) {
          $summaries[] = ['revision_id' => (int) $row->revision_id, 'changed' => (int) $row->changed];
        }
      }
      $data[] = [
        'uuid' => $form->uuid(),
        'title' => $form->label(),
        'current_revision_id' => (int) $form->getRevisionId(),
        'revisions' => $summaries,
        'revisions_truncated' => $revision_total > count($summaries),
        'revisions_total' => $revision_total,
      ];
    }
    return ['data' => $data, 'meta' => ['total' => $total, 'truncated' => $total > count($data)]];
  }

  /**
   * Reads field definitions from the exact revision and checks both scopes.
   */
  public function fields(string $uuid, int $revision): array {
    $scope = $this->scope($uuid);
    $storage = $this->manager->getStorage('form_schema');
    $form = $storage instanceof RevisionableStorageInterface ? $storage->loadRevision($revision) : NULL;
    if (!$form instanceof ContentEntityInterface || $form->uuid() !== strtolower($uuid)
      || $this->access->formIntegrationScope($form) !== $scope) {
      throw new IntegrationException('integration.form_schema_not_found', 404);
    }
    $schema = json_decode((string) $form->get('field_schema')->value, TRUE, 64, JSON_THROW_ON_ERROR);
    $fields = [];
    foreach ($schema['sections'] ?? [] as $section) {
      foreach ($section['fields'] ?? [] as $field) {
        if (count($fields) >= 1000) {
          throw new IntegrationException('integration.source_fields_limit', 422);
        }
        $options = NULL;
        if (isset($field['options'])) {
          $options = [];
          foreach ($field['options'] as $option) {
            $options[] = ['value' => (string) $option, 'label' => (string) $option];
          }
        }
        $fields[] = [
          'id' => (string) $field['id'],
          'label' => (string) ($field['label'] ?? $field['id']),
          'type' => (string) $field['type'],
          'required' => ($field['required'] ?? FALSE) === TRUE,
          'options' => $options,
          'section_title' => (string) ($section['title'] ?? ''),
        ];
      }
    }
    return $fields;
  }

  /**
   * Lists the forms of a jurisdiction and its organisations.
   *
   * Mirrors ScopeAccess::formIntegrationScope(). Organisation forms always
   * carry their jurisdiction; legacy forms without one count by their group.
   */
  private function scoped(QueryInterface $query, int $scope): QueryInterface {
    $legacy = $query->andConditionGroup()->notExists('field_jurisdiction')->notExists('field_organisation')->condition('field_group', $scope);
    return $query->condition($query->orConditionGroup()->condition('field_jurisdiction', $scope)->condition($legacy));
  }

  /**
   * Loads exactly one current form by UUID.
   */
  private function current(string $uuid): ContentEntityInterface {
    $forms = $this->manager->getStorage('form_schema')->loadByProperties(['uuid' => strtolower($uuid)]);
    $form = reset($forms);
    if (!$form instanceof ContentEntityInterface) {
      throw new IntegrationException('integration.form_schema_not_found', 404);
    }
    return $form;
  }

}
