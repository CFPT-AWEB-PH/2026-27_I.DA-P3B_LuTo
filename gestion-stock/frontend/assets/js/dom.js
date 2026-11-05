// Construction sûre du DOM : le texte passe toujours par des nœuds texte, jamais par innerHTML.

/**
 * h('button', { class: 'btn', onclick: fn, disabled: true }, 'Texte', autreNoeud)
 * Les propriétés on* deviennent des écouteurs ; class, dataset et les attributs sont gérés.
 */
export function h(tag, props = {}, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(props ?? {})) {
    if (value === undefined || value === null || value === false) continue;
    if (key.startsWith('on') && typeof value === 'function') {
      el.addEventListener(key.slice(2), value);
    } else if (key === 'class') {
      el.className = Array.isArray(value) ? value.filter(Boolean).join(' ') : value;
    } else if (key === 'dataset') {
      Object.assign(el.dataset, value);
    } else if (key === 'value' || key === 'checked' || key === 'selected') {
      el[key] = value;
    } else {
      el.setAttribute(key, value === true ? '' : String(value));
    }
  }
  append(el, ...children);
  return el;
}

/** Ajoute des enfants en ignorant null, undefined et false (contrairement à Element.append). */
export function append(el, ...children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
}

export function clear(el, ...children) {
  el.replaceChildren();
  append(el, ...children);
  return el;
}

/** Date UTC de la base (« 2026-11-02 12:00:00 ») vers l'heure locale. */
export function formatDate(value, withTime = true) {
  if (!value) return '–';
  const date = new Date(value.replace(' ', 'T') + 'Z');
  return withTime
    ? date.toLocaleString('fr-CH', { dateStyle: 'short', timeStyle: 'short' })
    : date.toLocaleDateString('fr-CH');
}
