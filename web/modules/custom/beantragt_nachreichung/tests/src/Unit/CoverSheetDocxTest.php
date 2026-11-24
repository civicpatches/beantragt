<?php

declare(strict_types=1);

namespace Drupal\Tests\beantragt_nachreichung\Unit;

use Drupal\beantragt_docgen\Docx\DocxPrecheck;
use Drupal\beantragt_nachreichung\Code\CoverCode;
use Drupal\beantragt_nachreichung\Docx\CoverSheetContent;
use Drupal\beantragt_nachreichung\Docx\CoverSheetDocx;
use Drupal\beantragt_nachreichung\Qr\QrPng;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The standalone cover sheet DOCX (companion PDF and staff reprint).
 */
#[Group('beantragt_nachreichung')]
final class CoverSheetDocxTest extends UnitTestCase {

  /**
   * One section, literal texts, bookmark, image part, relationship, type.
   */
  public function testStandaloneSheet(): void {
    $code = CoverCode::generate();
    $png = QrPng::render(CoverCode::payload($code));
    $docx = CoverSheetDocx::build(new CoverSheetContent(
      'MS-WG-2026-00042',
      'Wohngeld {Mietzuschuss} & <Lastenzuschuss>',
      CoverSheetContent::proofLines([
        ['fieldId' => 'lohn', 'label' => 'Lohnabrechnung'],
        ['fieldId' => 'miete', 'label' => 'Mietbescheinigung'],
        ['fieldId' => 'lohn', 'label' => 'Lohnabrechnung'],
      ]),
      new \DateTimeImmutable('2027-03-30'),
      CoverCode::group($code),
      $png,
    ));
    DocxPrecheck::assertDocx($docx);
    $parts = $this->parts($docx);
    $document = $parts['word/document.xml'];
    $this->assertSame(1, substr_count($document, '<w:sectPr'));
    $this->assertStringNotContainsString('oddPage', $document);
    $this->assertStringContainsString('w:name="' . CoverSheetDocx::BOOKMARK . '"', $document);
    $this->assertStringContainsString('Deckblatt für Ihre Unterlagen', $document);
    $this->assertStringContainsString('Aktenzeichen: MS-WG-2026-00042', $document);
    // Braces become parentheses (no placeholder), XML is escaped.
    $this->assertStringContainsString('Antrag: Wohngeld (Mietzuschuss) &amp; &lt;Lastenzuschuss&gt;', $document);
    $this->assertStringNotContainsString('{', $document);
    $this->assertStringContainsString('- Lohnabrechnung für 2 Personen', $document);
    $this->assertStringContainsString('- Mietbescheinigung', $document);
    $this->assertStringContainsString('Frist: 30.03.2027', $document);
    $this->assertStringContainsString(CoverCode::group($code), $document);
    $this->assertStringContainsString('Der Code enthält keine persönlichen Daten.', $document);
    $this->assertStringContainsString('r:embed="rIdBeantragtDeckblattQr"', $document);
    $this->assertStringContainsString('Id="rIdBeantragtDeckblattQr"', $parts['word/_rels/document.xml.rels']);
    $this->assertStringContainsString('Target="media/beantragt-deckblatt-qr-1.png"', $parts['word/_rels/document.xml.rels']);
    $this->assertStringContainsString('Extension="png"', $parts['[Content_Types].xml']);
    $this->assertSame($png, $parts['word/media/beantragt-deckblatt-qr-1.png']);
    // The identifier is on the sheet only grouped and inside the QR.
    $this->assertStringNotContainsString('BA1-', $document);
  }

  /**
   * Without a request record: no proof lines, no deadline.
   */
  public function testSheetWithoutRequestRecord(): void {
    $code = CoverCode::generate();
    $document = $this->parts(CoverSheetDocx::build(new CoverSheetContent('MS-1', 'Wohngeld', [], NULL, CoverCode::group($code), QrPng::render(CoverCode::payload($code)))))['word/document.xml'];
    $this->assertStringNotContainsString('Bitte legen Sie bei', $document);
    $this->assertStringNotContainsString('Frist:', $document);
  }

  /**
   * A PNG is required.
   */
  public function testPngIsRequired(): void {
    $this->expectException(\InvalidArgumentException::class);
    CoverSheetDocx::build(new CoverSheetContent('MS-1', 'Wohngeld', [], NULL, 'XXXX', 'not a png'));
  }

  /**
   * Reads the package parts.
   *
   * @return array<string, string>
   *   Part bytes by name.
   */
  private function parts(string $docx): array {
    $path = tempnam(sys_get_temp_dir(), 'coverdocx');
    file_put_contents($path, $docx);
    $zip = new \ZipArchive();
    $this->assertTrue($zip->open($path));
    $parts = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
      $name = (string) $zip->getNameIndex($index);
      $parts[$name] = (string) $zip->getFromIndex($index);
    }
    $zip->close();
    unlink($path);
    return $parts;
  }

}
