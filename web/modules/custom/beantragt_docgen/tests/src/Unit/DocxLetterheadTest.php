<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_docgen\Docx\DocxLetterhead;
use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\beantragt_docgen\Exception\DocgenApiException;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Central headers replace defaults and remain safe DOCX packages.
 */
#[Group('beantragt_docgen')]
final class DocxLetterheadTest extends UnitTestCase {

  /**
   * Both letter purposes replace the template header with one tenant header.
   */
  public function testReplacementAndGuards(): void {
    $png = (string) file_get_contents(dirname(__DIR__, 4) . '/beantragt_group/tests/fixtures/letterhead-light.png');
    ob_start();
    imagepng(imagecreatetruecolor(2, 2));
    $old_png = (string) ob_get_clean();
    $this->assertNotSame($png, $old_png);
    foreach (['Bewilligung', 'Nachforderung'] as $purpose) {
      $original = DocxWriter::build([$purpose], [
        'header_image' => [
          'png' => $old_png,
          'width_emu' => 100000,
          'height_emu' => 100000,
          'alt' => 'Wrong template branding',
          'name' => 'old-logo.png',
        ],
      ]);
      $result = DocxLetterhead::apply($original, 'data:image/png;base64,' . base64_encode($png), 'Issuing town & office');
      DocxPrecheck::assertDocx($result);
      $parts = $this->parts($result);
      $this->assertArrayNotHasKey('word/header1.xml', $parts);
      $this->assertArrayNotHasKey('word/media/old-logo.png', $parts);
      $this->assertArrayNotHasKey('word/_rels/header1.xml.rels', $parts);
      $this->assertStringNotContainsString('Wrong template branding', implode('', $parts));
      $this->assertStringContainsString('Issuing town &amp; office', $parts['word/beantragt-letterhead-1.xml']);
      $this->assertStringContainsString('{organisation.anschrift}', $parts['word/beantragt-letterhead-1.xml']);
      $this->assertStringContainsString('{jurisdiction.name}', $parts['word/beantragt-letterhead-1.xml']);
      $this->assertSame($png, $parts['word/media/beantragt-letterhead-1.png']);
      $this->assertSame(3, substr_count($parts['word/document.xml'], '<w:headerReference'));
      $this->assertStringContainsString($purpose, $parts['word/document.xml']);
      $again = $this->parts(DocxLetterhead::apply($result, 'data:image/png;base64,' . base64_encode($png), 'New town'));
      $this->assertStringNotContainsString('Issuing town', implode('', $again));
      $this->assertSame(1, substr_count($again['[Content_Types].xml'], 'wordprocessingml.header+xml'));
    }
  }

  /**
   * Images also used by a surviving document relationship must stay available.
   */
  public function testSharedImageSurvivesHeaderReplacement(): void {
    $png = (string) file_get_contents(dirname(__DIR__, 4) . '/beantragt_group/tests/fixtures/letterhead-light.png');
    $bytes = DocxWriter::build(['Letter'], [
      'header_image' => [
        'png' => $png,
        'width_emu' => 100000,
        'height_emu' => 100000,
        'alt' => 'Old header',
        'name' => 'shared.png',
      ],
    ]);
    $path = tempnam(sys_get_temp_dir(), 'shared-header');
    file_put_contents($path, $bytes);
    $zip = new \ZipArchive();
    $zip->open($path);
    $rels = $zip->getFromName('word/_rels/document.xml.rels');
    $zip->addFromString('word/_rels/document.xml.rels', str_replace('</Relationships>',
      '<Relationship Id="bodyImage" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/shared.png"/></Relationships>', $rels));
    $zip->close();
    try {
      $parts = $this->parts(DocxLetterhead::apply(file_get_contents($path), 'data:image/png;base64,' . base64_encode($png), 'New header'));
      $this->assertSame($png, $parts['word/media/shared.png']);
      $this->assertArrayNotHasKey('word/header1.xml', $parts);
      $this->assertStringContainsString('Id="bodyImage"', $parts['word/_rels/document.xml.rels']);
    }
    finally {
      unlink($path);
    }
  }

  /**
   * Missing, remote, SVG, fake and oversized logos are refused.
   */
  public function testInvalidBrandingFailsClosed(): void {
    foreach ([
      '' => 'docgen.letterhead_missing',
      'https://example.org/logo.png' => 'docgen.letterhead_invalid',
      'data:image/svg+xml;base64,PHN2Zy8+' => 'docgen.letterhead_invalid',
      'data:image/png;base64,' . base64_encode('not a PNG') => 'docgen.letterhead_invalid',
      'data:image/png;base64,' . str_repeat('A', 1400000) => 'docgen.letterhead_invalid',
    ] as $url => $expected) {
      try {
        DocxLetterhead::apply(DocxWriter::build(['Letter']), $url, 'Town');
        $this->fail('Unsafe branding accepted.');
      }
      catch (DocgenApiException $exception) {
        $this->assertSame($expected, $exception->errorCode);
      }
    }
  }

