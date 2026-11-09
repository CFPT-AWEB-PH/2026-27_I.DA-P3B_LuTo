// Génère les icônes PWA (PNG) sans dépendance : node scripts/generate-icons.mjs
// Motif : une armoire à trois tiroirs (le logo de l'application) sur l'indigo de la marque.
import { mkdirSync, writeFileSync } from 'node:fs';
import { deflateSync } from 'node:zlib';

const BRAND = [79, 70, 229]; // --primary
const WHITE = [255, 255, 255];
const OUT = new URL('../frontend/assets/icons/', import.meta.url);

const crcTable = Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});
const crc32 = (buf) => {
  let c = 0xffffffff;
  for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
};
const chunk = (type, data) => {
  const body = Buffer.concat([Buffer.from(type), data]);
  const out = Buffer.alloc(body.length + 8);
  out.writeUInt32BE(data.length, 0);
  body.copy(out, 4);
  out.writeUInt32BE(crc32(body), out.length - 4);
  return out;
};

/** Rectangle à coins arrondis, en coordonnées 0..1 : vrai si le point est à l'intérieur. */
const rounded = (x, y, x0, y0, x1, y1, r) => {
  if (x < x0 || x > x1 || y < y0 || y > y1) return false;
  const dx = Math.max(x0 + r - x, 0, x - (x1 - r));
  const dy = Math.max(y0 + r - y, 0, y - (y1 - r));
  return dx * dx + dy * dy <= r * r;
};

/** Couleur en un point : fond, armoire blanche, tiroirs découpés et poignées. `s` réduit le motif (zone sûre). */
function sample(x, y, { maskable }) {
  const s = maskable ? 0.72 : 1;
  const u = (x - 0.5) / s + 0.5;
  const v = (y - 0.5) / s + 0.5;
  const inBackground = maskable || rounded(x, y, 0, 0, 1, 1, 0.2);
  if (!inBackground) return null;

  let body = rounded(u, v, 0.22, 0.18, 0.78, 0.82, 0.05);
  for (const top of [0.24, 0.44, 0.64]) {
    if (rounded(u, v, 0.28, top, 0.72, top + 0.14, 0.015)) {
      body = rounded(u, v, 0.44, top + 0.055, 0.56, top + 0.085, 0.015);
    }
  }
  return body ? WHITE : BRAND;
}

function png(size, options = {}) {
  const AA = 4;
  const raw = Buffer.alloc((size * 4 + 1) * size);
  let p = 0;
  for (let py = 0; py < size; py++) {
    raw[p++] = 0;
    for (let px = 0; px < size; px++) {
      let r = 0; let g = 0; let b = 0; let a = 0;
      for (let j = 0; j < AA; j++) {
        for (let i = 0; i < AA; i++) {
          const color = sample((px + (i + 0.5) / AA) / size, (py + (j + 0.5) / AA) / size, options);
          if (!color) continue;
          r += color[0]; g += color[1]; b += color[2]; a++;
        }
      }
      raw[p++] = a ? Math.round(r / a) : 0;
      raw[p++] = a ? Math.round(g / a) : 0;
      raw[p++] = a ? Math.round(b / a) : 0;
      raw[p++] = Math.round((a / (AA * AA)) * 255);
    }
  }
  const header = Buffer.alloc(13);
  header.writeUInt32BE(size, 0);
  header.writeUInt32BE(size, 4);
  header[8] = 8; // 8 bits par canal
  header[9] = 6; // RVBA
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', header), chunk('IDAT', deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0)),
  ]);
}

mkdirSync(OUT, { recursive: true });
const files = {
  'icon-192.png': png(192),
  'icon-512.png': png(512),
  'icon-maskable-512.png': png(512, { maskable: true }),
  // iOS n'accepte pas la transparence : fond plein, comme l'icône masquable
  'apple-touch-icon.png': png(180, { maskable: true }),
  'favicon-32.png': png(32),
};
for (const [name, data] of Object.entries(files)) {
  writeFileSync(new URL(name, OUT), data);
  console.log(name, data.length, 'octets');
}
