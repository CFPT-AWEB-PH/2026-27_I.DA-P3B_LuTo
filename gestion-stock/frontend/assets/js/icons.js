// Icônes SVG en ligne (trait 24 × 24, style homogène). Construites par le DOM : compatibles avec la CSP.

const SVG = 'http://www.w3.org/2000/svg';

// Chaque icône : liste de tracés (« d » de path) ; « c:x,y,r » = cercle, « r:x,y,w,h,rx » = rectangle
const PATHS = {
  home: ['M3 10.5 12 3l9 7.5', 'M5 9.5V20h14V9.5', 'M10 20v-6h4v6'],
  box: ['M21 8 12 3 3 8v8l9 5 9-5V8Z', 'M3 8l9 5 9-5', 'M12 13v8'],
  room: ['M4 21V4h11v17', 'M15 21h5', 'M4 21h3', 'M11 12.5v.01', 'M15 6l5 1.5V21'],
  loans: ['M7 4 3 8l4 4', 'M3 8h13a4 4 0 0 1 0 8h-2', 'M17 20l4-4-4-4', 'M21 16H8'],
  history: ['M3 12a9 9 0 1 0 2.6-6.4L3 8', 'M3 3v5h5', 'M12 7.5V12l3 2'],
  tag: ['M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z', 'c:7.5,7.5,1.5'],
  layers: ['M12 3 2 8l10 5 10-5-10-5Z', 'M2 13l10 5 10-5', 'M2 17.5 12 22l10-4.5'],
  users: ['c:9,8,3.5', 'M2.5 20a6.5 6.5 0 0 1 13 0', 'M16 4.6a3.5 3.5 0 0 1 0 6.8', 'M18.5 14.2A6.5 6.5 0 0 1 21.5 20'],
  shield: ['M12 3 4 6v6c0 4.5 3.4 8 8 9 4.6-1 8-4.5 8-9V6l-8-3Z', 'M9 12l2 2 4-4'],
  listCheck: ['M11 6h10', 'M11 12h10', 'M11 18h10', 'M3 6l1.5 1.5L7 5', 'M3 12l1.5 1.5L7 11', 'M3 18l1.5 1.5L7 17'],
  sliders: ['M4 6h9', 'M17 6h3', 'M4 12h3', 'M11 12h9', 'M4 18h11', 'M19 18h1', 'c:15,6,2', 'c:9,12,2', 'c:17,18,2'],
  search: ['c:11,11,7', 'M20.5 20.5 16 16'],
  x: ['M18 6 6 18', 'M6 6l12 12'],
  menu: ['M4 7h16', 'M4 12h16', 'M4 17h16'],
  plus: ['M12 5v14', 'M5 12h14'],
  minus: ['M5 12h14'],
  chevronRight: ['M9 6l6 6-6 6'],
  chevronLeft: ['M15 6l-6 6 6 6'],
  chevronDown: ['M6 9l6 6 6-6'],
  arrowLeft: ['M19 12H5', 'M11 6l-6 6 6 6'],
  eye: ['M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z', 'c:12,12,3'],
  eyeOff: ['M3 3l18 18', 'M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3 3.9', 'M6.6 6.6A16.5 16.5 0 0 0 2 12s3.6 7 10 7a9.6 9.6 0 0 0 5.4-1.6', 'M9.9 9.9a3 3 0 0 0 4.2 4.2'],
  cube: ['M12 2.5 20.5 7v10L12 21.5 3.5 17V7L12 2.5Z', 'M3.5 7 12 11.5 20.5 7', 'M12 11.5v10'],
  logOut: ['M15 4h4v16h-4', 'M10 8l-4 4 4 4', 'M6 12h10'],
  check: ['M5 12.5l4.5 4.5L19 7.5'],
  alert: ['M12 3.5 2.5 20h19L12 3.5Z', 'M12 10v4', 'M12 17v.01'],
  alertCircle: ['c:12,12,9', 'M12 7.5v5', 'M12 16v.01'],
  info: ['c:12,12,9', 'M12 11v5', 'M12 8v.01'],
  more: ['M5 12h.01', 'M12 12h.01', 'M19 12h.01'],
  take: ['M12 3v12', 'M7 10l5 5 5-5', 'M4 21h16'],
  undo: ['M9 14 4 9l5-5', 'M4 9h11a5 5 0 0 1 0 10h-3'],
  redo: ['M15 14l5-5-5-5', 'M20 9H9a5 5 0 0 0 0 10h3'],
  cut: ['c:6,6,3', 'c:6,18,3', 'M8.5 8 20 19', 'M8.5 16 20 5'],
  paste: ['r:5,4,14,17,2', 'r:9,2,6,4,1', 'M9 12h6', 'M9 16h6'],
  save: ['M5 3h11l4 4v14H4V4a1 1 0 0 1 1-1Z', 'M8 3v6h7', 'M8 21v-7h8v7'],
  plan: ['M3 5l6-2 6 2 6-2v16l-6 2-6-2-6 2V5Z', 'M9 3v16', 'M15 5v16'],
  stairs: ['M3 20h5v-5h5v-5h5V5h3'],
  window: ['r:4,3,16,18,1', 'M12 3v18', 'M4 12h16'],
  help: ['c:12,12,9', 'M9.5 9.5a2.5 2.5 0 1 1 3.6 2.2c-.7.4-1.1 1-1.1 1.8v.5', 'M12 17v.01'],
  library: ['M4 4h4v16H4Z', 'M10 4h4v16h-4Z', 'M16 5l3.5-1 3 15.5-3.5 1Z'],
  move: ['M5 9l-3 3 3 3', 'M9 5l3-3 3 3', 'M15 19l-3 3-3-3', 'M19 9l3 3-3 3', 'M2 12h20', 'M12 2v20'],
  edit: ['M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4Z', 'M13.5 6.5l4 4'],
  trash: ['M4 7h16', 'M10 11v6', 'M14 11v6', 'M6 7l1 13h10l1-13', 'M9 7V4h6v3'],
  image: ['r:3,4,18,16,2', 'c:9,10,2', 'M21 16l-5-5-9 9'],
  refresh: ['M20 11a8 8 0 0 0-14.8-3.5L3 10', 'M3 4v6h6', 'M4 13a8 8 0 0 0 14.8 3.5L21 14', 'M21 20v-6h-6'],
  wifiOff: ['M3 3l18 18', 'M8.5 16.4a5 5 0 0 1 7 0', 'M5 12.9a10 10 0 0 1 5.2-2.7', 'M19 12.9a10 10 0 0 0-2.1-1.6', 'M2 9.3a15 15 0 0 1 4.3-2.6', 'M22 9.3A15 15 0 0 0 11 5.1', 'M12 20h.01'],
  pin: ['M12 21s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12Z', 'c:12,9,2.5'],
  copy: ['r:8,8,13,13,2', 'M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3'],
  rotateLeft: ['M3 12a9 9 0 1 0 3-6.7L3 8', 'M3 3v5h5'],
  rotateRight: ['M21 12a9 9 0 1 1-3-6.7L21 8', 'M21 3v5h-5'],
  ruler: ['M3 16.5 16.5 3 21 7.5 7.5 21 3 16.5Z', 'M7.5 12l2 2', 'M10.5 9l2 2', 'M13.5 6l2 2'],
  focus: ['M3 8V3h5', 'M16 3h5v5', 'M21 16v5h-5', 'M8 21H3v-5', 'c:12,12,3'],
  drawer: ['r:3,4,18,16,2', 'M3 12h18', 'M10 8h4', 'M10 16h4'],
  grid: ['r:3,3,7,7,1', 'r:14,3,7,7,1', 'r:3,14,7,7,1', 'r:14,14,7,7,1'],
  door: ['M5 21V3h14v18', 'M3 21h18', 'M15 12v.01'],
  palette: ['M12 3a9 9 0 0 0 0 18c1.4 0 2-1 2-2 0-1.5-1.2-1.7-1.2-3 0-1 .8-2 2-2H17a4 4 0 0 0 4-4c0-4-4-7-9-7Z', 'c:7.5,11,1', 'c:10,7,1', 'c:15,7,1'],
};

/** icon('box') → <svg> décoratif (aria-hidden). Taille via CSS (.icon, 1.25em par défaut). */
export function icon(name, className = '') {
  const el = document.createElementNS(SVG, 'svg');
  el.setAttribute('viewBox', '0 0 24 24');
  el.setAttribute('class', `icon ${className}`.trim());
  el.setAttribute('aria-hidden', 'true');
  el.setAttribute('focusable', 'false');
  for (const spec of PATHS[name] ?? []) {
    let shape;
    if (spec.startsWith('c:')) {
      const [cx, cy, r] = spec.slice(2).split(',');
      shape = document.createElementNS(SVG, 'circle');
      Object.entries({ cx, cy, r }).forEach(([k, v]) => shape.setAttribute(k, v));
    } else if (spec.startsWith('r:')) {
      const [x, y, width, height, rx] = spec.slice(2).split(',');
      shape = document.createElementNS(SVG, 'rect');
      Object.entries({ x, y, width, height, rx }).forEach(([k, v]) => shape.setAttribute(k, v));
    } else {
      shape = document.createElementNS(SVG, 'path');
      shape.setAttribute('d', spec);
    }
    el.append(shape);
  }
  return el;
}
