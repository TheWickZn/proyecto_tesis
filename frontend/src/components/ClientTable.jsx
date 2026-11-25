export default function ClientTable({ clients, onEdit, onDelete, onSearch, searchValue }) {
  return (
    <section className="panel">
      <div className="panel-header">
        <h3>Clientes registrados</h3>
        <div className="search-box">
          <input
            type="text"
            value={searchValue}
            onChange={(event) => onSearch(event.target.value)}
            placeholder="Buscar cliente..."
            aria-label="Buscar cliente"
          />
        </div>
      </div>

      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Cliente</th>
              <th>Contacto</th>
              <th>Empresa</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {clients.length === 0 ? (
              <tr>
                <td colSpan="5" className="empty-state">No hay clientes registrados.</td>
              </tr>
            ) : (
              clients.map((client) => (
                <tr key={client.id_cliente ?? client.id}>
                  <td>
                    <strong>
                      {client.nombres} {client.apellidos}
                    </strong>
                    <small>
                      {client.documento
                        ? `${client.tipo_documento || (String(client.documento).length === 11 ? 'RUC' : 'DNI')} ${client.documento}`
                        : 'Sin documento'}
                    </small>
                  </td>
                  <td>
                    {client.telefono || 'Sin teléfono'}
                    <small>{client.correo || 'Sin correo'}</small>
                  </td>
                  <td>{client.empresa || 'Sin empresa'}</td>
                  <td>
                    <span className={`status-badge ${client.estado === 'Activo' ? 'active' : 'inactive'}`}>
                      {client.estado || 'Activo'}
                    </span>
                  </td>
                  <td className="actions">
                    <button type="button" className="text-link" onClick={() => onEdit(client)}>
                      Editar
                    </button>
                    <button type="button" className="text-link danger" onClick={() => onDelete(client.id_cliente)}>
                      Eliminar
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}
