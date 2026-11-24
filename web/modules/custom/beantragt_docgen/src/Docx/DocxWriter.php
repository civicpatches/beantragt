<?php

declare(strict_types=1);

namespace Drupal\beantragt_docgen\Docx;

/**
 * Builds small, plain DOCX files from code.
 *
 * Used for the base template of the placeholder sample download, for the
 * demo template and in tests. The content stays reviewable here; the only
 * binary part is an optional letterhead image (option header_image, a PNG
 * the caller passes). Blocks are paragraphs (string or ['text', 'bold',
 * 'size', 'align', 'color']), tables (['table' => rows], each row a list of
 * cell texts; a row whose first cell opens a loop and whose last cell closes
 * it repeats per item in docxtemplater) and cards (['card' => rows], a
 * framed, fixed-width table with shaded and spanned cells, for printable
 * cards such as a parking permit; see card()).
 */
final class DocxWriter {

  private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
  private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  private const WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
  private const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
  private const PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
  private const HEADER_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml';
  private const ALIGNMENTS = ['left', 'center', 'right', 'both'];
  private const BORDER_STYLES = ['single', 'double', 'dashed', 'dotted'];

  /**
   * Returns DOCX bytes for the given blocks.
   *
   * @param array<int, string|array<string, mixed>> $blocks
   *   Paragraphs and tables.
   * @param array<string, mixed> $options
   *   Optional header_image: ['png' => bytes, 'width_emu' => int,
   *   'height_emu' => int, 'alt' => string, 'name' => 'name.png']. It becomes
   *   an inline picture in the default page header (word/header1.xml).
   */
  public static function build(array $blocks, array $options = []): string {
    if (!class_exists(\ZipArchive::class)) {
      throw new \RuntimeException('ext-zip is required to build DOCX files.');
    }
    $image = isset($options['header_image']) ? self::headerImage($options['header_image']) : NULL;
    $body = '';
    foreach ($blocks as $block) {
      if (is_array($block) && isset($block['card'])) {
        $body .= self::card($block);
      }
      elseif (is_array($block) && isset($block['table'])) {
        $body .= self::table($block['table'], $block['widths'] ?? []);
      }
      else {
        $body .= self::paragraph($block);
      }
    }
    $parts = [
      '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . ($image !== NULL ? '<Default Extension="png" ContentType="image/png"/>' : '')
      . '<Override PartName="/word/document.xml" ContentType="' . DocxPrecheck::DOCX_MAIN_TYPE . '"/>'
      . ($image !== NULL ? '<Override PartName="/word/header1.xml" ContentType="' . self::HEADER_TYPE . '"/>' : '')
      . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
      . '</Types>',
      '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="' . self::R . '/officeDocument" Target="word/document.xml"/>'
      . '</Relationships>',
      'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rIdStyles" Type="' . self::R . '/styles" Target="styles.xml"/>'
      . ($image !== NULL ? '<Relationship Id="rIdHeader1" Type="' . self::R . '/header" Target="header1.xml"/>' : '')
      . '</Relationships>',
      'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:styles xmlns:w="' . self::W . '"><w:docDefaults><w:rPrDefault><w:rPr>'
      . '<w:rFonts w:ascii="Liberation Sans" w:hAnsi="Liberation Sans" w:cs="Liberation Sans"/>'
      . '<w:sz w:val="22"/><w:lang w:val="de-DE"/></w:rPr></w:rPrDefault>'
      . '<w:pPrDefault><w:pPr><w:spacing w:after="120"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>',
      'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:body>'
      . $body
      . '<w:sectPr>'
      . ($image !== NULL ? '<w:headerReference w:type="default" r:id="rIdHeader1"/>' : '')
      . '<w:pgSz w:w="11906" w:h="16838"/>'
      . '<w:pgMar w:top="1417" w:right="1417" w:bottom="1134" w:left="1417" w:header="708" w:footer="708" w:gutter="0"/>'
      . '</w:sectPr></w:body></w:document>',
    ];
    if ($image !== NULL) {
      $parts['word/header1.xml'] = self::header($image);
      $parts['word/_rels/header1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdImage1" Type="' . self::R . '/image" Target="media/' . $image['name'] . '"/>'
        . '</Relationships>';
      $parts['word/media/' . $image['name']] = $image['png'];
    }
    $path = tempnam(sys_get_temp_dir(), 'docx');
    if ($path === FALSE) {
      throw new \RuntimeException('No temporary file for the DOCX.');
    }
    try {
      $zip = new \ZipArchive();
      if ($zip->open($path, \ZipArchive::OVERWRITE) !== TRUE) {
        throw new \RuntimeException('The DOCX could not be created.');
      }
      foreach ($parts as $name => $xml) {
        $zip->addFromString($name, $xml);
        // Fixed timestamps keep the bytes, and therefore the hash, stable.
        $zip->setMtimeName($name, 315532800);
      }
      $zip->close();
      $bytes = (string) file_get_contents($path);
    }
    finally {
      @unlink($path);
    }
    return $bytes;
  }

  /**
   * Validates the letterhead image option.
   *
   * @return array{png: string, width_emu: int, height_emu: int, alt: string, name: string}
   *   The checked option.
   */
  private static function headerImage(mixed $image): array {
    if (!is_array($image)
      || !is_string($image['png'] ?? NULL) || !str_starts_with($image['png'], "\x89PNG\r\n\x1a\n")
      || !is_int($image['width_emu'] ?? NULL) || $image['width_emu'] <= 0
      || !is_int($image['height_emu'] ?? NULL) || $image['height_emu'] <= 0
      || !is_string($image['alt'] ?? NULL)
      || !is_string($image['name'] ?? NULL) || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}\.png$/D', $image['name'])) {
      throw new \InvalidArgumentException('header_image needs png bytes, positive EMU sizes, an alt text and a name like logo.png.');
    }
    return $image;
  }

