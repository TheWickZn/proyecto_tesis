import { NavLink } from 'react-router-dom';

const navItems = [
  { to: '/crm', label: 'CRM Comercial' },
  { to: '/pipeline', label: 'Pipeline Kanban' },
  { to: '/assistant', label: 'Asistente CRM' },
  { to: '/whatsapp', label: 'Bandeja WhatsApp' },
];

export default function WorkspaceTabs() {
  const puedeGestionarCRM = globalThis.APP_FEATURES?.crm ?? true;
  const puedeVerWhatsApp = globalThis.APP_FEATURES?.whatsapp ?? true;
  const items = navItems.filter((item) => {
    if (item.to === '/whatsapp') return puedeVerWhatsApp;
    return puedeGestionarCRM;
  });

  return (
    <nav className="workspace-tabs" aria-label="Módulos comerciales">
      {items.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          className={({ isActive }) => `workspace-tab${isActive ? ' active' : ''}`}
        >
          {item.label}
        </NavLink>
      ))}
    </nav>
  );
}
