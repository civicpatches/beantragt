import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import PizZip from 'pizzip';
import { DEFAULT_LIMITS } from '../../src/config.js';
import { guardDocx } from '../../src/docx-guard.js';
import { buildDocx, buildDocxZip, NAMESPACES } from '../helpers/docx-fixture.js';

const limits = DEFAULT_LIMITS;
const REL = NAMESPACES.R;

function rejects(buffer, code, pattern = /./u) {
  assert.throws(() => guardDocx(buffer, limits), (error) => {
    assert.equal(error.code, code);
    assert.match(error.message, pattern);
    return true;
  });
}

describe('guardDocx: accepted input', () => {
  it('accepts a minimal DOCX and rebuilds it', () => {
    const result = guardDocx(buildDocx(), limits);
    assert.equal(result.removedExternalRelationships, 0);
    const rebuilt = new PizZip(result.toBuffer());
    assert.match(rebuilt.file('word/document.xml').asText(), /Hallo \{name\}/u);
  });
});

describe('guardDocx: structure', () => {
  it('rejects a file larger than the DOCX limit with 413', () => {
    assert.throws(() => guardDocx(buildDocx(), { ...limits, maxDocxBytes: 100 }), (error) => {
      assert.equal(error.status, 413);
      assert.equal(error.code, 'docx_too_large');
      return true;
    });
  });

  it('rejects an archive without [Content_Types].xml', () => {
    const zip = buildDocxZip();
    zip.remove('[Content_Types].xml');
    rejects(zip.generate({ type: 'nodebuffer' }), 'invalid_docx', /Content_Types/u);
  });

  it('rejects an archive without word/document.xml', () => {
    const zip = buildDocxZip();
    zip.remove('word/document.xml');
    rejects(zip.generate({ type: 'nodebuffer' }), 'invalid_docx', /word\/document\.xml/u);
  });

  it('rejects a main part with a non-docx content type', () => {
    rejects(buildDocx({ mainContentType: 'application/xml' }), 'invalid_docx', /content type/u);
  });

  it('rejects a DTD in any XML part', () => {
    const zip = buildDocxZip();
    zip.file('word/styles.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>');
    rejects(zip.generate({ type: 'nodebuffer' }), 'docx_forbidden_content', /DTD/u);
  });
});

