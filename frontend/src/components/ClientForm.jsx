import { useEffect, useState } from 'react';

const emptyClient = {
  id_cliente: 0,
  nombres: '',
  apellidos: '',
  tipo_documento: 'DNI',
  documento: '',
  telefono: '',
  correo: '',
  empresa: '',
  estado: 'Activo',
  observaciones: '',
};

export default function ClientForm({ editingClient, onSubmit, onCancel }) {
  const [form, setForm] = useState(emptyClient);

  useEffect(() => {
    if (editingClient) {
      const tipoDocumento = editingClient.tipo_documento
        || (String(editingClient.documento || '').length === 11 ? 'RUC' : 'DNI');
      setForm({
        ...emptyClient,
        ...editingClient,
        tipo_documento: tipoDocumento,
      });
      return;
    }

    setForm(emptyClient);
  }, [editingClient]);

  const handleChange = (event) => {
    const { name, value } = event.target;
    setForm((current) => {
      if (name === 'tipo_documento') {
        const maxLength = value === 'RUC' ? 11 : 8;
        return {
          ...current,
          tipo_documento: value,
          documento: current.documento.slice(0, maxLength),
        };
      }
      if (name === 'documento' || name === 'telefono') {
        const maxLength = name === 'telefono' ? 9 : current.tipo_documento === 'RUC' ? 11 : 8;
        return { ...current, [name]: value.replace(/\D/g, '').slice(0, maxLength) };
      }
      return { ...current, [name]: value };
    });
  };

  const handleSubmit = (event) => {
    event.preventDefault();
    onSubmit({
      ...form,
      nombres: form.nombres.trim(),
      apellidos: form.apellidos.trim(),
    });
  };

  return (
    <section className="panel">
      <div className="panel-header">
        <h3>{editingClient ? 'Editar cliente' : 'Nuevo cliente'}</h3>
        {editingClient ? (
          <button type="button" className="text-link" onClick={onCancel}>Cancelar</button>
        ) : null}
      </div>

      <form className="data-form" onSubmit={handleSubmit}>
        <input type="hidden" name="id_cliente" value={form.id_cliente || 0} />

        <div className="form-grid">
          <div className="campo">
            <label htmlFor="nombres">Nombres *</label>
            <input
              id="nombres"
              name="nombres"
              value={form.nombres}
              onChange={handleChange}
              pattern="[\p{L}]+(?: [\p{L}]+)*"
              maxLength="100"
              title="Usa solo letras y espacios."
              required
            />
          </div>

          <div className="campo">
            <label htmlFor="apellidos">Apellidos</label>
            <input
              id="apellidos"
              name="apellidos"
              value={form.apellidos}
              onChange={handleChange}
              pattern="[\p{L}]+(?: [\p{L}]+)*"
              maxLength="100"
              title="Usa solo letras y espacios."
            />
          </div>

          <div className="campo">
            <label htmlFor="tipo_documento">Tipo de documento *</label>
            <select id="tipo_documento" name="tipo_documento" value={form.tipo_documento} onChange={handleChange} required>
              <option value="DNI">DNI</option>
              <option value="RUC">RUC</option>
            </select>
          </div>

          <div className="campo">
            <label htmlFor="documento">Número de documento *</label>
            <input
              id="documento"
              name="documento"
              type="text"
              inputMode="numeric"
              pattern={form.tipo_documento === 'RUC' ? '[0-9]{11}' : '[0-9]{8}'}
              maxLength={form.tipo_documento === 'RUC' ? 11 : 8}
              title={form.tipo_documento === 'RUC' ? 'El RUC debe tener 11 dígitos.' : 'El DNI debe tener 8 dígitos.'}
              value={form.documento}
              onChange={handleChange}
              required
            />
          </div>

          <div className="campo">
            <label htmlFor="telefono">Teléfono</label>
            <input
              id="telefono"
              name="telefono"
              type="tel"
              inputMode="numeric"
              pattern="9[0-9]{8}"
              maxLength="9"
              title="El teléfono debe tener 9 dígitos y comenzar con 9."
              value={form.telefono}
              onChange={handleChange}
            />
          </div>

          <div className="campo">
            <label htmlFor="correo">Correo</label>
            <input id="correo" name="correo" type="email" value={form.correo} onChange={handleChange} />
          </div>

          <div className="campo">
            <label htmlFor="empresa">Empresa</label>
            <input id="empresa" name="empresa" value={form.empresa} onChange={handleChange} />
          </div>

          <div className="campo">
            <label htmlFor="estado">Estado</label>
            <select id="estado" name="estado" value={form.estado} onChange={handleChange}>
              <option value="Activo">Activo</option>
              <option value="Inactivo">Inactivo</option>
            </select>
          </div>
        </div>

        <div className="campo">
          <label htmlFor="observaciones">Observaciones</label>
          <textarea id="observaciones" name="observaciones" rows="3" value={form.observaciones} onChange={handleChange} />
        </div>

        <button type="submit" className="btn-primary form-submit">
          {editingClient ? 'Guardar cambios' : 'Crear cliente'}
        </button>
      </form>
    </section>
  );
}
