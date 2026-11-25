export default function KanbanBoard({ stages, opportunities, onStageChange, onEdit, onDelete }) {
  const byStage = Object.fromEntries(stages.map((stage) => [stage.id_etapa, []]));

  for (const opportunity of opportunities) {
    const stageId = Number(opportunity.id_etapa ?? opportunity.stageId ?? 1);
    if (!byStage[stageId]) {
      byStage[stageId] = [];
    }
    byStage[stageId].push(opportunity);
  }

  return (
    <div className="kanban-board">
      {stages.map((stage) => (
        <div key={stage.id_etapa} className="kanban-column">
          <div className="kanban-heading">
            <h3>{stage.nombre}</h3>
            <span>{byStage[stage.id_etapa]?.length || 0}</span>
          </div>

          {byStage[stage.id_etapa]?.length ? (
            byStage[stage.id_etapa].map((opportunity) => (
              <article key={opportunity.id_oportunidad} className="opportunity-card">
                <strong>{opportunity.titulo}</strong>
                <span>{opportunity.cliente || 'Cliente sin nombre'}</span>
                <b>S/ {Number(opportunity.monto_estimado || 0).toFixed(2)}</b>

                <details className="questionnaire-details">
                  <summary>Ver cuestionario</summary>
                  <dl>
                    <dt>Tipo de cliente</dt>
                    <dd>{opportunity.tipo_cliente || 'No informado'}</dd>
                    <dt>Curso o programa</dt>
                    <dd>{opportunity.curso_interes || 'No informado'}</dd>
                    <dt>Servicios</dt>
                    <dd>{opportunity.servicio_interes || 'No informado'}</dd>
                    <dt>Área o departamento</dt>
                    <dd>{opportunity.area_cliente || 'No informado'}</dd>
                    <dt>Modalidad</dt>
                    <dd>{opportunity.modalidad || 'Por definir'}</dd>
                    <dt>Participantes</dt>
                    <dd>{opportunity.cantidad_participantes || 'No informado'}</dd>
                    <dt>Horario o fecha preferida</dt>
                    <dd>{opportunity.horario_preferido || 'No informado'}</dd>
                    <dt>Necesidad u objetivo</dt>
                    <dd>{opportunity.objetivo_cliente || 'No informado'}</dd>
                  </dl>
                </details>

                {opportunity.fecha_proxima_accion ? (
                  <small>Próxima acción: {opportunity.fecha_proxima_accion}</small>
                ) : null}

                <div className="kanban-actions">
                  <button type="button" className="text-link" onClick={() => onEdit(opportunity)}>
                    Editar
                  </button>
                  <select
                    value={Number(opportunity.id_etapa ?? opportunity.stageId ?? 1)}
                    onChange={(event) => onStageChange(opportunity.id_oportunidad, Number(event.target.value))}
                  >
                    {stages.map((option) => (
                      <option key={option.id_etapa} value={option.id_etapa}>
                        {option.nombre}
                      </option>
                    ))}
                  </select>

                  <button
                    type="button"
                    className="text-link danger"
                    onClick={() => {
                      if (window.confirm('¿Eliminar esta oportunidad?')) {
                        onDelete(opportunity.id_oportunidad);
                      }
                    }}
                  >
                    Eliminar
                  </button>
                </div>
              </article>
            ))
          ) : (
            <p className="kanban-empty">Sin oportunidades</p>
          )}
        </div>
      ))}
    </div>
  );
}
