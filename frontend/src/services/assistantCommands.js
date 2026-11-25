export function normalizeAssistantText(value) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase()
    .trim();
}

function cleanCommandValue(value) {
  return value.trim().replace(/^["'“”‘’]+|["'“”‘’]+$/g, '').trim();
}

export function parseAssistantCommand(text) {
  const normalizedText = normalizeAssistantText(text);
  if (/\bdocumentos?\b/.test(normalizedText)) {
    const query = normalizedText
      .replace(/\b(buscar|busca|consultar|consulta|listar|lista|mostrar|muestra|ver|dame|ensename)\b/g, ' ')
      .replace(/\b(documentos?)\b/g, ' ')
      .replace(/[“”"'‘’]/g, ' ')
      .replace(/\s+/g, ' ')
      .replace(/^\s*(?:(?:los|las|el|la|del|de|por|favor)\s+)+/, '')
      .trim();
    return { type: 'documents', query };
  }

  const movePatterns = [
    /^(?:mover|cambiar)\s+(?:la\s+)?oportunidad\s+(.+)\s+a\s+(?:(?:la\s+)?etapa\s+)?(.+)$/,
    /^cambiar\s+(?:la\s+)?etapa\s+de\s+(?:la\s+)?oportunidad\s+(.+)\s+a\s+(.+)$/,
  ];
  for (const pattern of movePatterns) {
    const match = normalizedText.match(pattern);
    if (match) {
      return {
        type: 'moveOpportunity',
        opportunityQuery: cleanCommandValue(match[1]),
        stageQuery: cleanCommandValue(match[2]),
      };
    }
  }

  const isClientQuery = /\bclientes?\b/.test(normalizedText);
  const isOpportunityQuery = /\boportunidades?\b/.test(normalizedText);
  if (isClientQuery || isOpportunityQuery) {
    const query = normalizedText
      .replace(/\b(buscar|busca|consultar|consulta|listar|lista|mostrar|muestra|ver|dame|ensename)\b/g, ' ')
      .replace(/\b(clientes?|oportunidades?)\b/g, ' ')
      .replace(/[“”"'‘’]/g, ' ')
      .replace(/\s+/g, ' ')
      .replace(/^\s*(?:(?:los|las|el|la|del|de|por|favor)\s+)+/, '')
      .trim();
    return { type: isClientQuery ? 'clients' : 'opportunities', query };
  }

  return { type: 'help' };
}
