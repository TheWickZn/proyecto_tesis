/**
 * SISTEMA DE GESTIÓN DOCUMENTAL - VALIDACIONES Y RESTRICCIONES GLOBALES
 * 
 * Reglas implementadas:
 * 1. Nombres y Apellidos: solo letras (mayúsculas/minúsculas/tildes/ñ) y espacios. Bloquea números y caracteres especiales.
 * 2. Documentos de identidad: solo números (0-9).
 * 3. Teléfono / Celular: límite 9 dígitos, debe comenzar con 9, y formatea con un espacio cada 3 dígitos (ej: 987 654 321).
 * 4. Calendario / Próxima acción: bloquea selección de fechas anteriores a la actual.
 * 5. Sanitización automática en formularios antes de enviar.
 */

(() => {
    'use strict';

    // Obtener fecha actual en formato local YYYY-MM-DD
    const obtenerFechaHoy = () => {
        const ahora = new Date();
        const año = ahora.getFullYear();
        const mes = String(ahora.getMonth() + 1).padStart(2, '0');
        const dia = String(ahora.getDate()).padStart(2, '0');
        return `${año}-${mes}-${dia}`;
    };

    /**
     * Formatea un número de celular a grupos de 3 dígitos: 9XX XXX XXX
     */
    const formatearTelefonoVisual = (valor) => {
        // Extraer solo dígitos
        let digitos = (valor || '').replace(/\D/g, '');
        
        // Si hay dígitos y el primero no es 9, forzar o ajustar si empieza por otro
        if (digitos.length > 0 && digitos[0] !== '9') {
            digitos = '9' + digitos.slice(0, 8);
        }
        
        // Limitar estrictamente a 9 dígitos
        digitos = digitos.slice(0, 9);
        
        // Formatear visualmente cada 3 números
        const partes = [];
        for (let i = 0; i < digitos.length; i += 3) {
            partes.push(digitos.slice(i, i + 3));
        }
        return partes.join(' ');
    };

    /**
     * Configurar restricciones para inputs de Nombres y Apellidos
     */
    const inicializarNombresYApellidos = (input) => {
        if (input.dataset.validadorLetrasIniciado) return;
        input.dataset.validadorLetrasIniciado = 'true';

        // Solo permitir letras, tildes, ñ/Ñ y espacios
        const regexValido = /^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]*$/;

        input.addEventListener('keydown', (e) => {
            // Permitir teclas de navegación y control
            const teclasPermitidas = [
                'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                'Home', 'End'
            ];
            if (teclasPermitidas.includes(e.key) || e.ctrlKey || e.metaKey || e.altKey) {
                return;
            }

            // Si es un solo caracter y no es letra ni espacio, bloquear evento
            if (e.key.length === 1 && !regexValido.test(e.key)) {
                e.preventDefault();
                mostrarAvisoFlotante(input, 'Solo se permiten letras (sin números ni símbolos)');
            }
        });

        input.addEventListener('input', () => {
            const valorOriginal = input.value;
            // Remover cualquier número o caracter no permitido
            const valorLimpio = valorOriginal.replace(/[^a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]/g, '');
            // Evitar espacios múltiples consecutivos
            const normalizado = valorLimpio.replace(/\s{2,}/g, ' ');
            if (valorOriginal !== normalizado) {
                input.value = normalizado;
            }
        });

        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const textoPegado = (e.clipboardData || window.clipboardData).getData('text') || '';
            const textoLimpio = textoPegado.replace(/[^a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s]/g, '').replace(/\s{2,}/g, ' ');
            document.execCommand('insertText', false, textoLimpio);
        });
    };

    /**
     * Configurar restricciones para inputs de Documento de Identidad (Solo números)
     */
    const inicializarDocumentosIdentidad = (input) => {
        if (input.dataset.validadorDocIniciado) return;
        input.dataset.validadorDocIniciado = 'true';

        input.addEventListener('keydown', (e) => {
            const teclasPermitidas = [
                'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                'Home', 'End'
            ];
            if (teclasPermitidas.includes(e.key) || e.ctrlKey || e.metaKey) {
                return;
            }
            if (e.key.length === 1 && !/^[0-9]$/.test(e.key)) {
                e.preventDefault();
                mostrarAvisoFlotante(input, 'Solo se permiten números para documentos');
            }
        });

        input.addEventListener('input', () => {
            const max = parseInt(input.maxLength, 10) || 12;
            const soloNumeros = input.value.replace(/\D/g, '').slice(0, max);
            if (input.value !== soloNumeros) {
                input.value = soloNumeros;
            }
        });

        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const texto = (e.clipboardData || window.clipboardData).getData('text') || '';
            const max = parseInt(input.maxLength, 10) || 12;
            const limpio = texto.replace(/\D/g, '').slice(0, max);
            document.execCommand('insertText', false, limpio);
        });
    };

    /**
     * Configurar restricciones para Teléfono / Celular
     * - Límite de 9 dígitos
     * - Debe iniciar con 9
     * - Separación visual cada 3 números
     */
    const inicializarTelefonoCelular = (input) => {
        if (input.dataset.validadorTelIniciado) return;
        input.dataset.validadorTelIniciado = 'true';

        // Establecer atributos recomendados
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('maxlength', '11'); // 9 dígitos + 2 espacios = 11 caracteres visuales
        input.setAttribute('placeholder', '9XX XXX XXX');

        // Formatear valor inicial si ya tiene uno
        if (input.value) {
            input.value = formatearTelefonoVisual(input.value);
        }

        input.addEventListener('keydown', (e) => {
            const teclasPermitidas = [
                'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                'Home', 'End'
            ];
            if (teclasPermitidas.includes(e.key) || e.ctrlKey || e.metaKey) {
                return;
            }

            // Si es un caracter
            if (e.key.length === 1) {
                if (!/^[0-9]$/.test(e.key)) {
                    e.preventDefault();
                    mostrarAvisoFlotante(input, 'Solo números para el celular');
                    return;
                }
                const digitosActuales = input.value.replace(/\D/g, '');
                // El primer dígito debe ser 9 obligatoriamente
                if (digitosActuales.length === 0 && e.key !== '9') {
                    e.preventDefault();
                    input.value = '9';
                    mostrarAvisoFlotante(input, 'El celular debe iniciar con 9');
                    return;
                }
                // Si ya llegó a 9 dígitos, no permitir más
                if (digitosActuales.length >= 9) {
                    e.preventDefault();
                    mostrarAvisoFlotante(input, 'Máximo 9 dígitos');
                }
            }
        });

        input.addEventListener('input', () => {
            const formateado = formatearTelefonoVisual(input.value);
            input.value = formateado;
        });

        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const texto = (e.clipboardData || window.clipboardData).getData('text') || '';
            input.value = formatearTelefonoVisual(texto);
        });
    };

    /**
     * Configurar restricciones para Fechas / Próxima acción
     * - Bloquea fechas anteriores a hoy
     */
    const inicializarFechasRestringidas = (input) => {
        if (input.dataset.validadorFechaIniciado) return;
        input.dataset.validadorFechaIniciado = 'true';

        const hoy = obtenerFechaHoy();
        // Bloquear en el selector HTML5
        input.setAttribute('min', hoy);

        input.addEventListener('change', () => {
            if (input.value && input.value < hoy) {
                mostrarAvisoFlotante(input, 'No puedes seleccionar fechas anteriores a la actual');
                input.value = hoy;
            }
        });
    };

    /**
     * Mensaje de aviso flotante sutil y accesible
     */
    let temporizadorAviso = null;
    const mostrarAvisoFlotante = (elemento, mensaje) => {
        let tooltip = document.getElementById('validador-tooltip-flotante');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'validador-tooltip-flotante';
            tooltip.className = 'validador-tooltip';
            document.body.appendChild(tooltip);
        }

        tooltip.textContent = mensaje;
        const rect = elemento.getBoundingClientRect();
        tooltip.style.top = `${window.scrollY + rect.bottom + 6}px`;
        tooltip.style.left = `${window.scrollX + rect.left}px`;
        tooltip.classList.add('visible');

        clearTimeout(temporizadorAviso);
        temporizadorAviso = setTimeout(() => {
            tooltip.classList.remove('visible');
        }, 2500);
    };

    /**
     * Escaneo de elementos en la página
     */
    const inicializarValidaciones = () => {
        // Nombres y apellidos
        const inputsNombres = document.querySelectorAll(`
            input[name="nombres"],
            input[name="apellidos"],
            input[name="nombre"],
            input[name="apellido"],
            input[name="persona"],
            input[data-tipo="letras"]
        `);
        inputsNombres.forEach(inicializarNombresYApellidos);

        // Documentos de identidad (DNI, RUC, etc.)
        const inputsDocumento = document.querySelectorAll(`
            input[name="documento"],
            input[name="dni"],
            input[name="ruc"],
            input[data-tipo="documento"]
        `);
        inputsDocumento.forEach(inicializarDocumentosIdentidad);

        // Teléfono / celular
        const inputsTelefono = document.querySelectorAll(`
            input[name="telefono"],
            input[name="celular"],
            input[type="tel"],
            input[data-tipo="telefono"]
        `);
        inputsTelefono.forEach(inicializarTelefonoCelular);

        // Calendario / Próxima acción (fechas que no pueden ser pasadas)
        const inputsProximaAccion = document.querySelectorAll(`
            input[name="proxima_accion"],
            input[name="fecha_proxima_accion"],
            input[name="proxima_fecha"],
            input[data-no-pasado]
        `);
        inputsProximaAccion.forEach(inicializarFechasRestringidas);

        // Limpiar espacios en celulares antes de enviar formularios para compatibilidad
        document.querySelectorAll('form').forEach((form) => {
            if (form.dataset.limpiadorFormularioIniciado) return;
            form.dataset.limpiadorFormularioIniciado = 'true';

            form.addEventListener('submit', () => {
                form.querySelectorAll('input[name="telefono"], input[name="celular"]').forEach((telInput) => {
                    // El valor visual se mantiene, o se limpia para enviar los 9 dígitos
                    const soloDigitos = telInput.value.replace(/\D/g, '');
                    if (soloDigitos.length === 9) {
                        telInput.value = soloDigitos;
                    }
                });
            });
        });
    };

    // Inicializar al cargar y observar cambios dinámicos
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializarValidaciones);
    } else {
        inicializarValidaciones();
    }

    // Observar inserciones dinámicas de DOM si se abren modales o se inyectan formularios
    if (window.MutationObserver) {
        const observador = new MutationObserver(() => {
            inicializarValidaciones();
        });
        observador.observe(document.body, { childList: true, subtree: true });
    }

    // Exponer utilidad para uso manual si es necesario
    window.SistemaValidaciones = {
        formatearTelefono: formatearTelefonoVisual,
        obtenerHoy: obtenerFechaHoy,
        refrescar: inicializarValidaciones
    };
})();

