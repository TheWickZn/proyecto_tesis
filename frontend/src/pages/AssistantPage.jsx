import { useEffect, useState } from 'react';
import AssistantPanel from '../components/AssistantPanel';
import Topbar from '../components/Topbar';
import { api } from '../services/api';
import { normalizeAssistantText, parseAssistantCommand } from '../services/assistantCommands';

const welcomeMessage = 'Hola. Puedo consultar clientes, oportunidades y documentos, mover oportunidades de etapa y ayudarte a guardar archivos con título y cliente. Escribe “ayuda” para ver ejemplos.';
const currentTime = () => new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

export default function AssistantPage({ puedeGestionarDocumentos }) {
  const [clients, setClients] = useState([]);
  const [messages, setMessages] = useState([{
    id: 'assistant-welcome',
    sender: 'assistant',
    text: welcomeMessage,
    time: 'Ahora',
  }]);
  const [pendingAction, setPendingAction] = useState(null);
  const [processing, setProcessing] = useState(false);

  useEffect(() => {
    api.getClients()
      .then((response) => setClients(response.data || []))
      .catch((error) => {
        setMessages((current) => [...current, {
          id: `assistant-error-${Date.now()}`,
          sender: 'assistant',
          text: `No pude cargar los clientes: ${error.message}`,
          time: currentTime(),
        }]);
      });
  }, []);

  const addMessage = (sender, text, documents = []) => {
    setMessages((current) => [...current, {
      id: `${Date.now()}-${Math.random()}`,
      sender,
      text,
      documents,
      time: currentTime(),
    }]);
  };

  const handleMessage = async (text) => {
    const command = parseAssistantCommand(text);
    addMessage('user', text);
    if (command.type === 'help') {
      const documentExamples = puedeGestionarDocumentos
        ? '\n• buscar documento contrato Ana García\n• listar documentos de Ana García\nTambién puedes subir documentos en el formulario.'
        : '';
      addMessage('assistant', `Prueba con estos comandos:\n• listar clientes\n• buscar cliente Ana García\n• listar oportunidades\n• buscar oportunidad Implementación CRM\n• mover oportunidad Implementación CRM a Negociación${documentExamples}`);
      return;
    }

    setProcessing(true);
    try {
      if (command.type === 'clients') {
        const response = await api.getClients();
        const results = (response.data || []).filter((client) => {
          const name = `${client.nombres || ''} ${client.apellidos || ''} ${client.empresa || ''}`;
          return !command.query || normalizeAssistantText(name).includes(command.query);
        });
        if (!results.length) {
          addMessage('assistant', 'No encontré clientes que coincidan con esa búsqueda.');
          return;
        }
        const lines = results.slice(0, 10).map((client) =>
          `• ${`${client.nombres || ''} ${client.apellidos || ''}`.trim()}${client.empresa ? ` — ${client.empresa}` : ''}${client.estado ? ` (${client.estado})` : ''}`);
        addMessage('assistant', `Clientes encontrados (${results.length}):\n${lines.join('\n')}${results.length > lines.length ? `\nY ${results.length - lines.length} más.` : ''}`);
        return;
      }

      if (command.type === 'opportunities') {
        const response = await api.getOpportunities();
        const results = (response.data || []).filter((opportunity) => {
          const searchable = `${opportunity.titulo || ''} ${opportunity.cliente || ''}`;
          return !command.query || normalizeAssistantText(searchable).includes(command.query);
        });
        if (!results.length) {
          addMessage('assistant', 'No encontré oportunidades que coincidan con esa búsqueda.');
          return;
        }
        const lines = results.slice(0, 10).map((opportunity) =>
          `• ${opportunity.titulo} — ${opportunity.cliente || 'Sin cliente'} — ${opportunity.etapa || 'Sin etapa'} — S/ ${Number(opportunity.monto_estimado || 0).toLocaleString('es-PE')}`);
        addMessage('assistant', `Oportunidades encontradas (${results.length}):\n${lines.join('\n')}${results.length > lines.length ? `\nY ${results.length - lines.length} más.` : ''}`);
        return;
      }

      if (command.type === 'documents') {
        if (!puedeGestionarDocumentos) {
          addMessage('assistant', 'Tu usuario no tiene permiso para gestionar documentos.');
          return;
        }
        const response = await api.getDocuments(command.query);
        const documents = response.data || [];
        if (!documents.length) {
          addMessage('assistant', 'No encontré documentos con ese título o cliente.');
          return;
        }
        addMessage('assistant', `Documentos encontrados (${documents.length}). Selecciona un título para descargarlo:`, documents);
        return;
      }

      if (command.type === 'moveOpportunity') {
        const [opportunitiesResponse, stagesResponse] = await Promise.all([api.getOpportunities(), api.getStages()]);
        const opportunities = (opportunitiesResponse.data || []).filter((item) =>
          normalizeAssistantText(item.titulo || '').includes(command.opportunityQuery));
        const stages = stagesResponse.data || [];
        if (!opportunities.length) {
          addMessage('assistant', `No encontré la oportunidad “${command.opportunityQuery}”. Revisa el nombre e inténtalo de nuevo.`);
          return;
        }
        if (opportunities.length > 1) {
          const matches = opportunities.slice(0, 5).map((item) => `• ${item.titulo} — ${item.cliente || 'Sin cliente'}`).join('\n');
          addMessage('assistant', `Hay varias oportunidades con ese nombre. Especifica mejor cuál quieres mover:\n${matches}`);
          return;
        }
        const stage = stages.find((item) => normalizeAssistantText(item.nombre || '') === command.stageQuery);
        if (!stage) {
          addMessage('assistant', `No reconozco esa etapa. Las etapas disponibles son: ${stages.map((item) => item.nombre).filter(Boolean).join(', ')}.`);
          return;
        }
        const opportunity = opportunities[0];
        if (Number(opportunity.id_etapa) === Number(stage.id_etapa)) {
          addMessage('assistant', `“${opportunity.titulo}” ya está en la etapa “${stage.nombre}”.`);
          return;
        }
        setPendingAction({
          opportunityId: Number(opportunity.id_oportunidad),
          opportunityTitle: opportunity.titulo,
          clientName: opportunity.cliente || 'Sin cliente',
          currentStage: opportunity.etapa || 'Sin etapa',
          nextStage: stage.nombre,
          nextStageId: Number(stage.id_etapa),
        });
        addMessage('assistant', `Encontré “${opportunity.titulo}” de ${opportunity.cliente || 'Sin cliente'}. Confirma si quieres moverla de “${opportunity.etapa || 'Sin etapa'}” a “${stage.nombre}”.`);
      }
    } catch (error) {
      addMessage('assistant', `No pude completar la solicitud: ${error.message || 'error inesperado'}`);
    } finally {
      setProcessing(false);
    }
  };

  const handleUpload = async (formData) => {
    setProcessing(true);
    try {
      const response = await api.uploadDocument(formData);
      addMessage('assistant', `Documento “${response.data?.titulo || formData.get('titulo')}” guardado correctamente. Puedes buscarlo por el título o por el nombre del cliente.`);
      return true;
    } catch (error) {
      addMessage('assistant', `No pude subir el documento: ${error.message || 'error inesperado'}`);
      return false;
    } finally {
      setProcessing(false);
    }
  };

  const handleConfirm = async () => {
    if (!pendingAction) return;
    setProcessing(true);
    try {
      await api.updateOpportunityStage(pendingAction.opportunityId, pendingAction.nextStageId);
      addMessage('assistant', `Listo. “${pendingAction.opportunityTitle}” ahora está en “${pendingAction.nextStage}”.`);
      setPendingAction(null);
    } catch (error) {
      addMessage('assistant', `No pude mover la oportunidad: ${error.message || 'error inesperado'}`);
    } finally {
      setProcessing(false);
    }
  };

  const handleCancel = () => {
    setPendingAction(null);
    addMessage('assistant', 'De acuerdo, no hice ningún cambio.');
  };

  return (
    <>
      <Topbar title="Asistente CRM" subtitle="Consultas y acciones comerciales en el sistema local" />
      <AssistantPanel
        messages={messages}
        isProcessing={processing}
        pendingAction={pendingAction}
        clients={clients}
        puedeGestionarDocumentos={puedeGestionarDocumentos}
        onSendMessage={handleMessage}
        onUploadDocument={handleUpload}
        onConfirmAction={handleConfirm}
        onCancelAction={handleCancel}
      />
    </>
  );
}
