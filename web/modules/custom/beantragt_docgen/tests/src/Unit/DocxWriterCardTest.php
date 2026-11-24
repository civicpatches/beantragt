<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_docgen\Unit;

use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_docgen\Docx\DocxWriter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * The card block of the printable parking permit passes the DOCX precheck.
 */
#[CoversClass(DocxWriter::class)]
#[Group('beantragt_docgen')]
final class DocxWriterCardTest extends UnitTestCase {

  private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

  /**
   * Frame, lines, fixed width, spans, shading and run formatting.
   */
  public function testCardIsValidStableFramedTable(): void {
    $title = ['text' => 'Ihr Ausweis', 'align' => 'center', 'color' => '1f3a5f', 'bold' => TRUE, 'size' => 28];
    $blocks = [$title, $this->card()];
    $bytes = DocxWriter::build($blocks);
    DocxPrecheck::assertDocx($bytes);
    $this->assertSame(hash('sha256', $bytes), hash('sha256', DocxWriter::build($blocks)));

    $document = new \DOMDocument();
    $this->assertTrue($document->loadXML($this->part($bytes, 'word/document.xml')));
    $xpath = new \DOMXPath($document);
    $xpath->registerNamespace('w', self::W);

    // The paragraph before the card: centred, coloured, bold, 14 pt.
    $this->assertSame('center', $xpath->evaluate('string(/w:document/w:body/w:p[1]/w:pPr/w:jc/@w:val)'));
    $this->assertSame('1F3A5F', $xpath->evaluate('string(/w:document/w:body/w:p[1]/w:r/w:rPr/w:color/@w:val)'));
    $this->assertSame(1.0, $xpath->evaluate('count(/w:document/w:body/w:p[1]/w:r/w:rPr/w:b)'));
    $this->assertSame('28', $xpath->evaluate('string(/w:document/w:body/w:p[1]/w:r/w:rPr/w:sz/@w:val)'));

    // The card: fixed 8400 twips, centred, thick frame, thin lines between
    // rows, no vertical line, rows that never split.
    $table = '/w:document/w:body/w:tbl[1]';
    $this->assertSame('8400', $xpath->evaluate("string($table/w:tblPr/w:tblW/@w:w)"));
    $this->assertSame('dxa', $xpath->evaluate("string($table/w:tblPr/w:tblW/@w:type)"));
    $this->assertSame('center', $xpath->evaluate("string($table/w:tblPr/w:jc/@w:val)"));
    $this->assertSame('fixed', $xpath->evaluate("string($table/w:tblPr/w:tblLayout/@w:type)"));
    foreach (['top', 'left', 'bottom', 'right'] as $edge) {
      $this->assertSame('24', $xpath->evaluate("string($table/w:tblPr/w:tblBorders/w:$edge/@w:sz)"), $edge);
      $this->assertSame('1F3A5F', $xpath->evaluate("string($table/w:tblPr/w:tblBorders/w:$edge/@w:color)"), $edge);
    }
    $this->assertSame('4', $xpath->evaluate("string($table/w:tblPr/w:tblBorders/w:insideH/@w:sz)"));
    $this->assertSame('nil', $xpath->evaluate("string($table/w:tblPr/w:tblBorders/w:insideV/@w:val)"));
    $this->assertSame(3.0, $xpath->evaluate("count($table/w:tr/w:trPr/w:cantSplit)"));
    $this->assertSame(['2900', '5500'], array_map(static fn (\DOMNode $node): string => $node->nodeValue ?? '', iterator_to_array($xpath->query("$table/w:tblGrid/w:gridCol/@w:w"))));

    // Header row: one cell over both columns, shaded, white bold text.
    $head = "$table/w:tr[1]/w:tc[1]";
    $this->assertSame(1.0, $xpath->evaluate("count($table/w:tr[1]/w:tc)"));
    $this->assertSame('8400', $xpath->evaluate("string($head/w:tcPr/w:tcW/@w:w)"));
    $this->assertSame('2', $xpath->evaluate("string($head/w:tcPr/w:gridSpan/@w:val)"));
    $this->assertSame('1F3A5F', $xpath->evaluate("string($head/w:tcPr/w:shd/@w:fill)"));
    $this->assertSame('FFFFFF', $xpath->evaluate("string($head/w:p/w:r/w:rPr/w:color/@w:val)"));
    $this->assertSame('BEWOHNERPARKAUSWEIS', $xpath->evaluate("string($head/w:p/w:r/w:t)"));

    // Value row: a plain label and a large placeholder, column widths kept.
    $this->assertSame('2900', $xpath->evaluate("string($table/w:tr[2]/w:tc[1]/w:tcPr/w:tcW/@w:w)"));
    $this->assertSame('Amtliches Kennzeichen', $xpath->evaluate("string($table/w:tr[2]/w:tc[1]/w:p/w:r/w:t)"));
    $this->assertSame(0.0, $xpath->evaluate("count($table/w:tr[2]/w:tc[1]/w:p/w:r/w:rPr)"));
    $this->assertSame('40', $xpath->evaluate("string($table/w:tr[2]/w:tc[2]/w:p/w:r/w:rPr/w:sz/@w:val)"));
    $this->assertSame('{antrag.kennzeichen}', $xpath->evaluate("string($table/w:tr[2]/w:tc[2]/w:p/w:r/w:t)"));

    // Without frame and lines options the card keeps a thin grey frame and
    // no lines between rows.
    $plain = new \DOMDocument();
    $plain->loadXML($this->part(DocxWriter::build([['card' => [['a', 'b']], 'widths' => [1000, 1000]]]), 'word/document.xml'));
    $plain_xpath = new \DOMXPath($plain);
    $plain_xpath->registerNamespace('w', self::W);
    $this->assertSame('999999', $plain_xpath->evaluate('string(//w:tblBorders/w:top/@w:color)'));
    $this->assertSame('nil', $plain_xpath->evaluate('string(//w:tblBorders/w:insideH/@w:val)'));
  }