  /**
   * JPEGs are embedded directly, with their matching content type.
   */
  public function testJpeg(): void {
    $image = imagecreatetruecolor(20, 10);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();
    $parts = $this->parts(DocxLetterhead::apply(DocxWriter::build(['Letter']), 'data:image/jpeg;base64,' . base64_encode($jpeg), 'Town'));
    $this->assertSame($jpeg, $parts['word/media/beantragt-letterhead-1.jpeg']);
    $this->assertStringContainsString('ContentType="image/jpeg"', $parts['[Content_Types].xml']);
  }

  /**
   * Active paragraph/body sections and Strict namespaces preserve valid order.
   */
  public function testMultipleSectionsAndStrictNamespaces(): void {
    $png = (string) file_get_contents(dirname(__DIR__, 4) . '/beantragt_group/tests/fixtures/letterhead-light.png');
    foreach ([FALSE, TRUE] as $strict) {
      $parts = $this->parts(DocxWriter::build(['Letter']));
      $w = $strict ? 'http://purl.oclc.org/ooxml/wordprocessingml/main' : 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
      $r = $strict ? 'http://purl.oclc.org/ooxml/officeDocument/relationships' : 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
      $parts['word/document.xml'] = '<w:document xmlns:w="' . $w . '" xmlns:r="' . $r . '"><w:body>'
        . '<w:p><w:pPr><w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:cols w:num="2"/><w:docGrid w:linePitch="360"/>'
        . '<w:sectPrChange w:id="1" w:author="Fictional"><w:sectPr><w:pgMar w:top="100" w:left="100" w:right="100" w:bottom="100" w:header="100" w:footer="100" w:gutter="0"/></w:sectPr></w:sectPrChange>'
        . '</w:sectPr></w:pPr><w:r><w:t>{heute}</w:t></w:r></w:p>'
        . '<w:sectPr><w:pgMar w:top="4000" w:left="1111" w:right="1222" w:bottom="1333" w:header="444" w:footer="555" w:gutter="66"/><w:cols/></w:sectPr></w:body></w:document>';
      if ($strict) {
        foreach ($parts as $name => $value) {
          if (str_ends_with($name, '.rels')) {
            $parts[$name] = str_replace('http://schemas.openxmlformats.org/officeDocument/2006/relationships', $r, $value);
          }
        }
      }
      $path = tempnam(sys_get_temp_dir(), 'sections');
      $zip = new \ZipArchive();
      $zip->open($path, \ZipArchive::OVERWRITE);
      foreach ($parts as $name => $value) {
        $zip->addFromString($name, $value);
      }
      $zip->close();
      try {
        $result = $this->parts(DocxLetterhead::apply(file_get_contents($path), 'data:image/png;base64,' . base64_encode($png), 'Town'));
      }
      finally {
        unlink($path);
      }
      $xml = new \DOMDocument();
      $xml->loadXML($result['word/document.xml']);
      $xpath = new \DOMXPath($xml);
      $xpath->registerNamespace('w', $w);
      $this->assertSame(6, $xpath->query('//w:headerReference')->length);
      $this->assertSame(0, $xpath->query('//w:sectPrChange//w:headerReference')->length);
      $this->assertSame('100', $xpath->evaluate('string(//w:sectPrChange//w:pgMar/@w:top)'));
      $this->assertSame('0', $xpath->evaluate('string(//w:pPr/w:sectPr/w:pgMar/@w:gutter)'));
      $this->assertSame('pgSz', $xpath->query('//w:pPr/w:sectPr/w:pgMar/preceding-sibling::*[1]')->item(0)->localName);
      $this->assertSame('cols', $xpath->query('//w:pPr/w:sectPr/w:pgMar/following-sibling::*[1]')->item(0)->localName);
      foreach ([
        'top' => '4000',
        'left' => '1111',
        'right' => '1222',
        'bottom' => '1333',
        'footer' => '555',
        'gutter' => '66',
      ] as $key => $value) {
        $this->assertSame($value, $xpath->evaluate('string(/w:document/w:body/w:sectPr/w:pgMar/@w:' . $key . ')'));
      }
      $this->assertStringContainsString('xmlns:w="' . $w . '"', $result['word/beantragt-letterhead-1.xml']);
      $this->assertStringContainsString('Type="' . $r . '/image"', $result['word/_rels/beantragt-letterhead-1.xml.rels']);
    }
  }

  /**
   * Reads the checked package for structural assertions.
   */
  private function parts(string $bytes): array {
    $path = tempnam(sys_get_temp_dir(), 'letterhead-test');
    file_put_contents($path, $bytes);
    $zip = new \ZipArchive();
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->count(); $i++) {
      $parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
    }
    $zip->close();
    unlink($path);
    return $parts;
  }

}
