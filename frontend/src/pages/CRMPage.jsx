import { useEffect, useMemo, useState } from 'react';
import ClientForm from '../components/ClientForm';
import ClientTable from '../components/ClientTable';
import Topbar from '../components/Topbar';
import { api } from '../services/api';

export default function CRMPage() {
  const [clients, setClients] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [editingClient, setEditingClient] = useState(null);

  const loadClients = async () => {
    try {
      setLoading(true);
      const response = await api.getClients();
      setClients(response.data || []);
      setError('');
    } catch (err) {
      setClients([]);
      setError(err.message || 'No se pudo conectar con la base de datos.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadClients();
  }, []);

  const filteredClients = useMemo(() => {
    const term = search.trim().toLowerCase();

    if (!term) return clients;

    return clients.filter((client) => {
      const text = [
        client.nombres,
        client.apellidos,
        client.empresa,
        client.documento,
        client.correo,
        client.telefono,
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

      return text.includes(term);
    });
  }, [clients, search]);

  const handleSubmit = async (client) => {
    try {
      await api.saveClient(client);
      setEditingClient(null);
      await loadClients();
    } catch (err) {
      setError(err.message);
    }
  };

  const handleDelete = async (id) => {
    try {
      await api.deleteClient(id);
      setEditingClient(null);
      await loadClients();
    } catch (err) {
      setError(err.message);
    }
  };

  const handleEdit = (client) => {
    setEditingClient(client);
  };

  return (
    <>
      <Topbar title="CRM Comercial" subtitle="Gestión de clientes y seguimiento comercial" />

      <section className="metrics-grid">
        <div className="metric-card">
          <span>Clientes activos</span>
          <strong>{clients.filter((client) => client.estado === 'Activo').length}</strong>
        </div>
        <div className="metric-card">
          <span>Total clientes</span>
          <strong>{clients.length}</strong>
        </div>
        <div className="metric-card">
          <span>Estado</span>
          <strong>Operativo</strong>
        </div>
      </section>

      {error ? <div className="notice error">{error}</div> : null}

      {loading ? <div className="notice">Cargando clientes...</div> : null}

      <div className="crm-layout">
        <ClientForm editingClient={editingClient} onSubmit={handleSubmit} onCancel={() => setEditingClient(null)} />
        <ClientTable
          clients={filteredClients}
          searchValue={search}
          onSearch={setSearch}
          onEdit={handleEdit}
          onDelete={handleDelete}
        />
      </div>
    </>
  );
}
