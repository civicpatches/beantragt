<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_application\Kernel;

use Drupal\beantragt_application\Controller\EmailConfirmationController;
use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_schema\Entity\FormSchemaInterface;
use Drupal\Tests\group\Kernel\GroupKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises the confirmation response against real token and scope storage.
 */
#[Group('beantragt_application')]
final class EmailConfirmationKernelTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth', 'datetime', 'file', 'jsonapi', 'json_form_widget',
    'serialization', 'beantragt_schema', 'beantragt_workflow_status',
    'beantragt_application', 'beantragt_group', 'beantragt_roles_api',
    'beantragt_form_schema_group',
  ];

  /**
   * Tests first claim, replay at expiry, and generic token rejection.
   */
  public function testConfirmationResponseUsesOnlyTheTokenApplication(): void {
    $this->installConfig(['user', 'beantragt_group', 'beantragt_application']);
    $this->installEntitySchema('form_schema');
    $this->installEntitySchema('form_schema_group');
    $this->installEntitySchema('workflow_status');
    $this->installEntitySchema('application');
    $this->installSchema('beantragt_application', ['beantragt_ref_sequence']);
    $this->container->get('module_handler')->loadInclude('beantragt_group', 'install');
    _beantragt_group_ensure_slug_field($this->entityTypeManager());
    $this->setCurrentUser($this->createUser(['bypass beantragt group scope']));

    $jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Musterstadt',
      'field_slug' => 'musterstadt',
    ]);
    $organisation = $this->createGroup([
      'type' => 'org',
      'label' => 'Musteramt',
      'field_slug' => 'musteramt',
      'field_jurisdiction' => $jurisdiction->id(),
    ]);
    $other_jurisdiction = $this->createGroup([
      'type' => 'jur',
      'label' => 'Beispielstadt',
      'field_slug' => 'beispielstadt',
    ]);
    $now = $this->container->get('datetime.time')->getRequestTime();
    $token = str_repeat('A', 64);
    $other_token = str_repeat('B', 64);
    $application = $this->createApplication($token, $now, 'MST-2026-00001', [
      'field_jurisdiction' => $jurisdiction->id(),
      'field_organisation' => $organisation->id(),
    ]);
    $this->createApplication($other_token, $now, 'BST-2026-00002', [
      'field_jurisdiction' => $other_jurisdiction->id(),
    ]);
    $controller = EmailConfirmationController::create($this->container);
    $context = [
      'referenceNumber' => 'MST-2026-00001',
      'jurisdictionSlug' => 'musterstadt',
      'organisationSlug' => 'musteramt',
      'receipt' => [
        'referenceNumber' => 'MST-2026-00001',
        'formTitle' => 'Bestätigungsformular',
        'submittedAt' => '2026-11-21T13:24:05Z',
        'jurisdictionName' => 'Musterstadt',
        'organisationName' => 'Musteramt',
      ],
    ];
    $this->assertSame(['status' => 'confirmed'] + $context, $this->confirm($controller, $token, 200));
    $this->assertSame(['status' => 'already_confirmed'] + $context, $this->confirm($controller, $token, 200));

    // A valid token for another application resolves only that application,
    // even with the first application's reference and an unrelated URL hint.
    $this->assertSame([
      'status' => 'confirmed',
      'referenceNumber' => 'BST-2026-00002',
      'jurisdictionSlug' => 'beispielstadt',
      'receipt' => [
        'referenceNumber' => 'BST-2026-00002',
        'formTitle' => 'Bestätigungsformular',
        'submittedAt' => '2026-11-21T13:24:05Z',
        'jurisdictionName' => 'Beispielstadt',
        'organisationName' => NULL,
      ],
    ], $this->confirm($controller, $other_token, 200));

    $legacy_token = str_repeat('D', 64);
    $legacy = $this->createApplication($legacy_token, $now, 'BST-2026-00003', [
      'field_jurisdiction' => $other_jurisdiction->id(),
    ]);
    $this->container->get('database')->update('application')
      ->fields(['field_receipt_form_title' => NULL])
      ->condition('id', $legacy->id())
      ->execute();
    $this->assertSame([
      'status' => 'confirmed',
      'referenceNumber' => 'BST-2026-00003',
      'jurisdictionSlug' => 'beispielstadt',
      'receipt' => NULL,
    ], $this->confirm($controller, $legacy_token, 200));

    $invalid = ['error' => ['code' => 'confirmation.invalid']];
    $this->assertSame($invalid, $this->confirm($controller, str_repeat('C', 64), 404));
    $this->assertSame($invalid, $this->confirm($controller, 'malformed', 404));
    $this->container->get('database')->update('application')
      ->fields(['field_email_confirmation_expires' => $now - 1])
      ->condition('id', $application->id())
      ->execute();
    $this->assertSame($invalid, $this->confirm($controller, $token, 404));
  }

  /**
   * Stores a pending application with an exact expiry timestamp.
   */
  private function createApplication(string $token, int $expiry, string $reference, array $scope): ApplicationInterface {
    $form = $this->entityTypeManager()->getStorage('form_schema')->create($scope + [
      'label' => 'Bestätigungsformular',
      'field_schema' => json_encode([
        'title' => 'Bestätigungsformular',
        'sections' => [
          [
            'id' => 'main',
            'title' => 'Angaben',
            'fields' => [
              [
                'id' => 'message',
                'type' => 'text',
                'label' => 'Nachricht',
                'privacy' => ['kind' => 'not_personal'],
              ],
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR),
      'field_publication_scope' => 'internet',
      'field_workflow_status' => 'published',
      'status' => TRUE,
    ]);
    assert($form instanceof FormSchemaInterface);
    $form->save();
    $application = $this->entityTypeManager()->getStorage('application')->create($scope + [
      'field_schema' => $form->id(),
      'field_reference_number' => $reference,
      'field_data' => '{"message":"fixture-only"}',
      'field_applicant_email' => 'antrag@example.org',
      'field_email_confirmed' => FALSE,
      'field_email_confirmation_token_hash' => hash('sha256', $token),
      'field_email_confirmation_expires' => $expiry,
      'created' => strtotime('2026-11-21T13:24:05Z'),
      'status' => TRUE,
    ]);
    $application->save();
    $form->set('label', 'Späterer Titel')->save();
    assert($application instanceof ApplicationInterface);
    return $application;
  }

  /**
   * Calls the controller with untrusted scope hints and checks cache headers.
   */
  private function confirm(EmailConfirmationController $controller, string $token, int $expectedStatus): array {
    $request = Request::create(
      '/api/v1/public/applications/confirm',
      'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: json_encode([
        'token' => $token,
        'referenceNumber' => 'MST-2026-00001',
        'jurisdictionSlug' => 'unzugehoerig',
        'organisationSlug' => 'unzugehoerig',
      ], JSON_THROW_ON_ERROR),
    );
    $response = $controller->confirm($request);
    $this->assertSame($expectedStatus, $response->getStatusCode());
    $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    return json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
  }

}
