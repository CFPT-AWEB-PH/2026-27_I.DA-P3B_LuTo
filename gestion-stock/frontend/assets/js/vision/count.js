// Comptage de pièces identiques posées sur un fond clair (table blanche, feuille de papier).
// Vision classique, sans bibliothèque ni modèle appris : tout se calcule dans le navigateur, aucune image ne quitte l'appareil.
//  1. le blanc du fond est estimé localement, par tuiles, pour tolérer un éclairage inégal ;
//  2. une pièce = des pixels nettement plus sombres que ce fond, ou nettement colorés (ou, sur une feuille de couleur,
//     nettement moins colorés : pièces blanches) ;
//  3. nettoyage du masque, petits trous bouchés (trous de fixation, écrous), puis zones connexes ;
//  4. la surface d'une pièce seule est déduite des zones les plus fréquentes : une zone deux fois plus grande compte pour deux.
// Sans dépendance : testé hors navigateur par tests/frontend/vision_test.mjs.

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

function percentile(hist, total, p) {
  const target = total * p;
  let seen = 0;
  for (let v = 0; v < hist.length; v++) {
    seen += hist[v];
    if (seen >= target) return v;
  }
  return hist.length - 1;
}

function median(sorted) {
  const mid = sorted.length >> 1;
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

/** Moyenne 3 x 3 : atténue le bruit du capteur avant le seuil. */
function blur3(src, w, h) {
  const tmp = new Uint8Array(src.length);
  const out = new Uint8Array(src.length);
  for (let y = 0; y < h; y++) {
    const row = y * w;
    for (let x = 0; x < w; x++) {
      tmp[row + x] = (src[row + (x > 0 ? x - 1 : 0)] + src[row + x] + src[row + (x < w - 1 ? x + 1 : x)]) / 3;
    }
  }
  for (let y = 0; y < h; y++) {
    const up = (y > 0 ? y - 1 : 0) * w; const mid = y * w; const down = (y < h - 1 ? y + 1 : y) * w;
    for (let x = 0; x < w; x++) out[mid + x] = (tmp[up + x] + tmp[mid + x] + tmp[down + x]) / 3;
  }
  return out;
}

/** Érosion ou dilatation par un carré 3 x 3 (masque 0 / 1). */
function morph(src, w, h, dilate) {
  const tmp = new Uint8Array(src.length);
  const out = new Uint8Array(src.length);
  for (let y = 0; y < h; y++) {
    const row = y * w;
    for (let x = 0; x < w; x++) {
      const a = src[row + (x > 0 ? x - 1 : 0)]; const b = src[row + x]; const c = src[row + (x < w - 1 ? x + 1 : x)];
      tmp[row + x] = dilate ? (a | b | c) : (a & b & c);
    }
  }
  for (let y = 0; y < h; y++) {
    const up = (y > 0 ? y - 1 : 0) * w; const mid = y * w; const down = (y < h - 1 ? y + 1 : y) * w;
    for (let x = 0; x < w; x++) out[mid + x] = dilate ? (tmp[up + x] | tmp[mid + x] | tmp[down + x]) : (tmp[up + x] & tmp[mid + x] & tmp[down + x]);
  }
  return out;
}

/** Zones connexes des pixels valant `value` : étiquettes (0 = hors zone, k = zone k − 1) et mesures de chaque zone. */
function components(mask, w, h, value, eight) {
  const labels = new Int32Array(mask.length);
  const stack = new Int32Array(mask.length);
  const list = [];
  for (let start = 0; start < mask.length; start++) {
    if (mask[start] !== value || labels[start] !== 0) continue;
    const id = list.length + 1;
    let top = 0; let area = 0; let sx = 0; let sy = 0;
    let x0 = w; let y0 = h; let x1 = 0; let y1 = 0; let edge = false;
    stack[top++] = start;
    labels[start] = id;
    while (top > 0) {
      const i = stack[--top];
      const x = i % w; const y = (i - x) / w;
      area++; sx += x; sy += y;
      if (x < x0) x0 = x;
      if (x > x1) x1 = x;
      if (y < y0) y0 = y;
      if (y > y1) y1 = y;
      if (x === 0 || y === 0 || x === w - 1 || y === h - 1) edge = true;
      for (let dy = -1; dy <= 1; dy++) {
        const ny = y + dy;
        if (ny < 0 || ny >= h) continue;
        for (let dx = -1; dx <= 1; dx++) {
          if ((dx === 0 && dy === 0) || (!eight && dx !== 0 && dy !== 0)) continue;
          const nx = x + dx;
          if (nx < 0 || nx >= w) continue;
          const j = ny * w + nx;
          if (mask[j] === value && labels[j] === 0) {
            labels[j] = id;
            stack[top++] = j;
          }
        }
      }
    }
    list.push({ area, cx: sx / area, cy: sy / area, x0, y0, x1, y1, edge });
  }
  return { labels, list };
}

/**
 * Surface d'une pièce seule, d'après les surfaces des zones : le plus petit groupe de zones de taille voisine.
 * Des pièces qui se touchent forment des zones doubles ou triples, qui ne doivent pas tirer la référence vers le haut.
 */
export function estimatePieceArea(areas) {
  const sorted = [...areas].sort((a, b) => a - b);
  const n = sorted.length;
  if (n === 0) return null;
  let ref = sorted[0];
  if (n === 2 && sorted[1] <= sorted[0] * 1.45) ref = median(sorted);
  if (n >= 3) {
    const need = n >= 6 ? Math.max(2, Math.ceil(n * 0.15)) : 2;
    for (let i = 0; i < n; i++) {
      let j = i;
      while (j + 1 < n && sorted[j + 1] <= sorted[i] * 1.45) j++;
      if (j - i + 1 >= need) {
        ref = median(sorted.slice(i, j + 1));
        break;
      }
    }
  }
  // Affinage : médiane des zones qui valent manifestement une pièce
  const singles = sorted.filter((a) => a >= ref * 0.7 && a <= ref * 1.4);
  return singles.length ? median(singles) : ref;
}

/**
 * Analyse une image RGBA.
 * @param {Uint8ClampedArray|Uint8Array} rgba pixels (4 octets par pixel)
 * @param {{ sensitivity?: number, pieceRatio?: number|null }} options sensitivity 0 à 1 (0,5 par défaut : plus haut détecte
 *   des pièces plus claires, mais aussi les ombres) ; pieceRatio : surface d'une pièce rapportée à l'image, si elle est déjà connue
 * @returns {{ width, height, labels: Int32Array, blobs: Array, count: number, pieceArea: ?number, pieceRatio: ?number,
 *   white: number, coverage: number, tooDark: boolean, noBackground: boolean, edges: number, groups: number }}
 */
export function analyse(rgba, width, height, { sensitivity = 0.5, pieceRatio = null } = {}) {
  const n = width * height;
  const result = {
    width, height, labels: new Int32Array(n), blobs: [], count: 0, pieceArea: null, pieceRatio: null,
    white: 0, coverage: 0, tooDark: false, noBackground: false, edges: 0, groups: 0,
  };
  if (n === 0) return result;
  sensitivity = clamp(sensitivity, 0, 1);

  // Luminance et saturation
  const raw = new Uint8Array(n);
  const chroma = new Uint8Array(n);
  const hist = new Uint32Array(256);
  for (let i = 0, p = 0; i < n; i++, p += 4) {
    const r = rgba[p]; const g = rgba[p + 1]; const b = rgba[p + 2];
    const l = (r * 77 + g * 150 + b * 29) >> 8;
    raw[i] = l;
    hist[l]++;
    const max = r > g ? (r > b ? r : b) : (g > b ? g : b);
    const min = r < g ? (r < b ? r : b) : (g < b ? g : b);
    chroma[i] = max - min;
  }
  const white = percentile(hist, n, 0.9);
  result.white = white;
  if (white < 70) {
    result.tooDark = true;
    return result;
  }
  const lum = blur3(raw, width, height);

  // Blanc local : par tuile, le niveau au-dessous duquel se trouvent 80 % des pixels. Une tuile presque entièrement
  // couverte (pièce large, bord sombre de la table) n'est pas fiable : elle reprend le blanc de ses voisines.
  const T = Math.max(8, Math.round(Math.max(width, height) / 28));
  const gw = Math.ceil(width / T); const gh = Math.ceil(height / T);
  let level = new Float32Array(gw * gh);
  let known = new Uint8Array(gw * gh);
  const tileHist = new Uint32Array(256);
  for (let ty = 0; ty < gh; ty++) {
    for (let tx = 0; tx < gw; tx++) {
      tileHist.fill(0);
      const xEnd = Math.min(width, (tx + 1) * T); const yEnd = Math.min(height, (ty + 1) * T);
      let total = 0;
      for (let y = ty * T; y < yEnd; y++) for (let x = tx * T; x < xEnd; x++) { tileHist[lum[y * width + x]]++; total++; }
      const p80 = percentile(tileHist, total, 0.8);
      level[ty * gw + tx] = p80;
      known[ty * gw + tx] = p80 >= white * 0.55 ? 1 : 0;
    }
  }
  for (let pass = 0; pass < gw + gh && known.includes(0); pass++) {
    if (!known.includes(1)) { level.fill(white); break; }
    const nextLevel = level.slice(); const nextKnown = known.slice();
    for (let ty = 0; ty < gh; ty++) {
      for (let tx = 0; tx < gw; tx++) {
        if (known[ty * gw + tx]) continue;
        let sum = 0; let count = 0;
        for (let dy = -1; dy <= 1; dy++) {
          for (let dx = -1; dx <= 1; dx++) {
            const nx = tx + dx; const ny = ty + dy;
            if (nx < 0 || ny < 0 || nx >= gw || ny >= gh || !known[ny * gw + nx]) continue;
            sum += level[ny * gw + nx]; count++;
          }
        }
        if (count) { nextLevel[ty * gw + tx] = sum / count; nextKnown[ty * gw + tx] = 1; }
      }
    }
    level = nextLevel; known = nextKnown;
  }

  // Teinte habituelle du fond (éclairage chaud ou froid) : médiane de la saturation des pixels clairs
  const satHist = new Uint32Array(256);
  let bright = 0;
  for (let i = 0; i < n; i += 3) {
    if (raw[i] < white * 0.8) continue;
    satHist[Math.min(255, (chroma[i] * 200 / (raw[i] + 1)) | 0)]++;
    bright++;
  }
  const baseSat = bright ? percentile(satHist, bright, 0.5) / 200 : 0;

  // Seuil : plus sombre que le blanc local, ou coloré. Sur une feuille de couleur (fond nettement teinté), une pièce
  // blanche ou grise se reconnaît à l'inverse : elle est moins colorée que le fond.
  const darkRatio = 0.62 + 0.26 * sensitivity;
  const satGap = 0.36 - 0.22 * sensitivity;
  const satLimit = baseSat + satGap;
  const paleLimit = baseSat - satGap * 0.6;
  const tinted = paleLimit > 0.04;
  let mask = new Uint8Array(n);
  const col0 = new Int32Array(width); const colT = new Float32Array(width);
  for (let x = 0; x < width; x++) {
    const f = clamp((x + 0.5) / T - 0.5, 0, gw - 1);
    col0[x] = Math.min(gw - 2 < 0 ? 0 : gw - 2, Math.floor(f));
    colT[x] = gw > 1 ? f - col0[x] : 0;
  }
  for (let y = 0; y < height; y++) {
    const f = clamp((y + 0.5) / T - 0.5, 0, gh - 1);
    const r0 = Math.min(gh - 2 < 0 ? 0 : gh - 2, Math.floor(f));
    const rt = gh > 1 ? f - r0 : 0;
    const rowA = r0 * gw; const rowB = Math.min(gh - 1, r0 + 1) * gw;
    for (let x = 0; x < width; x++) {
      const c0 = col0[x]; const c1 = Math.min(gw - 1, c0 + 1); const t = colT[x];
      const top = level[rowA + c0] + (level[rowA + c1] - level[rowA + c0]) * t;
      const bottom = level[rowB + c0] + (level[rowB + c1] - level[rowB + c0]) * t;
      const background = top + (bottom - top) * rt;
      const i = y * width + x;
      if (lum[i] < background * darkRatio || (chroma[i] > 28 && chroma[i] > (raw[i] + 1) * satLimit)
        || (tinted && chroma[i] < (raw[i] + 1) * paleLimit)) mask[i] = 1;
    }
  }

  // Nettoyage : ouverture (grains isolés, ombres filiformes) puis fermeture (reflets, sérigraphie)
  mask = morph(morph(mask, width, height, false), width, height, true);
  mask = morph(morph(mask, width, height, true), width, height, false);

  // Trous enfermés dans une pièce : bouchés s'ils sont petits (un cercle de pièces n'enferme pas « une pièce »)
  const holeLimit = Math.max(64, n * 0.004);
  const background = components(mask, width, height, 0, false);
  const fill = background.list.map((zone) => !zone.edge && zone.area <= holeLimit);
  if (fill.includes(true)) {
    for (let i = 0; i < n; i++) if (background.labels[i] > 0 && fill[background.labels[i] - 1]) mask[i] = 1;
  }

  const { labels, list } = components(mask, width, height, 1, true);
  result.labels = labels;
  let covered = 0;
  for (const zone of list) covered += zone.area;
  result.coverage = covered / n;
  // Plus de la moitié de l'image « occupée » : ce n'est pas une table claire
  result.noBackground = result.coverage > 0.55;

  const minArea = Math.max(16, n * 0.00012);
  const inside = list.filter((zone) => !zone.edge && zone.area >= minArea);
  const ref = pieceRatio ? pieceRatio * n : estimatePieceArea(inside.map((zone) => zone.area));
  result.pieceArea = ref;
  result.pieceRatio = ref ? ref / n : null;

  result.blobs = list.map((zone, index) => {
    let kind = 'piece'; let count = 1;
    if (zone.edge) { kind = 'edge'; count = 0; }
    else if (zone.area < minArea || (ref && zone.area < ref * 0.35)) { kind = 'dust'; count = 0; }
    else if (ref) {
      count = Math.max(1, Math.round(zone.area / ref));
      if (count > 1) kind = 'group';
    }
    return { id: index + 1, ...zone, kind, count };
  });
  for (const blob of result.blobs) {
    result.count += blob.count;
    if (blob.kind === 'group') result.groups++;
    // Un bord sombre de table n'est pas « une pièce coupée » : seules les zones de taille comparable alertent
    if (blob.kind === 'edge' && blob.area >= minArea && (!ref || blob.area < ref * 6)) result.edges++;
  }
  return result;
}

const COLORS = {
  piece: [22, 163, 74],    // vert : une pièce
  group: [217, 119, 6],    // ambre : plusieurs pièces qui se touchent
  edge: [220, 38, 38],     // rouge : coupée par le bord, non comptée
  dust: [120, 128, 145],   // gris : trop petite, ignorée
  off: [120, 128, 145],    // gris : écartée à la main
};

/**
 * Dessine les zones dans une image RGBA de la taille de l'analyse : voile de couleur et contour plus marqué.
 * kinds : état affiché par zone (par défaut celui de l'analyse) ; permet de montrer une zone écartée ou corrigée.
 */
export function paintOverlay(result, pixels, kinds = null) {
  const { width, height, labels, blobs } = result;
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = y * width + x;
      const id = labels[i];
      const p = i * 4;
      if (id === 0) { pixels[p + 3] = 0; continue; }
      const border = x === 0 || y === 0 || x === width - 1 || y === height - 1
        || labels[i - 1] !== id || labels[i + 1] !== id || labels[i - width] !== id || labels[i + width] !== id;
      const color = COLORS[kinds?.[id - 1] ?? blobs[id - 1].kind];
      pixels[p] = color[0]; pixels[p + 1] = color[1]; pixels[p + 2] = color[2];
      pixels[p + 3] = border ? 255 : 48;
    }
  }
  return pixels;
}

/** Zone sous un point de l'image (ou toute proche : les petites pièces sont difficiles à viser). Renvoie l'indice, ou −1. */
export function blobAt(result, x, y, reach = 6) {
  const { width, height, labels } = result;
  x = Math.round(x); y = Math.round(y);
  for (let r = 0; r <= reach; r++) {
    for (let dy = -r; dy <= r; dy++) {
      for (let dx = -r; dx <= r; dx++) {
        if (Math.max(Math.abs(dx), Math.abs(dy)) !== r) continue;
        const nx = x + dx; const ny = y + dy;
        if (nx < 0 || ny < 0 || nx >= width || ny >= height) continue;
        const id = labels[ny * width + nx];
        if (id > 0) return id - 1;
      }
    }
  }
  return -1;
}
