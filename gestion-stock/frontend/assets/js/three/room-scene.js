// Scène 3D d'une salle : murs avec ouvertures, sol, meubles et rangements imbriqués.
// Rendu à la demande (aucune boucle permanente) pour ménager les appareils modestes.
// 1 unité Three.js = 1 mètre ; les données restent en millimètres.

import * as THREE from '/vendor/three/three.module.min.js';
import { OrbitControls } from '/vendor/three/OrbitControls.js';
import { CSS2DObject, CSS2DRenderer } from '/vendor/three/CSS2DRenderer.js';
import { containsPoint, formatLength, furnitureFits, interior, wallLength } from '../spatial.js';

const S = 0.001;
const SELECT_COLOR = 0xffa31a;
const INVALID_COLOR = 0xe5484d;
const HIGHLIGHT_COLOR = 0x2f80ff;
const DRAWER_OPEN_RATIO = 0.6;

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
    this.moveMode = false;
    this.materials = new Map();
    this.walls = [];
    this.animations = [];
    this.renderQueued = false;

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
    this.controls.minDistance = 0.2;
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
    this.dragging = null;
    this.bindEvents();

    this.resizeObserver = new ResizeObserver(() => this.resize());
    this.resizeObserver.observe(container);
    this.resize();
  }

  // ===== Données =====

  setData(room, openings, units) {
    const first = !this.room;
    this.room = room;
    this.openings = openings;
    this.units = units;
    const ids = new Set(units.map((u) => u.id));
    for (const id of [...this.hidden]) if (!ids.has(id)) this.hidden.delete(id);
    for (const id of [...this.openDrawers]) if (!ids.has(id)) this.openDrawers.delete(id);
    if (this.selectedId !== null && !ids.has(this.selectedId)) this.selectedId = null;

    this.clearGroup(this.roomGroup);
    this.clearGroup(this.unitsGroup);
    this.buildRoom();
    this.buildUnits();
    this.refreshOverlay();
    if (first) this.viewPreset('iso');
    this.updateWallVisibility();
    this.requestRender();
  }

  unit(id) {
    return this.units.find((u) => u.id === id) ?? null;
  }

  // ===== Construction de la salle =====

  buildRoom() {
    const room = this.room;
    const outline = room.outline;

    // Sol
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
    floor.userData.isFloor = true;
    this.roomGroup.add(floor);

    // Murs : épaisseur côté extérieur, découpés autour des ouvertures
    const height = room.height_mm;
    const thickness = Math.max(room.wall_thickness_mm, 20);
    const wallMaterial = new THREE.MeshStandardMaterial({ color: room.wall_color, roughness: 0.95, transparent: true, opacity: 1 });
    this.wallMaterial = wallMaterial;
    const holes = [
      { wall_index: 0, offset_mm: room.door_offset_mm, width_mm: room.door_width_mm, sill_mm: 0, height_mm: room.door_height_mm, kind: 'reference' },
      ...this.openings,
    ];
    this.walls = [];

    outline.forEach(([x1, z1], i) => {
      const [x2, z2] = outline[(i + 1) % outline.length];
      const length = wallLength(outline, i);
      if (length === 0) return;
      const ux = (x2 - x1) / length;
      const uz = (z2 - z1) / length;
      let nx = -uz; let nz = ux; // normale candidate
      const mx = (x1 + x2) / 2; const mz = (z1 + z2) / 2;
      if (containsPoint(outline, mx + nx * 5, mz + nz * 5)) { nx = -nx; nz = -nz; } // vers l'extérieur

      const wall = new THREE.Group();
      wall.userData = { outward: new THREE.Vector3(nx, 0, nz), center: new THREE.Vector3(mx * S, height * S / 2, mz * S) };
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
          piece.rotation.y = -Math.atan2(uz, ux);
          wall.add(piece);
        }
      }
      // Poteau d'angle extérieur, pour fermer la jonction avec le mur suivant
      const post = new THREE.Mesh(new THREE.BoxGeometry(thickness * S, height * S, thickness * S), wallMaterial);
      const [px, pz] = outline[(i + 1) % outline.length];
      post.position.set((px + nx * thickness / 2 + ux * thickness / 2) * S, height * S / 2, (pz + nz * thickness / 2 + uz * thickness / 2) * S);
      post.rotation.y = -Math.atan2(uz, ux);
      wall.add(post);

      // Vitrage des fenêtres
      for (const h of wallHoles.filter((o) => o.kind === 'window')) {
        const glass = new THREE.Mesh(
          new THREE.PlaneGeometry(h.width_mm * S, h.height_mm * S),
          new THREE.MeshStandardMaterial({ color: 0x9fd3ff, transparent: true, opacity: 0.25, side: THREE.DoubleSide, depthWrite: false }),
        );
        const uc = h.offset_mm + h.width_mm / 2;
        glass.position.set((x1 + ux * uc + nx * thickness / 2) * S, (h.sill_mm + h.height_mm / 2) * S, (z1 + uz * uc + nz * thickness / 2) * S);
        glass.rotation.y = -Math.atan2(uz, ux);
        wall.add(glass);
      }
      this.walls.push(wall);
      this.roomGroup.add(wall);
    });

    // Porte de référence : marquage au sol et étiquette
    const door = new THREE.Mesh(
      new THREE.PlaneGeometry(room.door_width_mm * S, Math.min(room.door_width_mm, 900) * S),
      new THREE.MeshBasicMaterial({ color: HIGHLIGHT_COLOR, transparent: true, opacity: 0.18, depthWrite: false }),
    );
    door.rotation.x = -Math.PI / 2;
    door.position.set((room.door_offset_mm + room.door_width_mm / 2) * S, 0.002, Math.min(room.door_width_mm, 900) * S / 2);
    this.roomGroup.add(door);
    this.roomGroup.add(label('Porte (référence)', (room.door_offset_mm + room.door_width_mm / 2) * S, room.door_height_mm * S + 0.08, 0, 'scene-label scene-label-door'));

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
    const children = new Map();
    for (const u of this.units) {
      const list = children.get(u.parent_id) ?? [];
      list.push(u);
      children.set(u.parent_id, list);
    }
    const build = (u, parentObject, parent) => {
      const group = new THREE.Group();
      group.userData.unitId = u.id;
      if (parent) {
        const inside = interior(parent.shape, parent.width_mm, parent.depth_mm, parent.height_mm, parent.panel_mm) ?? { x: 0, y: 0, z: 0 };
        group.position.set((inside.x + u.pos_x_mm) * S, (inside.y + u.pos_y_mm) * S, (inside.z + u.pos_z_mm) * S);
      } else {
        group.position.set(u.pos_x_mm * S, u.pos_y_mm * S, u.pos_z_mm * S);
        group.rotation.y = (u.rotation_deg * Math.PI) / 180;
      }
      group.userData.baseZ = group.position.z;
      if (u.shape === 'drawer' && this.openDrawers.has(u.id)) group.position.z += u.depth_mm * DRAWER_OPEN_RATIO * S;
      group.visible = !this.hidden.has(u.id);
      this.buildPanels(u, group);
      parentObject.add(group);
      this.unitGroups.set(u.id, group);
      for (const child of children.get(u.id) ?? []) build(child, group, u);
    };
    for (const u of children.get(null) ?? []) build(u, this.unitsGroup, null);
  }

  material(color, variant = '') {
    const key = color + variant;
    if (!this.materials.has(key)) {
      const base = new THREE.Color(color);
      const options = { color: base, roughness: 0.75, metalness: 0.02 };
      if (variant === 'front') options.color = base.clone().multiplyScalar(0.85);
      if (variant === 'handle') Object.assign(options, { color: new THREE.Color(0x555b63), metalness: 0.6, roughness: 0.35 });
      if (variant === 'ghost') Object.assign(options, { transparent: true, opacity: 0.18, depthWrite: false });
      const material = new THREE.MeshStandardMaterial(options);
      material.userData.shared = true;
      this.materials.set(key, material);
    }
    return this.materials.get(key);
  }

  buildPanels(u, group) {
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

    switch (u.shape) {
      case 'cabinet': case 'compartment': case 'cell':
        if (p === 0) return ghost();
        add(0, 0, 0, p, h, d);
        add(w - p, 0, 0, w, h, d);
        add(p, 0, 0, w - p, p, d);
        add(p, h - p, 0, w - p, h, d);
        add(p, p, 0, w - p, h - p, p);
        return;
      case 'drawer': case 'box': case 'bin':
        if (p === 0) return ghost();
        add(0, 0, 0, w, p, d);
        add(0, p, 0, p, h, d);
        add(w - p, p, 0, w, h, d);
        add(p, p, 0, w - p, h, p);
        add(p, p, d - p, w - p, h, d, u.shape === 'drawer' ? this.material(u.color, 'front') : this.material(u.color));
        if (u.shape === 'drawer') {
          const half = Math.min(60, w / 6);
          add(w / 2 - half, h * 0.6 - 6, d, w / 2 + half, h * 0.6 + 6, d + 18, this.material(u.color, 'handle'));
        }
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
    this.refreshOverlay();
    this.requestRender();
  }

  /** Met un rangement en évidence : rend visibles ses parents, ouvre les tiroirs, centre la vue. */
  highlight(id) {
    const u = this.unit(id);
    if (!u) return;
    this.highlightId = id;
    for (let cursor = u; cursor; cursor = this.unit(cursor.parent_id)) {
      this.hidden.delete(cursor.id);
      const g = this.unitGroups.get(cursor.id);
      if (g) g.visible = true;
      if (cursor.shape === 'drawer' && cursor.id !== id) this.setDrawerOpen(cursor.id, true);
    }
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
    if (open) this.openDrawers.add(id); else this.openDrawers.delete(id);
    const target = g.userData.baseZ + (open ? u.depth_mm * DRAWER_OPEN_RATIO * S : 0);
    this.animate(250, (t) => { g.position.z += (target - g.position.z) * t; });
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
    const { center, size } = this.bounds();
    const span = Math.max(size.x, size.z, 2);
    const target = new THREE.Vector3(center.x, name === 'door' ? 1.2 : 0.6, center.z);
    let position;
    if (name === 'top') {
      position = new THREE.Vector3(center.x, span * 1.6, center.z + 0.001);
    } else if (name === 'door') {
      const doorX = (this.room.door_offset_mm + this.room.door_width_mm / 2) * S;
      position = new THREE.Vector3(doorX, 1.6, -0.6);
      target.set(doorX, 1.1, Math.min(center.z * 2, 4));
    } else {
      position = new THREE.Vector3(center.x + span * 0.55, span * 0.9, center.z + span * 1.1);
    }
    this.moveCamera(position, target);
  }

  focus(id) {
    const g = this.unitGroups.get(id);
    if (!g) return;
    // Les matrices des parents ne sont calculées qu'au rendu : les mettre à jour avant de mesurer
    this.scene.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(g);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3()).length();
    const direction = this.camera.position.clone().sub(this.controls.target).normalize();
    const distance = Math.max(size / (2 * Math.tan((this.camera.fov * Math.PI) / 360)) * 2.2, 0.6);
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

  // ===== Déplacement à la souris (meubles) =====

  setMoveMode(enabled) {
    this.moveMode = enabled;
    this.renderer.domElement.classList.toggle('is-move-mode', enabled);
  }

  bindEvents() {
    const canvas = this.renderer.domElement;
    let down = null;

    this.onPointerDown = (event) => {
      down = { x: event.clientX, y: event.clientY };
      if (!this.moveMode || event.button !== 0) return;
      const hit = this.pick(event);
      const top = hit && this.topLevelOf(hit);
      if (!top || top.id !== this.selectedId) return;
      const floorPoint = this.floorPoint(event);
      if (!floorPoint) return;
      const g = this.unitGroups.get(top.id);
      this.dragging = { unit: top, group: g, offsetX: g.position.x - floorPoint.x, offsetZ: g.position.z - floorPoint.z, start: g.position.clone(), moved: false };
      this.controls.enabled = false;
      canvas.setPointerCapture(event.pointerId);
    };

    this.onPointerMove = (event) => {
      if (!this.dragging) return;
      const point = this.floorPoint(event);
      if (!point) return;
      const step = event.shiftKey ? 100 : 10;
      const x = Math.round(((point.x + this.dragging.offsetX) / S) / step) * step;
      const z = Math.round(((point.z + this.dragging.offsetZ) / S) / step) * step;
      this.dragging.group.position.set(x * S, this.dragging.group.position.y, z * S);
      this.dragging.moved = true;
      this.dragging.candidate = { ...this.dragging.unit, pos_x_mm: x, pos_z_mm: z };
      const ok = furnitureFits(this.room, this.units, this.dragging.candidate);
      this.selectionBox?.material.color.setHex(ok ? SELECT_COLOR : INVALID_COLOR);
      this.callbacks.onDrag?.(this.dragging.candidate, ok);
      this.requestRender();
    };

    this.onPointerUp = async (event) => {
      const wasClick = down && Math.hypot(event.clientX - down.x, event.clientY - down.y) < 5;
      down = null;
      if (this.dragging) {
        const drag = this.dragging;
        this.dragging = null;
        this.controls.enabled = true;
        canvas.releasePointerCapture?.(event.pointerId);
        if (drag.moved && drag.candidate) {
          const ok = await this.callbacks.onMove?.(drag.unit, { pos_x_mm: drag.candidate.pos_x_mm, pos_z_mm: drag.candidate.pos_z_mm });
          if (!ok) {
            drag.group.position.copy(drag.start);
            this.selectionBox?.material.color.setHex(SELECT_COLOR);
            this.requestRender();
          }
          return;
        }
      }
      if (wasClick && event.button === 0) {
        const hit = this.pick(event);
        this.callbacks.onSelect?.(hit?.id ?? null);
      }
    };

    this.onDoubleClick = (event) => {
      const hit = this.pick(event);
      if (!hit) return;
      if (hit.shape === 'drawer') this.setDrawerOpen(hit.id, !this.isDrawerOpen(hit.id));
      else this.focus(hit.id);
    };

    this.onContextMenu = (event) => {
      if (!this.callbacks.onContextMenu) return;
      const hit = this.pick(event);
      if (!hit) return;
      event.preventDefault();
      this.callbacks.onContextMenu(hit, event);
    };

    canvas.addEventListener('pointerdown', this.onPointerDown);
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
    this.setPointer(event);
    for (const hit of this.raycaster.intersectObject(this.unitsGroup, true)) {
      if (!isVisible(hit.object) || hit.object.userData.unitId === undefined) continue;
      return this.unit(hit.object.userData.unitId);
    }
    return null;
  }

  floorPoint(event) {
    this.setPointer(event);
    const point = new THREE.Vector3();
    return this.raycaster.ray.intersectPlane(new THREE.Plane(new THREE.Vector3(0, 1, 0), 0), point) ? point : null;
  }

  topLevelOf(u) {
    let cursor = u;
    while (cursor && cursor.parent_id !== null) cursor = this.unit(cursor.parent_id);
    return cursor;
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
    this.controls.dispose();
    const canvas = this.renderer.domElement;
    canvas.removeEventListener('pointerdown', this.onPointerDown);
    canvas.removeEventListener('pointermove', this.onPointerMove);
    canvas.removeEventListener('pointerup', this.onPointerUp);
    canvas.removeEventListener('dblclick', this.onDoubleClick);
    canvas.removeEventListener('contextmenu', this.onContextMenu);
    this.clearGroup(this.roomGroup);
    this.clearGroup(this.unitsGroup);
    for (const m of this.materials.values()) m.dispose();
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

/** Libère géométries et matériaux ; les matériaux du cache (userData.shared) sont libérés dans dispose(). */
function disposeObject(object) {
  object.traverse((o) => {
    o.geometry?.dispose();
    if (o.material && !o.material.userData.shared) {
      o.material.map?.dispose();
      o.material.dispose();
    }
    if (o.isCSS2DObject) o.element.remove();
  });
}

/** Textures de sol générées (pas de fichier image à télécharger). */
function floorTexture(kind) {
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
