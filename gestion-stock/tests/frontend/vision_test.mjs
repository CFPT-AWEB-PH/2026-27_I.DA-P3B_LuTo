// Tests du comptage à la caméra sur des images de synthèse : table claire à l'éclairage inégal, bruit de capteur,
// pièces sombres, colorées, percées, qui se touchent, coupées par le bord, ombres, bord sombre de la table.
// Usage : node tests/frontend/vision_test.mjs

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const load = (path) => import('data:text/javascript;base64,' + readFileSync(join(root, path)).toString('base64'));
const { analyse, blobAt, estimatePieceArea, paintOverlay } = await load('frontend/assets/js/vision/count.js');

let failed = 0;
const check = (label, ok, detail = '') => {
  if (!ok) failed++;
  console.log(`${ok ? '  ok  ' : 'ÉCHEC '} ${label}${ok || detail === '' ? '' : ` : ${detail}`}`);
};

function randomFrom(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Table claire : dégradé d'éclairage de gauche à droite, vignettage, bruit. */
function table(width, height, { seed = 1, level = 232, falloff = 0.12, tint = [1, 1, 1], noise = 5 } = {}) {
  const random = randomFrom(seed);
  const pixels = new Uint8ClampedArray(width * height * 4);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const dx = (x - width / 2) / width; const dy = (y - height / 2) / height;
      const light = level * (1 - falloff * (x / width)) * (1 - 0.25 * (dx * dx + dy * dy));
      const p = (y * width + x) * 4;
      for (let c = 0; c < 3; c++) pixels[p + c] = light * tint[c] + (random() - 0.5) * 2 * noise;
      pixels[p + 3] = 255;
    }
  }
  return { width, height, pixels, random };
}

/** Rectangle tourné, plein ; color [r, g, b] ou facteur d'assombrissement (ombre). */
function rect(image, cx, cy, w, h, angle, color) {
  const { width, height, pixels } = image;
  const cos = Math.cos(angle); const sin = Math.sin(angle);
  const reach = Math.ceil(Math.hypot(w, h) / 2) + 1;
  for (let y = Math.max(0, Math.floor(cy - reach)); y <= Math.min(height - 1, Math.ceil(cy + reach)); y++) {
    for (let x = Math.max(0, Math.floor(cx - reach)); x <= Math.min(width - 1, Math.ceil(cx + reach)); x++) {
      const u = (x - cx) * cos + (y - cy) * sin; const v = -(x - cx) * sin + (y - cy) * cos;
      if (Math.abs(u) > w / 2 || Math.abs(v) > h / 2) continue;
      const p = (y * width + x) * 4;
      for (let c = 0; c < 3; c++) pixels[p + c] = typeof color === 'number' ? pixels[p + c] * color : color[c] + (image.random() - 0.5) * 8;
    }
  }
}

function disc(image, cx, cy, r, color) {
  const { width, height, pixels } = image;
  for (let y = Math.max(0, Math.floor(cy - r)); y <= Math.min(height - 1, Math.ceil(cy + r)); y++) {
    for (let x = Math.max(0, Math.floor(cx - r)); x <= Math.min(width - 1, Math.ceil(cx + r)); x++) {
      if ((x - cx) ** 2 + (y - cy) ** 2 > r * r) continue;
      const p = (y * width + x) * 4;
      for (let c = 0; c < 3; c++) pixels[p + c] = typeof color === 'number' ? pixels[p + c] * color : color[c] + (image.random() - 0.5) * 8;
    }
  }
}

/** Positions sans chevauchement, à distance des bords. */
function scatter(image, count, spacing, margin = spacing) {
  const points = [];
  let guard = 0;
  while (points.length < count && guard++ < 100000) {
    const x = margin + image.random() * (image.width - 2 * margin);
    const y = margin + image.random() * (image.height - 2 * margin);
    if (points.every(([px, py]) => Math.hypot(px - x, py - y) >= spacing)) points.push([x, y]);
  }
  if (points.length < count) throw new Error('Image de test trop petite pour ' + count + ' pièces');
  return points;
}

const BLACK = [24, 25, 30];
const run = (image, options) => analyse(image.pixels, image.width, image.height, options);

