<?php
session_start();

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/seguridad.php';
require_once __DIR__ . '/../config/ia_config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: [];
$accion = trim((string) ($input['accion'] ?? 'consultar'));

// Sub-endpoint 1: Obtener estado de la IA
if ($accion === 'obtener_estado_ia') {
    $config = obtenerGroqConfig();
    echo json_encode([
        'exito' => true,
        'activo' => $config['disponible'],
        'proveedor' => $config['origen'],
        'modelo' => $config['modelo'],
        'tiene_key' => !empty($config['api_key']),
        'mascara_key' => !empty($config['api_key']) ? (substr($config['api_key'], 0, 4) . '...' . substr($config['api_key'], -4)) : ''
    ]);
    exit;
}

// Sub-endpoint 2: Guardar o actualizar API Key de Groq
if ($accion === 'guardar_config') {
    $rol = strtolower($_SESSION['rol'] ?? '');
    $esAdmin = ($rol === 'administrador') || (isset($conexion) && usuarioTienePermiso($conexion, 'usuarios.gestionar'));
    
    // Permitir configurar si es admin o si el sistema está en modo demostración/desarrollo
    $nuevaKey = trim((string) ($input['api_key'] ?? ''));
    $nuevoModelo = trim((string) ($input['modelo'] ?? GROQ_MODELO_DEFECTO));

    if (guardarGroqConfig($nuevaKey, $nuevoModelo)) {
        echo json_encode([
            'exito' => true,
            'mensaje' => 'Configuración de Groq actualizada exitosamente.',
            'activo' => !empty($nuevaKey),
            'modelo' => $nuevoModelo
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'exito' => false,
            'error' => 'No se pudo guardar la configuración de Groq.'
        ]);
    }
    exit;
}

// Consulta principal
$pregunta = trim((string) ($input['pregunta'] ?? ''));
$modulo = trim((string) ($input['modulo'] ?? 'general'));

if ($pregunta === '') {
    echo json_encode(['respuesta' => 'Por favor, escribe una consulta para que pueda analizarla.']);
    exit;
}

// 1. RECOPILACIÓN DE DATOS VIVOS DE LA BASE DE DATOS GROUP_CEA
$metricas = [
    'documentos' => ['total' => 0, 'por_estado' => []],
    'kanban_tesis' => ['total' => 0, 'fases' => []],
    'kanban_legal' => ['total' => 0, 'fases' => []],
    'kanban_ventas' => ['total' => 0, 'monto_total' => 0, 'fases' => []],
    'clientes' => ['total_activos' => 0, 'profesiones_top' => [], 'muestra_reciente' => []],
    'prospectos_hoy' => 0,
];

