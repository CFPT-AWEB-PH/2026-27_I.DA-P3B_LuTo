// Scène 3D d'une salle : murs avec ouvertures, sol, meubles et rangements imbriqués.
// Rendu à la demande (aucune boucle permanente) pour ménager les appareils modestes.
// 1 unité Three.js = 1 mètre ; les données restent en millimètres.

import * as THREE from '/vendor/three/three.module.min.js';
import { OrbitControls } from '/vendor/three/OrbitControls.js';
import { CSS2DObject, CSS2DRenderer } from '/vendor/three/CSS2DRenderer.js';
import { TransformControls } from '/vendor/three/TransformControls.js';
import {
  clampToParent, clampToRoom, containsPoint, formatLength, freeFloorArea, interior, openingProblem, referenceDoor, rotation, slideOpening, snapToEdges, unitFootprint, unitProblem, wallFrame, wallLength,
} from '../spatial.js';
import { buildObjects } from './item-models.js';
import { buildShell } from './room-shell.js';
import { boxCorners, fitPosition } from './fit.js';
import { formatQuantity } from '../stock-dialogs.js';

const S = 0.001;
const SELECT_COLOR = 0xffa31a;
const INVALID_COLOR = 0xe5484d;
const HIGHLIGHT_COLOR = 0x2f80ff;
const DRAWER_OPEN_RATIO = 0.97;
const VALID_COLOR = 0x2fb56f;
const SNAP_DISTANCE = 12; // mm : distance d'aimantation aux bords voisins
const STRIP_COLOR = 0xd9dbd6; // bandeau métallique des tiroirs
const OPEN_TOP = new Set(['drawer', 'box', 'bin']);
const MIN_LOOSE_AREA = 40; // mm : plus étroit, la place libre d'un rangement divisé ne montre pas d'objets

export class RoomScene {
  /**
   * @param {HTMLElement} container
   * @param {{ onSelect?: Function, onMove?: Function, onOpenUnit?: Function }} callbacks
   */
  constructor(container, callbacks = {}) {
    this.container = container;
    this.callbacks = callbacks;
    this.unitGroups = new Map();
    this.units = [];
    this.hidden = new Set();
    this.openDrawers = new Set();
    this.selectedId = null;
    this.highlightId = null;
    this.showDimensions = false;
    this.wallMode = 'auto';
    this.minimal = callbacks.minimal ?? false;
    this.gizmoMode = 'translate';
    this.draft = null;
    this.materials = new Map();
    this.contents = [];
    this.walls = [];
    this.animations = [];
    this.renderQueued = false;
    this.selectedOpening = null;
    this.openingOverride = null;

    this.renderer = new THREE.WebGLRenderer({ antialias: true });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.renderer.outputColorSpace = THREE.SRGBColorSpace;
    this.renderer.domElement.className = 'scene-canvas';
    this.renderer.domElement.tabIndex = 0;
    container.append(this.renderer.domElement);

    this.labelRenderer = new CSS2DRenderer();
    this.labelRenderer.domElement.className = 'scene-labels';
    container.append(this.labelRenderer.domElement);

    this.scene = new THREE.Scene();
    const background = getComputedStyle(container).getPropertyValue('--scene-bg').trim() || '#dde2ea';
    this.scene.background = new THREE.Color(background);

    this.camera = new THREE.PerspectiveCamera(50, 1, 0.01, 500);
    this.controls = new OrbitControls(this.camera, this.renderer.domElement);
    this.controls.screenSpacePanning = true;
    this.controls.maxPolarAngle = Math.PI / 2 - 0.01;
    this.controls.minDistance = 0.08;
    this.controls.maxDistance = 150;
    this.controls.addEventListener('change', () => {
      this.updateWallVisibility();
      this.requestRender();
    });

    this.scene.add(new THREE.HemisphereLight(0xffffff, 0x8a8170, 1.6));
    const sun = new THREE.DirectionalLight(0xffffff, 1.4);
    sun.position.set(5, 10, 3);
    this.scene.add(sun);

    this.roomGroup = new THREE.Group();
    this.unitsGroup = new THREE.Group();
    this.overlayGroup = new THREE.Group();
    this.scene.add(this.roomGroup, this.unitsGroup, this.overlayGroup);

    this.raycaster = new THREE.Raycaster();
    this.pointer = new THREE.Vector2();
    if (!this.minimal) this.createGizmo();
    this.bindEvents();

    this.resizeObserver = new ResizeObserver(() => this.resize());
    this.resizeObserver.observe(container);
    this.resize();
  }

  // ===== Données =====

  setData(room, openings, units, contents = []) {
    const first = !this.room;
    this.room = room;
    this.openings = openings;
    this.units = units;
    this.contents = contents;
    const ids = new Set(units.map((u) => u.id));
    for (const id of [...this.hidden]) if (!ids.has(id)) this.hidden.delete(id);
    for (const id of [...this.openDrawers]) if (!ids.has(id)) this.openDrawers.delete(id);
    if (this.selectedId !== null && !ids.has(this.selectedId)) this.selectedId = null;

    this.detachGizmo();
    this.openingOverride = null;
    if (this.selectedOpening !== null && !this.opening(this.selectedOpening)) this.selectedOpening = null;
    this.clearGroup(this.roomGroup);
    this.clearGroup(this.unitsGroup);
    if (!this.minimal) this.buildRoom();
    this.buildUnits();
    this.reapplyFocus();
    this.refreshOverlay();
    if (first && !this.minimal) this.viewPreset('iso');
    this.updateWallVisibility();
    this.refreshGizmo();
    this.requestRender();
  }

  /** Aperçu autonome : cadre tout le contenu de la scène. */
  frameAll() {
    this.scene.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(this.unitsGroup);
    if (box.isEmpty()) return;
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    const distance = Math.max(size.length() / (2 * Math.tan((this.camera.fov * Math.PI) / 360)) * 1.15, 0.12);
    this.camera.position.copy(center).add(new THREE.Vector3(0.45, 0.8, 0.9).normalize().multiplyScalar(distance));
    this.controls.target.copy(center);
    this.controls.update();
    this.requestRender();
  }

  unit(id) {
    return this.units.find((u) => u.id === id) ?? null;
  }

  /** Couleurs imposées par rangement (ex. état de remplissage) ; null rétablit les couleurs d'origine. */
  setColorOverrides(map) {
    this.colorOverrides = map;
    if (!this.room) return;
    this.rebuildUnits();
  }

  // ===== Construction de la salle =====

  buildRoom() {
    let room = this.room;
    const outline = room.outline;

    // Pendant un glisser, l'ouverture suit le pointeur : les murs sont recalculés avec sa position provisoire
    const override = this.openingOverride;
    if (override?.id === 'ref') room = { ...room, door_offset_mm: override.offset_mm };
    const openings = override && override.id !== 'ref' ? [...this.openings.filter((o) => o.id !== override.id), override] : this.openings;
    const shell = buildShell(room, openings);
    this.wallMaterial = shell.wallMaterial;
    this.walls = shell.walls;
    this.openingGroups = shell.openingGroups;
    this.roomGroup.add(shell.group);

    // Porte de référence : marquage au sol et étiquette
    const door = new THREE.Mesh(
      new THREE.PlaneGeometry(room.door_width_mm * S, Math.min(room.door_width_mm, 900) * S),
      new THREE.MeshBasicMaterial({ color: HIGHLIGHT_COLOR, transparent: true, opacity: 0.18, depthWrite: false }),
    );
    door.rotation.x = -Math.PI / 2;
    door.position.set((room.door_offset_mm + room.door_width_mm / 2) * S, 0.002, Math.min(room.door_width_mm, 900) * S / 2);
    this.roomGroup.add(door);
    this.roomGroup.add(label('Porte principale', (room.door_offset_mm + room.door_width_mm / 2) * S, room.door_height_mm * S + 0.08, 0, 'scene-label scene-label-door'));

    // Origine et axes
    const axes = new THREE.AxesHelper(0.5);
    axes.position.y = 0.003;
    this.roomGroup.add(axes);
    this.roomGroup.add(label('Origine (0, 0, 0)', 0, 0.05, 0, 'scene-label scene-label-origin'));
    this.roomGroup.add(label('X', 0.55, 0.02, 0, 'scene-label scene-axis'));
    this.roomGroup.add(label('Z', 0, 0.02, 0.55, 'scene-label scene-axis'));

    // Cotes de la salle
    this.roomDims = new THREE.Group();
    outline.forEach(([x1, z1], i) => {
      const [x2, z2] = outline[(i + 1) % outline.length];
      this.roomDims.add(label(`Mur ${i + 1} : ${formatLength(wallLength(outline, i), room.display_unit)}`,
        ((x1 + x2) / 2) * S, 0.05, ((z1 + z2) / 2) * S, 'scene-label scene-dim'));
    });
    this.roomDims.add(label(`Hauteur : ${formatLength(room.height_mm, room.display_unit)}`, 0, room.height_mm * S, 0, 'scene-label scene-dim'));
    this.roomDims.visible = this.showDimensions;
    this.roomGroup.add(this.roomDims);
  }

