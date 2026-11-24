<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * The letterhead image of the demo template passes the DOCX precheck.
 */
#[CoversClass(DocxWriter::class)]
#[Group('beantragt_docgen')]
final class DocxWriterHeaderImageTest extends UnitTestCase {

  /**
   * Header part, image relationship and media part; stable bytes.
   */
  public function testHeaderImageIsValidStableHeaderPart(): void {
    $option = $this->option();
    $bytes = DocxWriter::build(['{organisation.name}', 'Bescheid'], ['header_image' => $option]);
    DocxPrecheck::assertDocx($bytes);
    $this->assertSame(hash('sha256', $bytes), hash('sha256', DocxWriter::build(['{organisation.name}', 'Bescheid'], ['header_image' => $option])));

    $parts = $this->parts($bytes);
    $this->assertSame($option['png'], $parts['word/media/musterstadt-logo.png']);
    $this->assertStringContainsString('<Default Extension="png" ContentType="image/png"/>', $parts['[Content_Types].xml']);
    $this->assertStringContainsString('PartName="/word/header1.xml"', $parts['[Content_Types].xml']);
    $this->assertStringContainsString('Id="rIdHeader1"', $parts['word/_rels/document.xml.rels']);
    $this->assertStringContainsString('<w:headerReference w:type="default" r:id="rIdHeader1"/>', $parts['word/document.xml']);
    $this->assertStringContainsString('Target="media/musterstadt-logo.png"', $parts['word/_rels/header1.xml.rels']);
    $header = new \DOMDocument();
    $this->assertTrue($header->loadXML($parts['word/header1.xml']));
    $extent = $header->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'extent')->item(0);
    $this->assertSame('1584000', $extent?->getAttribute('cx'));
    $this->assertSame('492800', $extent?->getAttribute('cy'));
    $doc_pr = $header->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'docPr')->item(0);
    $this->assertSame('Stadt Musterstadt', $doc_pr?->getAttribute('descr'));

    // Without the option nothing changes.
    $plain = $this->parts(DocxWriter::build(['Bescheid']));
    $this->assertArrayNotHasKey('word/header1.xml', $plain);
    $this->assertStringNotContainsString('headerReference', $plain['word/document.xml']);
  }

  /**
   * Unusable options are refused before any part is written.
   */
  public function testInvalidHeaderImageIsRefused(): void {
    foreach ([
      ['png' => 'GIF89a'],
      ['width_emu' => 0],
      ['height_emu' => '492800'],
      ['name' => '../logo.png'],
      ['alt' => NULL],
    ] as $change) {
      try {
        DocxWriter::build(['x'], ['header_image' => array_replace($this->option(), $change)]);
        $this->fail('Invalid header image accepted: ' . json_encode(array_keys($change)));
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * The option the demo template seed passes.
   */
  private function option(): array {
    return [
      'png' => (string) file_get_contents(dirname(__DIR__, 4) . '/beantragt_group/tests/fixtures/letterhead-light.png'),
      'width_emu' => 1584000,
      'height_emu' => 492800,
      'alt' => 'Stadt Musterstadt',
      'name' => 'musterstadt-logo.png',
    ];
  }

  /**
   * Reads all parts of a DOCX.
   *
   * @return array<string, string>
   *   Part bytes keyed by name.
   */
  private function parts(string $bytes): array {
    $path = tempnam(sys_get_temp_dir(), 'docx-test');
    file_put_contents($path, $bytes);
    $zip = new \ZipArchive();
    $this->assertTrue($zip->open($path));
    $parts = [];
    for ($i = 0; $i < $zip->count(); $i++) {
      $name = (string) $zip->getNameIndex($i);
      $parts[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);
    return $parts;
  }

}