  /**
   * Renders the default page header with one inline picture.
   *
   * @param array{png: string, width_emu: int, height_emu: int, alt: string, name: string} $image
   *   The checked letterhead image.
   */
  private static function header(array $image): string {
    $alt = self::escape($image['alt']);
    $name = self::escape($image['name']);
    $extent = 'cx="' . $image['width_emu'] . '" cy="' . $image['height_emu'] . '"';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<w:hdr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '" xmlns:wp="' . self::WP . '"'
      . ' xmlns:a="' . self::A . '" xmlns:pic="' . self::PIC . '">'
      . '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:drawing>'
      . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
      . '<wp:extent ' . $extent . '/>'
      . '<wp:docPr id="1" name="' . $name . '" descr="' . $alt . '"/>'
      . '<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
      . '<a:graphic><a:graphicData uri="' . self::PIC . '"><pic:pic>'
      . '<pic:nvPicPr><pic:cNvPr id="1" name="' . $name . '" descr="' . $alt . '"/><pic:cNvPicPr/></pic:nvPicPr>'
      . '<pic:blipFill><a:blip r:embed="rIdImage1"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
      . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext ' . $extent . '/></a:xfrm>'
      . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
      . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p></w:hdr>';
  }

  /**
   * Escapes text for WordprocessingML.
   */
  private static function escape(string $text): string {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  }

  /**
   * Renders one paragraph.
   *
   * @param string|array<string, mixed> $block
   *   Text or ['text' => string, 'bold' => bool, 'size' => half points,
   *   'align' => left|center|right|both, 'color' => RRGGBB].
   */
  private static function paragraph(string|array $block): string {
    $text = is_array($block) ? (string) ($block['text'] ?? '') : $block;
    $paragraph_properties = '';
    $properties = '';
    if (is_array($block)) {
      if (isset($block['align'])) {
        if (!in_array($block['align'], self::ALIGNMENTS, TRUE)) {
          throw new \InvalidArgumentException('align must be one of ' . implode(', ', self::ALIGNMENTS) . '.');
        }
        $paragraph_properties = '<w:pPr><w:jc w:val="' . $block['align'] . '"/></w:pPr>';
      }
      if (!empty($block['bold'])) {
        $properties .= '<w:b/>';
      }
      if (isset($block['color'])) {
        $properties .= '<w:color w:val="' . self::color($block['color'], 'color') . '"/>';
      }
      if (isset($block['size'])) {
        $properties .= '<w:sz w:val="' . (int) $block['size'] . '"/>';
      }
    }
    $run_properties = $properties === '' ? '' : '<w:rPr>' . $properties . '</w:rPr>';
    return '<w:p>' . $paragraph_properties . '<w:r>' . $run_properties . '<w:t xml:space="preserve">' . self::escape($text) . '</w:t></w:r></w:p>';
  }