console.log('== Surface d\'une pièce');
check('Pièces seules', Math.abs(estimatePieceArea([98, 100, 101, 103, 99]) - 100) <= 1);
check('Quelques doubles et triples', Math.abs(estimatePieceArea([100, 102, 97, 205, 199, 301, 99, 101]) - 100) <= 2);
check('Une seule zone', estimatePieceArea([240]) === 240);
check('Un simple et un double', estimatePieceArea([100, 210]) === 100);
check('1, 2 et 3 pièces', estimatePieceArea([100, 200, 300]) === 100);
check('Aucune zone', estimatePieceArea([]) === null);

console.log('== Comptage');
{
  const image = table(640, 480, { seed: 11 });
  for (const [x, y] of scatter(image, 24, 52)) rect(image, x, y, 30, 24, image.random() * Math.PI, BLACK);
  const r = run(image);
  check('24 modules noirs tournés au hasard', r.count === 24 && r.groups === 0 && r.edges === 0, `compté ${r.count}, groupes ${r.groups}, bords ${r.edges}`);
}
{
  const image = table(640, 480, { seed: 12 });
  for (const [x, y] of scatter(image, 40, 26)) disc(image, x, y, 5, [70, 72, 78]);
  const r = run(image);
  check('40 écrous (disques de 10 px)', r.count === 40, `compté ${r.count}`);
}
{
  const image = table(480, 360, { seed: 13 });
  for (const [x, y] of scatter(image, 30, 30)) rect(image, x, y, 16, 7, image.random() * Math.PI, BLACK);
  const r = run(image);
  check('30 entretoises en basse résolution (480 x 360)', r.count === 30, `compté ${r.count}`);
}
{
  const image = table(640, 480, { seed: 14 });
  const spots = scatter(image, 15, 80);
  spots.slice(0, 12).forEach(([x, y]) => rect(image, x, y, 30, 24, 0, BLACK));
  spots.slice(12).forEach(([x, y]) => { rect(image, x - 15, y, 30, 24, 0, BLACK); rect(image, x + 15, y, 30, 24, 0, BLACK); });
  const r = run(image);
  check('12 pièces seules et 3 paires qui se touchent : 18', r.count === 18 && r.groups === 3, `compté ${r.count}, groupes ${r.groups}`);
}
{
  const image = table(640, 480, { seed: 15, tint: [1, 0.96, 0.88] });
  const spots = scatter(image, 15, 60);
  spots.slice(0, 8).forEach(([x, y]) => rect(image, x, y, 30, 24, image.random(), [205, 38, 47]));
  spots.slice(8).forEach(([x, y]) => rect(image, x, y, 30, 24, image.random(), [238, 200, 46]));
  const r = run(image);
  check('Pièces rouges et jaunes sous un éclairage chaud', r.count === 15, `compté ${r.count}`);
}
{
  const image = table(640, 480, { seed: 16 });
  rect(image, 40, 240, 80, 480, 0, [58, 44, 34]);
  rect(image, 320, 25, 640, 50, 0, [58, 44, 34]);
  for (const [x, y] of scatter({ ...image, width: 500, height: 380 }, 16, 56)) rect(image, x + 110, y + 70, 30, 24, image.random(), BLACK);
  const r = run(image);
  check('Bord sombre de la table autour de la feuille blanche', r.count === 16 && r.edges === 0, `compté ${r.count}, bords ${r.edges}`);
}
{
  const image = table(640, 480, { seed: 17 });
  for (const [x, y] of scatter(image, 10, 70)) rect(image, x, y, 30, 24, 0, BLACK);
  rect(image, 632, 240, 30, 24, 0, BLACK);
  const r = run(image);
  check('Pièce coupée par le bord : signalée, pas comptée', r.count === 10 && r.edges === 1, `compté ${r.count}, bords ${r.edges}`);
}
{
  const image = table(640, 480, { seed: 18 });
  const spots = scatter(image, 20, 60);
  spots.forEach(([x, y]) => rect(image, x + 4, y + 4, 32, 26, 0, 0.88));
  spots.forEach(([x, y]) => rect(image, x, y, 30, 24, 0, BLACK));
  const r = run(image);
  check('Ombres douces sous les pièces', r.count === 20 && r.groups === 0, `compté ${r.count}, groupes ${r.groups}`);
}
{
  const image = table(640, 480, { seed: 19 });
  const spots = scatter(image, 14, 70);
  for (const [x, y] of spots) {
    rect(image, x, y, 40, 32, 0, BLACK);
    for (const [dx, dy] of [[-14, -10], [14, -10], [-14, 10], [14, 10]]) disc(image, x + dx, y + dy, 3, [228, 228, 226]);
  }
  const r = run(image);
  const areas = r.blobs.filter((b) => b.kind === 'piece').map((b) => b.area);
  check('Cartes percées de trous de fixation : trous bouchés', r.count === 14 && Math.min(...areas) > 40 * 32 * 0.9, `compté ${r.count}, plus petite zone ${Math.min(...areas)}`);
}
{
  const image = table(640, 480, { seed: 20, falloff: 0.4 });
  for (const [x, y] of scatter(image, 18, 60)) rect(image, x, y, 30, 24, image.random(), [90, 92, 98]);
  const r = run(image);
  check('Éclairage très inégal (−40 % d\'un bord à l\'autre), pièces grises', r.count === 18, `compté ${r.count}`);
}
{
  const single = table(640, 480, { seed: 21 });
  for (const [x, y] of scatter(single, 6, 90)) rect(single, x, y, 30, 24, 0, BLACK);
  const learned = run(single).pieceRatio;
  const image = table(640, 480, { seed: 22 });
  for (const [x, y] of scatter(image, 7, 110)) { rect(image, x - 15, y, 30, 24, 0, BLACK); rect(image, x + 15, y, 30, 24, 0, BLACK); }
  const free = run(image);
  const locked = run(image, { pieceRatio: learned });
  check('Taille de pièce mémorisée : 7 paires collées comptent 14', locked.count === 14 && free.count === 7, `mémorisée ${locked.count}, libre ${free.count}`);
}