  // ===== Rangements =====

  buildUnits() {
    this.unitGroups.clear();
    this.childrenOf = new Map();
    for (const u of this.units) {
      const list = this.childrenOf.get(u.parent_id) ?? [];
      list.push(u);
      this.childrenOf.set(u.parent_id, list);
    }
    this.contentsOf = new Map();
    for (const c of this.contents) {
      const list = this.contentsOf.get(c.unit_id) ?? [];
      list.push(c);
      this.contentsOf.set(c.unit_id, list);
    }
    for (const u of this.childrenOf.get(null) ?? []) this.buildUnit(u, this.unitsGroup, null);
  }

  rebuildUnits() {
    this.detachGizmo();
    this.clearGroup(this.unitsGroup);
    this.buildUnits();
    this.reapplyFocus();
    this.refreshOverlay();
    this.refreshGizmo();
    this.requestRender();
  }

  /** Place un rangement dans le repère de son parent (intérieur) ou de la salle. */
  placeUnit(group, u, parent) {
    const inside = parent ? interior(parent.shape, parent.width_mm, parent.depth_mm, parent.height_mm, parent.panel_mm) ?? { x: 0, y: 0, z: 0 } : { x: 0, y: 0, z: 0 };
    group.position.set((inside.x + u.pos_x_mm) * S, (inside.y + u.pos_y_mm) * S, (inside.z + u.pos_z_mm) * S);
    group.rotation.y = parent ? 0 : (u.rotation_deg * Math.PI) / 180;
    group.userData.baseZ = group.position.z;
    if (u.shape === 'drawer' && this.openDrawers.has(u.id)) group.position.z += u.depth_mm * DRAWER_OPEN_RATIO * S;
  }

  buildUnit(u, parentObject, parent) {
    const group = new THREE.Group();
    group.userData.unitId = u.id;
    this.placeUnit(group, u, parent);
    group.visible = !this.hidden.has(u.id);
    this.buildPanels(u, group, { covered: this.isCovered(u) });

    const content = new THREE.Group();
    group.add(content);
    group.userData.content = content;
    parentObject.add(group);
    this.unitGroups.set(u.id, group);
    if (u.shape !== 'drawer' || this.openDrawers.has(u.id)) this.buildContent(u);
    else content.visible = false;
  }

  /** Un rangement frère est-il posé directement dessus ? (son étiquette ne peut alors pas dépasser du bord) */
  isCovered(u) {
    const top = u.pos_y_mm + u.height_mm;
    return (this.childrenOf.get(u.parent_id) ?? []).some((o) => o.id !== u.id
      && Math.abs(o.pos_y_mm - top) <= 5
      && o.pos_x_mm < u.pos_x_mm + u.width_mm && o.pos_x_mm + o.width_mm > u.pos_x_mm
      && o.pos_z_mm < u.pos_z_mm + u.depth_mm && o.pos_z_mm + o.depth_mm > u.pos_z_mm);
  }

  /** Sous-rangements et objets : pour un tiroir, construits à sa première ouverture (économise la mémoire et le rendu). */
  buildContent(u) {
    const group = this.unitGroups.get(u.id);
    if (!group || group.userData.contentBuilt) return;
    group.userData.contentBuilt = true;
    const content = group.userData.content;
    const kids = this.childrenOf.get(u.id) ?? [];
    for (const child of kids) this.buildUnit(child, content, u);
    const entries = this.contentsOf.get(u.id);
    const inside = interior(u.shape, u.width_mm, u.depth_mm, u.height_mm, u.panel_mm);
    // Un rangement divisé montre ses objets directs dans la place restée libre entre ses subdivisions
    if (entries && inside) {
      const free = freeFloorArea(inside, kids);
      if (free.width >= MIN_LOOSE_AREA && free.depth >= MIN_LOOSE_AREA) {
        const objects = buildObjects(u.id, { ...inside, ...free }, entries);
        objects.position.set((inside.x + free.x) * S, inside.y * S, (inside.z + free.z) * S);
        content.add(objects);
      }
    }
  }

  material(color, variant = '') {
    const key = color + variant;
    if (!this.materials.has(key)) {
      const base = new THREE.Color(color);
      const options = { color: base, roughness: 0.75, metalness: 0.02 };
      if (variant === 'front') options.color = base.clone().multiplyScalar(0.85);
      if (variant === 'handle') Object.assign(options, { color: new THREE.Color(STRIP_COLOR), metalness: 0.25, roughness: 0.45 });
      if (variant === 'ghost') Object.assign(options, { transparent: true, opacity: 0.18, depthWrite: false });
      const material = new THREE.MeshStandardMaterial(options);
      material.userData.shared = true;
      this.materials.set(key, material);
    }
    return this.materials.get(key);
  }

  /** Étiquette imprimée : texture générée, partagée entre les étiquettes de même texte et format. */
  labelMaterial(text, widthMm, heightMm, { align = 'center', background = '#f7f5ee' } = {}) {
    const ratio = Math.round((widthMm / heightMm) * 2) / 2;
    const key = `label|${text}|${ratio}|${align}|${background}`;
    if (!this.materials.has(key)) {
      const canvas = document.createElement('canvas');
      canvas.height = 48;
      canvas.width = Math.min(1024, Math.max(48, Math.round(48 * ratio)));
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = background;
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      let size = 30;
      ctx.font = `600 ${size}px system-ui, sans-serif`;
      const room = canvas.width - 12;
      if (ctx.measureText(text).width > room) {
        size = Math.max(12, Math.floor((size * room) / ctx.measureText(text).width));
        ctx.font = `600 ${size}px system-ui, sans-serif`;
      }
      ctx.fillStyle = '#1c1f26';
      ctx.textBaseline = 'middle';
      ctx.textAlign = align;
      ctx.fillText(text, align === 'left' ? 8 : canvas.width / 2, canvas.height / 2 + 1, room);
      const map = new THREE.CanvasTexture(canvas);
      map.colorSpace = THREE.SRGBColorSpace;
      map.anisotropy = 4;
      const material = new THREE.MeshBasicMaterial({ map });
      material.userData.shared = true;
      this.materials.set(key, material);
    }
    return this.materials.get(key);
  }

  /** Fond de tiroir perforé (motif répété tous les 32 mm). */
  perforatedMaterial(color) {
    const key = `perforated|${color}`;
    if (!this.materials.has(key)) {
      const canvas = document.createElement('canvas');
      canvas.width = canvas.height = 64;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = color;
      ctx.fillRect(0, 0, 64, 64);
      ctx.fillStyle = 'rgba(232, 196, 120, .75)';
      ctx.beginPath();
      ctx.arc(32, 32, 6, 0, Math.PI * 2);
      ctx.fill();
      const map = new THREE.CanvasTexture(canvas);
      map.wrapS = map.wrapT = THREE.RepeatWrapping;
      map.colorSpace = THREE.SRGBColorSpace;
      const material = new THREE.MeshStandardMaterial({ map, roughness: 0.8 });
      material.userData.shared = true;
      this.materials.set(key, material);
    }
    return this.materials.get(key);
  }

