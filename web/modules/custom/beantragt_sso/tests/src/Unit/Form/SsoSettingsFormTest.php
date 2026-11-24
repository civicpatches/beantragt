<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_sso\Unit\Form;

use Drupal\beantragt_sso\Form\SsoSettingsForm;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the SSO provider settings form structure.
 */
#[CoversClass(SsoSettingsForm::class)]
#[Group('beantragt_sso')]
final class SsoSettingsFormTest extends UnitTestCase {

  /**
   * The machine name source must be built before the machine name element.
   */
  public function testProviderLabelPrecedesMachineName(): void {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key): mixed => match ($key) {
        'default_provider' => 'bundid',
        'providers.bundid' => ['label' => 'BundID'],
        default => NULL,
      },
    );
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('getEditable')
      ->with('beantragt_sso.settings')
      ->willReturn($config);

    $form = (new SsoSettingsForm(
      $config_factory,
      $this->createMock(TypedConfigManagerInterface::class),
    ))->buildForm([], new FormState());

    $keys = array_keys($form);
    self::assertLessThan(
      array_search('default_provider', $keys, TRUE),
      array_search('label', $keys, TRUE),
    );
    self::assertSame(['label'], $form['default_provider']['#machine_name']['source']);
  }

  /**
   * E-mail linking needs an explicit mailbox-verification assertion.
   */
  public function testUnverifiedEmailLinkingIsRejected(): void {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $form_state = new FormState();
    $form_state->setValues([
      'default_relay_path' => '/',
      'enabled' => FALSE,
      'link_by_email' => TRUE,
      'email_verified' => FALSE,
      'profile' => 'generic',
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $form = new SsoSettingsForm(
      $config_factory,
      $this->createMock(TypedConfigManagerInterface::class),
    );

    $form_array = [];
    $form->validateForm($form_array, $form_state);

    self::assertArrayHasKey('link_by_email', $form_state->getErrors());
  }

}
