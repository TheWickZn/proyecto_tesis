import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import KanbanBoard from '../components/KanbanBoard';
import OpportunityForm from '../components/OpportunityForm';
import Topbar from '../components/Topbar';
import { api } from '../services/api';

export default function PipelinePage() {
  const [clients, setClients] = useState([]);
  const [stages, setStages] = useState([]);
  const [opportunities, setOpportunities] = useState([]);
  const [editingOpportunity, setEditingOpportunity] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadData = async () => {
    try {
      setLoading(true);
      const [clientsResponse, stagesResponse, opportunitiesResponse] = await Promise.all([
        api.getClients(),
        api.getStages(),
        api.getOpportunities(),
      ]);

      setClients(clientsResponse.data || []);
      setStages((stagesResponse.data || []).sort((a, b) => Number(a.orden) - Number(b.orden)));
      setOpportunities((opportunitiesResponse.data || []).map((opportunity) => ({
        ...opportunity,
        cliente: opportunity.cliente || 'Cliente sin nombre',
        stageId: Number(opportunity.id_etapa || 1),
      })));
      setError('');
    } catch (err) {
      setClients([]);
      setStages([]);
      setOpportunities([]);
      setError(err.message || 'No se pudo conectar con la base de datos.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleSaveOpportunity = async (payload) => {
    try {
      if (editingOpportunity) {
        await api.updateOpportunity({ ...payload, id: editingOpportunity.id_oportunidad });
      } else {
        await api.createOpportunity(payload);
      }
      setEditingOpportunity(null);
      await loadData();
      return true;
    } catch (err) {
      setError(err.message);
      return false;
    }
  };

  const handleStageChange = async (id, nextStageId) => {
    try {
      await api.updateOpportunityStage(id, nextStageId);
      await loadData();
    } catch (err) {
      setError(err.message);
    }
  };

  const handleDelete = async (id) => {
    try {
      await api.deleteOpportunity(id);
      await loadData();
    } catch (err) {
      setError(err.message);
    }
  };

  return (
    <>
      <Topbar title="Pipeline Kanban" subtitle="Visualización de oportunidades por etapas" />

      {error ? <div className="notice error">{error}</div> : null}
      {loading ? <div className="notice">Cargando pipeline...</div> : null}
      {!loading && clients.length === 0 ? (
        <div className="notice">
          Para iniciar una gestión, primero registra un cliente en <Link to="/crm">CRM Comercial</Link>.
        </div>
      ) : null}

      <div className="pipeline-layout">
        <OpportunityForm
          key={editingOpportunity?.id_oportunidad ?? 'new'}
          clients={clients}
          stages={stages}
          editingOpportunity={editingOpportunity}
          onSubmit={handleSaveOpportunity}
          onCancel={() => setEditingOpportunity(null)}
        />
        {!loading && stages.length ? (
          <KanbanBoard
            stages={stages}
            opportunities={opportunities}
            onStageChange={handleStageChange}
            onEdit={setEditingOpportunity}
            onDelete={handleDelete}
          />
        ) : null}
      </div>
    </>
  );
}