  buildPanels(source, group, { covered = false } = {}) {
    const u = this.colorOverrides?.has(source.id) ? { ...source, color: this.colorOverrides.get(source.id) } : source;
    const { width: w, height: h, depth: d } = { width: u.width_mm, height: u.height_mm, depth: u.depth_mm };
    const p = u.panel_mm;
    const add = (x0, y0, z0, x1, y1, z1, mat = this.material(u.color)) => {
      if (x1 - x0 <= 0 || y1 - y0 <= 0 || z1 - z0 <= 0) return;
      const mesh = new THREE.Mesh(new THREE.BoxGeometry((x1 - x0) * S, (y1 - y0) * S, (z1 - z0) * S), mat);
      mesh.position.set(((x0 + x1) / 2) * S, ((y0 + y1) / 2) * S, ((z0 + z1) / 2) * S);
      mesh.userData.unitId = u.id;
      group.add(mesh);
    };
    const ghost = () => {
      add(0, 0, 0, w, h, d, this.material(u.color, 'ghost'));
      const edges = new THREE.LineSegments(new THREE.EdgesGeometry(new THREE.BoxGeometry(w * S, h * S, d * S)), new THREE.LineBasicMaterial({ color: u.color }));
      edges.position.set((w / 2) * S, (h / 2) * S, (d / 2) * S);
      group.add(edges);
    };

    const plane = (planeW, planeH, cx, cy, z, mat) => {
      const mesh = new THREE.Mesh(new THREE.PlaneGeometry(planeW * S, planeH * S), mat);
      mesh.position.set(cx * S, cy * S, z * S);
      mesh.userData.unitId = u.id;
      group.add(mesh);
    };
    // Étiquette collée sur la face avant. Sans étiquette, le nom s'il a été choisi (pas « Bac 3 » généré)
    const sticker = ({ raised = false } = {}) => {
      const text = u.label || (/^\S+ [\d.]+$/.test(u.name) ? '' : u.name);
      if (!text || w < 25 || h < 12) return;
      const stickerW = Math.min(w * 0.8, 90);
      const stickerH = Math.min(h * 0.32, 16);
      if (!raised) {
        plane(stickerW, stickerH, w / 2, h - stickerH / 2 - Math.min(h * 0.08, 5), d + 0.3, this.labelMaterial(text, stickerW, stickerH));
        return;
      }
      // Porte-étiquette incliné sur le bord avant, qui dépasse du bac : lisible d'en haut et de face,
      // même quand les bacs sont collés les uns aux autres
      const tagW = Math.min(w * 0.86, 100);
      const tagH = Math.max(Math.min(h * 0.4, 18), 9);
      const tilt = 0.75;
      const mesh = new THREE.Mesh(new THREE.PlaneGeometry(tagW * S, tagH * S), this.labelMaterial(text, tagW, tagH));
      mesh.rotation.x = -tilt;
      // Bord bas posé sur le haut de la paroi avant, le haut penché vers l'intérieur : la face regarde vers l'avant et le haut
      mesh.position.set((w / 2) * S, (h + (tagH / 2) * Math.cos(tilt) - 1) * S, (d - p / 2 - (tagH / 2) * Math.sin(tilt)) * S);
      mesh.userData.unitId = u.id;
      group.add(mesh);
      // Dos du porte-étiquette, dans la couleur du bac
      const back = new THREE.Mesh(new THREE.PlaneGeometry(tagW * S, tagH * S), this.material(u.color));
      back.rotation.x = Math.PI - tilt;
      back.position.copy(mesh.position);
      back.position.add(new THREE.Vector3(0, -Math.sin(tilt), -Math.cos(tilt)).multiplyScalar(0.0004));
      back.userData.unitId = u.id;
      group.add(back);
    };

    switch (u.shape) {
      case 'cabinet': case 'compartment': case 'cell':
        if (p === 0) return ghost();
        add(0, 0, 0, p, h, d);
        add(w - p, 0, 0, w, h, d);
        add(p, 0, 0, w - p, p, d);
        add(p, h - p, 0, w - p, h, d);
        add(p, p, 0, w - p, h - p, p);
        if (u.shape !== 'cabinet') sticker();
        return;
      case 'drawer': case 'box': case 'bin':
        if (p === 0) return ghost();
        add(0, 0, 0, w, p, d);
        add(0, p, 0, p, h, d);
        add(w - p, p, 0, w, h, d);
        add(p, p, 0, w - p, h, p);
        if (u.shape === 'drawer') {
          // Façade pleine avec bandeau-poignée : étiquette à gauche, métal à droite, comme une armoire d'atelier
          add(0, 0, d - p, w, h, d);
          const strip = Math.min(h * 0.28, Math.max(h * 0.2, 22));
          add(0, h - strip, d, w, h, d + 8, this.material('#d9dbd6', 'handle'));
          plane(w * 0.7, strip * 0.86, w * 0.36, h - strip / 2, d + 8.2, this.labelMaterial(u.label || u.name, w * 0.7, strip * 0.86, { align: 'left' }));
          // Fond perforé
          const base = new THREE.PlaneGeometry((w - 2 * p) * S, (d - 2 * p) * S);
          const uv = base.attributes.uv;
          for (let i = 0; i < uv.count; i++) uv.setXY(i, (uv.getX(i) * (w - 2 * p)) / 32, (uv.getY(i) * (d - 2 * p)) / 32);
          const floor = new THREE.Mesh(base, this.perforatedMaterial(u.color));
          floor.rotation.x = -Math.PI / 2;
          floor.position.set((w / 2) * S, (p + 0.3) * S, (d / 2) * S);
          floor.userData.unitId = u.id;
          group.add(floor);
          return;
        }
        add(p, p, d - p, w - p, h, d);
        sticker({ raised: !covered });
        return;
      case 'shelf':
        add(0, 0, 0, w, Math.max(p, 2), d);
        return;
      default:
        add(0, 0, 0, w, h, d);
    }
  }

  // ===== Sélection, cotes, mise en évidence =====

  select(id) {
    this.selectedId = id;
    this.selectedOpening = null;
    this.refreshOverlay();
    this.refreshGizmo();
    this.requestRender();
  }

  /** Sélectionne une ouverture ('ref' : la porte principale) à la place d'un rangement. */
  selectOpening(id) {
    this.selectedId = null;
    this.selectedOpening = id;
    this.refreshOverlay();
    this.refreshGizmo();
    this.requestRender();
  }

  opening(id) {
    if (id === 'ref') return referenceDoor(this.room);
    return this.openings?.find((o) => o.id === id) ?? null;
  }

  /** Met un rangement en évidence : rend visibles ses parents, ouvre les tiroirs, centre la vue. */
  highlight(id) {
    const u = this.unit(id);
    if (!u) return;
    this.highlightId = id;
    this.select(id);
    this.focus(id);
  }

  setShowDimensions(show) {
    this.showDimensions = show;
    if (this.roomDims) this.roomDims.visible = show;
    this.refreshOverlay();
    this.requestRender();
  }