describe('guardDocx: macros and embedded objects', () => {
  it('rejects a macro-enabled main document (.docm)', () => {
    rejects(buildDocx({ mainContentType: 'application/vnd.ms-word.document.macroEnabled.main+xml' }), 'docx_macro_or_template');
  });

  it('rejects a Word template (.dotx)', () => {
    rejects(buildDocx({ mainContentType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml' }), 'docx_macro_or_template');
  });

  it('rejects a VBA project part', () => {
    rejects(buildDocx({ extraEntries: { 'word/vbaProject.bin': Buffer.from('VBA') } }), 'docx_macro_or_template');
  });

  it('rejects a VBA content type declaration', () => {
    rejects(buildDocx({ extraContentTypes: ['<Default Extension="bin" ContentType="application/vnd.ms-office.vbaProject"/>'] }), 'docx_macro_or_template');
  });

  it('rejects ActiveX controls and OLE embeddings', () => {
    rejects(buildDocx({ extraEntries: { 'word/activeX/activeX1.xml': '<ax/>' } }), 'docx_macro_or_template');
    rejects(buildDocx({ extraEntries: { 'word/embeddings/oleObject1.bin': Buffer.from('OLE') } }), 'docx_macro_or_template');
    rejects(buildDocx({
      documentRelationships: [`<Relationship Id="rIdOle" Type="${REL}/oleObject" Target="media/object.bin"/>`],
    }), 'docx_macro_or_template');
  });

  it('rejects altChunk parts', () => {
    rejects(buildDocx({
      documentRelationships: [`<Relationship Id="rIdAlt" Type="${REL}/aFChunk" Target="afchunk.htm"/>`],
      extraEntries: { 'word/afchunk.htm': '<html></html>' },
    }), 'docx_forbidden_content', /altChunk/u);
  });

  it('rejects field codes that load outside content, also when split across runs', () => {
    rejects(buildDocx({
      bodyXml: '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> DDE</w:instrText></w:r>'
        + '<w:r><w:instrText>AUTO c:\\\\x "y"</w:instrText></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>',
    }), 'docx_forbidden_content', /Field codes/u);
    rejects(buildDocx({
      bodyXml: '<w:p><w:fldSimple w:instr=" INCLUDEPICTURE &quot;http://169.254.169.254/x&quot; "><w:r><w:t>x</w:t></w:r></w:fldSimple></w:p>',
    }), 'docx_forbidden_content');
  });

  it('matches field types case-insensitively, in single quotes and with any prefix', () => {
    rejects(buildDocx({
      bodyXml: '<w:p><w:fldSimple w:instr=\' includepicture "http://10.0.0.1/x" \'><w:r><w:t>x</w:t></w:r></w:fldSimple></w:p>',
    }), 'docx_forbidden_content');
    rejects(buildDocx({
      bodyXml: `<w:p xmlns:x="${NAMESPACES.W}"><x:r><x:fldChar x:fldCharType="begin"/></x:r>`
        + '<x:r><x:instrText>IncludeText "\\\\server\\share\\x.docx"</x:instrText></x:r>'
        + '<x:r><x:fldChar x:fldCharType="end"/></x:r></w:p>',
    }), 'docx_forbidden_content');
  });

  it('finds a forbidden field nested inside another field', () => {
    rejects(buildDocx({
      bodyXml: '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText>IF 1 = 1 "</w:instrText></w:r>'
        + '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText>LINK Excel.Sheet "c:\\x.xlsx"</w:instrText></w:r>'
        + '<w:r><w:fldChar w:fldCharType="end"/></w:r><w:r><w:instrText>"</w:instrText></w:r>'
        + '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>',
    }), 'docx_forbidden_content');
  });

  it('accepts a hyperlink field whose tooltip says Link', () => {
    const result = guardDocx(buildDocx({
      bodyXml: '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> HYPERLINK "https://example.org" \\o "Link" </w:instrText></w:r>'
        + '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>Webseite</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>',
    }), limits);
    assert.equal(result.removedExternalRelationships, 0);
  });

  it('keeps harmless field codes such as PAGE', () => {
    const result = guardDocx(buildDocx({
      bodyXml: '<w:p><w:fldSimple w:instr=" PAGE "><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p>',
    }), limits);
    assert.equal(result.removedExternalRelationships, 0);
  });
});

describe('guardDocx: XML parts under other names', () => {
  const HEADER_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml';
  const headerPart = instruction => `<?xml version="1.0" encoding="UTF-8"?><w:hdr xmlns:w="${NAMESPACES.W}"><w:p>`
    + `<w:fldSimple w:instr="${instruction}"><w:r><w:t>x</w:t></w:r></w:fldSimple></w:p></w:hdr>`;

  function renamedHeader(content) {
    return buildDocx({
      documentRelationships: [`<Relationship Id="rIdH" Type="${REL}/header" Target="h1.bin"/>`],
      extraContentTypes: [`<Override PartName="/word/h1.bin" ContentType="${HEADER_TYPE}"/>`],
      extraEntries: { 'word/h1.bin': content },
    });
  }

  it('checks field codes in a header part stored without .xml extension', () => {
    rejects(renamedHeader(headerPart(' INCLUDETEXT &quot;/etc/hostname&quot; ')), 'docx_forbidden_content', /Field codes/u);
  });

  it('checks field codes in a part with UTF-8 BOM and leading whitespace', () => {
    const content = Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from(`\n  ${headerPart(' LINK x ').replace(/^<\?xml[^>]*\?>/u, '')}`)]);
    rejects(renamedHeader(content), 'docx_forbidden_content', /Field codes/u);
  });

  it('rejects a DTD in a part stored without .xml extension', () => {
    rejects(renamedHeader('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'), 'docx_forbidden_content', /DTD/u);
  });

  it('rejects UTF-16 encoded XML parts', () => {
    const utf16 = Buffer.concat([Buffer.from([0xff, 0xfe]), Buffer.from(headerPart(' PAGE '), 'utf16le')]);
    rejects(renamedHeader(utf16), 'invalid_docx', /UTF-8/u);
    rejects(renamedHeader(Buffer.from('<w:hdr/>', 'utf16le')), 'invalid_docx', /UTF-8/u);
  });

  it('rejects DATABASE and RD fields', () => {
    rejects(buildDocx({
      bodyXml: '<w:p><w:fldSimple w:instr=" DATABASE \\d &quot;c:\\\\data.mdb&quot; \\s &quot;select * from t&quot; "><w:r><w:t>x</w:t></w:r></w:fldSimple></w:p>',
    }), 'docx_forbidden_content', /DATABASE/u);
    rejects(buildDocx({
      bodyXml: '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> RD "kapitel2.docx" \\f </w:instrText></w:r>'
        + '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>',
    }), 'docx_forbidden_content');
  });

  it('leaves binary media untouched', () => {
    const png = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x3c, 0x00, 0x41]);
    const result = guardDocx(buildDocx({ extraEntries: { 'word/media/image1.png': png } }), limits);
    assert.deepEqual(Buffer.from(result.zip.file('word/media/image1.png').asUint8Array()), png);
  });
});

