<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Service\AttachmentScanResult;
use Drupal\beantragt_application\Service\AttachmentStorageQuota;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests attachment quota aggregates against real SQL storage.
 */
#[CoversClass(AttachmentStorageQuota::class)]
#[Group('beantragt_application')]
#[RunTestsInSeparateProcesses]
final class AttachmentStorageQuotaKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'datetime',
    'file',
    'jsonapi',
    'json_form_widget',
    'language',
    'serialization',
    'beantragt_schema',
    'beantragt_workflow_status',
    'beantragt_application',
    'beantragt_group',
    'beantragt_roles_api',
    'beantragt_workflow',
    'beantragt_notification',
    'beantragt_comment',
  ];

  /**
   * Quota service under test.
   */
  private AttachmentStorageQuota $quota;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('file');
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installEntitySchema('application_attachment');
    $this->installEntitySchema('application_comment');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);

    $this->quota = $this->container->get('beantragt_application.attachment_storage_quota');
  }

  /**
   * The aggregate matches the previous entity-by-entity size calculation.
   */
  public function testAggregateMatchesPreviousEntityBasedSum(): void {
    $this->insertForm(11, 7);
    $this->insertForm(12, 8);
    $this->insertApplication(101, 11, 7);
    $this->insertApplication(102, 12, 8);
    $this->insertFile(501, 200);
    $this->insertFile(502, 125);
    $this->insertFile(503, 75);
    $this->insertFile(505, 50);

    $sizes = [125, 0, -25, 875];
    foreach ($sizes as $delta => $size) {
      $this->insertAttachment(
        1001 + $delta,
        11,
        101,
        $size,
        'claimed',
        fileId: $delta === 0 ? 502 : NULL,
        derivativeFileId: $delta < 2 ? 503 : NULL,
      );
    }
    $this->insertAttachment(2001, 12, 102, 5000, 'claimed', fileId: 505);
    $this->insertComment(301, 101, [501, 501, 502, 503, 505]);
    $this->insertComment(302, 101, [506]);

    $previous_attachment_sum = array_sum(array_map(
      static fn(int $size): int => max(0, $size),
      $sizes,
    ));
    $previous_derivative_sum = 75;
    $previous_legacy_comment_sum = 200 + 200 + 50;

    $this->assertSame([
      'usedStorageBytes' => $previous_attachment_sum + $previous_derivative_sum + $previous_legacy_comment_sum,
      'attachmentCount' => count($sizes) + 3,
    ], $this->quota->usageForJurisdiction($this->jurisdiction(7)));
  }

  /**
   * Pending bytes are filtered to the jurisdiction before aggregation.
   */
  public function testPendingAggregateIsJurisdictionScoped(): void {
    $this->insertForm(11, 7);
    $this->insertForm(12, 8);
    $this->insertForm(13, NULL, 701);
    $this->insertForm(14, NULL, NULL, 7);
    $this->insertForm(15, NULL, NULL, 702);
    $this->insertForm(16, NULL, 801);
    $this->insertOrganisationScope(701, 7);
    $this->insertOrganisationScope(702, 7);
    $this->insertOrganisationScope(801, 8);
    $this->insertApplication(101, 11, 7);
    $now = $this->container->get('datetime.time')->getRequestTime();

    $this->insertAttachment(1001, 11, NULL, 100, 'uploaded', AttachmentScanResult::STATUS_CLEAN, $now + 300);
    $this->insertAttachment(1002, 11, NULL, 200, 'uploaded', AttachmentScanResult::STATUS_CLEAN, $now - 1);
    $this->insertAttachment(1003, 11, NULL, 300, 'uploaded', AttachmentScanResult::STATUS_QUARANTINED, $now - 1);
    $this->insertAttachment(1004, 11, 101, 400, 'uploaded', AttachmentScanResult::STATUS_CLEAN, $now + 300);
    $this->insertAttachment(1005, 11, NULL, 500, 'claimed', AttachmentScanResult::STATUS_CLEAN, $now + 300);
    $this->insertAttachment(2001, 12, NULL, 1000, 'uploaded', AttachmentScanResult::STATUS_CLEAN, $now + 300);
    $this->insertAttachment(1006, 13, NULL, 50, 'uploaded', AttachmentScanResult::STATUS_CLEAN, $now + 300);
    $this->insertAttachment(1007, 14, NULL, 60, 'uploaded', AttachmentScanResult::STATUS_CLEAN, $now + 300);
    $this->insertAttachment(1008, 15, NULL, 70, 'uploaded', AttachmentScanResult::STATUS_CLEAN, $now + 300);
    $this->insertAttachment(2002, 16, NULL, 1100, 'uploaded', AttachmentScanResult::STATUS_CLEAN, $now + 300);

    $this->assertSame(
      580,
      $this->quota->pendingUploadBytesForJurisdiction($this->jurisdiction(7)),
    );
  }

  /**
   * Inserts a minimal form schema row for an aggregate fixture.
   */
  private function insertForm(
    int $id,
    ?int $jurisdictionId,
    ?int $organisationId = NULL,
    ?int $groupId = NULL,
  ): void {
    $this->container->get('database')->insert('form_schema')
      ->fields([
        'id' => $id,
        'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
        'uid' => 0,
        'status' => 1,
        'label' => 'Quota form ' . $id,
        'field_jurisdiction' => $jurisdictionId,
        'field_organisation' => $organisationId,
        'field_group' => $groupId,
      ])
      ->execute();
  }

  /**
   * Inserts an organisation-to-jurisdiction scope relation.
   */
  private function insertOrganisationScope(int $organisationId, int $jurisdictionId): void {
    $this->container->get('database')->insert('group__field_jurisdiction')
      ->fields([
        'bundle' => 'org',
        'deleted' => 0,
        'entity_id' => $organisationId,
        'revision_id' => $organisationId,
        'langcode' => 'en',
        'delta' => 0,
        'field_jurisdiction_target_id' => $jurisdictionId,
      ])
      ->execute();
  }

  /**
   * Inserts a minimal application row for an aggregate fixture.
   */
  private function insertApplication(int $id, int $schemaId, int $jurisdictionId): void {
    $this->container->get('database')->insert('application')
      ->fields([
        'id' => $id,
        'uuid' => sprintf('10000000-0000-4000-8000-%012d', $id),
        'uid' => 0,
        'status' => 1,
        'field_schema' => $schemaId,
        'field_jurisdiction' => $jurisdictionId,
      ])
      ->execute();
  }

  /**
   * Inserts a minimal attachment row for an aggregate fixture.
   */
  private function insertAttachment(
    int $id,
    int $schemaId,
    ?int $applicationId,
    int $size,
    string $uploadStatus,
    string $scanStatus = AttachmentScanResult::STATUS_CLEAN,
    ?int $claimExpires = NULL,
    ?int $fileId = NULL,
    ?int $derivativeFileId = NULL,
  ): void {
    $this->container->get('database')->insert('application_attachment')
      ->fields([
        'id' => $id,
        'uuid' => sprintf('20000000-0000-4000-8000-%012d', $id),
        'uid' => 0,
        'field_file__target_id' => $fileId,
        'field_schema' => $schemaId,
        'field_application' => $applicationId,
        'field_field_id' => 'evidence',
        'field_claim_expires' => $claimExpires,
        'field_upload_status' => $uploadStatus,
        'field_analysis_status' => 'not_requested',
        'field_scan_status' => $scanStatus,
        'field_size' => $size,
        'field_guard_derivative__target_id' => $derivativeFileId,
      ])
      ->execute();
  }

  /**
   * Inserts a managed file row for aggregate fixtures.
   */
  private function insertFile(int $id, int $size): void {
    $this->container->get('database')->insert('file_managed')
      ->fields([
        'fid' => $id,
        'uuid' => sprintf('30000000-0000-4000-8000-%012d', $id),
        'langcode' => 'en',
        'uid' => 0,
        'filename' => 'quota-' . $id . '.pdf',
        'uri' => 'private://quota-' . $id . '.pdf',
        'filemime' => 'application/pdf',
        'filesize' => $size,
        'status' => 1,
        'created' => 1,
        'changed' => 1,
      ])
      ->execute();
  }

  /**
   * Inserts one comment and its file reference rows.
   *
   * @param int $id
   *   Comment ID.
   * @param int $applicationId
   *   ID of the application the comment belongs to.
   * @param int[] $fileIds
   *   Referenced file IDs in field order.
   */
  private function insertComment(int $id, int $applicationId, array $fileIds): void {
    $this->container->get('database')->insert('application_comment')
      ->fields([
        'id' => $id,
        'uuid' => sprintf('40000000-0000-4000-8000-%012d', $id),
        'uid' => 0,
        'status' => 1,
        'field_application' => $applicationId,
        'field_body' => 'Quota fixture',
        'field_author_role' => 'citizen',
        'field_visibility' => 'public',
        'created' => 1,
      ])
      ->execute();

    $insert = $this->container->get('database')->insert('application_comment__field_attachments')
      ->fields([
        'bundle',
        'deleted',
        'entity_id',
        'revision_id',
        'langcode',
        'delta',
        'field_attachments_target_id',
      ]);
    foreach ($fileIds as $delta => $file_id) {
      $insert->values([
        'application_comment',
        0,
        $id,
        $id,
        'en',
        $delta,
        $file_id,
      ]);
    }
    $insert->execute();
  }

  /**
   * Builds a jurisdiction reference for service calls.
   */
  private function jurisdiction(int $id): GroupInterface {
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('id')->willReturn($id);
    return $jurisdiction;
  }

}