  /**
   * Renders a framed card: a fixed-width table centred on the page.
   *
   * Options of the block: 'card' => rows (each a list of cells), 'widths' =>
   * column widths in twentieths of a point (required, they fix the card
   * width), 'frame' => ['size' => eighths of a point, 'color' => RRGGBB,
   * 'style' => single|double|dashed|dotted] for the outer border, 'lines' =>
   * ['size', 'color'] for the lines between rows (none when absent) and
   * 'padding' => cell margin in twentieths of a point. A cell is a text or
   * ['text', 'bold', 'size', 'align', 'color', 'fill' => RRGGBB, 'span' =>
   * columns]. The spans of a row must add up to the number of columns. Rows
   * never break across pages.
   *
   * @param array<string, mixed> $block
   *   The card block.
   */
  private static function card(array $block): string {
    $rows = $block['card'];
    $widths = $block['widths'] ?? NULL;
    if (!is_array($rows) || $rows === [] || !is_array($widths) || $widths === []) {
      throw new \InvalidArgumentException('card needs rows and column widths.');
    }
    $widths = array_values($widths);
    foreach ($widths as $width) {
      if (!is_int($width) || $width <= 0) {
        throw new \InvalidArgumentException('card widths must be positive integers.');
      }
    }
    $frame = self::border($block['frame'] ?? [], 'frame');
    $lines = isset($block['lines']) ? self::border($block['lines'], 'lines') : 'w:val="nil"';
    $padding = $block['padding'] ?? 100;
    if (!is_int($padding) || $padding < 0 || $padding > 1440) {
      throw new \InvalidArgumentException('card padding must be between 0 and 1440.');
    }
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/><w:jc w:val="center"/><w:tblBorders>'
      . '<w:top ' . $frame . '/><w:left ' . $frame . '/><w:bottom ' . $frame . '/><w:right ' . $frame . '/>'
      . '<w:insideH ' . $lines . '/><w:insideV w:val="nil"/></w:tblBorders><w:tblLayout w:type="fixed"/><w:tblCellMar>'
      . '<w:top w:w="' . $padding . '" w:type="dxa"/><w:left w:w="' . ($padding + 40) . '" w:type="dxa"/>'
      . '<w:bottom w:w="' . $padding . '" w:type="dxa"/><w:right w:w="' . ($padding + 40) . '" w:type="dxa"/>'
      . '</w:tblCellMar></w:tblPr><w:tblGrid>';
    foreach ($widths as $width) {
      $xml .= '<w:gridCol w:w="' . $width . '"/>';
    }
    $xml .= '</w:tblGrid>';
    foreach (array_values($rows) as $row) {
      if (!is_array($row) || $row === []) {
        throw new \InvalidArgumentException('card rows must be lists of cells.');
      }
      $xml .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
      $column = 0;
      foreach (array_values($row) as $cell) {
        $cell = is_array($cell) ? $cell : ['text' => (string) $cell];
        $span = $cell['span'] ?? 1;
        if (!is_int($span) || $span < 1 || $column + $span > count($widths)) {
          throw new \InvalidArgumentException('card cell spans exceed the columns.');
        }
        $properties = '<w:tcW w:w="' . array_sum(array_slice($widths, $column, $span)) . '" w:type="dxa"/>';
        if ($span > 1) {
          $properties .= '<w:gridSpan w:val="' . $span . '"/>';
        }
        if (isset($cell['fill'])) {
          $properties .= '<w:shd w:val="clear" w:color="auto" w:fill="' . self::color($cell['fill'], 'fill') . '"/>';
        }
        $properties .= '<w:vAlign w:val="center"/>';
        $xml .= '<w:tc><w:tcPr>' . $properties . '</w:tcPr>'
          . self::paragraph(array_intersect_key($cell, array_flip(['text', 'bold', 'size', 'align', 'color']))) . '</w:tc>';
        $column += $span;
      }
      if ($column !== count($widths)) {
        throw new \InvalidArgumentException('card cell spans must fill every column.');
      }
      $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl><w:p/>';
  }

  /**
   * Returns the attributes of one border edge.
   *
   * @param mixed $border
   *   ['size' => eighths of a point, 'color' => RRGGBB, 'style' => one of
   *   self::BORDER_STYLES]; missing keys fall back to a thin grey line.
   * @param string $option
   *   The option name, for the error message.
   */
  private static function border(mixed $border, string $option): string {
    if (!is_array($border)) {
      throw new \InvalidArgumentException($option . ' must be an array.');
    }
    $size = $border['size'] ?? 4;
    $style = $border['style'] ?? 'single';
    if (!is_int($size) || $size < 2 || $size > 96 || !in_array($style, self::BORDER_STYLES, TRUE)) {
      throw new \InvalidArgumentException($option . ' needs a size between 2 and 96 and a known style.');
    }
    return 'w:val="' . $style . '" w:sz="' . $size . '" w:space="0" w:color="' . self::color($border['color'] ?? '999999', $option) . '"';
  }

  /**
   * Checks an RRGGBB colour.
   */
  private static function color(mixed $color, string $option): string {
    if (!is_string($color) || !preg_match('/^[0-9A-Fa-f]{6}$/D', $color)) {
      throw new \InvalidArgumentException($option . ' must be a colour like 1F3A5F.');
    }
    return strtoupper($color);
  }

  /**
   * Renders a bordered table; the first row is the header.
   *
   * @param array<int, array<int, string>> $rows
   *   Rows of cell texts.
   * @param int[] $widths
   *   Column widths in twentieths of a point.
   */
  private static function table(array $rows, array $widths): string {
    $border = '<w:top w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
      . '<w:left w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
      . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
      . '<w:right w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
      . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
      . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="999999"/>';
    $columns = max(1, ...array_map('count', $rows ?: [[]]));
    $widths = array_pad(array_map('intval', $widths), $columns, (int) floor(9000 / $columns));
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>' . $border . '</w:tblBorders></w:tblPr><w:tblGrid>';
    foreach ($widths as $width) {
      $xml .= '<w:gridCol w:w="' . $width . '"/>';
    }
    $xml .= '</w:tblGrid>';
    foreach (array_values($rows) as $row_index => $row) {
      $xml .= '<w:tr>';
      foreach (array_values($row) as $cell_index => $cell) {
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$cell_index] . '" w:type="dxa"/></w:tcPr>'
          . self::paragraph(['text' => (string) $cell, 'bold' => $row_index === 0]) . '</w:tc>';
      }
      $xml .= '</w:tr>';
    }
    return $xml . '</w:tbl><w:p/>';
  }

}
