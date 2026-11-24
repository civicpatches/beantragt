import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { DEFAULT_LIMITS } from '../../src/config.js';
import { readZipSafely, xmlEncoding } from '../../src/zip-guard.js';
import { writeZip } from '../helpers/zip-writer.js';

const limits = DEFAULT_LIMITS;

function rejectsWith(fn, pattern) {
  assert.throws(fn, (error) => {
    assert.equal(error.code, 'invalid_docx');
    assert.equal(error.status, 422);
    assert.match(error.message, pattern);
    return true;
  });
}

describe('readZipSafely', () => {
  it('reads stored and deflated entries', () => {
    const zip = writeZip([
      { name: 'a.txt', data: 'stored', method: 0 },
      { name: 'dir/', data: '', method: 0 },
      { name: 'dir/b.xml', data: '<x/>' },
    ]);
    const entries = readZipSafely(zip, limits);
    assert.deepEqual([...entries.keys()], ['a.txt', 'dir/b.xml']);
    assert.equal(entries.get('a.txt').toString(), 'stored');
    assert.equal(entries.get('dir/b.xml').toString(), '<x/>');
  });

  it('rejects data that is not a ZIP archive', () => {
    rejectsWith(() => readZipSafely(Buffer.from('%PDF-1.7 this is not a zip archive at all'), limits), /not a ZIP/u);
    rejectsWith(() => readZipSafely(Buffer.alloc(4), limits), /not a ZIP/u);
  });

  it('rejects more entries than allowed', () => {
    const zip = writeZip(Array.from({ length: 6 }, (_, index) => ({ name: `f${index}.xml`, data: '<x/>' })));
    rejectsWith(() => readZipSafely(zip, { ...limits, maxZipEntries: 5 }), /too many entries/u);
  });

  it('rejects an archive whose entries expand beyond the total limit', () => {
    const zip = writeZip([
      { name: 'a.bin', data: Buffer.alloc(600 * 1024, 1) },
      { name: 'b.bin', data: Buffer.alloc(600 * 1024, 2) },
    ]);
    rejectsWith(() => readZipSafely(zip, { ...limits, maxUncompressedBytes: 1024 * 1024 }), /expands beyond/u);
  });

  it('rejects a compression bomb by ratio', () => {
    const zip = writeZip([{ name: 'word/document.xml', data: Buffer.alloc(8 * 1024 * 1024, 0x20) }]);
    assert.ok(zip.length < 64 * 1024, 'fixture must be a high-ratio archive');
    rejectsWith(() => readZipSafely(zip, limits), /compression ratio/u);
  });

  it('stops at the declared size when the header lies', () => {
    // Declares 1 KiB but inflates to 4 MiB: the inflater must stop at the cap.
    const zip = writeZip([{ name: 'word/document.xml', data: Buffer.alloc(4 * 1024 * 1024, 0x41), declaredSize: 1024 }]);
    rejectsWith(() => readZipSafely(zip, limits), /declared size/u);
  });

  it('rejects a checksum mismatch', () => {
    const zip = writeZip([{ name: 'a.xml', data: '<a/>', method: 0 }]);
    // Flip a byte of the stored payload (after the 30-byte header and 5-byte name).
    zip[35] ^= 0xff;
    rejectsWith(() => readZipSafely(zip, limits), /checksum/u);
  });

  it('rejects encrypted entries', () => {
    const zip = writeZip([{ name: 'a.xml', data: '<a/>', flags: 1 }]);
    rejectsWith(() => readZipSafely(zip, limits), /Encrypted/u);
  });

  it('rejects unsafe and duplicate entry names', () => {
    for (const name of ['../evil.xml', '/abs.xml', 'a\\b.xml', 'word/./x.xml', 'C:/x.xml']) {
      rejectsWith(() => readZipSafely(writeZip([{ name, data: '<a/>' }]), limits), /unsafe entry name/u);
    }
    const duplicate = writeZip([{ name: 'Word/Document.xml', data: '<a/>' }, { name: 'word/document.xml', data: '<b/>' }]);
    rejectsWith(() => readZipSafely(duplicate, limits), /duplicate/u);
  });

  it('limits the total size of XML parts, whatever their name', () => {
    const xml = `<x>${'a'.repeat(600)}</x>`;
    const small = { ...limits, maxXmlBytes: 1000 };
    assert.equal(readZipSafely(writeZip([{ name: 'a.xml', data: xml }]), small).size, 1);
    rejectsWith(() => readZipSafely(writeZip([
      { name: 'a.xml', data: xml },
      { name: 'b.bin', data: xml },
    ]), small), /XML parts/u);
    rejectsWith(() => readZipSafely(writeZip([
      { name: 'a.rels', data: 'x'.repeat(600) },
      { name: 'b.xml', data: 'y'.repeat(600) },
    ]), small), /XML parts/u);
  });

  it('does not count binary entries towards the XML budget', () => {
    const binary = Buffer.alloc(5000, 0x89);
    const entries = readZipSafely(writeZip([{ name: 'image.png', data: binary }]), { ...limits, maxXmlBytes: 1000 });
    assert.equal(entries.get('image.png').length, 5000);
  });
});

describe('xmlEncoding', () => {
  it('classifies content by its first bytes', () => {
    assert.equal(xmlEncoding(Buffer.from('<?xml version="1.0"?><a/>')), 'utf8');
    assert.equal(xmlEncoding(Buffer.from('\uFEFF \n<a/>')), 'utf8');
    assert.equal(xmlEncoding(Buffer.from('<a/>', 'utf16le')), 'utf16');
    assert.equal(xmlEncoding(Buffer.from([0xfe, 0xff, 0x00, 0x3c])), 'utf16');
    assert.equal(xmlEncoding(Buffer.from([0x89, 0x50, 0x4e, 0x47])), null);
    assert.equal(xmlEncoding(Buffer.from([0x3c, 0x00, 0x01, 0x00])), 'utf8');
    assert.equal(xmlEncoding(Buffer.alloc(0)), null);
  });
});
