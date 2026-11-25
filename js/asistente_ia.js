/**
 * COPILOTO OPERATIVO Y ANALÍTICO — GROUP_CEA
 * Potenciado con Groq API (Llama 3.3 70B) & Motor Contextual Institucional
 */

(() => {
    'use strict';

    const obtenerModuloActual = () => {
        const path = window.location.pathname.toLowerCase();
        if (path.includes('crm_comercial')) return 'crm_comercial';
        if (path.includes('pipeline')) return 'pipeline';
        if (path.includes('documentos')) return 'documentos';
        if (path.includes('clientes')) return 'clientes';
        if (path.includes('prospectos')) return 'prospectos';
        if (path.includes('asesor')) return 'asesor';
        if (path.includes('legal')) return 'legal';
        if (path.includes('contratos')) return 'contratos';
        if (path.includes('contabilidad') || path.includes('pagos')) return 'finanzas';
        if (path.includes('dashboard')) return 'inicio';
        return 'general';
    };

    const crearWidgetCopiloto = () => {
        if (document.getElementById('copiloto-cea-widget')) return;

        const widget = document.createElement('div');
        widget.id = 'copiloto-cea-widget';
        widget.className = 'copiloto-wrapper';
        widget.innerHTML = `
            <!-- Botón Flotante del Copiloto -->
            <button type="button" id="copiloto-toggle-btn" class="copiloto-trigger" aria-label="Abrir Copiloto CEA" title="Copiloto Analítico y de Consultas">
                <div class="copiloto-trigger-icon">
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path>
                        <path d="M2 12h20"></path>
                    </svg>
                </div>
                <span class="copiloto-trigger-label">Copiloto CEA</span>
                <span class="copiloto-indicator" id="copiloto-live-indicator" title="Sincronizado"></span>
            </button>

            <!-- Ventana del Copiloto Popover -->
            <div id="copiloto-window" class="copiloto-window" hidden>
                <div class="copiloto-header">
                    <div class="copiloto-header-info">
                        <div class="copiloto-avatar">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                            </svg>
                        </div>
                        <div>
                            <div class="copiloto-title-row">
                                <strong>Copiloto CEA</strong>
                                <span id="copiloto-model-badge" class="copiloto-model-pill">Motor Institucional</span>
                            </div>
                            <small class="copiloto-status">Gestión Jurídica, Tesis & Ventas</small>
                        </div>
                    </div>
                    <div class="copiloto-header-actions">
                        <button type="button" id="copiloto-config-toggle" class="copiloto-icon-btn" title="Configurar Groq API" aria-label="Configurar Groq">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                            </svg>
                        </button>
                        <button type="button" id="copiloto-close-btn" class="copiloto-icon-btn" aria-label="Cerrar panel" title="Cerrar">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>
                </div>

                <!-- Panel de Configuración Groq (Desplegable) -->
                <div id="copiloto-config-panel" class="copiloto-config-panel" hidden>
                    <div class="config-panel-inner">
                        <div class="config-title-row">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polygon points="12 8 8 12 12 16 12 8"></polygon>
                            </svg>
                            <strong>Configuración de Groq API</strong>
                        </div>
                        <p class="config-desc">
                            Ingresa tu clave de Groq para activar <code>Llama 3.3 70B</code> con respuesta ultra-rápida y comprensión profunda de GROUP_CEA.
                        </p>
                        <form id="copiloto-config-form" class="config-form">
                            <label for="groq-key-input" class="config-label">API Key de Groq (gsk_...)</label>
                            <input type="password" id="groq-key-input" class="config-input" placeholder="gsk_xxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off">
                            
                            <label for="groq-model-select" class="config-label" style="margin-top:8px;">Modelo de IA</label>
                            <select id="groq-model-select" class="config-input">
                                <option value="llama-3.3-70b-versatile">Llama 3.3 70B Versatile (Recomendado)</option>
                                <option value="llama-3.1-8b-instant">Llama 3.1 8B Instant (Ultra-rápido)</option>
                                <option value="mixtral-8x7b-32768">Mixtral 8x7B (32k contexto)</option>
                            </select>

                            <div class="config-actions">
                                <button type="submit" class="btn-config-save" id="btn-save-groq">Guardar Clave</button>
                                <span id="config-status-msg" class="config-status-msg"></span>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Consultas Rápidas Sugeridas -->
                <div class="copiloto-prompts">
                    <button type="button" class="copiloto-chip" data-prompt="¿Cómo va el embudo de ventas hoy y qué matrículas están ganadas?">Embudo de Ventas</button>
                    <button type="button" class="copiloto-chip" data-prompt="¿Qué alumnos están en fase de Turnitin o esperando simulación de sustentación?">Avance Tesis & Turnitin</button>
                    <button type="button" class="copiloto-chip" data-prompt="¿Qué convenios y contratos legales faltan firmar con clientes?">Contratos & Legal</button>
                    <button type="button" class="copiloto-chip" data-prompt="Dame un resumen de contactos, teléfonos y profesiones registradas en el CRM">Clientes & Contactos</button>
                    <button type="button" class="copiloto-chip" data-prompt="Dame un resumen institucional integral de GROUP_CEA con recomendaciones operativas">Resumen Ejecutivo</button>
                </div>

                <!-- Historial de Conversación -->
                <div id="copiloto-messages" class="copiloto-messages">
                    <div class="copiloto-msg bot">
                        <div class="copiloto-bubble">
                            Hola. Soy el <strong>Copiloto Inteligente de GROUP_CEA</strong>. Estoy conectado en tiempo real con los expedientes, el progreso de tesis (Turnitin y sustentaciones), el pipeline comercial y los contratos jurídicos.<br><br>¿En qué puedo asistirte hoy?
                        </div>
                    </div>
                </div>

                <!-- Entrada de Consulta -->
                <form id="copiloto-form" class="copiloto-form">
                    <input type="text" id="copiloto-input" placeholder="Consulta sobre tesistas, Turnitin, ventas, clientes o contratos..." autocomplete="off" required>
                    <button type="submit" id="copiloto-send-btn" class="copiloto-send-btn" aria-label="Enviar consulta" title="Enviar consulta">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </form>
            </div>
        `;

        document.body.appendChild(widget);

        // Referencias del DOM
        const toggleBtn = document.getElementById('copiloto-toggle-btn');
        const chatWindow = document.getElementById('copiloto-window');
        const closeBtn = document.getElementById('copiloto-close-btn');
        const chatForm = document.getElementById('copiloto-form');
        const chatInput = document.getElementById('copiloto-input');
        const chatMessages = document.getElementById('copiloto-messages');
        const configToggle = document.getElementById('copiloto-config-toggle');
        const configPanel = document.getElementById('copiloto-config-panel');
        const configForm = document.getElementById('copiloto-config-form');
        const groqKeyInput = document.getElementById('groq-key-input');
        const groqModelSelect = document.getElementById('groq-model-select');
        const configStatus = document.getElementById('config-status-msg');
        const modelBadge = document.getElementById('copiloto-model-badge');
        const liveIndicator = document.getElementById('copiloto-live-indicator');

        // Cargar estado inicial de la IA
        const cargarEstadoIA = async () => {
            try {
                const res = await fetch('../api/asistente_ia.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ accion: 'obtener_estado_ia' })
                });
                const data = await res.json();
                if (data.exito) {
                    if (data.tiene_key) {
                        modelBadge.textContent = 'Groq · ' + (data.modelo.includes('70b') ? 'Llama 3.3 70B' : 'Llama 3.1');
                        modelBadge.classList.add('groq-active');
                        liveIndicator.style.background = '#10b981';
                        groqKeyInput.placeholder = data.mascara_key || 'Clave Groq configurada';
                        if (data.modelo) groqModelSelect.value = data.modelo;
                    } else {
                        modelBadge.textContent = 'Motor Local';
                        modelBadge.classList.remove('groq-active');
                        liveIndicator.style.background = '#3b82f6';
                    }
                }
            } catch (e) {
                // Silencioso
            }
        };

        cargarEstadoIA();

        const abrirPanel = () => {
            chatWindow.hidden = false;
            void chatWindow.offsetWidth;
            chatWindow.classList.add('is-open');
            toggleBtn.classList.add('is-active');
            chatInput.focus();
        };

        const cerrarPanel = () => {
            chatWindow.classList.remove('is-open');
            toggleBtn.classList.remove('is-active');
            setTimeout(() => {
                if (!chatWindow.classList.contains('is-open')) {
                    chatWindow.hidden = true;
                }
            }, 250);
        };

        toggleBtn.addEventListener('click', () => {
            if (chatWindow.classList.contains('is-open')) {
                cerrarPanel();
            } else {
                abrirPanel();
            }
        });

        closeBtn.addEventListener('click', cerrarPanel);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && chatWindow.classList.contains('is-open')) {
                cerrarPanel();
            }
        });

        // Configuración de Groq
        configToggle.addEventListener('click', () => {
            configPanel.hidden = !configPanel.hidden;
            if (!configPanel.hidden) {
                groqKeyInput.focus();
            }
        });

        configForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const keyVal = groqKeyInput.value.trim();
            const modelVal = groqModelSelect.value;
            const btnSave = document.getElementById('btn-save-groq');

            btnSave.disabled = true;
            btnSave.textContent = 'Guardando...';

            try {
                const res = await fetch('../api/asistente_ia.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        accion: 'guardar_config',
                        api_key: keyVal,
                        modelo: modelVal
                    })
                });
                const data = await res.json();
                btnSave.disabled = false;
                btnSave.textContent = 'Guardar Clave';

                if (data.exito) {
                    configStatus.textContent = '¡Guardado con éxito!';
                    configStatus.style.color = '#10b981';
                    cargarEstadoIA();
                    setTimeout(() => {
                        configPanel.hidden = true;
                        configStatus.textContent = '';
                    }, 1500);
                } else {
                    configStatus.textContent = data.error || 'Error al guardar';
                    configStatus.style.color = '#ef4444';
                }
            } catch (err) {
                btnSave.disabled = false;
                btnSave.textContent = 'Guardar Clave';
                configStatus.textContent = 'Error de conexión';
                configStatus.style.color = '#ef4444';
            }
        });

        // Enviar consulta al backend
        const enviarPregunta = async (textoPregunta) => {
            const pregunta = (textoPregunta || chatInput.value).trim();
            if (!pregunta) return;

            // Mensaje del usuario
            const userMsg = document.createElement('div');
            userMsg.className = 'copiloto-msg user';
            userMsg.innerHTML = `<div class="copiloto-bubble">${escaparHtml(pregunta)}</div>`;
            chatMessages.appendChild(userMsg);
            chatInput.value = '';
            chatMessages.scrollTop = chatMessages.scrollHeight;

            // Indicador de carga
            const loadingMsg = document.createElement('div');
            loadingMsg.className = 'copiloto-msg bot loading';
            loadingMsg.innerHTML = `
                <div class="copiloto-bubble">
                    <div class="copiloto-typing"><span></span><span></span><span></span></div>
                    <span style="font-size:0.78rem; color:#64748b;">Analizando datos institucionales...</span>
                </div>
            `;
            chatMessages.appendChild(loadingMsg);
            chatMessages.scrollTop = chatMessages.scrollHeight;

            try {
                const response = await fetch('../api/asistente_ia.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        pregunta: pregunta,
                        modulo: obtenerModuloActual()
                    })
                });

                const data = await response.json();
                loadingMsg.remove();

                const botMsg = document.createElement('div');
                botMsg.className = 'copiloto-msg bot';

                const textoFormateado = formatearMarkdown(data.respuesta || 'No se obtuvo respuesta para esta consulta.');
                
                let sourceBadge = '';
                if (data.proveedor === 'groq') {
                    sourceBadge = `<div class="copiloto-source-tag groq">⚡ Groq · ${escaparHtml(data.modelo || 'Llama 3.3')}</div>`;
                }

                botMsg.innerHTML = `<div class="copiloto-bubble">${textoFormateado}${sourceBadge}</div>`;
                chatMessages.appendChild(botMsg);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            } catch (error) {
                loadingMsg.remove();
                const errorMsg = document.createElement('div');
                errorMsg.className = 'copiloto-msg bot error';
                errorMsg.innerHTML = `<div class="copiloto-bubble error-bubble">Error de conexión con el servidor. Inténtalo de nuevo.</div>`;
                chatMessages.appendChild(errorMsg);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        };

        chatForm.addEventListener('submit', (e) => {
            e.preventDefault();
            enviarPregunta();
        });

        // Chips interactivos
        document.querySelectorAll('.copiloto-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                abrirPanel();
                enviarPregunta(chip.dataset.prompt);
            });
        });
    };

    const escaparHtml = (str) => {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    };

    const formatearMarkdown = (str) => {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/### (.*?)\n/g, '<h4 class="copiloto-h4">$1</h4>')
            .replace(/## (.*?)\n/g, '<h3 class="copiloto-h3">$1</h3>')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/`([^`]+)`/g, '<code class="copiloto-code">$1</code>')
            .replace(/---/g, '<hr class="copiloto-divider">')
            .replace(/\n/g, '<br>');
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', crearWidgetCopiloto);
    } else {
        crearWidgetCopiloto();
    }
})();
