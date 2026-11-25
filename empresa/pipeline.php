<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
require_once "../config/catalogos.php";

$rolCodigoActual = strtolower($_SESSION["rol"] ?? "");
$esAdminActual = ($rolCodigoActual === "administrador") || (isset($conexion) && usuarioTienePermiso($conexion, "usuarios.gestionar"));

$puedeVerVentas = $esAdminActual || (isset($conexion) && usuarioTienePermiso($conexion, "crm.gestionar"));
$puedeVerTesis  = $esAdminActual || (isset($conexion) && usuarioTienePermiso($conexion, "tesis.gestionar"));
$puedeVerLegal  = $esAdminActual || (isset($conexion) && usuarioTienePermiso($conexion, "legal.gestionar"));

if (!$puedeVerVentas && !$puedeVerTesis && !$puedeVerLegal) {
    http_response_code(403);
    exit("No tienes permisos suficientes para acceder a los tableros de pipeline.");
}

$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

// Definición oficial de los 3 Tableros Kanban requeridos
$tablerosDefinicion = [
    "ventas" => [
        "nombre" => "Tablero de ventas",
        "icono" => "📊",
        "descripcion" => "Gestión de oportunidades de venta, captación y matrículas.",
        "columnas" => [
            "Lead interesado",
            "Primer contacto hecho",
            "Cotización enviada",
            "Matrícula confirmada (Ganado)"
        ]
    ],
    "tesis" => [
        "nombre" => "Tablero de operaciones tesis",
        "icono" => "🎓",
        "descripcion" => "Flujo y progreso de tesistas desde asignación hasta la aprobación de tesis.",
        "columnas" => [
            "Alumno asignado a asesor",
            "Elección de tema",
            "Desarrollo de Cap. 1 y 2",
            "Revisión de Turnitin",
            "Simulación de sustentación",
            "Tesis aprobada"
        ]
    ],
    "legal" => [
        "nombre" => "Tablero de gestión legal contratos/convenios",
        "icono" => "⚖️",
        "descripcion" => "Proceso de elaboración, revisión y firma de contratos y convenios.",
        "columnas" => [
            "Solicitud recibida",
            "Redacción de contrato",
            "En revisión por el cliente",
            "Firmado y archivado"
        ]
    ]
];

// Selección inteligente de tab según permisos del usuario
if (!isset($_GET["tab"])) {
    if ($puedeVerTesis && !$puedeVerVentas) {
        $tabActivo = "tesis";
    } elseif ($puedeVerLegal && !$puedeVerVentas) {
        $tabActivo = "legal";
    } else {
        $tabActivo = "ventas";
    }
} else {
    $tabActivo = $_GET["tab"] ?? "ventas";
}

if (!isset($tablerosDefinicion[$tabActivo])) {
    $tabActivo = "ventas";
}

// Fallback de pestaña si solicita una sin permiso
if ($tabActivo === "ventas" && !$puedeVerVentas) {
    $tabActivo = $puedeVerTesis ? "tesis" : ($puedeVerLegal ? "legal" : "ventas");
} elseif ($tabActivo === "tesis" && !$puedeVerTesis) {
    $tabActivo = $puedeVerVentas ? "ventas" : ($puedeVerLegal ? "legal" : "tesis");
} elseif ($tabActivo === "legal" && !$puedeVerLegal) {
    $tabActivo = $puedeVerLegal ? "legal" : ($puedeVerVentas ? "ventas" : "tesis");
}

$infoTablero = $tablerosDefinicion[$tabActivo];
$columnas = $infoTablero["columnas"];

$mensaje = "";
$error = "";

