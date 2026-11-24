<?php

declare(strict_types=1);

/**
 * Privacy tests for application comment notifications.
 *
 * PHP version 8
 *
 * @category Tests
 * @package DrupalTestsbeantragt_CommentUnit
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GPL-2.0-or-later
 * @link https://www.drupal.org/project/drupal
 */

namespace Drupal\Tests\beantragt_comment\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests privacy defaults for application comment notifications.
 *
 * @category Tests
 * @package DrupalTestsbeantragt_CommentUnit
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GPL-2.0-or-later
 * @link https://www.drupal.org/project/drupal
 */
#[Group('beantragt_comment')]
class ApplicationCommentNotificationPrivacyTest extends UnitTestCase {

  /**
   * Safe comment notification templates do not include free-text bodies.
   *
   * @return void
   *   No return value.
   */
  public function testSafeMailTemplatesExcludeCommentBodyToken(): void {
    include_once dirname(__DIR__, 3) . '/beantragt_comment.install';

    $templates = _beantragt_comment_safe_mail_templates();

    foreach ($templates as $template) {
      $this->assertStringNotContainsString(
            '@comment_body',
            $template['subject']
        );
      $this->assertStringNotContainsString('@comment_body', $template['body']);
    }
  }

  /**
   * Legacy template values are scrubbed without keeping body tokens.
   *
   * @return void
   *   No return value.
   */
  public function testScrubTemplateValuesRemovesCommentBodyTokens(): void {
    include_once dirname(__DIR__, 3) . '/beantragt_comment.install';

    $templates = _beantragt_comment_safe_mail_templates();
    $safe_template = $templates['application_comment_staff'];

    $scrubbed = _beantragt_comment_scrub_template_values(
          [
            'subject' => 'Legacy @comment_body',
            'body' => 'Legacy body @comment_body',
          ],
          $safe_template
      );

    $this->assertIsArray($scrubbed);
    $this->assertSame($safe_template['subject'], $scrubbed['subject']);
    $this->assertSame($safe_template['body'], $scrubbed['body']);
  }

  /**
   * Safe custom subjects survive when only the body is unsafe.
   *
   * @return void
   *   No return value.
   */
  public function testScrubTemplateValuesPreservesSafeSubject(): void {
    include_once dirname(__DIR__, 3) . '/beantragt_comment.install';

    $templates = _beantragt_comment_safe_mail_templates();
    $safe_template = $templates['application_comment_staff'];

    $scrubbed = _beantragt_comment_scrub_template_values(
          [
            'subject' => 'Custom safe subject',
            'body' => 'Legacy body @comment_body',
          ],
          $safe_template
      );

    $this->assertIsArray($scrubbed);
    $this->assertSame('Custom safe subject', $scrubbed['subject']);
    $this->assertSame($safe_template['body'], $scrubbed['body']);
  }

  /**
   * Notification-owned repair detects legacy private comment tokens.
   *
   * @return void
   *   No return value.
   */
  public function testNotificationRepairDetectsPrivateCommentTokens(): void {
    include_once dirname(__DIR__, 4) . '/beantragt_notification/beantragt_notification.install';

    $this->assertTrue(
      _beantragt_notification_comment_template_contains_private_tokens([
        'subject' => 'Legacy subject',
        'body' => 'Legacy body @comment_body',
      ])
    );
    $this->assertTrue(
      _beantragt_notification_comment_template_contains_private_tokens([
        'subject' => 'Legacy subject',
        'body' => 'Attachment list: @attachments',
      ])
    );
    $this->assertFalse(
      _beantragt_notification_comment_template_contains_private_tokens([
        'subject' => 'Safe subject',
        'body' => 'Safe body without private tokens.',
      ])
    );
  }

  /**
   * The runtime comment hook does not pass free-text bodies to mail templates.
   *
   * @return void
   *   No return value.
   */
  public function testRuntimeHookDoesNotExposeCommentBodyToken(): void {
    $module_source = file_get_contents(
          dirname(__DIR__, 3) . '/beantragt_comment.module'
      );

    $this->assertIsString($module_source);
    $this->assertStringNotContainsString("'@comment_body'", $module_source);
    $this->assertStringNotContainsString('"@comment_body"', $module_source);
  }

}
