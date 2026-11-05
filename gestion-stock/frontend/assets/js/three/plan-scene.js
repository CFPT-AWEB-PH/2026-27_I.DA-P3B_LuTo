// Plan du bâtiment : toutes les salles d'un étage (ou tous les étages), avec portes et meubles.
// Reprend le rendu, la caméra et le gizmo de l'éditeur de salle ; les calculs se font ici dans le repère
// du plan, en millimètres, chaque salle étant placée par son origine et sa rotation.

import * as THREE from '/vendor/three/three.module.min.js';
import { CSS2DObject } from '/vendor/three/CSS2DRenderer.js';
import { RoomScene } from './room-scene.js';
import { buildShell } from './room-shell.js';
import {
  clampToRoom, containsPoint, formatLength, isRectangle, openingProblem, outlineSize, planBounds, planToRoom, referenceDoor, rotation, roomToPlan, slideOpening, snapToEdges, unitFootprint, unitProblem, wallFrame,
} from '../spatial.js';

const S = 0.001;
const SELECT_COLOR = 0xffa31a;
const INVALID_COLOR = 0xe5484d;
const ROOM_SNAP = 200; // mm : les salles s'aimantent les unes aux autres, mur contre mur
const UNIT_SNAP = 40;
const GHOST_COLOR = 0x8d969e;

const deg = (radians) => ((Math.round(THREE.MathUtils.radToDeg(radians) / 5) * 5) % 360 + 360) % 360;

export class PlanScene extends RoomScene {
  constructor(container, callbacks = {}) {
    super(container, callbacks);
    this.entries = new Map();
    this.selection = null;
    this.floorId = null;
    this.is2d = true;
    this.wallMode = 'auto';
    this.controls.maxDistance = 800;
    this.camera.far = 3000;
    this.camera.updateProjectionMatrix();
  }

  // ===== Données =====

  /** @param {{ floors, rooms, openings, units }} plan @param {number|'all'} floorId */
  setPlan(plan, floorId) {
    this.plan = plan;
    const changedFloor = this.floorId !== floorId;
    this.floorId = floorId;
    this.rebuild();
    if (!this.framed || changedFloor) { this.frame(); this.framed = true; }
  }

  roomById(id) { return this.plan?.rooms.find((r) => r.id === id) ?? null; }
  unitById(id) { return this.plan?.units.find((u) => u.id === id) ?? null; }
  openingsOf(id) { return this.plan.openings.filter((o) => o.room_id === id); }
  unitsOf(id) { return this.plan.units.filter((u) => u.room_id === id); }

  openingById(room, id) {
    if (id === 'ref') return referenceDoor(room);
    return this.openingsOf(room.id).find((o) => o.id === id) ?? null;
  }

  /** Altitude de chaque étage : somme des hauteurs des étages en dessous, à partir du niveau 0. */
  elevation(floor) {
    const sorted = [...this.plan.floors].sort((a, b) => a.level - b.level);
    const zero = sorted.findIndex((f) => f.level >= 0);
    const index = sorted.indexOf(floor);
    let y = 0;
    for (let i = zero; i < index; i++) y += sorted[i].height_mm;
    for (let i = index; i < zero; i++) y -= sorted[i].height_mm;
    return y;
  }

  visibleFloors() {
    if (this.floorId === 'all') return this.plan.floors;
    return this.plan.floors.filter((f) => f.id === this.floorId);
  }

  rebuild() {
    this.detachGizmo();
    this.clearGroup(this.roomGroup);
    this.clearGroup(this.unitsGroup);
    this.entries.clear();
    this.allWalls = [];
    const floors = this.visibleFloors();
    for (const floor of floors) {
      const y = this.floorId === 'all' ? this.elevation(floor) : 0;
      for (const room of this.plan.rooms.filter((r) => r.floor_id === floor.id)) this.buildEntry(room, y);
    }
    // Repère pour aligner les étages : contour des salles de l'étage du dessous, en pointillés
    if (this.floorId !== 'all' && floors[0]) {
      const below = this.plan.floors.filter((f) => f.level < floors[0].level).sort((a, b) => b.level - a.level)[0];
      if (below) for (const room of this.plan.rooms.filter((r) => r.floor_id === below.id)) this.roomGroup.add(this.ghostOutline(room));
    }
    this.updateWallVisibility();
    this.refreshOverlay();
    this.refreshGizmo();
    this.requestRender();
  }

