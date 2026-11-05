// Lecture de listes de personnes dans le navigateur : .xls (Excel 97-2003), .xlsx et .csv.
// Sans dépendance ; ne garde que les valeurs des cellules de la première feuille (ni formules ni macros).

const decoder1252 = new TextDecoder('windows-1252');
const decoder16 = new TextDecoder('utf-16le');

/** @param {ArrayBuffer} buffer @param {string} name @returns {Promise<string[][]>} lignes de la première feuille */
export async function readSpreadsheet(buffer, name = '') {
  const bytes = new Uint8Array(buffer);
  if (bytes[0] === 0xd0 && bytes[1] === 0xcf && bytes[2] === 0x11 && bytes[3] === 0xe0) return readXls(buffer);
  if (bytes[0] === 0x50 && bytes[1] === 0x4b) return readXlsx(buffer);
  if (/\.xls[xm]?$/i.test(name)) throw new Error('Ce fichier n’est pas un classeur Excel lisible. Enregistrez-le en .xlsx ou en .csv.');
  return readCsv(bytes);
}

// ===== CSV =====

function readCsv(bytes) {
  let text;
  try { text = new TextDecoder('utf-8', { fatal: true }).decode(bytes); } catch { text = decoder1252.decode(bytes); }
  text = text.replace(/^﻿/, '');
  const first = text.split(/\r?\n/, 1)[0] ?? '';
  const separator = [';', '\t', ','].map((s) => [s, first.split(s).length]).sort((a, b) => b[1] - a[1])[0][0];
  const rows = [];
  let row = []; let cell = ''; let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (c === '"') quoted = false; else cell += c;
    } else if (c === '"' && cell === '') quoted = true;
    else if (c === separator) { row.push(cell); cell = ''; } else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; } else if (c !== '\r') cell += c;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows;
}

// ===== XLS : conteneur OLE2, puis enregistrements BIFF8 =====

const END = 0xfffffffe; const FREE = 0xffffffff;

function oleStream(buffer, wanted) {
  const view = new DataView(buffer);
  const sectorSize = 1 << view.getUint16(30, true);
  const miniSize = 1 << view.getUint16(32, true);
  const cutoff = view.getUint32(56, true);
  const offset = (n) => (n + 1) * sectorSize;

  const fatSectors = [];
  for (let i = 0; i < 109; i++) {
    const s = view.getUint32(76 + i * 4, true);
    if (s !== FREE) fatSectors.push(s);
  }
  let difat = view.getUint32(68, true);
  for (let guard = 0; difat !== END && difat !== FREE && guard < 1000; guard++) {
    for (let i = 0; i < sectorSize / 4 - 1; i++) {
      const s = view.getUint32(offset(difat) + i * 4, true);
      if (s !== FREE) fatSectors.push(s);
    }
    difat = view.getUint32(offset(difat) + sectorSize - 4, true);
  }
  const fat = [];
  for (const s of fatSectors) for (let i = 0; i < sectorSize / 4; i++) fat.push(view.getUint32(offset(s) + i * 4, true));

  const chain = (start, table) => {
    const out = [];
    for (let s = start, guard = 0; s !== END && s !== FREE && s < table.length && guard < 1e6; s = table[s], guard++) out.push(s);
    return out;
  };
  const read = (start, size) => {
    const out = new Uint8Array(chain(start, fat).length * sectorSize);
    chain(start, fat).forEach((s, i) => out.set(new Uint8Array(buffer, offset(s), Math.min(sectorSize, buffer.byteLength - offset(s))), i * sectorSize));
    return size === null ? out : out.slice(0, size);
  };

  const dir = read(view.getUint32(48, true), null);
  const dirView = new DataView(dir.buffer);
  const entries = [];
  for (let p = 0; p + 128 <= dir.length; p += 128) {
    const nameLength = dirView.getUint16(p + 64, true);
    if (nameLength < 2) continue;
    entries.push({
      name: decoder16.decode(dir.subarray(p, p + nameLength - 2)), type: dir[p + 66],
      start: dirView.getUint32(p + 116, true), size: dirView.getUint32(p + 120, true),
    });
  }
  const entry = entries.find((e) => wanted.includes(e.name));
  if (!entry) throw new Error('Aucune feuille de calcul trouvée dans ce fichier.');
  if (entry.size >= cutoff) return read(entry.start, entry.size);

  // Petit flux : rangé dans le « mini-flux » de l'entrée racine
  const root = entries.find((e) => e.type === 5);
  const miniStream = read(root.start, root.size);
  const miniFatBytes = read(view.getUint32(60, true), null);
  const miniView = new DataView(miniFatBytes.buffer);
  const miniFat = [];
  for (let i = 0; i + 4 <= miniFatBytes.length; i += 4) miniFat.push(miniView.getUint32(i, true));
  const sectors = chain(entry.start, miniFat);
  const out = new Uint8Array(sectors.length * miniSize);
  sectors.forEach((s, i) => out.set(miniStream.subarray(s * miniSize, (s + 1) * miniSize), i * miniSize));
  return out.slice(0, entry.size);
}