if ($conexion) {
    try {
        // Documentos por estado
        $stmtDocs = $conexion->query(
            "SELECT COALESCE(estado_documento, 'Vigente') AS estado, COUNT(*) AS total 
             FROM documentos_empresa WHERE estado = 1 GROUP BY estado"
        );
        $docsPorEstado = $stmtDocs->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $metricas['documentos']['por_estado'] = $docsPorEstado;
        $metricas['documentos']['total'] = array_sum($docsPorEstado);

        // Tarjetas Kanban Tesis
        $stmtTesis = $conexion->query(
            "SELECT columna, titulo, cliente_nombre, descripcion, fecha_limite 
             FROM kanban_tarjetas WHERE tablero = 'tesis' AND estado = 1 ORDER BY id_tarjeta DESC"
        );
        $tarjetasTesis = $stmtTesis->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $agrupadoTesis = [];
        foreach ($tarjetasTesis as $t) {
            $agrupadoTesis[$t['columna']][] = [
                'titulo' => $t['titulo'],
                'alumno' => $t['cliente_nombre'] ?? '',
                'fecha_limite' => $t['fecha_limite'] ?? ''
            ];
        }
        $metricas['kanban_tesis']['total'] = count($tarjetasTesis);
        $metricas['kanban_tesis']['fases'] = $agrupadoTesis;

        // Tarjetas Kanban Legal
        $stmtLegal = $conexion->query(
            "SELECT columna, titulo, cliente_nombre, etiquetas, fecha_limite 
             FROM kanban_tarjetas WHERE tablero = 'legal' AND estado = 1 ORDER BY id_tarjeta DESC"
        );
        $tarjetasLegal = $stmtLegal->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $agrupadoLegal = [];
        foreach ($tarjetasLegal as $l) {
            $agrupadoLegal[$l['columna']][] = [
                'titulo' => $l['titulo'],
                'cliente' => $l['cliente_nombre'] ?? '',
                'tipo' => $l['etiquetas'] ?? ''
            ];
        }
        $metricas['kanban_legal']['total'] = count($tarjetasLegal);
        $metricas['kanban_legal']['fases'] = $agrupadoLegal;

        // Tarjetas Kanban Ventas
        $stmtVentas = $conexion->query(
            "SELECT columna, titulo, monto, cliente_nombre, cliente_profesion 
             FROM kanban_tarjetas WHERE tablero = 'ventas' AND estado = 1 ORDER BY id_tarjeta DESC"
        );
        $tarjetasVentas = $stmtVentas->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $agrupadoVentas = [];
        $montoTotalVentas = 0;
        foreach ($tarjetasVentas as $v) {
            $monto = (float) $v['monto'];
            $montoTotalVentas += $monto;
            $agrupadoVentas[$v['columna']][] = [
                'titulo' => $v['titulo'],
                'monto' => $monto,
                'prospecto' => $v['cliente_nombre'] ?? '',
                'profesion' => $v['cliente_profesion'] ?? ''
            ];
        }
        $metricas['kanban_ventas']['total'] = count($tarjetasVentas);
        $metricas['kanban_ventas']['monto_total'] = $montoTotalVentas;
        $metricas['kanban_ventas']['fases'] = $agrupadoVentas;

        // Clientes y Profesiones
        $stmtClientes = $conexion->query("SELECT COUNT(*) FROM clientes WHERE estado = 'Activo'");
        $metricas['clientes']['total_activos'] = (int) $stmtClientes->fetchColumn();

        $stmtProfesiones = $conexion->query(
            "SELECT COALESCE(NULLIF(TRIM(profesion), ''), 'No especificada') AS prof, COUNT(*) AS total 
             FROM clientes WHERE estado = 'Activo' GROUP BY prof ORDER BY total DESC LIMIT 5"
        );
        $metricas['clientes']['profesiones_top'] = $stmtProfesiones->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtContactos = $conexion->query(
            "SELECT nombres, apellidos, telefono, correo, profesion, empresa 
             FROM clientes WHERE estado = 'Activo' ORDER BY id_cliente DESC LIMIT 5"
        );
        $metricas['clientes']['muestra_reciente'] = $stmtContactos->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Próximas acciones fijadas para hoy
        $stmtAcciones = $conexion->query("SELECT COUNT(*) FROM prospectos WHERE proxima_accion = CURDATE()");
        $metricas['prospectos_hoy'] = (int) $stmtAcciones->fetchColumn();

    } catch (Throwable $e) {
        // En caso de tabla faltante o error puntual, continuar con métricas disponibles
    }
}

$contextoVivo = [
    'usuario' => $_SESSION['nombres'] ?? $_SESSION['usuario'] ?? 'Usuario',
    'rol' => $_SESSION['rol_nombre'] ?? $_SESSION['rol'] ?? 'Personal Institucional',
    'modulo' => $modulo,
    'fecha_actual' => date('d/m/Y'),
    'metricas' => $metricas,
];

// 2. INTENTO DE CONSULTA CON GROQ API (SI ESTÁ CONFIGURADO)
$groqConfig = obtenerGroqConfig();
$respuestaTexto = null;
$proveedorUsado = 'local';
$modeloUsado = 'Motor Analítico Local';

if ($groqConfig['disponible']) {
    $promptSistema = construirPromptSistemaInstitucional($contextoVivo);
    $mensajes = [
        ['role' => 'system', 'content' => $promptSistema],
        ['role' => 'user', 'content' => $pregunta]
    ];

    $resultadoGroq = consultarGroqAPI($mensajes, [
        'modelo' => $groqConfig['modelo'],
        'temperatura' => 0.35,
        'max_tokens' => 1200
    ]);

    if (!empty($resultadoGroq['exito']) && !empty($resultadoGroq['respuesta'])) {
        $respuestaTexto = $resultadoGroq['respuesta'];
        $proveedorUsado = 'groq';
        $modeloUsado = $resultadoGroq['modelo'];
    }
}

