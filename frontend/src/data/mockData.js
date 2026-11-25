export const mockConversations = [
  {
    id: 1,
    name: 'Ana García',
    lastSeen: 'Hace 2 min',
    unread: 2,
    status: 'Activa',
    messages: [
      { id: 1, sender: 'them', text: 'Hola, revisamos la propuesta del curso.', time: '09:15', status: 'Leído' },
      { id: 2, sender: 'me', text: 'Perfecto, hoy la enviamos con el detalle.', time: '09:18', status: 'Enviado' },
      { id: 3, sender: 'them', text: 'Gracias, lo revisamos mañana.', time: '09:20', status: 'Leído' },
    ],
  },
  {
    id: 2,
    name: 'Luis Vargas',
    lastSeen: 'Hace 15 min',
    unread: 0,
    status: 'Activa',
    messages: [
      { id: 1, sender: 'them', text: 'Necesitamos confirmar la fecha del diagnóstico.', time: '08:50', status: 'Leído' },
      { id: 2, sender: 'me', text: 'La disponibilidad es el jueves a las 10:00.', time: '08:55', status: 'Enviado' },
    ],
  },
  {
    id: 3,
    name: 'Soporte CEA',
    lastSeen: 'Ayer',
    unread: 1,
    status: 'Pendiente',
    messages: [
      { id: 1, sender: 'them', text: 'Queda pendiente el cierre del expediente.', time: 'Ayer', status: 'Leído' },
    ],
  },
];
