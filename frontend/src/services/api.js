const API_BASE = globalThis.APP_API_BASE || '/api/comercial.php';

async function request(action, options = {}) {
  const params = new URLSearchParams({ action, ...(options.params || {}) });
  const url = `${API_BASE}?${params.toString()}`;
  const isFormData = options.body instanceof FormData;

  const response = await fetch(url, {
    method: options.method || 'GET',
    headers: {
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {}),
    },
    body: options.body ? (isFormData ? options.body : JSON.stringify(options.body)) : undefined,
    credentials: 'include',
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(payload.error || 'Error al consultar la API.');
  }

  return payload;
}

export const api = {
  getClients: () => request('clientes'),
  saveClient: (client) => request('clientes', { method: 'POST', body: client }),
  deleteClient: (id) => request('clientes', { method: 'DELETE', params: { id: String(id) } }),
  getStages: () => request('etapas'),
  getDocuments: (query = '') => request('documentos', { params: { q: query } }),
  uploadDocument: (formData) => request('documentos', { method: 'POST', body: formData }),
  getOpportunities: () => request('oportunidades'),
  createOpportunity: (payload) => request('oportunidades', { method: 'POST', body: payload }),
  updateOpportunity: (payload) => request('oportunidades', { method: 'PATCH', body: payload }),
  updateOpportunityStage: (id, idEtapa) => request('oportunidades', {
    method: 'PATCH',
    body: { id, id_etapa: idEtapa },
  }),
  deleteOpportunity: (id) => request('oportunidades', { method: 'DELETE', params: { id: String(id) } }),
};