  refreshOverlay() {
    for (const g of this.unitGroups.values()) {
      const old = g.children.filter((c) => c.userData.overlay);
      for (const o of old) {
        g.remove(o);
        disposeObject(o);
      }
    }
    this.clearGroup(this.overlayGroup);
    const selectedOpening = this.selectedOpening === null ? null : (this.openingOverride ?? this.opening(this.selectedOpening));
    if (selectedOpening) {
      this.openingBox = openingOutline(this.room, selectedOpening, SELECT_COLOR);
      this.overlayGroup.add(this.openingBox);
    }
    for (const [id, color] of [[this.highlightId, HIGHLIGHT_COLOR], [this.selectedId, SELECT_COLOR]]) {
      const u = this.unit(id);
      const g = this.unitGroups.get(id);
      if (!u || !g) continue;
      const box = selectionBox(u, color);
      box.userData.overlay = true;
      g.add(box);
      if (id === this.selectedId) this.selectionBox = box;
      if (id === this.selectedId && this.showDimensions) {
        const unit = this.room.display_unit;
        const dims = new THREE.Group();
        dims.userData.overlay = true;
        dims.add(label(`L ${formatLength(u.width_mm, unit)}`, (u.width_mm / 2) * S, 0, u.depth_mm * S, 'scene-label scene-dim scene-dim-unit'));
        dims.add(label(`H ${formatLength(u.height_mm, unit)}`, 0, (u.height_mm / 2) * S, u.depth_mm * S, 'scene-label scene-dim scene-dim-unit'));
        dims.add(label(`P ${formatLength(u.depth_mm, unit)}`, u.width_mm * S, 0, (u.depth_mm / 2) * S, 'scene-label scene-dim scene-dim-unit'));
        g.add(dims);
      }
    }
  }

  // ===== Visibilité =====

  setHidden(id, hidden) {
    if (hidden) this.hidden.add(id); else this.hidden.delete(id);
    const g = this.unitGroups.get(id);
    if (g) g.visible = !hidden;
    this.requestRender();
  }

  showAll() {
    this.hidden.clear();
    for (const g of this.unitGroups.values()) g.visible = true;
    this.requestRender();
  }

  /** auto : masque les murs entre la caméra et la salle ; transparent ; hidden ; solid */
  setWallMode(mode) {
    this.wallMode = mode;
    this.updateWallVisibility();
    this.requestRender();
  }

  updateWallVisibility() {
    if (!this.wallMaterial) return;
    this.wallMaterial.opacity = this.wallMode === 'transparent' ? 0.25 : 1;
    this.wallMaterial.depthWrite = this.wallMode !== 'transparent';
    for (const wall of this.walls) {
      const { outward, center } = wall.userData;
      const facing = this.camera.position.clone().sub(center).dot(outward) > 0;
      wall.visible = this.wallMode === 'hidden' ? false : this.wallMode === 'auto' ? !facing : true;
    }
  }

  // ===== Tiroirs =====

  isDrawerOpen(id) {
    return this.openDrawers.has(id);
  }

  setDrawerOpen(id, open) {
    const u = this.unit(id);
    const g = this.unitGroups.get(id);
    if (!u || !g || u.shape !== 'drawer') return;
    if (open) {
      // Comme sur une vraie armoire : un seul tiroir ouvert à la fois dans un meuble
      for (const other of this.childrenOf.get(u.parent_id) ?? []) {
        if (other.id !== id && this.openDrawers.has(other.id)) this.setDrawerOpen(other.id, false);
      }
      this.openDrawers.add(id);
      this.buildContent(u);
      g.userData.content.visible = true;
    } else {
      this.openDrawers.delete(id);
    }
    const target = this.drawerTarget(u, g);
    this.animate(280, (t) => {
      g.position.z += (target - g.position.z) * t;
      if (t >= 1 && !this.openDrawers.has(id)) g.userData.content.visible = false;
      // Le gizmo rejoint le tiroir une fois celui-ci arrivé à sa place
      if (t >= 1) this.refreshGizmo();
    });
  }

  drawerTarget(u, g) {
    return g.userData.baseZ + (this.openDrawers.has(u.id) ? u.depth_mm * DRAWER_OPEN_RATIO * S : 0);
  }

  /** Rend un rangement atteignable : parents affichés, construits, et tiroirs parents ouverts. */
  reveal(id) {
    const chain = [];
    for (let cursor = this.unit(id); cursor; cursor = this.unit(cursor.parent_id)) chain.unshift(cursor);
    for (const cursor of chain) {
      this.hidden.delete(cursor.id);
      const g = this.unitGroups.get(cursor.id);
      if (!g) continue;
      g.visible = true;
      if (cursor.shape === 'drawer' && !this.openDrawers.has(cursor.id)) this.setDrawerOpen(cursor.id, true);
      else this.buildContent(cursor);
    }
    this.refreshOverlay();
    this.refreshGizmo();
  }

  // ===== Regarder dans un rangement =====

  /**
   * Isole un rangement pour voir son contenu : la salle, les autres meubles et les parois de ses parents
   * disparaissent, la caméra plonge dedans. exitFocus() remet tout comme avant, vue comprise.
   */
  enterFocus(id) {
    const u = this.unit(id);
    if (!u || !this.unitGroups.get(id)) return;
    if (this.focusId === null || this.focusId === undefined) {
      this.focusReturn = { position: this.camera.position.clone(), target: this.controls.target.clone() };
    } else {
      this.restoreFocusVisibility();
    }
    this.focusId = id;
    this.reveal(id);
    if (u.shape === 'drawer' && !this.openDrawers.has(id)) this.setDrawerOpen(id, true);
    this.buildContent(u);
    this.applyFocus();
    this.refreshGizmo();
    this.callbacks.onFocusChange?.(u);
    // Rangement ouvert par le haut : vue plongeante ; sinon de face, un peu au-dessus
    if (OPEN_TOP.has(u.shape)) this.lookInto(id);
    else {
      const g = this.unitGroups.get(id);
      const box = this.finalBox(g);
      const center = box.getCenter(new THREE.Vector3());
      const front = new THREE.Vector3(0, 0, 1).applyQuaternion(g.getWorldQuaternion(new THREE.Quaternion())).normalize();
      const direction = front.clone().add(new THREE.Vector3(0, 0.45, 0)).normalize();
      this.moveCamera(fitPosition(this.camera, boxCorners(box.min, box.max), center, direction, 0.08), center);
    }
    this.requestRender();
  }

  exitFocus() {
    if (this.focusId === null || this.focusId === undefined) return;
    this.restoreFocusVisibility();
    this.focusId = null;
    this.refreshGizmo();
    if (this.focusReturn) this.moveCamera(this.focusReturn.position, this.focusReturn.target);
    this.focusReturn = null;
    this.callbacks.onFocusChange?.(null);
    this.requestRender();
  }

  get focusedId() { return this.focusId ?? null; }

  applyFocus() {
    const chain = new Set();
    for (let c = this.unit(this.focusId); c; c = this.unit(c.parent_id)) chain.add(c.id);
    this.focusHidden = [];
    const hide = (object) => {
      if (!object.visible) return;
      object.visible = false;
      this.focusHidden.push(object);
    };
    hide(this.roomGroup);
    for (const child of this.unitsGroup.children) if (!chain.has(child.userData.unitId)) hide(child);
    for (const aid of chain) {
      if (aid === this.focusId) continue;
      const g = this.unitGroups.get(aid);
      if (!g) continue;
      // Parois et étiquettes du parent ; dans son contenu, seul le chemin vers le rangement reste
      for (const child of g.children) if (child !== g.userData.content && !child.userData.overlay) hide(child);
      for (const child of g.userData.content.children) if (!chain.has(child.userData.unitId)) hide(child);
    }
  }

  restoreFocusVisibility() {
    for (const object of this.focusHidden ?? []) object.visible = true;
    this.focusHidden = [];
  }

  /** Après une reconstruction (enregistrement, annulation…) : le rangement reste isolé, ou la vue normale revient s'il n'existe plus. */
  reapplyFocus() {
    if (this.focusId === null || this.focusId === undefined) return;
    this.roomGroup.visible = true;
    this.focusHidden = [];
    if (!this.unit(this.focusId) || !this.unitGroups.get(this.focusId)) {
      this.focusId = null;
      this.focusReturn = null;
      this.callbacks.onFocusChange?.(null);
      return;
    }
    this.reveal(this.focusId);
    this.applyFocus();
  }

  // ===== Gizmo : déplacer, pivoter, redimensionner directement dans la vue =====