describe('guardDocx: external relationships', () => {
  it('removes external relationships and the attributes pointing at them', () => {
    const result = guardDocx(buildDocx({
      documentRelationships: [
        `<Relationship Id="rIdLink" Type="${REL}/hyperlink" Target="https://example.org/" TargetMode="External"/>`,
        `<Relationship Id="rIdImg" Type="${REL}/image" Target="http://169.254.169.254/latest" TargetMode="External"/>`,
      ],
      bodyXml: '<w:p><w:hyperlink r:id="rIdLink"><w:r><w:t>Webseite</w:t></w:r></w:hyperlink></w:p>',
    }), limits);
    assert.equal(result.removedExternalRelationships, 2);
    const rebuilt = new PizZip(result.toBuffer());
    const rels = rebuilt.file('word/_rels/document.xml.rels').asText();
    assert.doesNotMatch(rels, /External/u);
    assert.match(rels, /rIdStyles/u);
    const documentXml = rebuilt.file('word/document.xml').asText();
    assert.doesNotMatch(documentXml, /rIdLink/u);
    assert.match(documentXml, /<w:hyperlink><w:r><w:t>Webseite<\/w:t>/u);
  });

  it('drops an attached template that points outside', () => {
    const result = guardDocx(buildDocx({
      settingsXml: `<?xml version="1.0" encoding="UTF-8"?><w:settings xmlns:w="${NAMESPACES.W}" xmlns:r="${REL}">`
        + '<w:attachedTemplate r:id="rIdTpl"/><w:defaultTabStop w:val="708"/></w:settings>',
      extraEntries: {
        'word/_rels/settings.xml.rels': '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          + `<Relationship Id="rIdTpl" Type="${REL}/attachedTemplate" Target="file:///C:/Vorlagen/x.dotm" TargetMode="External"/></Relationships>`,
      },
    }), limits);
    assert.equal(result.removedExternalRelationships, 1);
    const settings = new PizZip(result.toBuffer()).file('word/settings.xml').asText();
    assert.doesNotMatch(settings, /attachedTemplate/u);
    assert.match(settings, /defaultTabStop/u);
  });

  it('rejects an internal attached template relationship', () => {
    rejects(buildDocx({
      documentRelationships: [`<Relationship Id="rIdTpl" Type="${REL}/attachedTemplate" Target="template.dotm"/>`],
    }), 'docx_macro_or_template');
  });
});
