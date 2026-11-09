// Historique des modifications d'un éditeur (Ctrl+Z, Ctrl+Y) et presse-papiers des rangements (Ctrl+C, X, V).
// Chaque entrée sait se défaire et se refaire par l'API : le serveur reste seul juge, et une annulation
// devenue impossible (quelqu'un a modifié la même chose entre-temps) est signalée, pas forcée.

import { toast } from './ui.js';

const MAX_ENTRIES = 100;

export class History {
  /** @param {{ onChange?: Function }} options onChange : appelé quand les boutons doivent être réévalués. */
  constructor({ onChange = () => {} } = {}) {
    this.done = [];
    this.undone = [];
    this.running = false;
    this.onChange = onChange;
    // Une suppression annulée recrée le rangement sous un autre identifiant : les entrées plus anciennes suivent
    this.ids = new Map();
  }

  /** Identifiant actuel d'un rangement (recréé après une suppression annulée). */
  id(original) {
    let id = original;
    for (let guard = 0; this.ids.has(id) && guard < 100; guard++) id = this.ids.get(id);
    return id;
  }

  remember(oldId, newId) {
    if (oldId !== newId) this.ids.set(oldId, newId);
  }

  /** @param {{ label: string, undo: Function, redo: Function }} entry */
  push(entry) {
    this.done.push(entry);
    if (this.done.length > MAX_ENTRIES) this.done.shift();
    this.undone = [];
    this.onChange();
  }

  /** Ajoute une entrée et propose de l'annuler tout de suite, sans chercher le raccourci. */
  pushWithToast(entry, message) {
    this.push(entry);
    toast(message, 'success', { action: { label: 'Annuler', run: () => this.undo() } });
  }

  get canUndo() { return this.done.length > 0; }
  get canRedo() { return this.undone.length > 0; }
  get undoLabel() { return this.done.at(-1)?.label ?? null; }
  get redoLabel() { return this.undone.at(-1)?.label ?? null; }

  async undo() { return this.step(this.done, this.undone, 'undo', 'Annulé'); }
  async redo() { return this.step(this.undone, this.done, 'redo', 'Rétabli'); }

  async step(from, to, method, word) {
    if (this.running || from.length === 0) return false;
    this.running = true;
    const entry = from.pop();
    try {
      await entry[method]();
      to.push(entry);
      toast(`${word} : ${entry.label}.`, 'info');
      return true;
    } catch (error) {
      toast(`Impossible : ${error.message ?? 'une erreur est survenue.'} L'action « ${entry.label} » a été retirée de l'historique.`, 'error');
      return false;
    } finally {
      this.running = false;
      this.onChange();
    }
  }
}

// Presse-papiers partagé entre les salles et le plan, conservé si la page est rechargée
const STORAGE_KEY = 'unit-clipboard';
let memory = null;

export const clipboard = {
  /** @param {{ tree: object, name: string, cut?: { id: number, room_id: number } | null }} value */
  set(value) {
    memory = value;
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(value)); } catch { /* stockage indisponible : la mémoire suffit */ }
  },
  get() {
    if (memory) return memory;
    try { memory = JSON.parse(localStorage.getItem(STORAGE_KEY)); } catch { memory = null; }
    return memory;
  },
  clearCut() {
    const value = this.get();
    if (value?.cut) this.set({ ...value, cut: null });
  },
};

/** Vrai si le raccourci clavier ne doit pas être intercepté : saisie en cours ou fenêtre ouverte. */
export function typingOrDialog(event) {
  const target = event.target;
  if (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return true;
  return Boolean(document.querySelector('dialog[open]'));
}

/** Touche d'action pour l'affichage : Cmd sur Mac, Ctrl ailleurs. */
export const MOD = /Mac|iPhone|iPad/.test(navigator.platform ?? '') ? '⌘' : 'Ctrl';

const shortcutLine = (keys, text) => [keys, text];

/** Aide-mémoire affiché par « ? ». */
export const SHORTCUTS = [
  ['Modifier', [
    shortcutLine([`${MOD}+Z`], 'Annuler'),
    shortcutLine([`${MOD}+Y`, `${MOD}+Maj+Z`], 'Rétablir'),
    shortcutLine([`${MOD}+C`], 'Copier le rangement'),
    shortcutLine([`${MOD}+X`], 'Couper : il se déplace, avec son contenu, au collage'),
    shortcutLine([`${MOD}+V`], 'Coller dans le rangement sélectionné ou la salle'),
    shortcutLine([`${MOD}+D`], 'Dupliquer à côté'),
    shortcutLine([`${MOD}+S`], 'Enregistrer comme modèle réutilisable'),
    shortcutLine(['Suppr'], 'Supprimer'),
  ]],
  ['Gizmo', [
    shortcutLine(['Q'], 'Sélection seule'),
    shortcutLine(['W'], 'Déplacer'),
    shortcutLine(['E'], 'Pivoter'),
    shortcutLine(['R'], 'Dimensions'),
    shortcutLine(['← ↑ → ↓'], 'Décaler de 1 cm (Maj : 10 cm)'),
    shortcutLine(['Pg préc.', 'Pg suiv.'], 'Monter, descendre'),
  ]],
  ['Vue', [
    shortcutLine(['F'], 'Centrer sur la sélection'),
    shortcutLine(['H'], 'Masquer ou réafficher'),
    shortcutLine(['Échap'], 'Tout désélectionner'),
    shortcutLine(['Double-clic'], 'Ouvrir : salle, tiroir'),
    shortcutLine(['Clic droit'], 'Menu d’actions'),
    shortcutLine(['?'], 'Cet aide-mémoire'),
  ]],
];