// Manejo de acciones (POST / AJAX)
if ($_SERVER["REQUEST_METHOD"] === "POST" && $conexion) {
    $accion = $_POST["accion"] ?? "";

    if ($accion === "mover_tarjeta") {
        $idTarjeta = (int) ($_POST["id_tarjeta"] ?? 0);
        $nuevaColumna = trim($_POST["nueva_columna"] ?? "");

        if ($idTarjeta > 0 && in_array($nuevaColumna, $columnas, true)) {
            $stmt = $conexion->prepare("UPDATE kanban_tarjetas SET columna = :columna WHERE id_tarjeta = :id");
            $stmt->execute([":columna" => $nuevaColumna, ":id" => $idTarjeta]);
            
            if (!empty($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest") {
                header("Content-Type: application/json");
                echo json_encode(["success" => true, "columna" => $nuevaColumna]);
                exit;
            }
            $mensaje = "Tarjeta movida a '{$nuevaColumna}'.";
        }
    } elseif ($accion === "crear_tarjeta") {
        $titulo = trim($_POST["titulo"] ?? "");
        $columnaDestino = trim($_POST["columna"] ?? $columnas[0]);
        $tablero = trim($_POST["tablero"] ?? $tabActivo);
        $idCliente = (int) ($_POST["id_cliente"] ?? 0) ?: null;
        $monto = (float) ($_POST["monto"] ?? 0);
        $fechaLimite = trim($_POST["fecha_limite"] ?? "") ?: null;
        $etiquetas = trim($_POST["etiquetas"] ?? "") ?: null;
        $descripcion = trim($_POST["descripcion"] ?? "") ?: null;

        if ($titulo === "") {
            $error = "El título de la tarjeta es obligatorio.";
        } elseif (!in_array($columnaDestino, $tablerosDefinicion[$tablero]["columnas"] ?? [], true)) {
            $error = "Columna inválida para este tablero.";
        } else {
            $stmtInsert = $conexion->prepare(
                "INSERT INTO kanban_tarjetas (tablero, columna, titulo, id_cliente, monto, fecha_limite, etiquetas, descripcion, creado_por)
                 VALUES (:tablero, :columna, :titulo, :cliente, :monto, :fecha, :etiquetas, :descripcion, :usuario)"
            );
            $stmtInsert->execute([
                ":tablero" => $tablero,
                ":columna" => $columnaDestino,
                ":titulo" => $titulo,
                ":cliente" => $idCliente,
                ":monto" => $monto,
                ":fecha" => $fechaLimite,
                ":etiquetas" => $etiquetas,
                ":descripcion" => $descripcion,
                ":usuario" => (int) $_SESSION["id_usuario"]
            ]);
            $mensaje = "Tarjeta '{$titulo}' agregada al tablero.";
        }
    } elseif ($accion === "eliminar_tarjeta") {
        $idTarjeta = (int) ($_POST["id_tarjeta"] ?? 0);
        if ($idTarjeta > 0) {
            $conexion->prepare("UPDATE kanban_tarjetas SET estado = 0 WHERE id_tarjeta = :id")->execute([":id" => $idTarjeta]);
            $mensaje = "Tarjeta eliminada.";
        }
    }
}

// Cargar tarjetas del tablero activo
$tarjetasPorColumna = [];
foreach ($columnas as $col) {
    $tarjetasPorColumna[$col] = [];
}

$clientes = [];
$usuarios = [];

if ($conexion) {
    try {
        $stmtTarjetas = $conexion->prepare(
            "SELECT k.*, 
                    CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente_nombre,
                    c.telefono AS cliente_telefono,
                    c.profesion AS cliente_profesion,
                    CONCAT_WS(' ', u.nombres, u.apellidos) AS responsable_nombre
             FROM kanban_tarjetas k
             LEFT JOIN clientes c ON c.id_cliente = k.id_cliente
             LEFT JOIN usuarios u ON u.id_usuario = k.responsable_id
             WHERE k.tablero = :tablero AND k.estado = 1
             ORDER BY k.orden ASC, k.id_tarjeta DESC"
        );
        $stmtTarjetas->execute([":tablero" => $tabActivo]);
        $registros = $stmtTarjetas->fetchAll(PDO::FETCH_ASSOC);

        foreach ($registros as $t) {
            if (isset($tarjetasPorColumna[$t["columna"]])) {
                $tarjetasPorColumna[$t["columna"]][] = $t;
            }
        }

        $clientes = $conexion->query("SELECT id_cliente, nombres, apellidos, empresa, profesion FROM clientes WHERE estado = 'Activo' ORDER BY nombres")->fetchAll();
        $usuarios = $conexion->query("SELECT id_usuario, nombres, apellidos FROM usuarios WHERE estado = 1 ORDER BY nombres")->fetchAll();
    } catch (Throwable $e) {
        $error = "Error al cargar el tablero: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pipeline Kanban | <?= $escapar($infoTablero["nombre"]) ?></title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
    <style>
        .kanban-tab-selector {
            display: flex;
            gap: 10px;
            margin-bottom: 22px;
            flex-wrap: wrap;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
        }
        .kanban-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            text-decoration: none;
            color: #475569;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            transition: all 0.2s ease;
        }
        .kanban-tab-btn:hover {
            border-color: #93c5fd;
            color: #1d4ed8;
            transform: translateY(-1px);
        }
        .kanban-tab-btn.is-active {
            background: linear-gradient(135deg, #1d4ed8 0%, #0e1b3d 100%);
            color: #ffffff;
            border-color: transparent;
            box-shadow: 0 4px 14px rgba(29, 78, 216, 0.28);
        }
        .kanban-board-scroll {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(280px, 320px);
            gap: 18px;
            overflow-x: auto;
            padding-bottom: 18px;
            align-items: start;
        }
        .kanban-column-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 270px);
            box-shadow: 0 2px 8px rgba(15, 27, 61, 0.04);
        }
        .kanban-column-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #cbd5e1;
        }
        .kanban-column-header h4 {
            margin: 0;
            font-size: 0.88rem;
            font-weight: 800;
            color: #1e293b;
        }
        .kanban-counter {
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 0.75rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid #bfdbfe;
        }
        .kanban-cards-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            overflow-y: auto;
            padding-right: 2px;
            min-height: 120px;
        }
        .kanban-card-item {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 2px 8px rgba(15, 27, 61, 0.04);
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .kanban-card-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(15, 27, 61, 0.08);
            border-color: #93c5fd;
        }
        .kanban-card-tag {
            align-self: flex-start;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            background: #eff6ff;
            color: #1d4ed8;
            text-transform: uppercase;
        }
        .kanban-card-title {
            font-weight: 700;
            font-size: 0.92rem;
            color: #0f172a;
            margin: 0;
            line-height: 1.35;
        }
        .kanban-card-meta {
            font-size: 0.78rem;
            color: #64748b;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .kanban-card-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 6px;
            padding-top: 8px;
            border-top: 1px solid #f1f5f9;
        }
        .kanban-move-select {
            font-size: 0.74rem;
            padding: 3px 6px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #1e293b;
            cursor: pointer;
            max-width: 155px;
        }
        .btn-add-card {
            width: 100%;
            padding: 8px;
            background: transparent;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            color: #64748b;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            margin-top: 8px;
            transition: all 0.2s ease;
        }
        .btn-add-card:hover {
            background: #ffffff;
            border-color: #2563eb;
            color: #2563eb;
        }
    </style>
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner">
            <p class="eyebrow">Pipelines Operativos y Comerciales</p>
            <h1><?= $escapar($infoTablero["nombre"]) ?></h1>
            <p><?= $escapar($infoTablero["descripcion"]) ?></p>
        </div>

        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>

        <!-- Selector de los 3 Tableros Kanban -->
        <nav class="kanban-tab-selector" aria-label="Tableros Kanban">
            <?php if ($puedeVerVentas): ?>
            <a href="pipeline.php?tab=ventas" class="kanban-tab-btn <?= $tabActivo === 'ventas' ? 'is-active' : '' ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="5" height="16" rx="1"/></svg>
                <span>Tablero de ventas</span>
            </a>
            <?php endif; ?>
            <?php if ($puedeVerTesis): ?>
            <a href="pipeline.php?tab=tesis" class="kanban-tab-btn <?= $tabActivo === 'tesis' ? 'is-active' : '' ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                <span>Tablero de operaciones tesis</span>
            </a>
            <?php endif; ?>
            <?php if ($puedeVerLegal): ?>
            <a href="pipeline.php?tab=legal" class="kanban-tab-btn <?= $tabActivo === 'legal' ? 'is-active' : '' ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
                <span>Tablero de gestión legal contratos/convenios</span>
            </a>
            <?php endif; ?>
        </nav>

        <!-- Contenedor del Tablero Kanban -->
        <div class="kanban-board-scroll">
            <?php foreach ($columnas as $col): ?>
                <?php $tarjetasCol = $tarjetasPorColumna[$col] ?? []; ?>
                <div class="kanban-column-box" data-columna="<?= $escapar($col) ?>">
                    <div class="kanban-column-header">
                        <h4><?= $escapar($col) ?></h4>
                        <span class="kanban-counter"><?= count($tarjetasCol) ?></span>
                    </div>

                    <div class="kanban-cards-list">
                        <?php foreach ($tarjetasCol as $tarjeta): ?>
                            <div class="kanban-card-item" id="tarjeta-<?= (int) $tarjeta["id_tarjeta"] ?>">
                                <?php if (!empty($tarjeta["etiquetas"])): ?>
                                    <span class="kanban-card-tag"><?= $escapar($tarjeta["etiquetas"]) ?></span>
                                <?php endif; ?>

                                <h5 class="kanban-card-title"><?= $escapar($tarjeta["titulo"]) ?></h5>

                                <?php if (!empty($tarjeta["cliente_nombre"])): ?>
                                    <div class="kanban-card-meta">
                                        <strong>👤 <?= $escapar($tarjeta["cliente_nombre"]) ?></strong>
                                        <?php if (!empty($tarjeta["cliente_profesion"])): ?>
                                            <span>💼 <?= $escapar($tarjeta["cliente_profesion"]) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($tarjeta["cliente_telefono"])): ?>
                                            <span>📞 <?= $escapar($tarjeta["cliente_telefono"]) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($tarjeta["descripcion"])): ?>
                                    <p style="margin:2px 0; font-size:0.78rem; color:#475569;"><?= $escapar($tarjeta["descripcion"]) ?></p>
                                <?php endif; ?>

                                <?php if ((float)$tarjeta["monto"] > 0): ?>
                                    <div style="font-weight:800; color:#1d4ed8; font-size:0.85rem;">
                                        S/ <?= number_format((float)$tarjeta["monto"], 2) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="kanban-card-actions">
                                    <!-- Selector para mover de columna rápidamente -->
                                    <form method="post" style="display:flex; align-items:center; gap:4px;">
                                        <input type="hidden" name="accion" value="mover_tarjeta">
                                        <input type="hidden" name="id_tarjeta" value="<?= (int) $tarjeta["id_tarjeta"] ?>">
                                        <select name="nueva_columna" class="kanban-move-select" onchange="this.form.submit()" title="Mover a otra etapa">
                                            <option value="" disabled selected>Mover a...</option>
                                            <?php foreach ($columnas as $cDestino): ?>
                                                <?php if ($cDestino !== $col): ?>
                                                    <option value="<?= $escapar($cDestino) ?>"><?= $escapar($cDestino) ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>

                                    <!-- Eliminar tarjeta -->
                                    <form method="post" onsubmit="return confirm('¿Eliminar esta tarjeta del tablero?');" style="display:inline;">
                                        <input type="hidden" name="accion" value="eliminar_tarjeta">
                                        <input type="hidden" name="id_tarjeta" value="<?= (int) $tarjeta["id_tarjeta"] ?>">
                                        <button type="submit" style="background:none; border:none; color:#dc2626; cursor:pointer; font-size:0.75rem;" title="Eliminar">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($tarjetasCol)): ?>
                            <div style="text-align:center; padding:18px 8px; color:#94a3b8; font-size:0.78rem;">
                                Sin tarjetas en esta fase
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Botón para añadir tarjeta en esta columna -->
                    <button type="button" class="btn-add-card" onclick="abrirModalCrearTarjeta('<?= $escapar($col) ?>')">
                        + Nueva tarjeta
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>