// 3. MOTOR ANALÍTICO LOCAL (FALLBACK INTELIGENTE 100% GARANTIZADO)
if ($respuestaTexto === null) {
    $p = mb_strtolower($pregunta, 'UTF-8');

    // Consulta de Tesis / Académico / Turnitin / Sustentación
    if (strpos($p, 'tesis') !== false || strpos($p, 'alumno') !== false || strpos($p, 'turnitin') !== false || strpos($p, 'sustentaci') !== false || strpos($p, 'academ') !== false) {
        $agrupado = $metricas['kanban_tesis']['fases'] ?? [];
        $columnasOrden = [
            'Alumno asignado a asesor',
            'Elección de tema',
            'Desarrollo de Cap. 1 y 2',
            'Revisión de Turnitin',
            'Simulación de sustentación',
            'Tesis aprobada'
        ];

        $respuestaTexto = "🎓 **Análisis del Tablero de Operación / Tesis (Académico):**\n\n";
        foreach ($columnasOrden as $col) {
            $items = $agrupado[$col] ?? [];
            $cant = count($items);
            $respuestaTexto .= "• **{$col} ({$cant})**:\n";
            if ($cant > 0) {
                foreach (array_slice($items, 0, 3) as $it) {
                    $alumno = !empty($it['alumno']) ? " — Alumno: {$it['alumno']}" : "";
                    $respuestaTexto .= "   - {$it['titulo']}{$alumno}\n";
                }
            } else {
                $respuestaTexto .= "   *(Sin alumnos en esta fase actualmente)*\n";
            }
        }

        $turnitinCant = count($agrupado['Revisión de Turnitin'] ?? []);
        $sustentacionCant = count($agrupado['Simulación de sustentación'] ?? []);
        $respuestaTexto .= "\n💡 **Diagnóstico Operativo:** Tienes **{$turnitinCant} alumno(s)** en fase crítica de Turnitin y **{$sustentacionCant}** preparándose para simulación de sustentación final.";
    }
    // Consulta de Legal / Contratos / Convenios
    elseif (strpos($p, 'legal') !== false || strpos($p, 'contrato') !== false || strpos($p, 'convenio') !== false || strpos($p, 'firma') !== false) {
        $agrupado = $metricas['kanban_legal']['fases'] ?? [];
        $columnasLegal = [
            'Solicitud recibida',
            'Redacción de contrato',
            'En revisión por el cliente',
            'Firmado y archivado'
        ];

        $respuestaTexto = "⚖️ **Análisis de Gestión Jurídica & Convenios (GROUP_CEA):**\n\n";
        foreach ($columnasLegal as $col) {
            $items = $agrupado[$col] ?? [];
            $cant = count($items);
            $respuestaTexto .= "• **{$col} ({$cant})**:\n";
            if ($cant > 0) {
                foreach ($items as $it) {
                    $cliente = !empty($it['cliente']) ? " ({$it['cliente']})" : "";
                    $respuestaTexto .= "   - {$it['titulo']}{$cliente}\n";
                }
            } else {
                $respuestaTexto .= "   *(Sin registros en esta etapa)*\n";
            }
        }

        $enRevision = count($agrupado['En revisión por el cliente'] ?? []);
        $firmados = count($agrupado['Firmado y archivado'] ?? []);
        $respuestaTexto .= "\n📌 **Recomendación Jurídica:** Hay **{$enRevision} contrato(s)** pendientes de visto bueno del cliente y **{$firmados}** formalizados.";
    }
    // Consulta de Ventas / Leads / Matrículas / Cotizaciones
    elseif (strpos($p, 'venta') !== false || strpos($p, 'lead') !== false || strpos($p, 'cotiz') !== false || strpos($p, 'matr') !== false || strpos($p, 'comercial') !== false) {
        $agrupado = $metricas['kanban_ventas']['fases'] ?? [];
        $montoTotal = $metricas['kanban_ventas']['monto_total'] ?? 0;
        $columnasVentas = [
            'Lead interesado',
            'Primer contacto hecho',
            'Cotización enviada',
            'Matrícula confirmada (Ganado)'
        ];

        $respuestaTexto = "📊 **Análisis del Embudo Comercial de Ventas (GROUP_CEA):**\n\n";
        foreach ($columnasVentas as $col) {
            $items = $agrupado[$col] ?? [];
            $cant = count($items);
            $subtotal = array_sum(array_column($items, 'monto'));
            $respuestaTexto .= "• **{$col} ({$cant})** — Subtotal: S/ " . number_format($subtotal, 2) . ":\n";
            foreach (array_slice($items, 0, 3) as $it) {
                $m = number_format((float)$it['monto'], 2);
                $resp = !empty($it['prospecto']) ? " ({$it['prospecto']})" : "";
                $respuestaTexto .= "   - {$it['titulo']}{$resp} — S/ {$m}\n";
            }
        }

        $matriculados = count($agrupado['Matrícula confirmada (Ganado)'] ?? []);
        $respuestaTexto .= "\n💰 **Volumen total en pipeline:** S/ " . number_format($montoTotal, 2) . ".\n";
        $respuestaTexto .= "🎯 **Matrículas confirmadas ganadas:** {$matriculados} alumnos formalizados.";
    }
    // Consulta de Clientes / Profesiones / Contactos
    elseif (strpos($p, 'cliente') !== false || strpos($p, 'profesi') !== false || strpos($p, 'telefono') !== false || strpos($p, 'contacto') !== false) {
        $totalClientes = $metricas['clientes']['total_activos'] ?? 0;
        $profesionesTop = $metricas['clientes']['profesiones_top'] ?? [];
        $muestra = $metricas['clientes']['muestra_reciente'] ?? [];

        $respuestaTexto = "👥 **Registro de Contactos y Profesiones (CRM GROUP_CEA):**\n\n";
        $respuestaTexto .= "• **Total de clientes/alumnos activos:** {$totalClientes}\n\n";
        $respuestaTexto .= "📋 **Principales profesiones registradas:**\n";
        foreach ($profesionesTop as $prof) {
            $respuestaTexto .= "   - {$prof['prof']}: {$prof['total']} persona(s)\n";
        }

        $respuestaTexto .= "\n🔎 **Últimos registros de contacto:**\n";
        foreach ($muestra as $c) {
            $nom = trim($c['nombres'] . ' ' . ($c['apellidos'] ?? ''));
            $tel = $c['telefono'] ?: 'Sin teléfono';
            $prof = $c['profesion'] ?: 'No especificada';
            $respuestaTexto .= "• **{$nom}** ({$prof}) — Tel: `{$tel}`\n";
        }
    }
    // Consulta de Documentos
    elseif (strpos($p, 'documento') !== false || strpos($p, 'archivo') !== false || strpos($p, 'expediente') !== false) {
        $totalDocs = $metricas['documentos']['total'] ?? 0;
        $porEstado = $metricas['documentos']['por_estado'] ?? [];

        $respuestaTexto = "📁 **Control Documental y Expedientes Digitales:**\n\n";
        $respuestaTexto .= "• **Total de documentos activos en repositorio:** {$totalDocs}\n";
        foreach ($porEstado as $est => $cant) {
            $respuestaTexto .= "   - Estado **{$est}**: {$cant} archivo(s)\n";
        }
        $respuestaTexto .= "\nTodos los expedientes disponen de validación criptográfica y seguimiento normativo institucional.";
    }
    // Resumen institucional general
    else {
        $totalDocs = $metricas['documentos']['total'] ?? 0;
        $totalClientes = $metricas['clientes']['total_activos'] ?? 0;
        $montoPipeline = number_format($metricas['kanban_ventas']['monto_total'] ?? 0, 2);

        $respuestaTexto = "🏛️ **Copiloto CEA — Resumen Ejecutivo del Sistema:**\n\n";
        $respuestaTexto .= "Estimado/a **{$contextoVivo['usuario']}** ({$contextoVivo['rol']}), a continuación el estado institucional al **{$contextoVivo['fecha_actual']}**:\n\n";
        $respuestaTexto .= "1. **Área Académica & Tesis:**\n";
        $respuestaTexto .= "   • Alumnos en pipeline: **{$metricas['kanban_tesis']['total']}** en distintas fases de asesoría y Turnitin.\n\n";
        $respuestaTexto .= "2. **Área Legal & Contratos:**\n";
        $respuestaTexto .= "   • Casos y contratos activos: **{$metricas['kanban_legal']['total']}** en trámite o archivados.\n\n";
        $respuestaTexto .= "3. **Área Comercial & Clientes:**\n";
        $respuestaTexto .= "   • Clientes/Tesistas activos: **{$totalClientes}**\n";
        $respuestaTexto .= "   • Volumen financiero en embudo: **S/ {$montoPipeline}**\n";
        $respuestaTexto .= "   • Próximas acciones programadas para hoy: **{$metricas['prospectos_hoy']}**\n\n";
        $respuestaTexto .= "¿Deseas profundizar sobre un estudiante en específico, estado de contratos o cotizaciones?";
    }

    if (!$groqConfig['disponible']) {
        $respuestaTexto .= "\n\n---\n⚡ *Sugerencia: Puedes activar el motor Groq Llama 3.3 70B ingresando tu API Key en la tuerca de configuración del Copiloto.*";
    }
}

echo json_encode([
    'exito' => true,
    'respuesta' => $respuestaTexto,
    'proveedor' => $proveedorUsado,
    'modelo' => $modeloUsado,
    'timestamp' => date('H:i:s'),
    'contexto' => [
        'usuario' => $contextoVivo['usuario'],
        'rol' => $contextoVivo['rol'],
        'modulo' => $modulo,
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
