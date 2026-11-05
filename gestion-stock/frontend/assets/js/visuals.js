// Apparence 3D des objets : modèles disponibles et reconnaissance d'après le nom.
// Sans dépendance à Three.js : utilisé aussi par les formulaires.

export const VISUALS = {
  auto: 'Automatique (d’après le nom)',
  screw: 'Vis',
  nut: 'Écrou',
  washer: 'Rondelle',
  resistor: 'Résistance',
  capacitor: 'Condensateur',
  chip: 'Circuit, socle',
  wire: 'Câble, fil',
  screwdriver: 'Tournevis',
  pliers: 'Pince',
  wrench: 'Clé',
  hammer: 'Marteau',
  beam: 'Profilé, barre',
  wheel: 'Roue, poulie',
  box: 'Pièce, boîtier',
};

const RULES = [
  [/tournevis/, 'screwdriver'],
  [/resistance|\bohm/, 'resistor'],
  [/condensateur|capacite/, 'capacitor'],
  [/ecrou/, 'nut'],
  [/rondelle/, 'washer'],
  [/\bpinces?\b/, 'pliers'],
  [/\bcles?\b/, 'wrench'],
  [/marteau|maillet/, 'hammer'],
  [/cable|\bfils?\b/, 'wire'],
  [/\broues?\b|poulie|pneu/, 'wheel'],
  [/profile|barre|poutre|equerre/, 'beam'],
  [/\bsocles?\b|circuit|\bic\b|microcontroleur|arduino/, 'chip'],
  [/\bvis\b|\bboulons?\b|\bm\d+(?:[.,]\d+)?\s*[x×*]\s*\d/, 'screw'],
];

function normalize(text) {
  return String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/** Modèle à afficher : celui choisi sur la fiche, sinon déduit du nom puis de la catégorie. */
export function resolveVisual(entry) {
  if (entry.visual && entry.visual !== 'auto') return entry.visual;
  for (const source of [entry.name, entry.category]) {
    const text = normalize(source);
    for (const [pattern, visual] of RULES) if (pattern.test(text)) return visual;
  }
  return 'box';
}

/** « Vis M3 x 12 » → { diameter: 3, length: 12 } ; « Écrou M4 » → { diameter: 4 }. */
export function sizeFromName(name) {
  const text = String(name ?? '');
  const both = text.match(/\bM\s*(\d+(?:[.,]\d+)?)\s*[xX×*]\s*(\d+(?:[.,]\d+)?)/);
  if (both) return { diameter: Number(both[1].replace(',', '.')), length: Number(both[2].replace(',', '.')) };
  const one = text.match(/\bM\s*(\d+(?:[.,]\d+)?)\b/);
  return one ? { diameter: Number(one[1].replace(',', '.')) } : {};
}
