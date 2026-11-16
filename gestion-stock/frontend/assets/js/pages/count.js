// Comptage à la caméra : les pièces sont posées sur une surface claire, la caméra les compte, le stock est mis à jour.
// L'image est analysée dans le navigateur (vision/count.js) : elle n'est ni envoyée ni conservée. Seul le nombre validé
// part au serveur, par les opérations de stock habituelles (correction ou ajout), donc avec permissions et historique.

import { api } from '../api.js';
import { clear, h } from '../dom.js';
import { icon } from '../icons.js';
import { busy, emptyState, locationLabel, pageHeader, showError, stepper, toast } from '../ui.js';
import { formatQuantity, pickItem, pickUnit, unitLabel } from '../stock-dialogs.js';
import { analyse, blobAt, paintOverlay } from '../vision/count.js';
import { modelThumb } from './items.js';

const LIVE_WIDTH = 480;   // analyse en direct : légère, plusieurs fois par seconde même sur un Raspberry Pi
const STILL_WIDTH = 960;  // image figée : plus de détail pour le compte que l'on valide
const LIVE_DELAY = 160;   // pause entre deux analyses en direct (ms)

let stopCurrent = () => {};

/** Appelé par le routeur en quittant la page : la caméra ne reste jamais allumée ailleurs. */
export function dispose() {
  stopCurrent();
}

const CAMERA_ERRORS = {
  NotAllowedError: 'L’accès à la caméra a été refusé. Autorisez-le dans le navigateur (icône à gauche de l’adresse), ou importez une photo.',
  NotFoundError: 'Aucune caméra n’a été trouvée sur cet appareil. Vous pouvez importer une photo.',
  NotReadableError: 'La caméra est utilisée par une autre application. Fermez-la, puis réessayez.',
  OverconstrainedError: 'Cette caméra n’est plus disponible. Choisissez-en une autre.',
};