  createGizmo() {
    // Le gizmo manipule un repère placé au centre du rangement ; le rangement suit par calcul
    this.proxy = new THREE.Object3D();
    this.proxy.rotation.order = 'YXZ';
    this.gizmo = new TransformControls(this.camera, this.renderer.domElement);
    this.gizmo.setSize(0.85);
    this.gizmo.setTranslationSnap(0.005);
    this.gizmo.setRotationSnap(THREE.MathUtils.degToRad(5));
    this.scene.add(this.gizmo.getHelper());
    this.gizmo.addEventListener('change', () => this.requestRender());
    this.gizmo.addEventListener('dragging-changed', (event) => {
      this.controls.enabled = !event.value;
      this.dragging = event.value;
    });
    this.gizmo.addEventListener('mouseDown', () => this.gizmoStart());
    this.gizmo.addEventListener('objectChange', () => this.gizmoMove());
    this.gizmo.addEventListener('mouseUp', () => this.gizmoEnd());
  }

  /** translate, rotate, scale ou none. */
  setGizmoMode(mode) {
    this.gizmoMode = mode;
    this.refreshGizmo();
    this.requestRender();
  }

  /** Cible du gizmo : le brouillon en cours, sinon le rangement sélectionné si l'utilisateur peut le modifier. */
  gizmoTarget() {
    if (this.draft) return this.draft;
    if (this.previewGroup) return null;
    // Rangement isolé pour regarder dedans : pas de poignées qui masquent le contenu
    if (this.focusId !== null && this.focusId !== undefined && this.selectedId === this.focusId) return null;
    const unit = this.unit(this.selectedId);
    const group = this.unitGroups.get(this.selectedId);
    if (!unit || !group || !this.callbacks.canEdit?.(unit)) return null;
    return { unit, group, parent: this.unit(unit.parent_id) };
  }

  detachGizmo() {
    this.gizmo?.detach();
    this.proxy?.removeFromParent();
    this.gizmoContext = null;
  }

  refreshGizmo() {
    if (!this.gizmo || this.gizmo.dragging) return;
    this.detachGizmo();
    const opening = this.gizmoMode === 'none' || this.selectedOpening === null ? null : this.opening(this.selectedOpening);
    if (opening) return this.attachOpeningGizmo(opening);
    const target = this.gizmoMode === 'none' ? null : this.gizmoTarget();
    if (!target?.group.parent) return;
    const { unit, group, parent } = target;
    // Un rangement imbriqué reste aligné sur son parent : pas de rotation
    const mode = this.gizmoMode === 'rotate' && parent ? 'translate' : this.gizmoMode;
    group.parent.add(this.proxy);
    const half = this.halfVector(unit, unit.rotation_deg);
    this.proxy.position.set(group.position.x + half.x * S, group.position.y + half.y * S, group.position.z + half.z * S);
    this.proxy.rotation.set(0, group.rotation.y, 0);
    this.proxy.scale.set(1, 1, 1);
    this.gizmo.attach(this.proxy);
    this.gizmo.setMode(mode);
    this.gizmo.setSpace(mode === 'translate' && !parent ? 'world' : 'local');
    this.gizmo.showX = mode !== 'rotate';
    this.gizmo.showZ = mode !== 'rotate';
    this.gizmo.showY = true;
    this.gizmoContext = { unit, group, parent };
  }

  /** Une ouverture se déplace sur le sol : elle suit le mur le plus proche ; une fenêtre monte et descend aussi. */
  attachOpeningGizmo(opening) {
    if (!this.callbacks.canEditRoom?.()) return;
    const frame = wallFrame(this.room.outline, opening.wall_index, opening.offset_mm + opening.width_mm / 2);
    this.scene.add(this.proxy);
    this.proxy.position.set(frame.x * S, (opening.sill_mm + opening.height_mm / 2) * S, frame.z * S);
    this.proxy.rotation.set(0, 0, 0);
    this.proxy.scale.set(1, 1, 1);
    this.gizmo.attach(this.proxy);
    this.gizmo.setMode('translate');
    this.gizmo.setSpace('world');
    this.gizmo.showX = true;
    this.gizmo.showZ = true;
    this.gizmo.showY = opening.kind === 'window';
    this.gizmoContext = { opening };
  }

  /** Vecteur du coin arrière-gauche-bas vers le centre, après rotation (en mm). */
  halfVector(u, rotationDeg) {
    const { cos, sin } = rotation(rotationDeg);
    const hx = u.width_mm / 2; const hz = u.depth_mm / 2;
    return { x: hx * cos + hz * sin, y: u.height_mm / 2, z: -hx * sin + hz * cos };
  }

  gizmoStart() {
    if (!this.gizmoContext) return;
    this.gizmoContext.candidate = null;
    this.gizmoContext.start = this.gizmoContext.opening ?? { ...this.gizmoContext.unit };
  }

  /** Repères d'aimantation sur X et Z : bords des voisins et de ce qui contient le rangement. */
  snapEdges(c, parent) {
    const xs = []; const zs = []; const ys = [];
    if (parent) {
      const inside = interior(parent.shape, parent.width_mm, parent.depth_mm, parent.height_mm, parent.panel_mm);
      if (inside) { xs.push(0, inside.width); zs.push(0, inside.depth); ys.push(0, inside.height); }
      for (const o of this.units) {
        if (o.parent_id !== parent.id || o.id === c.id) continue;
        xs.push(o.pos_x_mm, o.pos_x_mm + o.width_mm);
        zs.push(o.pos_z_mm, o.pos_z_mm + o.depth_mm);
        ys.push(o.pos_y_mm, o.pos_y_mm + o.height_mm);
      }
    } else {
      for (const [x, z] of this.room.outline) { xs.push(x); zs.push(z); }
      ys.push(0);
      for (const o of this.units) {
        if (o.parent_id !== null || o.id === c.id) continue;
        const corners = unitFootprint(o);
        xs.push(...corners.map((p) => p[0]));
        zs.push(...corners.map((p) => p[1]));
        ys.push(o.pos_y_mm, o.pos_y_mm + o.height_mm);
      }
    }
    return { xs, ys, zs };
  }

  gizmoMove() {
    const context = this.gizmoContext;
    if (!context?.start) return;
    if (context.opening) return this.gizmoMoveOpening(context);
    const { group, parent, start } = context;
    const inside = parent ? interior(parent.shape, parent.width_mm, parent.depth_mm, parent.height_mm, parent.panel_mm) ?? { x: 0, y: 0, z: 0 } : { x: 0, y: 0, z: 0 };
    const openOffset = start.shape === 'drawer' && this.openDrawers.has(start.id) ? start.depth_mm * DRAWER_OPEN_RATIO : 0;
    const center = { x: this.proxy.position.x / S - inside.x, y: this.proxy.position.y / S - inside.y, z: this.proxy.position.z / S - inside.z - openOffset };
    const c = { ...start };
    const mode = this.gizmo.getMode();

    if (mode === 'rotate') {
      c.rotation_deg = ((Math.round(THREE.MathUtils.radToDeg(this.proxy.rotation.y)) % 360) + 360) % 360;
    } else if (mode === 'scale') {
      const dimension = (base, factor) => Math.max(10, Math.round((base * Math.abs(factor)) / 5) * 5);
      c.width_mm = dimension(start.width_mm, this.proxy.scale.x);
      c.height_mm = dimension(start.height_mm, this.proxy.scale.y);
      c.depth_mm = dimension(start.depth_mm, this.proxy.scale.z);
      group.scale.set(c.width_mm / start.width_mm, c.height_mm / start.height_mm, c.depth_mm / start.depth_mm);
    }
    const half = this.halfVector(c, c.rotation_deg);
    c.pos_x_mm = Math.round(center.x - half.x);
    c.pos_y_mm = Math.round(center.y - half.y);
    c.pos_z_mm = Math.round(center.z - half.z);

    if (mode === 'translate') {
      const edges = this.snapEdges(c, parent);
      const axisAligned = c.rotation_deg % 90 === 0;
      const swapped = c.rotation_deg % 180 !== 0;
      // Emprise au sol d'un meuble tourné de 90° : largeur et profondeur échangées, coin déplacé
      if (axisAligned) {
        const corners = unitFootprint(c);
        const minX = Math.min(...corners.map((p) => p[0])); const minZ = Math.min(...corners.map((p) => p[1]));
        const sizeX = swapped ? c.depth_mm : c.width_mm; const sizeZ = swapped ? c.width_mm : c.depth_mm;
        c.pos_x_mm += snapToEdges(minX, sizeX, edges.xs, SNAP_DISTANCE) - minX;
        c.pos_z_mm += snapToEdges(minZ, sizeZ, edges.zs, SNAP_DISTANCE) - minZ;
      }
      c.pos_y_mm = snapToEdges(c.pos_y_mm, c.height_mm, edges.ys, SNAP_DISTANCE);
    }
    // Butée : on ne sort jamais de ce qui contient le rangement. Sans cela, le moindre glissement d'un tiroir
    // aussi profond que son armoire était refusé par le serveur au relâchement.
    Object.assign(c, parent ? clampToParent(c, parent) : clampToRoom(c, this.room));
    for (const key of ['pos_x_mm', 'pos_y_mm', 'pos_z_mm']) c[key] = Math.round(c[key]);
    if (mode === 'scale') group.scale.set(c.width_mm / start.width_mm, c.height_mm / start.height_mm, c.depth_mm / start.depth_mm);

    group.position.set((inside.x + c.pos_x_mm) * S, (inside.y + c.pos_y_mm) * S, (inside.z + c.pos_z_mm + openOffset) * S);
    if (!parent) group.rotation.y = (c.rotation_deg * Math.PI) / 180;
    context.candidate = c;

    const problem = unitProblem(this.room, this.units, parent, c);
    const box = this.draft ? this.draftBox : this.selectionBox;
    box?.material.color.setHex(problem ? INVALID_COLOR : (this.draft ? VALID_COLOR : SELECT_COLOR));
    this.callbacks.onGizmoChange?.(c, problem, Boolean(this.draft));
    this.requestRender();
  }

