// Defensive ZIP reader for untrusted DOCX uploads.
//
// The reader never trusts declared sizes alone: it checks the central
// directory against the limits first, then inflates each entry with a hard
// output cap (zlib maxOutputLength) equal to the declared size and verifies
// length and CRC-32. A ZIP bomb with lying headers therefore stops at the
// declared size instead of exhausting memory. ZIP64, multi-disk archives,
// encryption and compression methods other than stored/deflate are rejected;
// Office never writes them for documents of the allowed size.

import { crc32, inflateRawSync } from 'node:zlib';
import { unprocessable } from './errors.js';

const SIG_EOCD = 0x06054b50;
const SIG_ZIP64_LOCATOR = 0x07064b50;
const SIG_CENTRAL = 0x02014b50;
const SIG_LOCAL = 0x04034b50;
const EOCD_MIN = 22;
const MAX_COMMENT = 0xffff;

function invalid(message, details) {
  return unprocessable('invalid_docx', message, details);
}

function findEndOfCentralDirectory(buffer) {
  const lowest = Math.max(0, buffer.length - EOCD_MIN - MAX_COMMENT);
  for (let offset = buffer.length - EOCD_MIN; offset >= lowest; offset -= 1) {
    if (buffer.readUInt32LE(offset) === SIG_EOCD) {
      return offset;
    }
  }
  return -1;
}

const WHITESPACE = new Set([0x20, 0x09, 0x0a, 0x0d]);

/**
 * Classifies entry content by its first bytes, independent of the entry name:
 * a part that a consumer may load as XML through a content-type override must
 * get the same checks as one called *.xml.
 *
 * @param {Buffer} content
 * @returns {'utf8'|'utf16'|null} 'utf8' when the bytes start (after an
 *   optional UTF-8 BOM and whitespace) with '<'; 'utf16' for a UTF-16 BOM or
 *   a UTF-16 encoded '<' plus ASCII character; null otherwise.
 */
export function xmlEncoding(content) {
  if (content.length >= 2) {
    const [first, second] = content;
    if ((first === 0xfe && second === 0xff) || (first === 0xff && second === 0xfe)) {
      return 'utf16';
    }
  }
  if (content.length >= 4) {
    // '<' plus an ASCII character in UTF-16 LE or BE without a BOM.
    const [b0, b1, b2, b3] = content;
    const ascii = byte => byte >= 0x21 && byte <= 0x7e;
    if ((b0 === 0x3c && b1 === 0x00 && ascii(b2) && b3 === 0x00)
      || (b0 === 0x00 && b1 === 0x3c && b2 === 0x00 && ascii(b3))) {
      return 'utf16';
    }
  }
  let offset = content.length >= 3 && content[0] === 0xef && content[1] === 0xbb && content[2] === 0xbf ? 3 : 0;
  while (offset < content.length && WHITESPACE.has(content[offset])) {
    offset += 1;
  }
  return offset < content.length && content[offset] === 0x3c ? 'utf8' : null;
}

export function isXmlPart(name, content) {
  return /\.(xml|rels)$/iu.test(name) || xmlEncoding(content) !== null;
}

function isUnsafeName(name) {
  return (
    name.length === 0
    || name.includes('\0')
    || name.includes('\\')
    || name.startsWith('/')
    || /^[a-zA-Z]:/u.test(name)
    || name.split('/').some(segment => segment === '..' || segment === '.')
  );
}

/**
 * Reads and fully validates a ZIP archive.
 *
 * @param {Buffer} buffer Raw archive bytes.
 * @param {object} limits maxZipEntries, maxUncompressedBytes, maxXmlBytes,
 *   maxCompressionRatio, compressionRatioFloorBytes.
 * @returns {Map<string, Buffer>} Entry name to uncompressed content, in
 *   archive order. Directory entries are skipped.
 */
