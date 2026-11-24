<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_application\Entity\ApplicationInterface;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\beantragt_docgen\Service\LetterheadService;
use Drupal\beantragt_group\Service\JurisdictionConfigServiceInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The letterhead embeds logoDataUrl only; the dark screen logo never.
 */
#[Group('beantragt_docgen')]
final class LetterheadServiceTest extends UnitTestCase {

  /**
   * A set dark logo does not change the embedded image.
   */
  public function testDarkLogoDoesNotChangeTheLetterhead(): void {
    $assets = dirname(__DIR__, 4) . '/beantragt_group/tests/fixtures';
    $light = (string) file_get_contents($assets . '/letterhead-light.png');
    $dark = (string) file_get_contents($assets . '/letterhead-dark.png');
    $this->assertNotSame($light, $dark);
    $template = DocxWriter::build(['Bewilligung']);
    $light_only = ['logoDataUrl' => 'data:image/png;base64,' . base64_encode($light), 'logoAlt' => 'Stadt Musterstadt'];

    $without = $this->media($this->service($light_only)->apply($this->application(), $template));
    $with = $this->media($this->service($light_only + [
      'logoDarkDataUrl' => 'data:image/png;base64,' . base64_encode($dark),
    ])->apply($this->application(), $template));

    $this->assertSame(['word/media/beantragt-letterhead-1.png' => $light], $with);
    $this->assertSame($without, $with);
  }

  /**
   * A dark logo alone is no fallback: the letter is refused as before.
   */
  public function testDarkLogoAloneIsNoLetterhead(): void {
    $dark = (string) file_get_contents(dirname(__DIR__, 4) . '/beantragt_group/tests/fixtures/letterhead-dark.png');
    $service = $this->service(['logoDarkDataUrl' => 'data:image/png;base64,' . base64_encode($dark)]);
    try {
      $service->apply($this->application(), DocxWriter::build(['Bewilligung']));
      $this->fail('A dark logo alone produced a letterhead.');
    }
    catch (DocgenApiException $exception) {
      $this->assertSame('docgen.letterhead_missing', $exception->errorCode);
    }
  }

  /**
   * Builds the service over one stored theme section.
   */
  private function service(array $theme): LetterheadService {
    $config = $this->createMock(JurisdictionConfigServiceInterface::class);
    $config->method('getSection')->with($this->anything(), 'theme')->willReturn($theme);
    return new LetterheadService($config);
  }

  /**
   * Returns an application issued by one jurisdiction.
   */
  private function application(): ApplicationInterface {
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('bundle')->willReturn('jur');
    $jurisdiction->method('label')->willReturn('Stadt Musterstadt');
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('entity')->willReturn($jurisdiction);
    $application = $this->createMock(ApplicationInterface::class);
    $application->method('get')->with('field_jurisdiction')->willReturn($field);
    return $application;
  }

  /**
   * Returns the image parts of a DOCX package.
   *
   * @return array<string, string>
   *   Image bytes keyed by part name.
   */
  private function media(string $bytes): array {
    $path = tempnam(sys_get_temp_dir(), 'letterhead-service');
    file_put_contents($path, $bytes);
    $zip = new \ZipArchive();
    $zip->open($path);
    $media = [];
    for ($i = 0; $i < $zip->count(); $i++) {
      $name = (string) $zip->getNameIndex($i);
      if (str_starts_with($name, 'word/media/')) {
        $media[$name] = (string) $zip->getFromIndex($i);
      }
    }
    $zip->close();
    unlink($path);
    return $media;
  }

}