/** Valeur RK d'Excel : entier ou flottant compact. */
function rkValue(view, p) {
  const raw = view.getInt32(p, true);
  let value;
  if (raw & 2) value = raw >> 2;
  else {
    const tmp = new DataView(new ArrayBuffer(8));
    tmp.setInt32(4, raw & 0xfffffffc, true);
    value = tmp.getFloat64(0, true);
  }
  return raw & 1 ? value / 100 : value;
}

function readXls(buffer) {
  const stream = oleStream(buffer, ['Workbook', 'Book']);
  const view = new DataView(stream.buffer, stream.byteOffset, stream.length);
  const records = [];
  for (let p = 0; p + 4 <= stream.length;) {
    const id = view.getUint16(p, true);
    const length = view.getUint16(p + 2, true);
    records.push({ id, start: p + 4, length });
    p += 4 + length;
  }
  if (records[0]?.id !== 0x0809 || view.getUint16(records[0].start, true) !== 0x0600) {
    throw new Error('Format Excel trop ancien. Enregistrez le fichier en .xlsx ou en .csv.');
  }

  // Table des textes partagés : peut déborder sur des enregistrements CONTINUE
  const strings = [];
  records.forEach((record, index) => {
    if (record.id !== 0x00fc) return;
    const parts = [record];
    for (let j = index + 1; records[j]?.id === 0x003c; j++) parts.push(records[j]);
    let part = 0; let p = record.start + 8; let end = record.start + record.length;
    const total = view.getUint32(record.start + 4, true);
    const nextPart = () => { part++; p = parts[part].start; end = p + parts[part].length; };
    for (let n = 0; n < total && part < parts.length; n++) {
      if (p >= end) nextPart();
      let chars = view.getUint16(p, true); p += 2;
      let flags = stream[p++];
      const rich = flags & 8 ? view.getUint16(p, true) : 0; if (flags & 8) p += 2;
      const phonetic = flags & 4 ? view.getUint32(p, true) : 0; if (flags & 4) p += 4;
      let text = '';
      while (chars > 0) {
        if (p >= end) { nextPart(); flags = stream[p++]; }
        const wide = flags & 1;
        const take = Math.min(chars, Math.floor((end - p) / (wide ? 2 : 1)));
        text += (wide ? decoder16 : decoder1252).decode(stream.subarray(p, p + take * (wide ? 2 : 1)));
        p += take * (wide ? 2 : 1);
        chars -= take;
        if (take === 0) { nextPart(); flags = stream[p++]; }
      }
      strings.push(text);
      let skip = rich * 4 + phonetic;
      while (skip > 0) {
        if (p >= end) nextPart();
        const step = Math.min(skip, end - p);
        p += step; skip -= step;
      }
    }
  });

  // Première feuille de calcul : du premier BOF de type « feuille » à son EOF
  const rows = [];
  const put = (r, c, value) => { rows[r] ??= []; rows[r][c] = value; };
  const short = (p) => {
    const length = view.getUint16(p, true);
    const wide = stream[p + 2] & 1;
    const bytes = stream.subarray(p + 3, p + 3 + length * (wide ? 2 : 1));
    return (wide ? decoder16 : decoder1252).decode(bytes);
  };
  let inSheet = false;
  for (const { id, start: p, length } of records) {
    if (id === 0x0809 && view.getUint16(p + 2, true) === 0x0010) { inSheet = true; continue; }
    if (!inSheet) continue;
    if (id === 0x000a) break;
    const r = view.getUint16(p, true); const c = view.getUint16(p + 2, true);
    if (id === 0x00fd) put(r, c, strings[view.getUint32(p + 6, true)] ?? '');
    else if (id === 0x0204 || id === 0x00d6) put(r, c, short(p + 6));
    else if (id === 0x0203) put(r, c, String(view.getFloat64(p + 6, true)));
    else if (id === 0x027e) put(r, c, String(rkValue(view, p + 6)));
    else if (id === 0x00bd) {
      const last = view.getUint16(p + length - 2, true);
      for (let col = c, q = p + 4; col <= last; col++, q += 6) put(r, col, String(rkValue(view, q + 2)));
    }
  }
  return Array.from(rows, (row) => Array.from(row ?? [], (v) => v ?? ''));
}

// ===== XLSX : archive ZIP, puis XML =====

