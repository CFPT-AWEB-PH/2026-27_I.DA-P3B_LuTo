// Cadrage d'une caméra perspective : distance minimale pour que tous les points tiennent dans l'image,
// quel que soit le format de la vue (une vue haute et étroite demande de reculer davantage).

import * as THREE from '/vendor/three/three.module.min.js';

/**
 * Position de caméra regardant `target` depuis la direction `direction` (de la cible vers la caméra),
 * reculée juste assez pour que chaque point de `points` soit visible avec une marge de `margin` (part de l'image).
 */
export function fitPosition(camera, points, target, direction, margin = 0.08) {
  const dir = direction.clone().normalize();
  // Repère de l'image : « droite » et « haut » vus depuis la caméra (vue de dessus : le haut de l'image suit -Z)
  const right = new THREE.Vector3().crossVectors(camera.up, dir);
  if (right.lengthSq() < 1e-8) right.set(1, 0, 0);
  right.normalize();
  const up = new THREE.Vector3().crossVectors(dir, right).normalize();
  const tanV = Math.tan((camera.fov * Math.PI) / 360) * (1 - margin * 2);
  const tanH = tanV * camera.aspect;
  let distance = 0;
  for (const point of points) {
    const v = point.clone().sub(target);
    const ahead = v.dot(dir);
    distance = Math.max(distance, ahead + Math.abs(v.dot(up)) / tanV, ahead + Math.abs(v.dot(right)) / tanH);
  }
  distance = Math.max(distance, camera.near * 4);
  return target.clone().add(dir.multiplyScalar(distance));
}

/** Les 8 coins d'une boîte { min, max }. */
export function boxCorners(min, max) {
  const corners = [];
  for (const x of [min.x, max.x]) for (const y of [min.y, max.y]) for (const z of [min.z, max.z]) corners.push(new THREE.Vector3(x, y, z));
  return corners;
}