{
  const image = table(640, 480, { seed: 29, tint: [0.72, 0.85, 1] });
  const spots = scatter(image, 14, 60);
  spots.slice(0, 9).forEach(([x, y]) => rect(image, x, y, 30, 12, image.random() * Math.PI, [238, 238, 234]));
  spots.slice(9).forEach(([x, y]) => rect(image, x, y, 30, 12, image.random() * Math.PI, BLACK));
  const r = run(image);
  check('Pièces blanches et noires sur une feuille bleu clair', r.count === 14, `compté ${r.count}`);
}

console.log('== Cas limites');
{
  const image = table(640, 480, { seed: 23 });
  const r = run(image);
  check('Table vide : zéro', r.count === 0 && !r.tooDark && !r.noBackground, `compté ${r.count}`);
}
{
  const image = table(640, 480, { seed: 24, level: 48 });
  check('Image trop sombre : signalée', run(image).tooDark === true);
}
{
  const image = table(640, 480, { seed: 25 });
  rect(image, 210, 240, 420, 480, 0, [70, 60, 50]);
  const r = run(image);
  check('Table sombre sur les deux tiers de l\'image : fond non reconnu', r.noBackground === true && r.count === 0, `couverture ${r.coverage.toFixed(2)}, compté ${r.count}`);
}
{
  const image = table(640, 480, { seed: 26 });
  for (const [x, y] of scatter(image, 12, 70)) rect(image, x, y, 30, 24, 0, BLACK);
  disc(image, 30, 30, 1.5, BLACK);
  disc(image, 600, 440, 2, BLACK);
  const r = run(image);
  check('Poussières ignorées', r.count === 12, `compté ${r.count}`);
}
{
  const image = table(320, 240, { seed: 27 });
  rect(image, 100, 100, 30, 24, 0, BLACK);
  const r = run(image);
  const hit = blobAt(r, 100, 100); const near = blobAt(r, 118, 100); const far = blobAt(r, 250, 200);
  check('Désignation d\'une zone au doigt', hit >= 0 && near === hit && far === -1, `${hit}, ${near}, ${far}`);
  const overlay = paintOverlay(r, new Uint8ClampedArray(320 * 240 * 4));
  const centre = (100 * 320 + 100) * 4; const outside = (200 * 320 + 250) * 4;
  check('Calque : pièce teintée, fond transparent', overlay[centre + 3] > 0 && overlay[centre + 1] > overlay[centre] && overlay[outside + 3] === 0);
}
{
  const image = table(960, 720, { seed: 28 });
  for (const [x, y] of scatter(image, 60, 60)) rect(image, x, y, 34, 26, image.random() * Math.PI, BLACK);
  const start = performance.now();
  const r = run(image);
  const ms = performance.now() - start;
  check(`60 pièces en 960 x 720 (${ms.toFixed(0)} ms)`, r.count === 60 && ms < 1500, `compté ${r.count}`);
}

console.log(failed ? `\n${failed} échec(s).` : '\nTous les tests passent.');
process.exit(failed ? 1 : 0);
