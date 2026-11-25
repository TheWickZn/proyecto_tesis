function AssistantPanel({
  messages,
  isProcessing,
  pendingAction,
  clients,
  puedeGestionarDocumentos,
  onSendMessage,
  onUploadDocument,
  onConfirmAction,
  onCancelAction,
}) {
  const handleSubmit = (event) => {
    event.preventDefault();
    const input = event.currentTarget.elements.message;
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    onSendMessage(text);
  };

  const handleUpload = async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    if (await onUploadDocument(new FormData(form))) form.reset();
  };

  return (
    <section className="assistant-window" aria-label="Asistente CRM">
      <header className="chat-header">
        <div>
          <strong>Asistente del CRM</strong>
          <small>Consulta clientes, oportunidades y documentos con comandos</small>
        </div>
        <span className="online-tag">Disponible</span>
      </header>

      {puedeGestionarDocumentos ? (
        <form className="assistant-upload-form" onSubmit={handleUpload}>
          <strong>Subir documento de un cliente</strong>
          <div className="assistant-upload-fields">
            <label>
              Título del documento
              <input name="titulo" type="text" maxLength="180" required disabled={isProcessing || Boolean(pendingAction)} />
            </label>
            <label>
              Cliente
              <select name="id_cliente" defaultValue="" required disabled={isProcessing || Boolean(pendingAction)}>
                <option value="" disabled>Seleccionar cliente</option>
                {clients.filter((client) => client.estado === 'Activo').map((client) => (
                  <option key={client.id_cliente} value={client.id_cliente}>
                    {`${client.nombres} ${client.apellidos || ''}`.trim()}
                    {client.empresa ? ` · ${client.empresa}` : ''}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Categoría
              <select name="categoria" defaultValue="Otro" disabled={isProcessing || Boolean(pendingAction)}>
                {['Contrato', 'Propuesta', 'Informe', 'Reporte', 'Certificado', 'Otro'].map((category) => (
                  <option key={category}>{category}</option>
                ))}
              </select>
            </label>
            <label>
              Archivo (PDF, JPG o PNG; máximo 8 MB)
              <input
                type="file"
                name="archivo"
                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                required
                disabled={isProcessing || Boolean(pendingAction)}
              />
            </label>
          </div>
          <button type="submit" disabled={isProcessing || Boolean(pendingAction) || !clients.some((client) => client.estado === 'Activo')}>
            Subir documento
          </button>
        </form>
      ) : null}

      <div className="chat-messages" aria-live="polite">
        {messages.map((message) => (
          <div key={message.id} className={message.sender === 'user' ? 'message me' : 'message them'}>
            <p>{message.text}</p>
            {message.documents?.length > 0 ? (
              <ul className="assistant-document-results">
                {message.documents.map((document) => (
                  <li key={document.id_documento}>
                    <a href={`../empresa/documento_descargar.php?id=${document.id_documento}`}>{document.titulo}</a>
                    <span>{document.cliente} · {document.categoria} · {document.nombre_original}</span>
                  </li>
                ))}
              </ul>
            ) : null}
            <small>{message.time}</small>
          </div>
        ))}
        {isProcessing ? <div className="message them"><p>Procesando tu solicitud...</p></div> : null}
      </div>

      {pendingAction ? (
        <div className="assistant-confirmation">
          <div>
            <strong>Confirmar cambio</strong>
            <p>{pendingAction.opportunityTitle} ({pendingAction.clientName}): {pendingAction.currentStage} → {pendingAction.nextStage}</p>
          </div>
          <div className="assistant-actions">
            <button type="button" className="assistant-confirm" onClick={onConfirmAction} disabled={isProcessing}>Confirmar</button>
            <button type="button" className="assistant-cancel" onClick={onCancelAction} disabled={isProcessing}>Cancelar</button>
          </div>
        </div>
      ) : null}

      <form className="chat-form" onSubmit={handleSubmit}>
        <input
          type="text"
          name="message"
          placeholder='Ejemplo: "listar clientes" o "mover oportunidad X a Negociación"'
          aria-label="Escribe un comando para el asistente"
          disabled={isProcessing || Boolean(pendingAction)}
        />
        <button type="submit" disabled={isProcessing || Boolean(pendingAction)}>Enviar</button>
      </form>
    </section>
  );
}

export default AssistantPanel;