  /**
   * Paragraphs and tables without the new options keep their bytes.
   */
  public function testExistingBlocksAreUnchanged(): void {
    $xml = $this->part(DocxWriter::build([
      'Text',
      ['text' => 'Titel', 'bold' => TRUE, 'size' => 28],
      ['table' => [['Angabe', 'Wert'], ['A', '{antrag.a}']], 'widths' => [3500, 5500]],
    ]), 'word/document.xml');
    $this->assertStringContainsString('<w:p><w:r><w:t xml:space="preserve">Text</w:t></w:r></w:p>', $xml);
    $this->assertStringContainsString('<w:p><w:r><w:rPr><w:b/><w:sz w:val="28"/></w:rPr><w:t xml:space="preserve">Titel</w:t></w:r></w:p>', $xml);
    $this->assertStringContainsString('<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>', $xml);
    $this->assertStringNotContainsString('<w:pPr>', $xml);
  }

  /**
   * Unusable card and paragraph options are refused.
   */
  public function testInvalidOptionsAreRefused(): void {
    foreach ([
      'widths missing' => ['card' => [['a']]],
      'width zero' => ['card' => [['a']], 'widths' => [0]],
      'width string' => ['card' => [['a']], 'widths' => ['1000']],
      'no rows' => ['card' => [], 'widths' => [1000]],
      'span too wide' => ['card' => [[['text' => 'a', 'span' => 3]]], 'widths' => [1000, 1000]],
      'row too short' => ['card' => [['a']], 'widths' => [1000, 1000]],
      'row too long' => ['card' => [['a', 'b', 'c']], 'widths' => [1000, 1000]],
      'fill not hex' => ['card' => [[['text' => 'a', 'fill' => 'navy']]], 'widths' => [1000]],
      'frame style' => ['card' => [['a']], 'widths' => [1000], 'frame' => ['style' => 'wave']],
      'frame size' => ['card' => [['a']], 'widths' => [1000], 'frame' => ['size' => 200]],
      'lines color' => ['card' => [['a']], 'widths' => [1000], 'lines' => ['color' => '"/><w:x']],
      'padding' => ['card' => [['a']], 'widths' => [1000], 'padding' => -1],
      'paragraph align' => ['text' => 'a', 'align' => 'middle'],
      'paragraph color' => ['text' => 'a', 'color' => '12345'],
    ] as $case => $block) {
      try {
        DocxWriter::build([$block]);
        $this->fail('Invalid option accepted: ' . $case);
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * A card like the one of the demo permit.
   *
   * @return array<string, mixed>
   *   The card block.
   */
  private function card(): array {
    $banner = ['align' => 'center', 'color' => 'FFFFFF', 'fill' => '1F3A5F', 'span' => 2];
    return [
      'card' => [
        [['text' => 'BEWOHNERPARKAUSWEIS', 'bold' => TRUE, 'size' => 36] + $banner],
        ['Amtliches Kennzeichen', ['text' => '{antrag.kennzeichen}', 'bold' => TRUE, 'size' => 40]],
        [['text' => 'Ausgestellt am {heute}.', 'size' => 16, 'align' => 'center', 'fill' => 'E8EDF3', 'span' => 2]],
      ],
      'widths' => [2900, 5500],
      'frame' => ['size' => 24, 'color' => '1F3A5F'],
      'lines' => ['size' => 4, 'color' => 'BFC8D4'],
      'padding' => 80,
    ];
  }

  /**
   * Reads one part of a DOCX.
   */
  private function part(string $bytes, string $name): string {
    $path = tempnam(sys_get_temp_dir(), 'docx-test');
    file_put_contents($path, $bytes);
    $zip = new \ZipArchive();
    $this->assertTrue($zip->open($path));
    $part = (string) $zip->getFromName($name);
    $zip->close();
    unlink($path);
    return $part;
  }

}
