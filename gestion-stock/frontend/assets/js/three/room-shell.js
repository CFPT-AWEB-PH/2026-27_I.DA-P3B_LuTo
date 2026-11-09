// Enveloppe d'une salle : sol, murs percés et ouvertures (portes, fenêtres, passages).
// Partagée par l'éditeur d'une salle et par le plan du bâtiment. Toutes les pièces portent
// userData : roomId, et wallIndex pour un mur, openingId pour une ouverture, isFloor pour le sol.

import * as THREE from '/vendor/three/three.module.min.js';
import { containsPoint, referenceDoor, wallLength } from '../spatial.js';

const S = 0.001;
const FRAME = 45; // largeur du cadre des portes et fenêtres, en mm
const DOOR_ANGLE = 1.2; // porte ouverte à environ 70° : on voit son sens d'ouverture depuis le plan

/** Sol, murs et ouvertures. @returns {{ group, walls, wallMaterial, openingGroups: Map }} */
export function buildShell(room, openings) {
  const group = new THREE.Group();
  const outline = room.outline;

  const shape = new THREE.Shape(outline.map(([x, z]) => new THREE.Vector2(x * S, -z * S)));
  const floorGeometry = new THREE.ShapeGeometry(shape);
  floorGeometry.rotateX(-Math.PI / 2);
  const floorMaterial = new THREE.MeshStandardMaterial({ color: room.floor_color, roughness: 0.9 });
  const texture = floorTexture(room.floor_material);
  if (texture) {
    floorMaterial.map = texture.map;
    floorMaterial.color = new THREE.Color(room.floor_color).lerp(new THREE.Color('#ffffff'), 0.25);
    texture.map.repeat.set(1 / texture.size, 1 / texture.size);
  }
  const floor = new THREE.Mesh(floorGeometry, floorMaterial);
  floor.userData = { isFloor: true, roomId: room.id };
  group.add(floor);

  // Murs : épaisseur côté extérieur, découpés autour des ouvertures
  const height = room.height_mm;
  const thickness = Math.max(room.wall_thickness_mm, 20);
  const wallMaterial = new THREE.MeshStandardMaterial({ color: room.wall_color, roughness: 0.95, transparent: true, opacity: 1 });
  const holes = [referenceDoor(room), ...openings];
  const walls = [];
  const openingGroups = new Map();

  outline.forEach(([x1, z1], i) => {
    const [x2, z2] = outline[(i + 1) % outline.length];
    const length = wallLength(outline, i);
    if (length === 0) return;
    const ux = (x2 - x1) / length;
    const uz = (z2 - z1) / length;
    let nx = -uz; let nz = ux;
    const mx = (x1 + x2) / 2; const mz = (z1 + z2) / 2;
    if (containsPoint(outline, mx + nx * 5, mz + nz * 5)) { nx = -nx; nz = -nz; } // vers l'extérieur
    const yaw = -Math.atan2(uz, ux);
    // Le z local du mur vaut (-uz, ux) : côté intérieur selon le sens du contour
    const inward = nx * -uz + nz * ux > 0 ? -1 : 1;

    const wall = new THREE.Group();
    wall.userData = { outward: new THREE.Vector3(nx, 0, nz), center: new THREE.Vector3(mx * S, height * S / 2, mz * S), wallIndex: i };
    const wallHoles = holes.filter((h) => h.wall_index === i);
    const cuts = [...new Set([0, length, ...wallHoles.flatMap((h) => [h.offset_mm, h.offset_mm + h.width_mm])])].sort((a, b) => a - b);

    for (let k = 0; k < cuts.length - 1; k++) {
      const u0 = cuts[k]; const u1 = cuts[k + 1];
      if (u1 - u0 < 1) continue;
      let solid = [[0, height]];
      for (const h of wallHoles) {
        if (h.offset_mm < u1 - 0.5 && h.offset_mm + h.width_mm > u0 + 0.5) {
          solid = solid.flatMap(([a, b]) => {
            const parts = [];
            if (h.sill_mm > a) parts.push([a, Math.min(b, h.sill_mm)]);
            if (h.sill_mm + h.height_mm < b) parts.push([Math.max(a, h.sill_mm + h.height_mm), b]);
            return parts.filter(([p, q]) => q - p > 0.5);
          });
        }
      }
      for (const [v0, v1] of solid) {
        const piece = new THREE.Mesh(new THREE.BoxGeometry((u1 - u0) * S, (v1 - v0) * S, thickness * S), wallMaterial);
        const uc = (u0 + u1) / 2;
        piece.position.set((x1 + ux * uc + nx * thickness / 2) * S, ((v0 + v1) / 2) * S, (z1 + uz * uc + nz * thickness / 2) * S);
        piece.rotation.y = yaw;
        piece.userData = { roomId: room.id, wallIndex: i };
        wall.add(piece);
      }
    }
    // Poteau d'angle extérieur, pour fermer la jonction avec le mur suivant
    const post = new THREE.Mesh(new THREE.BoxGeometry(thickness * S, height * S, thickness * S), wallMaterial);
    post.position.set((x2 + nx * thickness / 2 + ux * thickness / 2) * S, height * S / 2, (z2 + nz * thickness / 2 + uz * thickness / 2) * S);
    post.rotation.y = yaw;
    post.userData = { roomId: room.id, wallIndex: i };
    wall.add(post);

    for (const hole of wallHoles) {
      const model = openingModel(hole, thickness, inward);
      model.position.set((x1 + ux * hole.offset_mm + nx * thickness / 2) * S, hole.sill_mm * S, (z1 + uz * hole.offset_mm + nz * thickness / 2) * S);
      model.rotation.y = yaw;
      model.traverse((o) => { o.userData.roomId = room.id; o.userData.openingId = hole.id; });
      // Hors du groupe du mur : une porte reste visible et sélectionnable quand son mur est masqué par la caméra
      group.add(model);
      openingGroups.set(hole.id, model);
    }
    walls.push(wall);
    group.add(wall);
  });

  return { group, walls, wallMaterial, openingGroups };
}

