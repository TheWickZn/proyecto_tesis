import { useState } from 'react';

const emptyOpportunity = {
  titulo: '',
  id_cliente: '',
  id_etapa: 1,
  monto_estimado: '0.00',
  fecha_proxima_accion: '',
  observaciones: '',
  tipo_cliente: '',
  curso_interes: '',
  servicio_interes: '',
  area_cliente: '',
  modalidad: '',
  cantidad_participantes: '',
  horario_preferido: '',
  objetivo_cliente: '',
};

const opportunityToForm = (opportunity) => {
  if (!opportunity) return emptyOpportunity;

  return {
    ...emptyOpportunity,
    ...opportunity,
    id_cliente: String(opportunity.id_cliente ?? ''),
    id_etapa: Number(opportunity.id_etapa || 1),
    monto_estimado: String(opportunity.monto_estimado ?? '0.00'),
    cantidad_participantes: opportunity.cantidad_participantes == null
      ? ''
      : String(opportunity.cantidad_participantes),
  };
};

export default function OpportunityForm({ clients, stages, editingOpportunity, onSubmit, onCancel }) {
  const [form, setForm] = useState(() => opportunityToForm(editingOpportunity));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const handleChange = (event) => {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');

    try {
      const saved = await onSubmit({
        ...form,
        id: editingOpportunity?.id_oportunidad,
        id_cliente: Number(form.id_cliente),
        id_etapa: Number(form.id_etapa),
        monto_estimado: Number(form.monto_estimado || 0),
        cantidad_participantes: form.cantidad_participantes === '' ? null : Number(form.cantidad_participantes),
      });
      if (saved) {
        setForm(emptyOpportunity);
      }
    } catch (err) {
      setError(err.message || 'No se pudo guardar la gestión comercial.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="panel pipeline-create">
      <div className="panel-header">
        <h3>{editingOpportunity ? 'Editar gestión comercial' : 'Nueva gestión comercial'}</h3>
        {editingOpportunity ? (
          <button type="button" className="text-link" onClick={onCancel}>Cancelar</button>
        ) : null}
      </div>

      <form className="data-form" onSubmit={handleSubmit}>
        <div className="form-grid">
          <div className="campo">
            <label htmlFor="id_cliente">Cliente *</label>
            <select id="id_cliente" name="id_cliente" value={form.id_cliente} onChange={handleChange} required>
              <option value="">Seleccionar cliente</option>
              {clients.map((client) => (
                <option key={client.id_cliente} value={client.id_cliente}>
                  {client.nombres} {client.apellidos}
                </option>
              ))}
            </select>
          </div>

          <div className="campo">
            <label htmlFor="titulo">Nombre de la gestión *</label>
            <input id="titulo" name="titulo" value={form.titulo} onChange={handleChange} maxLength="150" required />
          </div>

          <div className="campo">
            <label htmlFor="id_etapa">Etapa inicial</label>
            <select id="id_etapa" name="id_etapa" value={form.id_etapa} onChange={handleChange}>
              {stages.map((stage) => (
                <option key={stage.id_etapa} value={stage.id_etapa}>
                  {stage.nombre}
                </option>
              ))}
            </select>
          </div>

          <div className="campo">
            <label htmlFor="monto_estimado">Monto estimado</label>
            <input id="monto_estimado" name="monto_estimado" type="number" min="0" step="0.01" value={form.monto_estimado} onChange={handleChange} />
          </div>

          <div className="campo">
            <label htmlFor="fecha_proxima_accion">Próxima acción</label>
            <input id="fecha_proxima_accion" name="fecha_proxima_accion" type="date" value={form.fecha_proxima_accion} onChange={handleChange} />
          </div>
        </div>

        <fieldset className="questionnaire-fields">
          <legend>Cuestionario de esta gestión</legend>
          <p className="questionnaire-intro">Las respuestas quedan asociadas a esta oportunidad y se conservan por separado para cada gestión.</p>

          <div className="form-grid">
            <div className="campo">
              <label htmlFor="tipo_cliente">Tipo de cliente *</label>
              <select id="tipo_cliente" name="tipo_cliente" value={form.tipo_cliente} onChange={handleChange} required>
                <option value="">Seleccionar tipo</option>
                <option value="Persona natural">Persona natural</option>
                <option value="Empresa">Empresa</option>
              </select>
            </div>

            <div className="campo">
              <label htmlFor="curso_interes">Curso o programa de interés *</label>
              <input id="curso_interes" name="curso_interes" value={form.curso_interes} onChange={handleChange} maxLength="180" required />
            </div>

            <div className="campo">
              <label htmlFor="servicio_interes">Servicio(s) solicitado(s) *</label>
              <input id="servicio_interes" name="servicio_interes" value={form.servicio_interes} onChange={handleChange} maxLength="500" placeholder="Separa varios servicios con comas" required />
            </div>

            <div className="campo">
              <label htmlFor="area_cliente">Área o departamento *</label>
              <input id="area_cliente" name="area_cliente" value={form.area_cliente} onChange={handleChange} maxLength="150" placeholder="Ej. Recursos Humanos, ventas, administración" required />
            </div>

            <div className="campo">
              <label htmlFor="modalidad">Modalidad</label>
              <select id="modalidad" name="modalidad" value={form.modalidad} onChange={handleChange}>
                <option value="">Por definir</option>
                <option value="Presencial">Presencial</option>
                <option value="Virtual">Virtual</option>
                <option value="Híbrida">Híbrida</option>
              </select>
            </div>

            <div className="campo">
              <label htmlFor="cantidad_participantes">Cantidad de participantes</label>
              <input id="cantidad_participantes" name="cantidad_participantes" type="number" min="1" step="1" value={form.cantidad_participantes} onChange={handleChange} />
            </div>

            <div className="campo">
              <label htmlFor="horario_preferido">Horario o fecha preferida</label>
              <input id="horario_preferido" name="horario_preferido" value={form.horario_preferido} onChange={handleChange} maxLength="180" />
            </div>
          </div>

          <div className="campo">
            <label htmlFor="objetivo_cliente">Necesidad u objetivo del cliente</label>
            <textarea id="objetivo_cliente" name="objetivo_cliente" rows="3" value={form.objetivo_cliente} onChange={handleChange} />
          </div>
        </fieldset>

        <div className="campo">
          <label htmlFor="observaciones">Observaciones de seguimiento</label>
          <textarea id="observaciones" name="observaciones" rows="3" value={form.observaciones} onChange={handleChange} />
        </div>

        {error ? <div className="notice error" role="alert">{error}</div> : null}
        <button type="submit" className="btn-primary form-submit" disabled={saving}>
          {saving ? 'Guardando gestión...' : editingOpportunity ? 'Guardar cambios' : 'Guardar gestión y cuestionario'}
        </button>
      </form>
    </section>
  );
}
