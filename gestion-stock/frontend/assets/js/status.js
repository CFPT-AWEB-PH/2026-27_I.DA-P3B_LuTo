// Page provisoire de la phase 2 : affiche l'état renvoyé par /api/health.

function show(id, text, ok) {
  const el = document.getElementById(id);
  el.textContent = text;
  el.className = ok ? 'is-ok' : 'is-error';
}

try {
  const res = await fetch('/api/health', { headers: { Accept: 'application/json' } });
  const data = await res.json();
  show('status-server', 'en ligne', true);
  show('status-db', data.database === 'ok' ? 'connectée' : 'indisponible', data.database === 'ok');
  document.getElementById('status-version').textContent = data.version ?? '–';
} catch {
  show('status-server', 'injoignable', false);
  show('status-db', 'inconnue', false);
}
