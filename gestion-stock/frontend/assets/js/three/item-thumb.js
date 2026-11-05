// Vignettes du catalogue : le modèle 3D d'un objet, rendu une fois en image.
// Un seul moteur de rendu pour toute la liste, libéré dès qu'il ne sert plus : un navigateur limite le
// nombre de contextes WebGL, et l'éditeur 3D doit pouvoir en ouvrir un ensuite.

import * as THREE from '/vendor/three/three.module.min.js';
import { thumbModel } from './item-models.js';

const SIZE = 216; // trois fois la taille affichée, pour rester net sur les écrans à haute densité
const PADDING = 1.1;
const IDLE_MS = 3000;

const images = new Map();
let renderer = null;
let unavailable = false;
let idleTimer = null;
let queue = Promise.resolve();

function draw(entry) {
  renderer ??= new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(1);
  renderer.setSize(SIZE, SIZE, false);

  const scene = new THREE.Scene();
  scene.add(new THREE.HemisphereLight(0xffffff, 0x8a8170, 1.6));
  const sun = new THREE.DirectionalLight(0xffffff, 1.4);
  sun.position.set(1, 2, 1.5);
  scene.add(sun);
  const model = thumbModel(entry);
  scene.add(model);

  // Vue de trois quarts, cadrée au plus près sur les coins du modèle
  const box = new THREE.Box3().setFromObject(model);
  const center = box.getCenter(new THREE.Vector3());
  const radius = box.getSize(new THREE.Vector3()).length() || 1;
  const camera = new THREE.OrthographicCamera();
  camera.position.copy(center).add(new THREE.Vector3(0.8, 0.9, 1.1).normalize().multiplyScalar(radius * 2));
  camera.lookAt(center);
  camera.updateMatrixWorld();
  const middle = center.clone().applyMatrix4(camera.matrixWorldInverse);
  let half = 0;
  for (const x of [box.min.x, box.max.x]) {
    for (const y of [box.min.y, box.max.y]) {
      for (const z of [box.min.z, box.max.z]) {
        const corner = new THREE.Vector3(x, y, z).applyMatrix4(camera.matrixWorldInverse).sub(middle);
        half = Math.max(half, Math.abs(corner.x), Math.abs(corner.y));
      }
    }
  }
  half = (half || 1) * PADDING;
  Object.assign(camera, { left: -half, right: half, top: half, bottom: -half, near: radius * 0.01, far: radius * 4 });
  camera.updateProjectionMatrix();

  renderer.render(scene, camera);
  const url = renderer.domElement.toDataURL('image/png');
  model.traverse((object) => object.dispose?.()); // instances d'un tas ; les géométries partagées restent
  return url;
}

function releaseWhenIdle() {
  clearTimeout(idleTimer);
  idleTimer = setTimeout(() => {
    renderer?.dispose();
    renderer?.forceContextLoss();
    renderer = null;
  }, IDLE_MS);
}

/** Image du modèle d'un objet (adresse data:), ou null sans WebGL. Les rendus passent un par un pour ne pas figer la page. */
export function itemThumbUrl(item) {
  const entry = { name: item.name, visual: item.visual, visual_color: item.visual_color, category: item.category?.name };
  const key = JSON.stringify(entry);
  if (!images.has(key)) {
    images.set(key, queue = queue.then(() => new Promise((resolve) => {
      setTimeout(() => {
        if (unavailable) return resolve(null);
        try {
          resolve(draw(entry));
        } catch (error) {
          unavailable = true;
          console.warn('Vignette 3D indisponible', error);
          resolve(null);
        }
        releaseWhenIdle();
      });
    })));
  }
  return images.get(key);
}
