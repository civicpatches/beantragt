<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_schema\Kernel;

use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\beantragt_schema\Repository\FormSetupLog;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the append-only guided form setup log.
 */
#[CoversClass(FormSetupLog::class)]
#[Group('beantragt_schema')]
final class FormSetupLogTest extends KernelTestBase {

  private const FORM_A = '0b1f0c9e-7c1d-4e8a-9a51-3c2b1d0e9f01';
  private const FORM_B = '0b1f0c9e-7c1d-4e8a-9a51-3c2b1d0e9f02';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'options',
    'serialization',
    'jsonapi',
    'json_form_widget',
    'beantragt_roles_api',
    'beantragt_schema',
  ];

  /**
   * The log under test.
   */
  private FormSetupLog $log;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('form_schema');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('beantragt_schema', [FormSetupLog::TABLE]);
    $this->log = $this->container->get('beantragt_schema.form_setup_log');
  }

  /**
   * Appended decisions are read back; the latest row per key wins.
   */
  public function testLatestDecisionPerKey(): void {
    $this->log->appendDecisions(self::FORM_A, 10, 'dog', [
      $this->decision('krankheit', 'privacy.kind', 'other_personal', 'other'),
      $this->decision('', 'wording', [['id' => 'krankheit', 'label' => 'Krankheit', 'placeholder' => NULL]], 'kept'),
    ], 7);
    $this->log->appendDecisions(self::FORM_A, 11, 'dog', [
      $this->decision('krankheit', 'privacy.kind', 'special_category', 'accepted') + [
        'origin' => 'rule',
        'origin_ref' => 'privacy-catalog:label:health',
        'recommended_value' => 'special_category',
      ],
    ], 8);
    $this->log->appendDecisions(self::FORM_B, 3, 'dog', [
      $this->decision('krankheit', 'privacy.kind', 'identity', 'other'),
    ], 9);

    $latest = $this->log->latestDecisions(self::FORM_A);

    self::assertCount(2, $latest);
    [$wording, $kind] = $latest;
    self::assertSame('wording', $wording['property']);
    self::assertSame([['id' => 'krankheit', 'label' => 'Krankheit', 'placeholder' => NULL]], $wording['value']);
    self::assertSame('privacy.kind', $kind['property']);
    self::assertSame('special_category', $kind['value']);
    self::assertSame('accepted', $kind['choice']);
    self::assertSame(11, $kind['revision_id']);
    self::assertSame(8, $kind['uid']);
    self::assertSame('rule', $kind['origin']);
    self::assertSame('privacy-catalog:label:health', $kind['origin_ref']);
    self::assertSame('special_category', $kind['recommended_value']);
    self::assertSame('decision', $kind['kind']);
    self::assertNull($kind['run_slot']);
  }

  /**
   * A wording snapshot of a full section fits the value column.
   *
   * 100 fields (the field cap) with 350 character labels and placeholders
   * pass schema validation. With umlauts the JSON is far above 65,535 bytes,
   * the limit of a MariaDB TEXT column; it must round-trip unchanged.
   */
  public function testFullSectionWordingSnapshotFitsTheValueColumn(): void {
    $wording = [];
    for ($index = 0; $index < 100; $index++) {
      $wording[] = [
        'id' => sprintf('feld_%03d', $index),
        'label' => mb_substr(sprintf('Bezeichnung %03d ', $index) . str_repeat('ä', 350), 0, 350),
        'placeholder' => mb_substr(sprintf('Platzhalter %03d ', $index) . str_repeat('ö', 350), 0, 350),
      ];
    }
    $bytes = strlen(json_encode($wording, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    self::assertGreaterThan(65535, $bytes, 'The snapshot must exceed the TEXT limit to prove the column size.');

    $this->log->appendDecisions(self::FORM_A, 10, 'dog', [
      $this->decision('', 'wording', $wording, 'kept'),
      $this->decision('krankheit', 'privacy.kind', 'special_category', 'other'),
    ], 7);

    $latest = $this->log->latestDecisions(self::FORM_A);
    self::assertCount(2, $latest);
    self::assertSame('wording', $latest[0]['property']);
    self::assertSame($wording, $latest[0]['value']);
  }

  /**
   * Exactly one run row per form; a second run rolls back its rows.
   */
  public function testOneRunPerFormAndRollback(): void {
    $manifest = [
      'documents' => [['id' => self::FORM_B, 'version' => 'revision:31:changed:1762600000']],
      'kept' => 0,
      'dropped' => 4,
    ];
    self::assertSame(0, $this->log->appendRun(self::FORM_A, 10, 'model-x', [], $manifest));
    self::assertSame($manifest, $this->log->run(self::FORM_A)['value']);
    self::assertSame('model-x', $this->log->run(self::FORM_A)['model_id']);

    try {
      $this->log->appendRun(self::FORM_A, 10, 'model-x', [$this->recommendation('nachweis')], $manifest);
      self::fail('A second run row was stored.');
    }
    catch (IntegrityConstraintViolationException) {
      // Expected: the unique key (form_uuid, run_slot) refuses it.
    }

    self::assertSame([], $this->log->recommendations(self::FORM_A), 'The rows of the refused run were rolled back.');
    self::assertSame(1, $this->countRows(self::FORM_A, 'run'));

    // Many rows with run_slot NULL coexist, and another form has its own run.
    $this->log->appendDecisions(self::FORM_A, 10, 'dog', [
      $this->decision('a', 'sensitive', TRUE, 'kept'),
      $this->decision('b', 'sensitive', TRUE, 'kept'),
    ], 7);
    self::assertSame(2, $this->log->appendRun(self::FORM_B, 4, 'model-x', [
      $this->recommendation('nachweis'),
      $this->recommendation('attest'),
    ], $manifest));
    self::assertSame(2, $this->countRows(self::FORM_A, 'decision'));
    self::assertCount(2, $this->log->recommendations(self::FORM_B));
    self::assertSame(1, $this->countRows(self::FORM_B, 'run'));
  }

  /**
   * Rows are deleted with their form, other forms keep theirs.
   */
  public function testRowsAreDeletedWithTheForm(): void {
    $form = $this->container->get('entity_type.manager')->getStorage('form_schema')->create([
      'label' => 'Hundesteuer',
      'field_schema' => json_encode(['title' => 'Hundesteuer', 'sections' => []], JSON_THROW_ON_ERROR),
      'field_workflow_status' => 'draft',
      'status' => FALSE,
    ]);
    assert($form instanceof FormSchemaInterface);
    $form->save();
    $uuid = (string) $form->uuid();
    $this->log->appendDecisions($uuid, (int) $form->getRevisionId(), 'dog', [$this->decision('a', 'sensitive', TRUE, 'kept')], 7);
    $this->log->appendRun($uuid, (int) $form->getRevisionId(), 'model-x', [$this->recommendation('nachweis')], [
      'documents' => [],
      'kept' => 1,
      'dropped' => 0,
    ]);
    $this->log->appendDecisions(self::FORM_B, 3, 'dog', [$this->decision('a', 'sensitive', TRUE, 'kept')], 7);

    $form->delete();

    self::assertSame(0, $this->countRows($uuid));
    self::assertSame(1, $this->countRows(self::FORM_B));
  }

  /**
   * The repository offers no way to change a stored row.
   */
  public function testNoUpdatePath(): void {
    $public = array_map(
      static fn(\ReflectionMethod $method): string => $method->getName(),
      (new \ReflectionClass(FormSetupLog::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
    );
    foreach ($public as $name) {
      self::assertDoesNotMatchRegularExpression('/^(update|set|change|edit|replace|merge|upsert)/i', $name);
    }
    self::assertContains('appendDecisions', $public);
    self::assertContains('appendRun', $public);
  }

  /**
   * A decision row.
   *
   * @return array<string, mixed>
   *   Row values.
   */
  private function decision(string $field_id, string $property, mixed $value, string $choice): array {
    return ['field_id' => $field_id, 'property' => $property, 'value' => $value, 'choice' => $choice];
  }

  /**
   * A recommendation row.
   *
   * @return array<string, mixed>
   *   Row values.
   */
  private function recommendation(string $field_id): array {
    return [
      'screen_id' => 'dog',
      'field_id' => $field_id,
      'property' => 'analysis.expectedDocumentType',
      'value' => 'Kopie der Haftpflichtversicherung',
      'origin' => 'model',
      'quote' => 'Bitte legen Sie eine Kopie der Haftpflichtversicherung bei.',
      'basis_hash' => str_repeat('a', 64),
      'source_id' => self::FORM_B,
      'source_title' => 'Merkblatt Hundesteuer',
      'source_version' => 'revision:31:changed:1762600000',
      'source_page' => 2,
    ];
  }

  /**
   * Counts the rows of a form, optionally of one kind.
   */
  private function countRows(string $form_uuid, ?string $kind = NULL): int {
    $query = $this->container->get('database')->select(FormSetupLog::TABLE, 'l')
      ->condition('form_uuid', $form_uuid);
    if ($kind !== NULL) {
      $query->condition('kind', $kind);
    }
    return (int) $query->countQuery()->execute()->fetchField();
  }

}
