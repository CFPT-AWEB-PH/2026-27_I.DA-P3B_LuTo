// Client de l'API : JSON, jeton CSRF sur les requêtes qui modifient des données, erreurs lisibles.

let csrfToken = null;
let unauthorizedHandler = null;

export function setCsrfToken(token) {
  csrfToken = token;
}

export function onUnauthorized(handler) {
  unauthorizedHandler = handler;
}

export class ApiError extends Error {
  constructor(status, message, details = {}) {
    super(message);
    this.status = status;
    this.details = details;
  }

  /** Erreurs de validation par champ : { nom: 'message' } */
  get fields() {
    return this.details?.fields ?? {};
  }
}

export async function api(path, { method = 'GET', body } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && csrfToken) headers['X-CSRF-Token'] = csrfToken;

  let response;
  try {
    response = await fetch(`/api${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
    });
  } catch {
    throw new ApiError(0, 'Serveur injoignable. Vérifiez votre connexion réseau.');
  }

  if (response.status === 204) return null;
  let data = null;
  try {
    data = await response.json();
  } catch {
    // réponse non JSON (proxy, panne) : message générique ci-dessous
  }
  if (!response.ok) {
    const error = new ApiError(response.status, data?.error?.message ?? `Erreur ${response.status}`, data?.error ?? {});
    if (response.status === 401 && unauthorizedHandler) unauthorizedHandler();
    throw error;
  }
  return data;
}