  buildEntry(room, y) {
    const group = new THREE.Group();
    group.position.set((room.plan_x_mm ?? 0) * S, y * S, (room.plan_z_mm ?? 0) * S);
    group.rotation.y = ((room.plan_rotation_deg ?? 0) * Math.PI) / 180;
    this.roomGroup.add(group);
    const entry = { room, group, y, shell: null, units: new Map(), overlay: null, label: null };
    this.entries.set(room.id, entry);
    this.buildEntryShell(entry, room, this.openingsOf(room.id));
    for (const unit of this.unitsOf(room.id)) this.buildPlanUnit(entry, unit);
    return entry;
  }

  buildEntryShell(entry, room, openings) {
    if (entry.shell) {
      entry.group.remove(entry.shell.group);
      disposeTree(entry.shell.group);
      entry.label?.removeFromParent();
      entry.label?.element.remove();
      this.allWalls = this.allWalls.filter((w) => !entry.shell.walls.includes(w));
    }
    const shell = buildShell(room, openings);
    entry.shell = shell;
    entry.group.add(shell.group);
    this.allWalls.push(...shell.walls);
    shell.wallMaterial.userData.roomId = room.id;

    const size = outlineSize(room.outline);
    entry.label = roomLabel(room.name, `${formatLength(size.width, room.display_unit)} × ${formatLength(size.depth, room.display_unit)}`, (size.minX + size.maxX) / 2 * S, 0.05, (size.minZ + size.maxZ) / 2 * S);
    entry.group.add(entry.label);
  }

  buildPlanUnit(entry, unit) {
    const group = new THREE.Group();
    group.position.set(unit.pos_x_mm * S, unit.pos_y_mm * S, unit.pos_z_mm * S);
    group.rotation.y = (unit.rotation_deg * Math.PI) / 180;
    group.userData.unitId = unit.id;
    this.buildPanels(unit, group);
    entry.group.add(group);
    entry.units.set(unit.id, group);
  }

