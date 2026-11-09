// Thème clair ou sombre : choix de la personne (mémorisé dans ce navigateur) ou réglage du système.
// Script classique chargé dans <head>, avant le premier affichage : pas de flash de la mauvaise couleur.
// Pose data-theme="light" | "dark" sur <html> ; les couleurs sombres de app.css dépendent de cet attribut.
(function () {
  var KEY = 'theme';
  var COLORS = { light: '#eceff4', dark: '#0c0d10' };
  var media = window.matchMedia('(prefers-color-scheme: dark)');
  var listeners = [];
  var memory = 'auto'; // si le stockage est bloqué, le choix vaut pour cette page

  function read() {
    try { var v = localStorage.getItem(KEY); return v === 'light' || v === 'dark' ? v : 'auto'; } catch (e) { return memory; }
  }
  function resolved(choice) { return choice === 'auto' ? (media.matches ? 'dark' : 'light') : choice; }
  function apply() {
    var choice = read();
    var theme = resolved(choice);
    document.documentElement.setAttribute('data-theme', theme);
    var metas = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < metas.length; i++) metas[i].setAttribute('content', COLORS[theme]);
    listeners.slice().forEach(function (fn) { fn(choice, theme); });
  }

  window.appTheme = {
    /** 'auto' | 'light' | 'dark' */
    get: read,
    set: function set(choice) {
      memory = choice;
      // Transition douce le temps de la bascule (pas au chargement de la page)
      var root = document.documentElement;
      root.classList.add('theme-transition');
      clearTimeout(set.timer);
      set.timer = setTimeout(function () { root.classList.remove('theme-transition'); }, 400);
      try { if (choice === 'auto') localStorage.removeItem(KEY); else localStorage.setItem(KEY, choice); } catch (e) { /* voir memory */ }
      apply();
    },
    /** Appelle fn(choix, thème) à chaque changement ; renvoie de quoi se désabonner. */
    onChange: function (fn) {
      listeners.push(fn);
      return function () { listeners = listeners.filter(function (f) { return f !== fn; }); };
    },
  };

  media.addEventListener('change', function () { if (read() === 'auto') apply(); });
  // Choix fait dans un autre onglet
  window.addEventListener('storage', function (event) { if (event.key === KEY || event.key === null) apply(); });
  apply();
})();
