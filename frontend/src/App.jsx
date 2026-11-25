import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import WorkspaceTabs from './components/WorkspaceTabs';
import CRMPage from './pages/CRMPage';
import PipelinePage from './pages/PipelinePage';
import WhatsAppPage from './pages/WhatsAppPage';
import AssistantPage from './pages/AssistantPage';
import './App.css';

function AppShell() {
  const puedeGestionarCRM = globalThis.APP_FEATURES?.crm ?? true;
  const puedeVerWhatsApp = globalThis.APP_FEATURES?.whatsapp ?? true;
  const puedeGestionarDocumentos = globalThis.APP_FEATURES?.documents ?? true;
  const rutaInicial = puedeGestionarCRM ? '/crm' : '/whatsapp';

  return (
    <>
      <WorkspaceTabs />
      <div className="app-content">
        <Routes>
          <Route path="/" element={<Navigate to={rutaInicial} replace />} />
          <Route path="/crm" element={puedeGestionarCRM ? <CRMPage /> : <Navigate to={rutaInicial} replace />} />
          <Route path="/pipeline" element={puedeGestionarCRM ? <PipelinePage /> : <Navigate to={rutaInicial} replace />} />
          <Route path="/assistant" element={puedeGestionarCRM ? <AssistantPage puedeGestionarDocumentos={puedeGestionarDocumentos} /> : <Navigate to={rutaInicial} replace />} />
          <Route
            path="/whatsapp"
            element={puedeVerWhatsApp ? <WhatsAppPage /> : <Navigate to="/crm" replace />}
          />
          <Route path="*" element={<Navigate to={rutaInicial} replace />} />
        </Routes>
      </div>
    </>
  );
}

export default function App() {
  return (
    <HashRouter>
      <AppShell />
    </HashRouter>
  );
}