  /** L'ouverture glisse le long du mur le plus proche ; les murs se recalculent en direct. */
  gizmoMoveOpening(context) {
    const o = slideOpening(this.room, context.start, this.proxy.position.x / S, this.proxy.position.y / S, this.proxy.position.z / S);
    context.candidate = o;
    this.openingOverride = o;
    this.clearGroup(this.roomGroup);
    this.buildRoom();
    this.updateWallVisibility();
    this.refreshOverlay();
    const problem = openingProblem(this.room, this.openings, o);
    this.openingBox?.material.color.setHex(problem ? INVALID_COLOR : SELECT_COLOR);
    this.callbacks.onOpeningChange?.(o, problem);
    this.requestRender();
  }

  gizmoEnd() {
    const context = this.gizmoContext;
    const candidate = context?.candidate;
    if (context) { context.candidate = null; context.start = null; }
    if (!candidate) return;
    // Après la fin du traitement du relâchement par le gizmo : il peut alors être rattaché
    const isDraft = Boolean(this.draft);
    queueMicrotask(() => {
      if (context.opening) {
        this.callbacks.onOpeningEnd?.(context.opening, candidate);
        return;
      }
      this.callbacks.onGizmoEnd?.(context.unit, candidate, isDraft);
    });
  }

  /** Fin de l'aperçu d'une ouverture : les murs reprennent les données réelles. */
  clearOpeningOverride() {
    if (!this.openingOverride) return;
    this.openingOverride = null;
    this.clearGroup(this.roomGroup);
    if (!this.minimal) this.buildRoom();
    this.updateWallVisibility();
    this.refreshOverlay();
    this.refreshGizmo();
    this.requestRender();
  }

  // ===== Brouillon : aperçu en direct d'un rangement en cours de création ou de modification =====

  /** Affiche le rangement tel qu'il sera : c'est aussi sur lui qu'agit le gizmo. */
  setDraft(unit, parent, { valid = true } = {}) {
    let group = unit.id > 0 ? this.unitGroups.get(unit.id) : this.draftGroup;
    if (!group) {
      if (parent) this.reveal(parent.id);
      group = new THREE.Group();
      const content = new THREE.Group();
      group.add(content);
      group.userData.content = content;
      (parent ? this.unitGroups.get(parent.id)?.userData.content : this.unitsGroup)?.add(group);
      this.draftGroup = group;
    }
    this.placeUnit(group, unit, parent);
    group.scale.set(1, 1, 1);
    group.visible = true;
    for (const child of [...group.children]) {
      if (child === group.userData.content) continue;
      group.remove(child);
      disposeObject(child);
    }
    this.buildPanels(unit, group);
    this.draftBox = selectionBox(unit, valid ? VALID_COLOR : INVALID_COLOR);
    this.draftBox.userData.overlay = true;
    group.add(this.draftBox);
    this.draft = { unit, group, parent };
    this.refreshGizmo();
    this.requestRender();
  }

  setDraftValid(valid) {
    this.draftBox?.material.color.setHex(valid ? VALID_COLOR : INVALID_COLOR);
    this.requestRender();
  }

  /** Aperçu d'un ensemble généré (tiroirs, grille de bacs) dans un rangement. */
  setPreviewUnits(parent, drafts) {
    this.clearPreviewUnits();
    const content = this.unitGroups.get(parent.id)?.userData.content;
    if (!content) return;
    this.previewGroup = new THREE.Group();
    for (const d of drafts) {
      const group = new THREE.Group();
      this.placeUnit(group, d, parent);
      this.buildPanels(d, group);
      this.previewGroup.add(group);
    }
    content.add(this.previewGroup);
    this.refreshGizmo();
    this.requestRender();
  }

  clearPreviewUnits() {
    if (!this.previewGroup) return;
    this.previewGroup.removeFromParent();
    disposeObject(this.previewGroup);
    this.previewGroup = null;
    this.requestRender();
  }

  /** Fin du brouillon : la scène reprend l'état réel. */
  clearDraft() {
    this.clearPreviewUnits();
    this.draft = null;
    this.draftBox = null;
    this.draftGroup = null;
    this.rebuildUnits();
  }

  // ===== Caméra =====

  bounds() {
    const xs = this.room.outline.map((p) => p[0] * S);
    const zs = this.room.outline.map((p) => p[1] * S);
    const min = new THREE.Vector3(Math.min(...xs), 0, Math.min(...zs));
    const max = new THREE.Vector3(Math.max(...xs), this.room.height_mm * S, Math.max(...zs));
    return { min, max, center: min.clone().add(max).multiplyScalar(0.5), size: max.clone().sub(min) };
  }

  viewPreset(name) {
    const { min, max, center } = this.bounds();
    if (name === 'door') {
      const doorX = (this.room.door_offset_mm + this.room.door_width_mm / 2) * S;
      this.moveCamera(new THREE.Vector3(doorX, 1.6, -0.6), new THREE.Vector3(doorX, 1.1, Math.min(center.z * 2, 4)));
      return;
    }
    // Vue 3D et vue de dessus : toute la salle dans l'image, quel que soit le format de la vue
    const target = new THREE.Vector3(center.x, name === 'top' ? 0 : (max.y - min.y) * 0.3, center.z);
    const direction = name === 'top' ? new THREE.Vector3(0, 1, 0.001) : new THREE.Vector3(0.45, 0.75, 0.95);
    // Les 8 coins, haut des murs compris : vu d'en haut, il est plus près de la caméra et paraît plus grand que le sol
    this.moveCamera(fitPosition(this.camera, boxCorners(min, max), target, direction, 0.04), target);
  }

  /** Boîte englobante en position finale, sans attendre la fin de l'ouverture des tiroirs. */
  finalBox(g) {
    const moved = [];
    for (const u of this.units) {
      const drawer = u.shape === 'drawer' && this.unitGroups.get(u.id);
      if (drawer) { moved.push([drawer, drawer.position.z]); drawer.position.z = this.drawerTarget(u, drawer); }
    }
    this.scene.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(g);
    for (const [drawer, z] of moved) drawer.position.z = z;
    this.scene.updateMatrixWorld(true);
    return box;
  }