export function readZipSafely(buffer, limits) {
  if (!Buffer.isBuffer(buffer) || buffer.length < EOCD_MIN) {
    throw invalid('The file is not a ZIP archive.');
  }
  if (buffer.readUInt32LE(0) !== SIG_LOCAL) {
    throw invalid('The file is not a ZIP archive.');
  }

  const eocd = findEndOfCentralDirectory(buffer);
  if (eocd < 0) {
    throw invalid('The ZIP end-of-central-directory record is missing.');
  }
  if (eocd >= 20 && buffer.readUInt32LE(eocd - 20) === SIG_ZIP64_LOCATOR) {
    throw invalid('ZIP64 archives are not accepted.');
  }

  const diskNumber = buffer.readUInt16LE(eocd + 4);
  const centralDisk = buffer.readUInt16LE(eocd + 6);
  const entriesOnDisk = buffer.readUInt16LE(eocd + 8);
  const totalEntries = buffer.readUInt16LE(eocd + 10);
  const centralSize = buffer.readUInt32LE(eocd + 12);
  const centralOffset = buffer.readUInt32LE(eocd + 16);

  if (diskNumber !== 0 || centralDisk !== 0 || entriesOnDisk !== totalEntries) {
    throw invalid('Multi-disk ZIP archives are not accepted.');
  }
  if (totalEntries === 0xffff || centralSize === 0xffffffff || centralOffset === 0xffffffff) {
    throw invalid('ZIP64 archives are not accepted.');
  }
  if (totalEntries > limits.maxZipEntries) {
    throw invalid('The archive contains too many entries.', { maxEntries: limits.maxZipEntries });
  }
  if (centralOffset + centralSize > eocd) {
    throw invalid('The ZIP central directory is out of bounds.');
  }

  const headers = [];
  const seen = new Set();
  let declaredTotal = 0;
  let cursor = centralOffset;

  for (let index = 0; index < totalEntries; index += 1) {
    if (cursor + 46 > eocd || buffer.readUInt32LE(cursor) !== SIG_CENTRAL) {
      throw invalid('The ZIP central directory is corrupt.');
    }
    const flags = buffer.readUInt16LE(cursor + 8);
    const method = buffer.readUInt16LE(cursor + 10);
    const crc = buffer.readUInt32LE(cursor + 16);
    const compressedSize = buffer.readUInt32LE(cursor + 20);
    const uncompressedSize = buffer.readUInt32LE(cursor + 24);
    const nameLength = buffer.readUInt16LE(cursor + 28);
    const extraLength = buffer.readUInt16LE(cursor + 30);
    const commentLength = buffer.readUInt16LE(cursor + 32);
    const localOffset = buffer.readUInt32LE(cursor + 42);
    const nameEnd = cursor + 46 + nameLength;
    if (nameEnd > eocd) {
      throw invalid('The ZIP central directory is corrupt.');
    }
    const name = buffer.toString('utf8', cursor + 46, nameEnd);
    cursor = nameEnd + extraLength + commentLength;

    if (compressedSize === 0xffffffff || uncompressedSize === 0xffffffff || localOffset === 0xffffffff) {
      throw invalid('ZIP64 archives are not accepted.');
    }
    if (flags & 0x1) {
      throw invalid('Encrypted ZIP entries are not accepted.');
    }
    if (method !== 0 && method !== 8) {
      throw invalid('Unsupported ZIP compression method.');
    }
    if (isUnsafeName(name)) {
      throw invalid('The archive contains an unsafe entry name.');
    }
    const folded = name.toLowerCase();
    if (seen.has(folded)) {
      throw invalid('The archive contains duplicate entries.');
    }
    seen.add(folded);

    declaredTotal += uncompressedSize;
    if (declaredTotal > limits.maxUncompressedBytes) {
      throw invalid('The archive expands beyond the allowed size.', { maxUncompressedBytes: limits.maxUncompressedBytes });
    }
    if (
      uncompressedSize > limits.compressionRatioFloorBytes
      && uncompressedSize > compressedSize * limits.maxCompressionRatio
    ) {
      throw invalid('An archive entry exceeds the allowed compression ratio.', { maxCompressionRatio: limits.maxCompressionRatio });
    }
    if (method === 0 && compressedSize !== uncompressedSize) {
      throw invalid('The ZIP central directory is corrupt.');
    }

    headers.push({ name, method, crc, compressedSize, uncompressedSize, localOffset });
  }

  const entries = new Map();
  let xmlTotal = 0;
  for (const header of headers) {
    const { name, method, crc, compressedSize, uncompressedSize, localOffset } = header;
    if (localOffset + 30 > centralOffset || buffer.readUInt32LE(localOffset) !== SIG_LOCAL) {
      throw invalid('A ZIP local header is corrupt.');
    }
    const localNameLength = buffer.readUInt16LE(localOffset + 26);
    const localExtraLength = buffer.readUInt16LE(localOffset + 28);
    const dataStart = localOffset + 30 + localNameLength + localExtraLength;
    const dataEnd = dataStart + compressedSize;
    if (dataEnd > centralOffset) {
      throw invalid('A ZIP entry is out of bounds.');
    }

    if (name.endsWith('/')) {
      if (uncompressedSize !== 0) {
        throw invalid('A ZIP directory entry carries data.');
      }
      continue;
    }

    const compressed = buffer.subarray(dataStart, dataEnd);
    let content;
    if (method === 0) {
      content = Buffer.from(compressed);
    } else {
      try {
        content = inflateRawSync(compressed, { maxOutputLength: Math.max(1, uncompressedSize) });
      } catch {
        throw invalid('A ZIP entry does not decompress to its declared size.');
      }
    }
    if (content.length !== uncompressedSize) {
      throw invalid('A ZIP entry does not decompress to its declared size.');
    }
    if (crc32(content) !== crc) {
      throw invalid('A ZIP entry fails its checksum.');
    }
    if (isXmlPart(name, content)) {
      xmlTotal += content.length;
      if (xmlTotal > limits.maxXmlBytes) {
        throw invalid('The XML parts of the archive exceed the allowed size.', { maxXmlBytes: limits.maxXmlBytes });
      }
    }
    entries.set(name, content);
  }

  return entries;
}