export async function render(view, session) {
  const can = (p) => session.can(p);
  const params = new URLSearchParams(location.search);
  const live = Boolean(navigator.mediaDevices?.getUserMedia);

  const state = {
    item: null,            // fiche de l'objet compté
    place: null,           // { unit_id, path, quantity }
    mode: 'idle',          // idle | live | frozen
    stream: null,
    frame: null,           // image figée (ImageData)
    result: null,          // dernière analyse
    overrides: new Map(),  // corrections à la main : indice de zone → nombre
    selected: -1,
    lots: [],
    edited: false,         // total corrigé à la main
    sensitivity: 0.5,
    pieceRatio: null,      // taille d'une pièce mémorisée
    recent: [],            // derniers comptes en direct, pour un affichage stable
    saveMode: can('stock.adjust') ? 'replace' : 'add',
  };
  let timer = null;

  // ----- Scène : vidéo, image figée, calque -----
  const video = h('video', { class: 'scan-video', playsinline: true, muted: true, autoplay: true, 'aria-hidden': 'true' });
  video.muted = true;
  const still = h('canvas', { class: 'scan-still', hidden: true, 'aria-hidden': 'true' });
  const overlay = h('canvas', { class: 'scan-overlay', 'aria-label': 'Pièces détectées : touchez une zone pour corriger son nombre' });
  const work = document.createElement('canvas');
  const badgeNumber = h('span', { class: 'scan-badge-num' }, '0');
  const badgeUnit = h('span', { class: 'scan-badge-unit' }, 'pièce');
  const badge = h('div', { class: 'scan-badge', hidden: true }, badgeNumber, badgeUnit);
  const stateChip = h('span', { class: 'scan-state', hidden: true });
  const fileInput = h('input', { type: 'file', accept: 'image/*', capture: 'environment', hidden: true });
  const startButton = h('button', { class: 'btn btn-primary', type: 'button', onclick: (e) => busy(e.currentTarget, () => startCamera()) }, icon('camera'), 'Démarrer la caméra');
  const placeholder = h('div', { class: 'scan-placeholder' },
    h('span', { class: 'scan-placeholder-icon' }, icon('camera')),
    h('p', { class: 'scan-placeholder-title' }, live ? 'Caméra éteinte' : 'Caméra en direct indisponible'),
    h('p', { class: 'scan-placeholder-text' }, live
      ? 'Posez les pièces sur une table blanche ou une feuille, sans qu’elles se touchent. Pour des pièces blanches, prenez une feuille de couleur claire.'
      : 'Le direct demande une connexion sécurisée (https). Prenez une photo des pièces sur une surface blanche : elle sera comptée de la même façon.'),
    h('div', { class: 'scan-placeholder-actions' },
      live && startButton,
      h('button', { class: ['btn', !live && 'btn-primary'], type: 'button', onclick: () => fileInput.click() }, icon('image'), live ? 'Importer une photo' : 'Prendre une photo')));
  const stage = h('div', { class: 'scan-stage' }, video, still, overlay, badge, stateChip, placeholder);

  // ----- Commandes -----
  const captureButton = h('button', { class: 'btn btn-primary scan-main-action', type: 'button', hidden: true });
  const resumeButton = h('button', { class: 'btn', type: 'button', hidden: true, onclick: () => resume() }, icon('refresh'), 'Reprendre');
  const photoButton = h('button', { class: 'btn', type: 'button', hidden: true, onclick: () => fileInput.click() }, icon('image'), 'Photo');
  const cameraSelect = h('select', { class: 'scan-camera-select', 'aria-label': 'Caméra', hidden: true });
  const sensitivity = h('input', { type: 'range', min: 0, max: 100, step: 5, value: 50, id: 'scan-sensitivity' });
  const lockInput = h('input', { type: 'checkbox', disabled: true });
  const messages = h('div', { class: 'scan-messages', 'aria-live': 'polite' });
  const selectionBox = h('div', { class: 'scan-selection', hidden: true });
  const announcer = h('p', { class: 'sr-only', role: 'status' });

  // ----- Côté : objet, lots, enregistrement -----
  const itemCard = h('section', { class: 'card scan-card', 'aria-labelledby': 'scan-item-title' });
  const resultCard = h('section', { class: 'card scan-card', 'aria-labelledby': 'scan-result-title' });
  const totalInput = h('input', { type: 'number', inputmode: 'numeric', min: 0, step: 1, value: 0, 'aria-label': 'Total compté' });

  const unitOf = () => state.item?.unit || 'pièce';
  const effective = (index) => state.overrides.get(index) ?? state.result.blobs[index].count;
  const currentCount = () => (state.result ? state.result.blobs.reduce((sum, _, index) => sum + effective(index), 0) : 0);
  const lotsTotal = () => state.lots.reduce((a, b) => a + b, 0);
  const total = () => Math.max(0, Math.round(Number(String(totalInput.value).replace(',', '.')) || 0));

  // ----- Capture et analyse -----
  function grab(source, maxWidth) {
    const sw = source.videoWidth || source.naturalWidth || source.width;
    const sh = source.videoHeight || source.naturalHeight || source.height;
    if (!sw || !sh) return null;
    const scale = Math.min(1, maxWidth / sw);
    work.width = Math.max(1, Math.round(sw * scale));
    work.height = Math.max(1, Math.round(sh * scale));
    const context = work.getContext('2d', { willReadFrequently: true });
    context.drawImage(source, 0, 0, work.width, work.height);
    stage.style.setProperty('--ratio', String(sw / sh));
    return context.getImageData(0, 0, work.width, work.height);
  }

  function run(frame) {
    state.result = analyse(frame.data, frame.width, frame.height, { sensitivity: state.sensitivity, pieceRatio: state.pieceRatio });
    draw();
  }

  function draw() {
    const result = state.result;
    if (!result) return;
    if (overlay.width !== result.width || overlay.height !== result.height) { overlay.width = result.width; overlay.height = result.height; }
    const context = overlay.getContext('2d');
    const kinds = result.blobs.map((blob, index) => {
      if (!state.overrides.has(index)) return blob.kind;
      const value = state.overrides.get(index);
      return value === 0 ? 'off' : value > 1 ? 'group' : 'piece';
    });
    const image = context.createImageData(result.width, result.height);
    paintOverlay(result, image.data, kinds);
    context.putImageData(image, 0, 0);

    // Nombre inscrit sur les zones qui valent plusieurs pièces ; anneau autour de la zone sélectionnée
    const size = Math.max(11, Math.round(result.width / 36));
    context.font = `600 ${size}px Inter, system-ui, sans-serif`;
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    result.blobs.forEach((blob, index) => {
      const value = effective(index);
      if (value > 1) {
        const text = `×${value}`;
        const width = context.measureText(text).width + size * 0.7;
        context.fillStyle = 'rgb(20 22 30 / 88%)';
        context.beginPath();
        if (context.roundRect) context.roundRect(blob.cx - width / 2, blob.cy - size * 0.75, width, size * 1.5, size * 0.4);
        else context.rect(blob.cx - width / 2, blob.cy - size * 0.75, width, size * 1.5);
        context.fill();
        context.fillStyle = '#ffffff';
        context.fillText(text, blob.cx, blob.cy + 1);
      }
      if (index === state.selected) {
        context.strokeStyle = '#ffffff';
        context.lineWidth = Math.max(2, size / 5);
        context.setLineDash([size * 0.5, size * 0.35]);
        context.strokeRect(blob.x0 - 4, blob.y0 - 4, blob.x1 - blob.x0 + 9, blob.y1 - blob.y0 + 9);
        context.setLineDash([]);
      }
    });
    renderReadout();
  }

  /** Nombre affiché sur l'image : médiane des derniers comptes en direct, compte exact une fois l'image figée. */
  function renderReadout() {
    const result = state.result;
    let value = currentCount();
    if (state.mode === 'live') {
      state.recent.push(value);
      if (state.recent.length > 5) state.recent.shift();
      value = [...state.recent].sort((a, b) => a - b)[state.recent.length >> 1];
    }
    badge.hidden = state.mode === 'idle';
    badgeNumber.textContent = value.toLocaleString('fr-CH');
    badgeUnit.textContent = unitLabel(unitOf(), value);
    stateChip.hidden = state.mode === 'idle';
    stateChip.textContent = state.mode === 'live' ? 'En direct' : 'Image figée';
    stateChip.classList.toggle('is-live', state.mode === 'live');

    const notes = [];
    if (result?.tooDark) notes.push(['error', 'Image trop sombre : éclairez la table ou rapprochez une lampe.']);
    else if (result?.noBackground) notes.push(['error', 'Fond non reconnu : posez les pièces sur une surface blanche unie (table claire, feuille de papier).']);
    else if (result) {
      if (result.edges > 0) notes.push(['warn', `${result.edges === 1 ? 'Une pièce touche' : `${result.edges} pièces touchent`} le bord de l’image et ${result.edges === 1 ? 'n’est pas comptée' : 'ne sont pas comptées'} : ramenez-${result.edges === 1 ? 'la' : 'les'} vers le centre.`]);
      if (result.groups > 0) notes.push(['info', `${result.groups === 1 ? 'Des pièces se touchent' : `${result.groups} groupes de pièces se touchent`} : leur nombre est estimé d’après leur surface. Écartez-les pour un compte sûr${state.mode === 'frozen' ? ', ou touchez la zone pour corriger' : ''}.`]);
    }
    const key = JSON.stringify(notes);
    if (messages.dataset.key !== key) {
      messages.dataset.key = key;
      clear(messages, notes.map(([type, text]) => h('p', { class: ['alert', type === 'error' && 'alert-error', type === 'info' && 'alert-info'] }, icon(type === 'info' ? 'info' : 'alert'), h('span', {}, text))));
    }
    if (state.mode === 'frozen') captureLabel.textContent = `Ajouter ce lot · ${formatQuantity(value, unitOf())}`;
    captureButton.disabled = state.mode === 'frozen' && (value === 0 || Boolean(result?.tooDark));
  }

  const captureLabel = h('span', {}, 'Figer et compter');
  captureButton.append(icon('focus'), captureLabel);
  captureButton.addEventListener('click', () => (state.mode === 'frozen' ? addLot() : freeze(video)));

  function setMode(mode) {
    state.mode = mode;
    state.recent = [];
    placeholder.hidden = mode !== 'idle';
    video.hidden = mode !== 'live';
    still.hidden = mode !== 'frozen';
    overlay.hidden = mode === 'idle';
    stage.classList.toggle('is-frozen', mode === 'frozen');
    captureButton.hidden = mode === 'idle';
    captureLabel.textContent = 'Figer et compter';
    captureButton.disabled = false;
    resumeButton.hidden = mode !== 'frozen';
    photoButton.hidden = mode === 'idle';
    lockInput.disabled = mode !== 'frozen' && state.pieceRatio === null;
    if (mode !== 'frozen') { state.selected = -1; state.overrides.clear(); renderSelection(); }
    if (mode === 'idle') { badge.hidden = true; stateChip.hidden = true; clear(messages); messages.dataset.key = ''; }
  }

  function loop() {
    clearTimeout(timer);
    if (state.mode !== 'live' || !view.isConnected) return;
    if (video.readyState >= 2 && !document.hidden) {
      const frame = grab(video, LIVE_WIDTH);
      if (frame) run(frame);
    }
    timer = setTimeout(loop, LIVE_DELAY);
  }

  async function startCamera(deviceId = null) {
    stopStream();
    const size = { width: { ideal: 1280 }, height: { ideal: 960 } };
    try {
      state.stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: deviceId ? { deviceId: { exact: deviceId }, ...size } : { facingMode: { ideal: 'environment' }, ...size },
      });
    } catch (error) {
      setMode('idle');
      showError(new Error(CAMERA_ERRORS[error.name] ?? `La caméra n’a pas pu démarrer (${error.message}).`));
      return;
    }
    if (!view.isConnected) return stopStream();
    video.srcObject = state.stream;
    try { await video.play(); } catch { /* lecture déjà lancée par autoplay */ }
    setMode('live');
    loop();
    listCameras();
  }

  /** Les noms des caméras ne sont lisibles qu'une fois l'accès accordé. */
  async function listCameras() {
    try {
      const cameras = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
      const active = state.stream?.getVideoTracks()[0]?.getSettings().deviceId;
      cameraSelect.hidden = cameras.length < 2;
      clear(cameraSelect, cameras.map((camera, i) => h('option', { value: camera.deviceId, selected: camera.deviceId === active }, camera.label || `Caméra ${i + 1}`)));
    } catch { cameraSelect.hidden = true; }
  }
  cameraSelect.addEventListener('change', () => startCamera(cameraSelect.value));

  function stopStream() {
    clearTimeout(timer);
    state.stream?.getTracks().forEach((track) => track.stop());
    state.stream = null;
    video.srcObject = null;
  }

  function freeze(source) {
    const frame = grab(source, STILL_WIDTH);
    if (!frame) return;
    clearTimeout(timer);
    state.frame = frame;
    still.width = frame.width;
    still.height = frame.height;
    still.getContext('2d').putImageData(frame, 0, 0);
    setMode('frozen');
    run(frame);
    const count = currentCount();
    announcer.textContent = `Image figée : ${formatQuantity(count, unitOf())} ${count > 1 ? 'comptées' : 'comptée'}.`;
    captureButton.focus({ preventScroll: true });
  }

  function resume() {
    state.frame = null;
    if (state.stream) { setMode('live'); loop(); } else setMode('idle');
  }

  function addLot() {
    const count = currentCount();
    if (count <= 0) return;
    state.lots.push(count);
    state.edited = false;
    totalInput.value = lotsTotal();
    renderResult();
    toast(`Lot de ${formatQuantity(count, unitOf())} ajouté. Total : ${formatQuantity(lotsTotal(), unitOf())}.`);
    resume();
  }

  fileInput.addEventListener('change', async () => {
    const file = fileInput.files[0];
    fileInput.value = '';
    if (!file) return;
    try {
      const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
      freeze(bitmap);
      bitmap.close?.();
    } catch {
      showError(new Error('Cette image n’a pas pu être lue. Choisissez une photo au format JPEG ou PNG.'));
    }
  });

  // ----- Corrections à la main sur l'image figée -----
  overlay.addEventListener('click', (event) => {
    if (state.mode !== 'frozen' || !state.result) return;
    const box = overlay.getBoundingClientRect();
    const index = blobAt(state.result, ((event.clientX - box.left) / box.width) * state.result.width, ((event.clientY - box.top) / box.height) * state.result.height, Math.round(state.result.width / 60));
    state.selected = index;
    renderSelection();
    draw();
  });

  function renderSelection() {
    const index = state.selected;
    selectionBox.hidden = index < 0;
    if (index < 0) return clear(selectionBox);
    const blob = state.result.blobs[index];
    const input = h('input', { type: 'number', inputmode: 'numeric', min: 0, max: 999, step: 1, value: effective(index), 'aria-label': 'Nombre de pièces dans la zone sélectionnée' });
    input.addEventListener('input', () => {
      const value = Math.max(0, Math.min(999, Math.round(Number(input.value) || 0)));
      if (value === blob.count) state.overrides.delete(index); else state.overrides.set(index, value);
      draw();
    });
    const why = { edge: 'Coupée par le bord : non comptée.', dust: 'Trop petite : ignorée.', group: 'Plusieurs pièces qui se touchent.', piece: 'Une pièce.' }[blob.kind];
    clear(selectionBox,
      h('div', { class: 'scan-selection-text' }, h('strong', {}, 'Zone sélectionnée'), h('span', { class: 'sub' }, why)),
      stepper(input),
      h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Désélectionner', onclick: () => { state.selected = -1; renderSelection(); draw(); } }, icon('x')));
  }

  sensitivity.addEventListener('input', () => {
    state.sensitivity = Number(sensitivity.value) / 100;
    if (state.mode === 'frozen' && state.frame) { state.overrides.clear(); state.selected = -1; renderSelection(); run(state.frame); }
  });
  lockInput.addEventListener('change', () => {
    state.pieceRatio = lockInput.checked ? state.result?.pieceRatio ?? null : null;
    if (lockInput.checked && state.pieceRatio === null) lockInput.checked = false;
    lockInput.disabled = state.mode !== 'frozen' && state.pieceRatio === null;
    if (state.mode === 'frozen' && state.frame) run(state.frame);
  });

  // ----- Objet et emplacement -----
  async function loadItem(id, unitId = null) {
    try {
      const { item } = await api(`/items/${id}`);
      state.item = item;
      const wanted = unitId ?? state.place?.unit_id ?? null;
      const found = item.locations.find((l) => l.unit_id === wanted);
      if (found) state.place = found;
      // Emplacement choisi à la main et encore vide (ou vidé par l'inventaire) : il reste sélectionné
      else if (state.place && state.place.unit_id === wanted) state.place = { ...state.place, quantity: 0 };
      else state.place = item.locations.length === 1 ? item.locations[0] : null;
    } catch (error) {
      state.item = null;
      state.place = null;
      showError(error);
    }
    renderItem();
    renderResult();
    if (state.result) renderReadout();
  }

  async function chooseItem() {
    const picked = await pickItem('Quel objet comptez-vous ?');
    if (!picked) return;
    // Les lots déjà comptés restent : on peut compter d'abord et dire ensuite de quel objet il s'agit
    state.place = null;
    state.pieceRatio = null;
    lockInput.checked = false;
    lockInput.disabled = state.mode !== 'frozen';
    history.replaceState(null, '', `/count?item=${picked.id}`);
    await loadItem(picked.id);
  }

  async function chooseOtherPlace() {
    const picked = await pickUnit({ title: 'Où se trouvent ces pièces ?' });
    if (!picked) return;
    state.place = state.item.locations.find((l) => l.unit_id === picked.id) ?? { unit_id: picked.id, path: picked.path, quantity: 0 };
    renderItem();
    renderResult();
  }

  function renderItem() {
    const item = state.item;
    if (!item) {
      return clear(itemCard,
        h('div', { class: 'section-header' }, h('h2', { id: 'scan-item-title' }, 'Objet compté')),
        emptyState('Choisissez l’objet avant ou après le comptage : le nombre lui sera attribué.', {
          iconName: 'box', compact: true,
          action: h('button', { class: 'btn btn-primary', type: 'button', onclick: chooseItem }, icon('search'), 'Choisir un objet'),
        }));
    }
    const thumb = modelThumb(item);
    const known = item.locations.some((l) => l.unit_id === state.place?.unit_id);
    const places = [...item.locations, ...(state.place && !known ? [state.place] : [])];
    clear(itemCard,
      h('div', { class: 'section-header' }, h('h2', { id: 'scan-item-title' }, 'Objet compté'),
        h('button', { class: 'btn btn-small', type: 'button', onclick: chooseItem }, 'Changer')),
      h('div', { class: 'scan-item' }, thumb,
        h('div', { class: 'scan-item-text' },
          h('a', { class: 'scan-item-name', href: `/items/${item.id}` }, item.name),
          h('span', { class: 'sub' }, `${item.reference} · ${formatQuantity(item.total_quantity, item.unit)} en stock`))),
      !item.is_active && h('p', { class: 'alert' }, icon('alert'), h('span', {}, 'Cet objet est archivé : son stock ne peut pas être modifié.')),
      h('h3', { class: 'panel-subtitle' }, 'Emplacement'),
      places.length > 0 && h('div', { class: 'scan-places', role: 'radiogroup', 'aria-label': 'Emplacement compté' }, places.map((l) => h('label', { class: 'scan-place' },
        h('input', { type: 'radio', name: 'scan-place', checked: l.unit_id === state.place?.unit_id, onchange: () => { state.place = l; renderResult(); } }),
        h('span', { class: 'scan-place-path' }, locationLabel(l.path)),
        h('span', { class: 'scan-place-qty' }, formatQuantity(l.quantity, item.unit))))),
      places.length === 0 && h('p', { class: 'field-hint' }, 'Aucun stock enregistré pour cet objet : indiquez où se trouvent les pièces comptées.'),
      h('button', { class: 'btn btn-small scan-other-place', type: 'button', onclick: chooseOtherPlace }, icon('pin'), places.length ? 'Autre emplacement…' : 'Choisir un emplacement'));
  }

  // ----- Lots et enregistrement -----
  totalInput.addEventListener('input', () => { state.edited = total() !== lotsTotal(); renderSummary(); });
  const summary = h('div', { class: 'scan-summary' });
  const saveLabel = h('span', {}, 'Enregistrer');
  const saveButton = h('button', { class: 'btn btn-primary scan-save', type: 'button' }, icon('check'), saveLabel);
  const lotsList = h('ul', { class: 'scan-lots', 'aria-label': 'Lots comptés' });
  const modeBox = h('div', { class: 'scan-modes', role: 'radiogroup', 'aria-label': 'Que faire de ce compte ?' });

  function renderSummary() {
    const item = state.item; const place = state.place; const value = total();
    const unit = unitOf();
    const before = Number(place?.quantity ?? 0);
    const rows = [];
    if (item && place && (value > 0 || state.lots.length > 0)) {
      if (state.saveMode === 'replace') {
        const delta = value - before;
        rows.push(['Enregistré ici', formatQuantity(before, unit)], ['Compté', formatQuantity(value, unit)],
          ['Écart', delta === 0 ? 'aucun' : `${delta > 0 ? '+' : '−'}${formatQuantity(Math.abs(delta), unit)}`]);
      } else {
        rows.push(['Enregistré ici', formatQuantity(before, unit)], ['Ajouté', `+${formatQuantity(value, unit)}`], ['Après ajout', formatQuantity(before + value, unit)]);
      }
    }
    clear(summary, rows.length > 0 && h('dl', { class: 'props' }, rows.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)])));
    const ready = Boolean(item?.is_active && place && (value > 0 || (state.saveMode === 'replace' && state.lots.length > 0)));
    saveButton.disabled = !ready;
    saveLabel.textContent = state.saveMode === 'replace' ? `Enregistrer ${formatQuantity(value, unit)}` : `Ajouter ${formatQuantity(value, unit)} au stock`;
    saveHint.textContent = !item ? 'Choisissez l’objet compté pour enregistrer.' : !place ? 'Choisissez l’emplacement des pièces comptées.'
      : state.lots.length === 0 && value === 0 ? 'Figez l’image puis ajoutez un lot : le total s’additionne ici.' : '';
    saveHint.hidden = saveHint.textContent === '';
  }
  const saveHint = h('p', { class: 'field-hint' });

  function renderResult() {
    clear(lotsList, state.lots.map((lot, i) => h('li', { class: 'scan-lot' },
      h('span', {}, `Lot ${i + 1}`), h('strong', {}, formatQuantity(lot, unitOf())),
      h('button', { class: 'icon-btn icon-btn-small', type: 'button', 'aria-label': `Retirer le lot ${i + 1}`, title: 'Retirer ce lot', onclick: () => {
        state.lots.splice(i, 1);
        state.edited = false;
        totalInput.value = lotsTotal();
        renderResult();
      } }, icon('x')))));
    lotsList.hidden = state.lots.length === 0;
    const modes = [
      can('stock.adjust') && ['replace', 'Inventaire', 'C’est tout ce que contient cet emplacement : la quantité est remplacée.'],
      can('stock.add') && ['add', 'Arrivage', 'Ces pièces s’ajoutent à celles déjà enregistrées.'],
    ].filter(Boolean);
    modeBox.hidden = modes.length < 2;
    clear(modeBox, modes.map(([value, label, hint]) => h('label', { class: 'scan-mode' },
      h('input', { type: 'radio', name: 'scan-mode', value, checked: state.saveMode === value, onchange: () => { state.saveMode = value; renderSummary(); } }),
      h('span', { class: 'checkbox-text' }, label, h('span', { class: 'checkbox-hint' }, hint)))));
    renderSummary();
  }

  saveButton.addEventListener('click', () => busy(saveButton, async () => {
    const { item, place } = state;
    const value = total();
    const unit = unitOf();
    const detail = state.lots.length > 1 ? ` : ${state.lots.join(' + ')}` : '';
    const comment = `Comptage à la caméra${detail}${state.edited ? `, total corrigé à la main (${value})` : ''}`.slice(0, 500);
    try {
      if (state.saveMode === 'replace') {
        if (value === Number(place.quantity)) {
          toast(`Le compte confirme la quantité enregistrée (${formatQuantity(value, unit)}) : rien à corriger.`, 'info');
        } else {
          const r = await api('/stock/adjust', { method: 'POST', body: { item_id: item.id, unit_id: place.unit_id, quantity: value, comment } });
          toast(`Stock mis à jour : ${formatQuantity(r.quantity, unit)} à cet emplacement (avant : ${formatQuantity(place.quantity, unit)}).`);
        }
      } else {
        const r = await api('/stock/add', { method: 'POST', body: { item_id: item.id, unit_id: place.unit_id, quantity: value, comment } });
        toast(`Ajout enregistré : ${formatQuantity(value, unit)}. Il y en a maintenant ${formatQuantity(r.quantity, unit)} à cet emplacement.`);
      }
    } catch (error) {
      return showError(error);
    }
    state.lots = [];
    state.edited = false;
    totalInput.value = 0;
    await loadItem(item.id, place.unit_id);
  }));

  clear(resultCard,
    h('div', { class: 'section-header' }, h('h2', { id: 'scan-result-title' }, 'Total compté')),
    lotsList,
    h('div', { class: 'scan-total' }, stepper(totalInput)),
    modeBox, summary, saveHint, saveButton);

  // ----- Page -----
  clear(view,
    pageHeader({
      title: 'Comptage à la caméra',
      description: 'Posez les pièces sur une surface blanche : la caméra les compte et le stock est mis à jour.',
    }),
    h('div', { class: 'scan-layout' },
      h('section', { class: 'card scan-main', 'aria-label': 'Caméra' },
        stage,
        h('div', { class: 'scan-actions' }, captureButton, resumeButton, photoButton, cameraSelect),
        selectionBox, messages,
        h('div', { class: 'scan-settings' },
          h('label', { class: 'scan-range', for: 'scan-sensitivity' }, h('span', {}, 'Sensibilité'), sensitivity,
            h('span', { class: 'field-hint' }, 'Vers la droite : détecte les pièces claires, mais aussi les ombres.')),
          h('label', { class: 'chip-toggle', title: 'Les pièces qui se touchent sont alors comptées d’après cette taille. À refaire si la caméra ou la table bouge.' }, lockInput, 'Mémoriser la taille d’une pièce')),
        fileInput, announcer),
      h('div', { class: 'scan-side' }, itemCard, resultCard,
        h('p', { class: 'meta-footer' }, 'L’image est analysée sur cet appareil : elle n’est ni envoyée au serveur ni conservée.'))),
  );
  setMode('idle');
  renderItem();
  renderResult();

  stopCurrent = () => { stopStream(); stopCurrent = () => {}; };
  // Onglet masqué : inutile de garder la caméra allumée
  const onVisibility = () => { if (!view.isConnected) document.removeEventListener('visibilitychange', onVisibility); else if (!document.hidden && state.mode === 'live') loop(); };
  document.addEventListener('visibilitychange', onVisibility);

  const itemId = Number(params.get('item'));
  if (itemId > 0) loadItem(itemId, Number(params.get('unit')) || null);

  // Caméra déjà autorisée (poste de l'atelier) : elle démarre seule ; sinon, on attend le bouton pour ne pas surprendre
  if (live) {
    try {
      const permission = await navigator.permissions?.query({ name: 'camera' });
      if (permission?.state === 'granted' && view.isConnected) await startCamera();
    } catch { /* API absente (Firefox, Safari) : le bouton suffit */ }
  }
}
