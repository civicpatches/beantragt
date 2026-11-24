<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_delivery\Kernel;

use Drupal\beantragt_delivery\Service\IntegrationCredentialProfiles;
use Drupal\beantragt_delivery\Service\IntegrationRepository;
use Drupal\beantragt_delivery\Service\IntegrationRules;
use Drupal\beantragt_delivery\Service\ScopeAccess;
use Drupal\beantragt_delivery\Value\FitConnectSettings;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Php;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;

use Drupal\Core\Database\Connection;
use Drupal\Tests\beantragt_delivery\SyntheticDataContractRegistry;
use Drupal\Tests\UnitTestCase;

/**
 * Builds isolated repository dependencies in a competing database process.
 */
final class IntegrationRepositoryWorker extends UnitTestCase {

  /**
   * Builds the real repository with deterministic synthetic entity fixtures.
   */
  public function build(Connection $database, ?string $root = NULL, ?array $manifest = NULL): IntegrationRepository {
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturnCallback(fn(): string => '1');
    $group->method('bundle')->willReturn('jur');
    $group_storage = $this->createMock(EntityStorageInterface::class);
    $group_storage->method('load')->willReturn($group);
    $group_field = $this->createMock(FieldItemListInterface::class);
    $group_field->method('isEmpty')->willReturn(FALSE);
    $group_field->method('__isset')->willReturn(TRUE);
    $group_field->method('__get')->willReturn($group);
    $schema_field = $this->createMock(FieldItemListInterface::class);
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/us116-complete-fim-mapping.json'), TRUE);
    $schema_field->method('__get')->willReturn(json_encode($fixture['form'], JSON_THROW_ON_ERROR));
    $form = $this->createMock(ContentEntityInterface::class);
    $form->method('uuid')->willReturn('11111111-1111-4111-8111-111111111111');
    $form->method('id')->willReturn('10');
    $form->method('hasField')->willReturnCallback(static fn(string $field): bool => $field === 'field_group');
    $form->method('get')->willReturnCallback(static fn(string $field): FieldItemListInterface => $field === 'field_schema' ? $schema_field : $group_field);
    $form_storage = $this->createMock(ContentEntityStorageInterface::class);
    $form_storage->method('loadRevision')->willReturn($form);
    $form_storage->method('load')->willReturn($form);
    $form_storage->method('loadUnchanged')->willReturn($form);
    $form_storage->method('loadByProperties')->willReturn([$form]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([['group', $group_storage], ['form_schema', $form_storage]]);
    $registry = new SyntheticDataContractRegistry(contractDirectory: $root ?? dirname(__DIR__, 2) . '/fixtures/synthetic-contracts');
    if ($manifest !== NULL) {
      $registry->pinFixture($manifest);
    }
    $profiles = new IntegrationCredentialProfiles($this->getConfigFactoryStub([
      'beantragt_delivery.settings' => [
        'credential_profiles' => [
          'default' => [
            'all_scopes' => TRUE,
            'allowed_scopes' => [],
            'label' => 'Synthetic test operator',
            'client_id' => 'fake-client',
            'client_secret_env' => 'FAKE_US116_SECRET',
            'token_url' => FitConnectSettings::DEFAULT_TEST_TOKEN_URL,
            'submission_api_base' => FitConnectSettings::DEFAULT_TEST_SUBMISSION_API_BASE,
            'metadata_schema_version' => '2.1.0',
          ],
        ],
      ],
    ]));
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(100);
    return new IntegrationRepository($database, new Php(), $time, $manager, new ScopeAccess($manager), $profiles, $registry, new IntegrationRules($registry));
  }

}