  /** Centre la vue : par-dessus pour un tiroir, une boîte ou leur contenu, sinon sous l'angle actuel. */
  focus(id) {
    const u = this.unit(id);
    this.reveal(id);
    const g = this.unitGroups.get(id);
    if (!u || !g) return;
    let inDrawer = false;
    for (let cursor = u; cursor; cursor = this.unit(cursor.parent_id)) if (OPEN_TOP.has(cursor.shape)) inDrawer = true;
    if (inDrawer) return this.lookInto(id);
    const box = this.finalBox(g);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3()).length();
    const direction = this.camera.position.clone().sub(this.controls.target).normalize();
    const distance = Math.max(size / (2 * Math.tan((this.camera.fov * Math.PI) / 360)) * 2.2, 0.6);
    this.moveCamera(center.clone().add(direction.multiplyScalar(distance)), center);
  }

  /** Regarde une ouverture depuis l'intérieur de la salle. */
  focusOpening(id) {
    const o = this.opening(id);
    if (!o) return;
    const frame = wallFrame(this.room.outline, o.wall_index, o.offset_mm + o.width_mm / 2);
    const inward = containsPoint(this.room.outline, frame.x - frame.uz * 100, frame.z + frame.ux * 100) ? 1 : -1;
    const center = new THREE.Vector3(frame.x * S, (o.sill_mm + o.height_mm / 2) * S, frame.z * S);
    const distance = Math.max(3, (o.width_mm / 1000) * 3.5);
    const position = center.clone().add(new THREE.Vector3(-frame.uz * inward * distance, 1.2, frame.ux * inward * distance));
    this.moveCamera(position, center);
  }

  /** Vue plongeante : comme penché au-dessus du tiroir ouvert, avec sa façade en bas de l'écran. */
  lookInto(id) {
    const g = this.unitGroups.get(id);
    const u = this.unit(id);
    if (!g || !u) return;
    const box = this.finalBox(g);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3());
    // Avant du meuble dans le repère de la salle
    const front = new THREE.Vector3(0, 0, 1).applyQuaternion(g.getWorldQuaternion(new THREE.Quaternion())).normalize();
    const direction = new THREE.Vector3(0, 1, 0).addScaledVector(front, 0.22).normalize();
    const vertical = Math.tan((this.camera.fov * Math.PI) / 360);
    const fit = Math.max(Math.max(size.z, size.x * 0.1) / (2 * vertical), size.x / (2 * vertical * this.camera.aspect));
    const distance = Math.max(fit * 1.2 + size.y, 0.15);
    this.moveCamera(center.clone().add(direction.multiplyScalar(distance)), center);
  }

  moveCamera(position, target) {
    const fromPos = this.camera.position.clone();
    const fromTarget = this.controls.target.clone();
    if (fromPos.lengthSq() === 0) {
      this.camera.position.copy(position);
      this.controls.target.copy(target);
      this.controls.update();
      return;
    }
    let progress = 0;
    this.animate(400, (t) => {
      progress += (1 - progress) * t;
      this.camera.position.lerpVectors(fromPos, position, progress);
      this.controls.target.lerpVectors(fromTarget, target, progress);
      this.controls.update();
    });
  }

  bindEvents() {
    const canvas = this.renderer.domElement;
    let down = null;
    let gizmoPressed = false;
    // Clic droit : un glissement déplace la vue, un clic sans mouvement ouvre le menu.
    // Selon le système, l'événement contextmenu arrive à l'appui ou au relâchement.
    let right = null;
    let rightMoved = false;
    let pendingMenu = null;
    // Toucher : appui long pour le menu, annulé par un mouvement ou un second doigt
    let longPress = null;
    let longPressFired = false;
    const cancelLongPress = () => { clearTimeout(longPress?.timer); longPress = null; };
    const openMenu = (point) => this.handleMenu(point);

    this.onPointerDown = (event) => {
      down = { x: event.clientX, y: event.clientY };
      longPressFired = false;
      if (event.button === 2) { right = { x: event.clientX, y: event.clientY }; rightMoved = false; pendingMenu = null; }
      if (event.pointerType !== 'mouse') {
        cancelLongPress();
        if (event.isPrimary) {
          const point = { clientX: event.clientX, clientY: event.clientY };
          longPress = { ...point, timer: setTimeout(() => {
            longPress = null;
            longPressFired = true;
            openMenu(point);
            navigator.vibrate?.(10);
          }, 550) };
        }
      }
      // Un appui sur une poignée du gizmo n'est ni un clic ni un appui long
      gizmoPressed = Boolean(this.gizmo?.axis);
      if (gizmoPressed) cancelLongPress();
    };

    this.onPointerMove = (event) => {
      if (right && Math.hypot(event.clientX - right.x, event.clientY - right.y) > 5) rightMoved = true;
      if (longPress && Math.hypot(event.clientX - longPress.clientX, event.clientY - longPress.clientY) > 10) cancelLongPress();
    };

    this.onPointerUp = (event) => {
      const wasClick = down && !longPressFired && Math.hypot(event.clientX - down.x, event.clientY - down.y) < 5;
      down = null;
      cancelLongPress();
      if (event.button === 2) {
        right = null;
        if (pendingMenu && !rightMoved) openMenu(pendingMenu);
        pendingMenu = null;
      }
      if (wasClick && !gizmoPressed && event.button === 0) this.handleClick(event);
    };

    this.onDoubleClick = (event) => this.handleDoubleClick(event);

    // Survol : le nom de ce qui est sous le pointeur, et un curseur qui indique qu'on peut cliquer
    this.onHover = (event) => {
      if (event.pointerType !== 'mouse' || event.buttons !== 0 || this.minimal || !this.callbacks.onSelect) return;
      this.hoverEvent = { clientX: event.clientX, clientY: event.clientY };
      if (this.hoverQueued) return;
      this.hoverQueued = true;
      requestAnimationFrame(() => {
        this.hoverQueued = false;
        if (!this.disposed && this.hoverEvent && !this.dragging && !this.gizmo?.axis) this.showHover(this.hoverEvent);
        else this.hideHover();
      });
    };
    this.onLeave = () => { this.hoverEvent = null; this.hideHover(); };

    this.onContextMenu = (event) => {
      if (!this.callbacks.onContextMenu || event.shiftKey) return;
      event.preventDefault();
      const point = { clientX: event.clientX, clientY: event.clientY };
      if (longPressFired) return; // Android : fin de l'appui long, déjà traitée
      cancelLongPress();
      if (right) { pendingMenu = point; return; } // bouton encore enfoncé : décision au relâchement
      if (rightMoved) { rightMoved = false; return; } // la vue vient d'être déplacée
      longPressFired = event.pointerType !== 'mouse' && event.pointerType !== undefined && event.pointerType !== '';
      openMenu(point);
    };

    canvas.addEventListener('pointermove', this.onHover);
    canvas.addEventListener('pointerleave', this.onLeave);
    // Cliquer dans la vue termine une saisie en cours : sa valeur s'enregistre et les raccourcis redeviennent actifs
    canvas.addEventListener('pointerdown', () => {
      const active = document.activeElement;
      if (active instanceof HTMLElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) active.blur();
    });
    canvas.addEventListener('pointerdown', this.onPointerDown);
    canvas.addEventListener('pointercancel', cancelLongPress);
    canvas.addEventListener('pointermove', this.onPointerMove);
    canvas.addEventListener('pointerup', this.onPointerUp);
    canvas.addEventListener('dblclick', this.onDoubleClick);
    canvas.addEventListener('contextmenu', this.onContextMenu);
  }

  setPointer(event) {
    const rect = this.renderer.domElement.getBoundingClientRect();
    this.pointer.set(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
    this.raycaster.setFromCamera(this.pointer, this.camera);
  }

  /** Rangement visible le plus proche sous le pointeur. */
  pick(event) {
    return this.pickAny(event).unit;
  }

  /**
   * Ce qui se trouve sous le pointeur : rangement, ouverture ou mur, le plus proche d'abord.
   * @returns {{ unit, opening, wallIndex, point, itemName }}
   */
  pickAny(event) {
    this.setPointer(event);
    const hits = this.raycaster.intersectObjects([this.unitsGroup, this.roomGroup], true);
    const result = { unit: null, opening: null, wallIndex: null, point: null, itemName: null, item: null };
    for (const hit of hits) {
      const data = hit.object.userData;
      if (!isVisible(hit.object) || hit.object.isCSS2DObject || hit.object.isLine) continue;
      if (data.unitId !== undefined) {
        result.unit = this.unit(data.unitId);
        result.itemName = data.itemName ?? null;
        // Objet visé (présent ou emprunté) : { unit_id, item_id, name, quantity, unit, borrowed }
        result.item = data.entry ?? null;
        result.point = hit.point;
        return result;
      }
      if (data.openingId !== undefined) {
        result.opening = this.opening(data.openingId);
        result.point = hit.point;
        return result;
      }
      if (data.wallIndex !== undefined) {
        result.wallIndex = data.wallIndex;
        result.point = hit.point;
        return result;
      }
      if (data.isFloor) {
        result.point = hit.point;
        return result;
      }
    }
    return result;
  }

  handleClick(event) {
    const hit = this.pickAny(event);
    if (hit.opening && this.callbacks.onSelectOpening) return this.callbacks.onSelectOpening(hit.opening.id);
    return this.callbacks.onSelect?.(hit.unit?.id ?? null, hit);
  }

  handleDoubleClick(event) {
    const hit = this.pick(event);
    // Double-clic dans le vide, ou sur le rangement déjà isolé : retour à la salle
    if (!hit || hit.id === this.focusId) return this.exitFocus();
    // Double-clic sur un rangement : on l'isole et on regarde dedans (un tiroir s'ouvre)
    this.enterFocus(hit.id);
  }

  handleMenu(point) {
    const hit = this.pickAny(point);
    this.callbacks.onContextMenu?.(hit.unit, point, hit);
  }

  /** Texte de l'infobulle pour ce qui est sous le pointeur (null : rien à signaler). */
  hoverText(event) {
    const hit = this.pickAny(event);
    if (hit.item?.borrowed) return `${hit.item.name} : emprunté (${formatQuantity(hit.item.quantity, hit.item.unit)})`;
    if (hit.item) return `${hit.item.name} (${formatQuantity(hit.item.quantity, hit.item.unit)})`;
    if (hit.itemName) return hit.itemName;
    if (hit.unit) return hit.unit.label ? `${hit.unit.name} (${hit.unit.label})` : hit.unit.name;
    if (hit.opening) return hit.opening.name || { door: 'Porte', window: 'Fenêtre', opening: 'Passage' }[hit.opening.kind];
    return null;
  }

  showHover(event) {
    const text = this.hoverText(event);
    this.renderer.domElement.style.cursor = text ? 'pointer' : '';
    if (!text) return this.hideHover(false);
    if (!this.hoverChip) {
      this.hoverChip = document.createElement('div');
      this.hoverChip.className = 'scene-hover';
      this.container.append(this.hoverChip);
    }
    const rect = this.container.getBoundingClientRect();
    this.hoverChip.textContent = text;
    this.hoverChip.hidden = false;
    this.hoverChip.style.transform = `translate(${event.clientX - rect.left + 14}px, ${event.clientY - rect.top + 14}px)`;
  }

  hideHover(resetCursor = true) {
    if (this.hoverChip) this.hoverChip.hidden = true;
    if (resetCursor) this.renderer.domElement.style.cursor = '';
  }

  // ===== Rendu =====

  animate(duration, step) {
    const start = performance.now();
    let last = start;
    this.animations.push((now) => {
      const dt = Math.min(1, (now - last) / Math.max(1, duration - (last - start)));
      last = now;
      step(now - start >= duration ? 1 : dt);
      return now - start < duration;
    });
    this.requestRender();
  }

  requestRender() {
    if (this.renderQueued || this.disposed) return;
    this.renderQueued = true;
    requestAnimationFrame((now) => {
      this.renderQueued = false;
      this.animations = this.animations.filter((a) => a(now));
      this.renderer.render(this.scene, this.camera);
      this.labelRenderer.render(this.scene, this.camera);
      if (this.animations.length) this.requestRender();
    });
  }

  resize() {
    const { clientWidth: width, clientHeight: height } = this.container;
    if (!width || !height) return;
    this.camera.aspect = width / height;
    this.camera.updateProjectionMatrix();
    this.renderer.setSize(width, height);
    this.labelRenderer.setSize(width, height);
    this.requestRender();
  }

  clearGroup(group) {
    for (const child of [...group.children]) {
      group.remove(child);
      disposeObject(child);
    }
  }

  dispose() {
    this.disposed = true;
    this.resizeObserver.disconnect();
    this.detachGizmo();
    this.gizmo?.dispose();
    this.controls.dispose();
    const canvas = this.renderer.domElement;
    canvas.removeEventListener('pointermove', this.onHover);
    canvas.removeEventListener('pointerleave', this.onLeave);
    canvas.removeEventListener('pointerdown', this.onPointerDown);
    canvas.removeEventListener('pointermove', this.onPointerMove);
    canvas.removeEventListener('pointerup', this.onPointerUp);
    canvas.removeEventListener('dblclick', this.onDoubleClick);
    canvas.removeEventListener('contextmenu', this.onContextMenu);
    this.clearGroup(this.roomGroup);
    this.clearGroup(this.unitsGroup);
    this.hoverChip?.remove();
    for (const m of this.materials.values()) { m.map?.dispose(); m.dispose(); }
    this.renderer.dispose();
    canvas.remove();
    this.labelRenderer.domElement.remove();
  }
}