async function zipFiles(buffer) {
  const view = new DataView(buffer);
  let eocd = buffer.byteLength - 22;
  while (eocd >= 0 && view.getUint32(eocd, true) !== 0x06054b50) eocd--;
  if (eocd < 0) throw new Error('Archive illisible.');
  const count = view.getUint16(eocd + 10, true);
  let p = view.getUint32(eocd + 16, true);
  const files = new Map();
  for (let i = 0; i < count; i++) {
    const method = view.getUint16(p + 10, true);
    const compressed = view.getUint32(p + 20, true);
    const nameLength = view.getUint16(p + 28, true);
    const extra = view.getUint16(p + 30, true);
    const comment = view.getUint16(p + 32, true);
    const local = view.getUint32(p + 42, true);
    files.set(new TextDecoder().decode(new Uint8Array(buffer, p + 46, nameLength)), { method, compressed, local });
    p += 46 + nameLength + extra + comment;
  }
  return { view, buffer, files };
}

async function zipText({ view, buffer, files }, name) {
  const file = files.get(name);
  if (!file) return null;
  const start = file.local + 30 + view.getUint16(file.local + 26, true) + view.getUint16(file.local + 28, true);
  const data = new Uint8Array(buffer, start, file.compressed);
  if (file.method === 0) return new TextDecoder().decode(data);
  const stream = new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
  return new Response(stream).text();
}

function columnIndex(reference) {
  let n = 0;
  for (const c of reference.replace(/\d+/, '')) n = n * 26 + c.charCodeAt(0) - 64;
  return n - 1;
}

async function readXlsx(buffer) {
  const zip = await zipFiles(buffer);
  const parse = (text) => new DOMParser().parseFromString(text, 'application/xml');
  const sharedText = await zipText(zip, 'xl/sharedStrings.xml');
  const shared = sharedText ? [...parse(sharedText).getElementsByTagName('si')].map((si) => [...si.getElementsByTagName('t')].map((t) => t.textContent).join('')) : [];
  const sheetName = [...zip.files.keys()].filter((n) => /^xl\/worksheets\/sheet\d*\.xml$/.test(n)).sort()[0];
  if (!sheetName) throw new Error('Aucune feuille de calcul trouvée dans ce fichier.');
  const sheet = parse(await zipText(zip, sheetName));
  const rows = [];
  for (const row of sheet.getElementsByTagName('row')) {
    const r = Number(row.getAttribute('r')) - 1;
    const out = [];
    for (const cell of row.getElementsByTagName('c')) {
      const c = columnIndex(cell.getAttribute('r') ?? 'A');
      const type = cell.getAttribute('t');
      const v = cell.getElementsByTagName('v')[0]?.textContent ?? '';
      out[c] = type === 's' ? shared[Number(v)] ?? '' : type === 'inlineStr' ? [...cell.getElementsByTagName('t')].map((t) => t.textContent).join('') : v;
    }
    rows[r] = Array.from(out, (v) => v ?? '');
  }
  return Array.from(rows, (row) => row ?? []);
}

// ===== Reconnaissance des colonnes =====

const normalize = (text) => String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
const HEADERS = {
  last_name: /^(nom|nom de famille|last ?name|surname)$/,
  first_name: /^(prenom|prenoms|first ?name|given ?name)$/,
  group: /^(groupe|classe|group|class|volee)$/,
};

/**
 * Personnes d'un tableau : cherche la ligne d'en-tête (Nom, Prénom…), puis ne garde que nom, prénom et groupe.
 * Les autres colonnes (date de naissance…) sont volontairement laissées de côté.
 * @returns {{ people: Array<{ first_name, last_name, group }>, ignored: string[], error: string|null }}
 */
export function peopleFromRows(rows) {
  const headerIndex = rows.findIndex((row) => row.some((c) => HEADERS.last_name.test(normalize(c))) && row.some((c) => HEADERS.first_name.test(normalize(c))));
  if (headerIndex < 0) return { people: [], ignored: [], error: 'Colonnes « Nom » et « Prénom » introuvables dans la première feuille.' };
  const header = rows[headerIndex].map(normalize);
  const column = (pattern) => header.findIndex((c) => pattern.test(c));
  const last = column(HEADERS.last_name); const first = column(HEADERS.first_name); const group = column(HEADERS.group);
  const used = new Set([last, first, group]);
  const ignored = rows[headerIndex].filter((c, i) => c && !used.has(i) && !/^(no|n°|numero)$/.test(normalize(c)));
  const people = [];
  const seen = new Set();
  for (const row of rows.slice(headerIndex + 1)) {
    const person = { last_name: String(row[last] ?? '').trim(), first_name: String(row[first] ?? '').trim(), group: group >= 0 ? String(row[group] ?? '').trim() : '' };
    if (!person.last_name || !person.first_name) continue;
    const key = normalize(`${person.group}|${person.last_name}|${person.first_name}`);
    if (seen.has(key)) continue;
    seen.add(key);
    people.push(person);
  }
  return { people, ignored, error: people.length ? null : 'Aucune personne trouvée sous la ligne d’en-tête.' };
}