  ghostOutline(room) {
    const points = room.outline.map(([x, z]) => {
      const [px, pz] = roomToPlan(room, x, z);
      return new THREE.Vector3(px * S, 0.01, pz * S);
    });
    const line = new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(points), new THREE.LineDashedMaterial({ color: GHOST_COLOR, dashSize: 0.2, gapSize: 0.12 }));
    line.computeLineDistances();
    return line;
  }

  // ===== Vue =====

  planBox() {
    // Étage vide : cadre le bâtiment, dont le contour de l'étage du dessous sert de repère
    const rooms = this.entries.size ? [...this.entries.values()].map((e) => e.room) : this.plan.rooms;
    if (rooms.length === 0) return { minX: -4, maxX: 4, minZ: -4, maxZ: 4, maxY: 3 };
    const bounds = rooms.map(planBounds);
    const maxY = this.entries.size ? Math.max(...[...this.entries.values()].map((e) => e.y + e.room.height_mm)) * S : 3;
    return {
      minX: Math.min(...bounds.map((b) => b.minX)) * S, maxX: Math.max(...bounds.map((b) => b.maxX)) * S,
      minZ: Math.min(...bounds.map((b) => b.minZ)) * S, maxZ: Math.max(...bounds.map((b) => b.maxZ)) * S, maxY,
    };
  }

  /** 2D : vue de dessus avec une focale étroite, presque sans perspective ; glisser déplace la feuille. */
  setView(is2d) {
    this.is2d = is2d;
    this.camera.fov = is2d ? 16 : 50;
    this.camera.updateProjectionMatrix();
    this.controls.enableRotate = !is2d;
    this.controls.mouseButtons.LEFT = is2d ? THREE.MOUSE.PAN : THREE.MOUSE.ROTATE;
    this.controls.mouseButtons.RIGHT = THREE.MOUSE.PAN;
    this.controls.touches.ONE = is2d ? THREE.TOUCH.PAN : THREE.TOUCH.ROTATE;
    this.frame();
  }

  frame() {
    const box = this.planBox();
    const cx = (box.minX + box.maxX) / 2; const cz = (box.minZ + box.maxZ) / 2;
    const width = Math.max(box.maxX - box.minX, 4); const depth = Math.max(box.maxZ - box.minZ, 4);
    const tan = Math.tan((this.camera.fov * Math.PI) / 360);
    const needed = Math.max(depth, width / Math.max(this.camera.aspect, 0.1)) / (2 * tan) * 1.25;
    if (this.is2d) {
      this.moveCamera(new THREE.Vector3(cx, needed + box.maxY, cz + 0.001), new THREE.Vector3(cx, 0, cz));
    } else {
      const span = Math.max(width, depth);
      this.moveCamera(new THREE.Vector3(cx + span * 0.55, Math.max(span * 0.8, box.maxY + 3), cz + span * 1.05), new THREE.Vector3(cx, box.maxY / 3, cz));
    }
  }

  viewPreset() { this.frame(); }

  updateWallVisibility() {
    for (const entry of this.entries?.values() ?? []) {
      const material = entry.shell?.wallMaterial;
      if (!material) continue;
      const transparent = this.wallMode === 'transparent';
      material.opacity = transparent ? 0.25 : 1;
      material.depthWrite = !transparent;
      const world = new THREE.Vector3();
      const outward = new THREE.Vector3();
      for (const wall of entry.shell.walls) {
        world.copy(wall.userData.center).applyAxisAngle(UP, entry.group.rotation.y).add(entry.group.position);
        outward.copy(wall.userData.outward).applyAxisAngle(UP, entry.group.rotation.y);
        const facing = this.camera.position.clone().sub(world).dot(outward) > 0;
        wall.visible = this.wallMode === 'hidden' ? false : this.wallMode === 'auto' && !this.is2d ? !facing : true;
      }
    }
  }

  // ===== Sélection =====

  /** @param {{ kind: 'room'|'unit'|'opening', id, roomId } | null} selection */
  setSelection(selection) {
    this.selection = selection;
    this.refreshOverlay();
    this.refreshGizmo();
    this.requestRender();
  }

  select() { /* la sélection du plan passe par setSelection */ }

  refreshOverlay() {
    for (const entry of this.entries?.values() ?? []) {
      if (entry.overlay) {
        entry.overlay.removeFromParent();
        disposeTree(entry.overlay);
        entry.overlay = null;
      }
    }
    this.overlayLines = null;
    const sel = this.selection;
    if (!sel) return;
    const entry = this.entries.get(sel.roomId);
    if (!entry) return;
    const room = this.overrideRoom ?? entry.room;
    let lines = null;
    if (sel.kind === 'room') {
      lines = new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(room.outline.map(([x, z]) => new THREE.Vector3(x * S, room.height_mm * S + 0.01, z * S))), overlayMaterial());
    } else if (sel.kind === 'unit') {
      const unit = this.unitById(sel.id);
      if (unit) {
        lines = new THREE.LineSegments(new THREE.EdgesGeometry(new THREE.BoxGeometry(unit.width_mm * S, unit.height_mm * S, unit.depth_mm * S)), overlayMaterial());
        const { cos, sin } = rotation(unit.rotation_deg);
        const hx = unit.width_mm / 2; const hz = unit.depth_mm / 2;
        lines.position.set((unit.pos_x_mm + hx * cos + hz * sin) * S, (unit.pos_y_mm + unit.height_mm / 2) * S, (unit.pos_z_mm - hx * sin + hz * cos) * S);
        lines.rotation.y = (unit.rotation_deg * Math.PI) / 180;
      }
    } else {
      const opening = this.openingOverride ?? this.openingById(room, sel.id);
      if (opening) {
        const t = Math.max(room.wall_thickness_mm, 20);
        lines = new THREE.LineSegments(new THREE.EdgesGeometry(new THREE.BoxGeometry(opening.width_mm * S, opening.height_mm * S, (t + 40) * S)), overlayMaterial());
        const frame = wallFrame(room.outline, opening.wall_index, opening.offset_mm + opening.width_mm / 2);
        lines.position.set(frame.x * S, (opening.sill_mm + opening.height_mm / 2) * S, frame.z * S);
        lines.rotation.y = -Math.atan2(frame.uz, frame.ux);
      }
    }
    if (lines) {
      lines.renderOrder = 999;
      entry.group.add(lines);
      entry.overlay = lines;
      this.overlayLines = lines;
    }
  }

  // ===== Gizmo =====

  gizmoTarget() { return null; }

  refreshGizmo() {
    if (!this.gizmo || this.gizmo.dragging) return;
    this.detachGizmo();
    const sel = this.selection;
    const entry = sel && this.entries?.get(sel.roomId);
    if (!sel || !entry || this.gizmoMode === 'none' || !this.callbacks.canEdit?.(sel)) return;
    const room = entry.room;
    const yOf = (mm) => (entry.y + mm) * S;
    let center; let y; let yaw = 0; let mode = this.gizmoMode;
    let context;

    if (sel.kind === 'room') {
      const size = outlineSize(room.outline);
      center = roomToPlan(room, (size.minX + size.maxX) / 2, (size.minZ + size.maxZ) / 2);
      y = yOf(room.height_mm);
      yaw = (room.plan_rotation_deg * Math.PI) / 180;
      if (mode === 'scale' && !isRectangle(room.outline)) mode = 'translate';
      context = { kind: 'room', room, entry, size, center };
    } else if (sel.kind === 'unit') {
      const unit = this.unitById(sel.id);
      if (!unit) return;
      const corners = unitFootprint(unit);
      center = roomToPlan(room, (corners[0][0] + corners[2][0]) / 2, (corners[0][1] + corners[2][1]) / 2);
      y = yOf(unit.pos_y_mm + unit.height_mm);
      yaw = ((unit.rotation_deg + room.plan_rotation_deg) * Math.PI) / 180;
      if (mode === 'scale') mode = 'translate';
      context = { kind: 'unit', unit, room, entry };
    } else {
      const opening = this.openingById(room, sel.id);
      if (!opening) return;
      const frame = wallFrame(room.outline, opening.wall_index, opening.offset_mm + opening.width_mm / 2);
      center = roomToPlan(room, frame.x, frame.z);
      y = yOf(opening.sill_mm + opening.height_mm / 2);
      mode = 'translate';
      context = { kind: 'opening', opening, room, entry };
    }

    this.scene.add(this.proxy);
    this.proxy.position.set(center[0] * S, y, center[1] * S);
    this.proxy.rotation.set(0, yaw, 0);
    this.proxy.scale.set(1, 1, 1);
    this.gizmo.attach(this.proxy);
    this.gizmo.setMode(mode);
    this.gizmo.setSpace(mode === 'translate' ? 'world' : 'local');
    this.gizmo.setTranslationSnap(sel.kind === 'room' ? 0.05 : 0.01);
    this.gizmo.showX = mode !== 'rotate';
    this.gizmo.showZ = mode !== 'rotate';
    this.gizmo.showY = mode === 'rotate' || (sel.kind === 'opening' && context.opening.kind === 'window');
    this.gizmoContext = context;
  }

  gizmoStart() {
    if (!this.gizmoContext) return;
    this.gizmoContext.candidate = null;
    this.gizmoContext.startPos = this.proxy.position.clone();
    this.gizmoContext.dragging = true;
  }

  gizmoMove() {
    const context = this.gizmoContext;
    if (!context?.dragging) return;
    if (context.kind === 'room') this.moveRoom(context);
    else if (context.kind === 'unit') this.moveUnit(context);
    else this.moveOpening(context);
    this.requestRender();
  }

  moveRoom(context) {
    const { room, entry, size } = context;
    const mode = this.gizmo.getMode();
    const planX = this.proxy.position.x / S; const planZ = this.proxy.position.z / S;
    const c = { plan_x_mm: room.plan_x_mm, plan_z_mm: room.plan_z_mm, plan_rotation_deg: room.plan_rotation_deg, outline: room.outline };

    if (mode === 'translate') {
      c.plan_x_mm = Math.round(room.plan_x_mm + planX - context.center[0]);
      c.plan_z_mm = Math.round(room.plan_z_mm + planZ - context.center[1]);
      // Aimantation : bord contre bord, ou mur contre mur en comptant l'épaisseur du mur
      const b = planBounds({ ...room, ...c });
      const xs = []; const zs = [];
      for (const other of this.floorRooms(room)) {
        const o = planBounds(other);
        const t = Math.max(room.wall_thickness_mm, other.wall_thickness_mm);
        xs.push(o.minX, o.maxX, o.minX - t, o.maxX + t);
        zs.push(o.minZ, o.maxZ, o.minZ - t, o.maxZ + t);
      }
      c.plan_x_mm = Math.round(c.plan_x_mm + snapToEdges(b.minX, b.maxX - b.minX, xs, ROOM_SNAP) - b.minX);
      c.plan_z_mm = Math.round(c.plan_z_mm + snapToEdges(b.minZ, b.maxZ - b.minZ, zs, ROOM_SNAP) - b.minZ);
    } else if (mode === 'rotate') {
      c.plan_rotation_deg = deg(this.proxy.rotation.y);
      // Le centre reste immobile pendant la rotation
      const cx = (size.minX + size.maxX) / 2; const cz = (size.minZ + size.maxZ) / 2;
      const { cos, sin } = rotation(c.plan_rotation_deg);
      c.plan_x_mm = Math.round(context.center[0] - (cx * cos + cz * sin));
      c.plan_z_mm = Math.round(context.center[1] - (-cx * sin + cz * cos));
    } else {
      // Dimensions : la salle s'agrandit depuis son origine, ce qu'elle contient ne bouge pas
      const step = (v) => Math.max(1000, Math.round(v / 50) * 50);
      const width = step(size.width * Math.abs(this.proxy.scale.x));
      const depth = step(size.depth * Math.abs(this.proxy.scale.z));
      c.outline = [[0, 0], [width, 0], [width, depth], [0, depth]];
    }
    context.candidate = c;
    const preview = { ...room, ...c };
    entry.group.position.set(c.plan_x_mm * S, entry.y * S, c.plan_z_mm * S);
    entry.group.rotation.y = (c.plan_rotation_deg * Math.PI) / 180;
    if (mode === 'scale') {
      this.buildEntryShell(entry, preview, this.openingsOf(room.id));
      this.overrideRoom = preview;
      this.refreshOverlay();
    }
    this.updateWallVisibility();
    this.callbacks.onRoomChange?.(preview, this.roomOverlap(preview));
  }

  moveUnit(context) {
    const { unit, room, entry } = context;
    const mode = this.gizmo.getMode();
    const wx = this.proxy.position.x / S; const wz = this.proxy.position.z / S;
    // Le meuble passe dans la salle où se trouve son centre : on peut le tirer d'une salle à l'autre
    const target = this.roomAt(wx, wz, room) ?? room;
    const rotationDeg = mode === 'rotate'
      ? ((deg(this.proxy.rotation.y) - target.plan_rotation_deg) % 360 + 360) % 360
      : ((unit.rotation_deg + room.plan_rotation_deg - target.plan_rotation_deg) % 360 + 360) % 360;
    const [lx, lz] = planToRoom(target, wx, wz);
    const half = this.halfVector(unit, rotationDeg);
    let c = { ...unit, room_id: target.id, rotation_deg: rotationDeg, pos_x_mm: Math.round(lx - half.x), pos_z_mm: Math.round(lz - half.z) };

    if (mode === 'translate' && rotationDeg % 90 === 0) {
      const xs = target.outline.map((p) => p[0]); const zs = target.outline.map((p) => p[1]);
      for (const other of this.unitsOf(target.id)) {
        if (other.id === unit.id) continue;
        for (const [x, z] of unitFootprint(other)) { xs.push(x); zs.push(z); }
      }
      const corners = unitFootprint(c);
      const minX = Math.min(...corners.map((p) => p[0])); const maxX = Math.max(...corners.map((p) => p[0]));
      const minZ = Math.min(...corners.map((p) => p[1])); const maxZ = Math.max(...corners.map((p) => p[1]));
      c.pos_x_mm = Math.round(c.pos_x_mm + snapToEdges(minX, maxX - minX, xs, UNIT_SNAP) - minX);
      c.pos_z_mm = Math.round(c.pos_z_mm + snapToEdges(minZ, maxZ - minZ, zs, UNIT_SNAP) - minZ);
    }
    c = clampToRoom(c, target);
    context.candidate = { unit: c, target };

    // Aperçu dans le repère de la salle d'origine
    const [px, pz] = roomToPlan(target, c.pos_x_mm, c.pos_z_mm);
    const [ox, oz] = planToRoom(room, px, pz);
    const group = entry.units.get(unit.id);
    group.position.set(ox * S, c.pos_y_mm * S, oz * S);
    group.rotation.y = ((c.rotation_deg + target.plan_rotation_deg - room.plan_rotation_deg) * Math.PI) / 180;
    this.overlayLines?.position.set(group.position.x + (this.halfVector(c, c.rotation_deg + target.plan_rotation_deg - room.plan_rotation_deg).x) * S, group.position.y + (unit.height_mm / 2) * S, group.position.z + (this.halfVector(c, c.rotation_deg + target.plan_rotation_deg - room.plan_rotation_deg).z) * S);
    if (this.overlayLines) this.overlayLines.rotation.y = group.rotation.y;

    const problem = unitProblem(target, this.unitsOf(target.id).map((u) => ({ ...u, parent_id: null })), null, c);
    this.overlayLines?.material.color.setHex(problem ? INVALID_COLOR : SELECT_COLOR);
    this.callbacks.onUnitChange?.(c, target, problem);
  }

  moveOpening(context) {
    const { opening, room, entry } = context;
    const [x, z] = planToRoom(room, this.proxy.position.x / S, this.proxy.position.z / S);
    const o = slideOpening(room, opening, x, this.proxy.position.y / S - entry.y, z);
    context.candidate = o;
    this.openingOverride = o;
    const previewRoom = o.id === 'ref' ? { ...room, door_offset_mm: o.offset_mm } : room;
    const list = o.id === 'ref' ? this.openingsOf(room.id) : [...this.openingsOf(room.id).filter((x2) => x2.id !== o.id), o];
    this.buildEntryShell(entry, previewRoom, list);
    this.refreshOverlay();
    this.updateWallVisibility();
    const problem = openingProblem(room, this.openingsOf(room.id), o);
    this.overlayLines?.material.color.setHex(problem ? INVALID_COLOR : SELECT_COLOR);
    this.callbacks.onOpeningChange?.(o, problem, room);
  }

  gizmoEnd() {
    const context = this.gizmoContext;
    if (!context) return;
    const candidate = context.candidate;
    context.dragging = false;
    context.candidate = null;
    this.overrideRoom = null;
    if (!candidate) return;
    queueMicrotask(() => {
      this.openingOverride = null;
      this.callbacks.onGizmoEnd?.(context, candidate);
    });
  }

  floorRooms(room) {
    return this.plan.rooms.filter((r) => r.floor_id === room.floor_id && r.id !== room.id);
  }

  roomAt(x, z, prefer) {
    const contains = (room) => {
      const [lx, lz] = planToRoom(room, x, z);
      return containsPoint(room.outline, lx, lz);
    };
    if (contains(prefer)) return prefer;
    return this.floorRooms(prefer).find(contains) ?? null;
  }

  /** Autre salle du même étage que cette position chevauche (null : aucune). */
  roomOverlap(room) {
    const a = planBounds(room);
    return this.floorRooms(room).find((other) => {
      const b = planBounds(other);
      return a.minX < b.maxX - 10 && b.minX < a.maxX - 10 && a.minZ < b.maxZ - 10 && b.minZ < a.maxZ - 10;
    }) ?? null;
  }

  // ===== Pointeur =====

  pickAny(event) {
    this.setPointer(event);
    const result = { unit: null, opening: null, room: null, wallIndex: null, point: null, itemName: null };
    for (const hit of this.raycaster.intersectObject(this.roomGroup, true)) {
      const object = hit.object;
      if (object.isLine || object.isCSS2DObject || !visibleChain(object)) continue;
      const data = object.userData;
      if (data.unitId !== undefined) {
        result.unit = this.unitById(data.unitId);
        result.room = this.roomById(result.unit?.room_id);
      } else if (data.openingId !== undefined) {
        result.room = this.roomById(data.roomId);
        result.opening = result.room && this.openingById(result.room, data.openingId);
      } else if (data.wallIndex !== undefined || data.isFloor) {
        result.room = this.roomById(data.roomId);
        result.wallIndex = data.wallIndex ?? null;
      } else continue;
      result.point = hit.point;
      return result;
    }
    return result;
  }

  handleClick(event) { this.callbacks.onPick?.(this.pickAny(event)); }
  handleDoubleClick(event) { this.callbacks.onOpen?.(this.pickAny(event)); }
  handleMenu(point) { this.callbacks.onPlanMenu?.(this.pickAny(point), point); }

  hoverText(event) {
    const hit = this.pickAny(event);
    if (hit.unit) return hit.unit.name;
    if (hit.opening) return hit.opening.name || { door: 'Porte', window: 'Fenêtre', opening: 'Passage' }[hit.opening.kind];
    if (hit.room) return `${hit.room.name} : double-clic pour entrer`;
    return null;
  }

  /** Position sur le plan (mm) d'un point de la scène, et dans le repère d'une salle. */
  localPoint(room, point) {
    const [x, z] = planToRoom(room, point.x / S, point.z / S);
    return { x, z };
  }

  /** Centre la vue sur la sélection. */
  focusSelection() {
    const entry = this.selection && this.entries.get(this.selection.roomId);
    if (!entry) return this.frame();
    const b = planBounds(entry.room);
    const center = new THREE.Vector3((b.minX + b.maxX) / 2 * S, entry.y * S, (b.minZ + b.maxZ) / 2 * S);
    const span = Math.max(b.maxX - b.minX, b.maxZ - b.minZ, 3000) * S;
    const tan = Math.tan((this.camera.fov * Math.PI) / 360);
    const direction = this.camera.position.clone().sub(this.controls.target).normalize();
    return this.moveCamera(center.clone().add(direction.multiplyScalar((span / (2 * tan)) * 1.5)), center);
  }
}

const UP = new THREE.Vector3(0, 1, 0);

const overlayMaterial = () => new THREE.LineBasicMaterial({ color: SELECT_COLOR, depthTest: false, transparent: true });

function visibleChain(object) {
  for (let o = object; o; o = o.parent) if (!o.visible) return false;
  return true;
}

function disposeTree(object) {
  object.traverse((o) => {
    if (o.isCSS2DObject) o.element.remove();
    o.geometry?.dispose();
    if (o.material && !o.material.userData.shared) {
      o.material.map?.dispose();
      o.material.dispose();
    }
  });
}

/** Étiquette d'une salle sur le plan : son nom et ses dimensions. */
function roomLabel(name, size, x, y, z) {
  const el = document.createElement('div');
  el.className = 'plan-room-label';
  const strong = document.createElement('strong');
  strong.textContent = name;
  const span = document.createElement('span');
  span.textContent = size;
  el.append(strong, span);
  const object = new CSS2DObject(el);
  object.position.set(x, y, z);
  return object;
}