function isVisible(object) {
  for (let o = object; o; o = o.parent) if (!o.visible) return false;
  return true;
}

function label(text, x, y, z, className) {
  const el = document.createElement('div');
  el.className = className;
  el.textContent = text;
  const object = new CSS2DObject(el);
  object.position.set(x, y, z);
  return object;
}

function selectionBox(u, color) {
  const geometry = new THREE.EdgesGeometry(new THREE.BoxGeometry(u.width_mm * S, u.height_mm * S, u.depth_mm * S));
  const lines = new THREE.LineSegments(geometry, new THREE.LineBasicMaterial({ color, depthTest: false, transparent: true }));
  lines.position.set((u.width_mm / 2) * S, (u.height_mm / 2) * S, (u.depth_mm / 2) * S);
  lines.renderOrder = 999;
  return lines;
}

/** Contour d'une ouverture, dans le repère de la salle. */
function openingOutline(room, o, color) {
  const t = Math.max(room.wall_thickness_mm, 20);
  const geometry = new THREE.EdgesGeometry(new THREE.BoxGeometry(o.width_mm * S, o.height_mm * S, (t + 40) * S));
  const lines = new THREE.LineSegments(geometry, new THREE.LineBasicMaterial({ color, depthTest: false, transparent: true }));
  const frame = wallFrame(room.outline, o.wall_index, o.offset_mm + o.width_mm / 2);
  lines.position.set(frame.x * S, (o.sill_mm + o.height_mm / 2) * S, frame.z * S);
  lines.rotation.y = -Math.atan2(frame.uz, frame.ux);
  lines.renderOrder = 999;
  return lines;
}

/** Libère géométries et matériaux ; les matériaux du cache (userData.shared) sont libérés dans dispose(). */
function disposeObject(object) {
  object.traverse((o) => {
    if (!o.geometry?.userData.shared) o.geometry?.dispose();
    if (o.isInstancedMesh) o.dispose();
    if (o.material && !o.material.userData.shared) {
      o.material.map?.dispose();
      o.material.dispose();
    }
    if (o.isCSS2DObject) o.element.remove();
  });
}