/** Modèle d'une ouverture : origine au coin bas du trou, x le long du mur, milieu de l'épaisseur du mur. */
function openingModel(o, thickness, inward) {
  const w = o.width_mm; const h = o.height_mm; const t = thickness;
  const group = new THREE.Group();
  const frameColor = o.kind === 'window' ? 0xe8ecef : 0x7a5a36;
  const frame = new THREE.MeshStandardMaterial({ color: frameColor, roughness: 0.7 });
  const add = (lx, ly, lz, x, y, z, material = frame) => {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(lx * S, ly * S, lz * S), material);
    mesh.position.set(x * S, y * S, z * S);
    group.add(mesh);
    return mesh;
  };

  // Cadre, sauf dans un simple passage
  if (o.kind !== 'opening') {
    add(FRAME, h, t + 10, FRAME / 2, h / 2, 0);
    add(FRAME, h, t + 10, w - FRAME / 2, h / 2, 0);
    add(w, FRAME, t + 10, w / 2, h - FRAME / 2, 0);
  }
  if (o.kind === 'window') {
    add(w, FRAME, t + 10, w / 2, FRAME / 2, 0);
    if (w > 900) add(FRAME * 0.7, h, 30, w / 2, h / 2, 0);
    const glass = new THREE.MeshStandardMaterial({ color: 0x9fd3ff, transparent: true, opacity: 0.3, side: THREE.DoubleSide, depthWrite: false });
    add(w - FRAME, h - FRAME, 6, w / 2, h / 2, 0, glass);
  }
  if (o.kind === 'door') {
    // Battant ouvert vers l'intérieur de la salle, pivot sur le montant de départ
    const hinge = new THREE.Group();
    hinge.position.set(FRAME * S, 0, (inward * t / 2) * S);
    hinge.rotation.y = -inward * DOOR_ANGLE;
    const leafW = Math.max(w - 2 * FRAME, 50);
    const leafH = Math.max(h - FRAME - 8, 50);
    const leafMaterial = new THREE.MeshStandardMaterial({ color: 0xb58a55, roughness: 0.65 });
    const leaf = new THREE.Mesh(new THREE.BoxGeometry(leafW * S, leafH * S, 38 * S), leafMaterial);
    leaf.position.set((leafW / 2) * S, (leafH / 2 + 4) * S, 0);
    const handle = new THREE.Mesh(new THREE.BoxGeometry(110 * S, 18 * S, 60 * S), new THREE.MeshStandardMaterial({ color: 0xc9ccd2, metalness: 0.5, roughness: 0.4 }));
    handle.position.set((leafW - 90) * S, 1050 * S, 0);
    hinge.add(leaf, handle);
    group.add(hinge);
  }
  // Zone de sélection : couvre tout le trou, même quand il est vide ou que la porte est ouverte
  const pick = new THREE.Mesh(new THREE.BoxGeometry(w * S, h * S, (t + 20) * S), new THREE.MeshBasicMaterial({ transparent: true, opacity: 0, depthWrite: false }));
  pick.position.set((w / 2) * S, (h / 2) * S, 0);
  pick.userData.isPickZone = true;
  group.add(pick);
  return group;
}

/** Textures de sol générées (pas de fichier image à télécharger). */
export function floorTexture(kind) {
  if (kind === 'plain') return null;
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = 256;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#d8d8d8';
  ctx.fillRect(0, 0, 256, 256);
  let size = 1.2;
  if (kind === 'wood') {
    for (let row = 0; row < 8; row++) {
      ctx.fillStyle = `rgb(${200 + (row * 37) % 40}, ${200 + (row * 37) % 40}, ${200 + (row * 37) % 40})`;
      ctx.fillRect(0, row * 32, 256, 31);
      ctx.fillStyle = '#9a9a9a';
      ctx.fillRect(((row * 97) % 256), row * 32, 2, 31);
      ctx.fillRect(0, row * 32 + 31, 256, 1);
    }
  } else if (kind === 'tiles') {
    ctx.fillStyle = '#9e9e9e';
    for (let i = 0; i <= 4; i++) {
      ctx.fillRect(i * 64 - 1, 0, 2, 256);
      ctx.fillRect(0, i * 64 - 1, 256, 2);
    }
  } else {
    size = 2;
    for (let i = 0; i < 3000; i++) {
      const v = 180 + Math.floor(((i * 7919) % 60));
      ctx.fillStyle = `rgb(${v}, ${v}, ${v})`;
      ctx.fillRect((i * 131) % 256, (i * 197) % 256, 2, 2);
    }
  }
  const map = new THREE.CanvasTexture(canvas);
  map.wrapS = map.wrapT = THREE.RepeatWrapping;
  map.colorSpace = THREE.SRGBColorSpace;
  return { map, size };
}