<!-- Modal para Crear Tarjeta -->
<div id="modal-crear-tarjeta" style="display:none; position:fixed; inset:0; background:rgba(15,27,61,0.5); z-index:2000; align-items:center; justify-content:center;">
    <div style="background:#ffffff; border-radius:18px; width:min(520px, calc(100vw - 32px)); padding:24px; box-shadow:0 18px 48px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
            <h3 style="margin:0; font-size:1.1rem; color:#0e1b3d;">Nueva Tarjeta en: <span id="modal-col-nombre" style="color:#2563eb;"></span></h3>
            <button type="button" onclick="cerrarModalCrearTarjeta()" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <form method="post" class="data-form">
            <input type="hidden" name="accion" value="crear_tarjeta">
            <input type="hidden" name="tablero" value="<?= $escapar($tabActivo) ?>">
            <input type="hidden" id="modal-input-columna" name="columna" value="">

            <div class="campo">
                <label for="tarjeta-titulo">Título / Tarea / Asunto *</label>
                <input id="tarjeta-titulo" name="titulo" required placeholder="Ej.: Alumno Juan Pérez - Tesis Maestría">
            </div>

            <div class="form-grid">
                <div class="campo">
                    <label for="tarjeta-cliente">Cliente / Alumno Relacionado</label>
                    <select id="tarjeta-cliente" name="id_cliente">
                        <option value="">Sin cliente asociado</option>
                        <?php foreach ($clientes as $cli): ?>
                            <option value="<?= (int) $cli["id_cliente"] ?>">
                                <?= $escapar(trim($cli["nombres"] . " " . ($cli["apellidos"] ?? ""))) ?>
                                <?= $cli["profesion"] ? " · " . $escapar($cli["profesion"]) : "" ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label for="tarjeta-monto">Monto estimado (S/)</label>
                    <input id="tarjeta-monto" name="monto" type="number" step="0.01" min="0" placeholder="0.00" value="0.00">
                </div>
            </div>

            <div class="form-grid">
                <div class="campo">
                    <label for="tarjeta-etiquetas">Etiqueta / Categoría</label>
                    <input id="tarjeta-etiquetas" name="etiquetas" placeholder="Ej.: Turnitin, Pregrado, Convenio">
                </div>

                <div class="campo">
                    <label for="tarjeta-fecha">Fecha Límite</label>
                    <input id="tarjeta-fecha" name="fecha_limite" type="date" min="<?= date("Y-m-d") ?>">
                </div>
            </div>

            <div class="campo">
                <label for="tarjeta-desc">Detalles / Notas de avance</label>
                <textarea id="tarjeta-desc" name="descripcion" rows="3" placeholder="Observaciones sobre la etapa o requerimientos..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:12px;">
                <button type="button" class="btn-secondary" onclick="cerrarModalCrearTarjeta()">Cancelar</button>
                <button type="submit" class="btn-primary">Guardar Tarjeta</button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById("modal-crear-tarjeta");
const colNombre = document.getElementById("modal-col-nombre");
const inputCol = document.getElementById("modal-input-columna");

function abrirModalCrearTarjeta(columna) {
    inputCol.value = columna;
    colNombre.textContent = columna;
    modal.style.display = "flex";
}

function cerrarModalCrearTarjeta() {
    modal.style.display = "none";
}

window.addEventListener("click", (e) => {
    if (e.target === modal) cerrarModalCrearTarjeta();
});
</script>
</body>
</html>
