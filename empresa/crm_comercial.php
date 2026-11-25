<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
require_once "../config/catalogos.php";
require_once "../config/notificaciones.php";

if ($conexion) {
    exigirPermiso($conexion, "crm.gestionar");
}

$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

$mensaje = "";
$error = "";
$clienteSeleccionado = null;
$historialInteracciones = [];
$contactos = [];

// Métricas comerciales
$totalContactos = 0;
$totalInteracciones = 0;
$accionesPendientesHoy = 0;
$contactosConProfesion = 0;

if ($conexion) {
    try {
        // Manejo de acciones (POST)
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";

            if ($accion === "registrar_interaccion") {
                $idCliente = (int) ($_POST["id_cliente"] ?? 0);
                $tipo = trim($_POST["tipo_interaccion"] ?? "Llamada");
                $detalle = trim($_POST["detalle"] ?? "");
                $proximaAccion = trim($_POST["proxima_accion"] ?? "") ?: null;
                $resultado = trim($_POST["resultado"] ?? "") ?: null;
                $profesion = trim($_POST["profesion"] ?? "");

                if ($idCliente <= 0 || $detalle === "") {
                    throw new RuntimeException("Selecciona un cliente y describe el detalle de la interacción.");
                }

                if ($proximaAccion !== null && $proximaAccion < date("Y-m-d")) {
                    throw new RuntimeException("La fecha de próxima acción no puede ser anterior a hoy (" . date("d/m/Y") . ").");
                }

                // Guardar interacción en la base de datos
                $stmtInsert = $conexion->prepare(
                    "INSERT INTO crm_interacciones (id_cliente, usuario_id, tipo, detalle, proxima_accion, resultado)
                     VALUES (:cliente, :usuario, :tipo, :detalle, :proxima, :resultado)"
                );
                $stmtInsert->execute([
                    ":cliente" => $idCliente,
                    ":usuario" => (int) $_SESSION["id_usuario"],
                    ":tipo" => $tipo,
                    ":detalle" => $detalle,
                    ":proxima" => $proximaAccion,
                    ":resultado" => $resultado,
                ]);

                // Si se actualizó la profesión, guardarla en clientes
                if ($profesion !== "") {
                    $conexion->prepare("UPDATE clientes SET profesion = :prof WHERE id_cliente = :id")
                        ->execute([":prof" => $profesion, ":id" => $idCliente]);
                }

                $mensaje = "Interacción registrada correctamente en el historial del cliente.";
            } elseif ($accion === "guardar_contacto_crm") {
                $idCliente = (int) ($_POST["id_cliente"] ?? 0);
                $nombres = trim($_POST["nombres"] ?? "");
                $apellidos = trim($_POST["apellidos"] ?? "") ?: null;
                $telefonoRaw = trim($_POST["telefono"] ?? "");
                $telefonoLimpio = preg_replace('/\s+/', '', $telefonoRaw) ?: null;
                $correo = trim($_POST["correo"] ?? "") ?: null;
                $profesion = trim($_POST["profesion"] ?? "") ?: null;
                $empresa = trim($_POST["empresa"] ?? "") ?: null;
                $tipoDoc = trim($_POST["tipo_documento"] ?? "DNI");
                $documento = trim($_POST["documento"] ?? "");

                // Restricciones de validación
                if ($nombres === "" || !preg_match('/^[\p{L}]+(?: [\p{L}]+)*$/u', $nombres)) {
                    throw new RuntimeException("El nombre solo puede contener letras y espacios.");
                }
                if ($apellidos !== null && !preg_match('/^[\p{L}]+(?: [\p{L}]+)*$/u', $apellidos)) {
                    throw new RuntimeException("Los apellidos solo pueden contener letras y espacios.");
                }
                if ($telefonoLimpio !== null && !preg_match('/^9[0-9]{8}$/', $telefonoLimpio)) {
                    throw new RuntimeException("El celular debe tener 9 dígitos y comenzar con 9.");
                }

                if ($idCliente > 0) {
                    $stmtUpdate = $conexion->prepare(
                        "UPDATE clientes SET nombres = :nombres, apellidos = :apellidos, telefono = :telefono,
                         correo = :correo, profesion = :profesion, empresa = :empresa, tipo_documento = :tipo_doc,
                         documento = :documento WHERE id_cliente = :id"
                    );
                    $stmtUpdate->execute([
                        ":nombres" => $nombres,
                        ":apellidos" => $apellidos,
                        ":telefono" => $telefonoLimpio,
                        ":correo" => $correo,
                        ":profesion" => $profesion,
                        ":empresa" => $empresa,
                        ":tipo_doc" => $tipoDoc,
                        ":documento" => $documento,
                        ":id" => $idCliente
                    ]);
                    $mensaje = "Contacto actualizado en la base de datos comercial.";
                } else {
                    $stmtInsert = $conexion->prepare(
                        "INSERT INTO clientes (nombres, apellidos, telefono, correo, profesion, empresa, tipo_documento, documento, estado)
                         VALUES (:nombres, :apellidos, :telefono, :correo, :profesion, :empresa, :tipo_doc, :documento, 'Activo')"
                    );
                    $stmtInsert->execute([
                        ":nombres" => $nombres,
                        ":apellidos" => $apellidos,
                        ":telefono" => $telefonoLimpio,
                        ":correo" => $correo,
                        ":profesion" => $profesion,
                        ":empresa" => $empresa,
                        ":tipo_doc" => $tipoDoc,
                        ":documento" => $documento
                    ]);
                    $mensaje = "Nuevo contacto registrado en la base de datos del CRM.";
                }
            }
        }

        // Consultar métricas
        $totalContactos = (int) $conexion->query("SELECT COUNT(*) FROM clientes WHERE estado = 'Activo'")->fetchColumn();
        $totalInteracciones = (int) $conexion->query("SELECT COUNT(*) FROM crm_interacciones")->fetchColumn();
        $contactosConProfesion = (int) $conexion->query("SELECT COUNT(*) FROM clientes WHERE estado = 'Activo' AND profesion IS NOT NULL AND TRIM(profesion) != ''")->fetchColumn();
        $accionesPendientesHoy = (int) $conexion->query("SELECT COUNT(*) FROM crm_interacciones WHERE proxima_accion = CURDATE()")->fetchColumn();

        // Filtros de búsqueda
        $buscar = trim($_GET["buscar"] ?? "");
        $filtroProfesion = trim($_GET["profesion"] ?? "");

        $condiciones = ["c.estado = 'Activo'"];
        $params = [];

        if ($buscar !== "") {
            $condiciones[] = "(CONCAT_WS(' ', c.nombres, c.apellidos) LIKE :buscar OR c.correo LIKE :buscar OR c.telefono LIKE :buscar OR c.profesion LIKE :buscar OR c.empresa LIKE :buscar)";
            $params[":buscar"] = "%{$buscar}%";
        }
        if ($filtroProfesion !== "") {
            $condiciones[] = "c.profesion = :prof";
            $params[":prof"] = $filtroProfesion;
        }

        $where = "WHERE " . implode(" AND ", $condiciones);

        // Obtener contactos con conteo de interacciones y fecha de última interacción
        $stmtContactos = $conexion->prepare(
            "SELECT c.*, 
                    COUNT(i.id_interaccion) AS total_interacciones,
                    MAX(i.fecha_interaccion) AS ultima_interaccion,
                    (SELECT proxima_accion FROM crm_interacciones WHERE id_cliente = c.id_cliente AND proxima_accion >= CURDATE() ORDER BY proxima_accion ASC LIMIT 1) AS proxima_accion
             FROM clientes c
             LEFT JOIN crm_interacciones i ON i.id_cliente = c.id_cliente
             {$where}
             GROUP BY c.id_cliente
             ORDER BY ultima_interaccion DESC, c.id_cliente DESC"
        );
        $stmtContactos->execute($params);
        $contactos = $stmtContactos->fetchAll(PDO::FETCH_ASSOC);

        // Si se seleccionó ver el historial de un cliente específico
        $verClienteId = (int) ($_GET["cliente"] ?? 0);
        if ($verClienteId > 0) {
            $stmtCli = $conexion->prepare("SELECT * FROM clientes WHERE id_cliente = :id");
            $stmtCli->execute([":id" => $verClienteId]);
            $clienteSeleccionado = $stmtCli->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($clienteSeleccionado) {
                $stmtHist = $conexion->prepare(
                    "SELECT i.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario_nombre
                     FROM crm_interacciones i
                     INNER JOIN usuarios u ON u.id_usuario = i.usuario_id
                     WHERE i.id_cliente = :id
                     ORDER BY i.fecha_interaccion DESC, i.id_interaccion DESC"
                );
                $stmtHist->execute([":id" => $verClienteId]);
                $historialInteracciones = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Obtener lista de profesiones únicas para el filtro
        $profesionesList = $conexion->query(
            "SELECT DISTINCT profesion FROM clientes 
             WHERE profesion IS NOT NULL AND TRIM(profesion) != '' 
             ORDER BY profesion ASC"
        )->fetchAll(PDO::FETCH_COLUMN);

    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "Error en el servidor: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contactos y seguimiento | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
    <style>
        .crm-meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #334155;
        }
        .crm-interaction-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #2563eb;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 6px rgba(15, 27, 61, 0.04);
        }
        .crm-interaction-card.tipo-whatsapp { border-left-color: #22c55e; }
        .crm-interaction-card.tipo-correo { border-left-color: #3b82f6; }
        .crm-interaction-card.tipo-reunion { border-left-color: #8b5cf6; }
        .crm-interaction-card.tipo-cotizacion { border-left-color: #f59e0b; }
        .crm-badge-tipo {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            text-transform: uppercase;
        }
    </style>
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner">
            <p class="eyebrow">Área Comercial y Ventas</p>
            <h1>Contactos y seguimiento</h1>
            <p>Base de datos de contactos, correos, teléfonos, profesiones, seguimiento e historial completo de interacciones.</p>
        </div>

        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>

        <!-- Tarjetas de Métricas del CRM -->
        <section class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon blue">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= number_format($totalContactos) ?></span>
                    <span class="kpi-label">Contactos Registrados</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon green">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= number_format($totalInteracciones) ?></span>
                    <span class="kpi-label">Interacciones Guardadas</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon amber">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= number_format($contactosConProfesion) ?></span>
                    <span class="kpi-label">Con Profesión Registrada</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon purple">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= number_format($accionesPendientesHoy) ?></span>
                    <span class="kpi-label">Seguimientos para Hoy</span>
                </div>
            </div>
        </section>

        <!-- Detalle de Historial si hay un cliente seleccionado -->
        <?php if ($clienteSeleccionado): ?>
        <section class="crud-layout" style="grid-template-columns: 1.1fr 1.9fr; gap: 20px; margin-bottom: 24px;">
            <!-- Registrar Nueva Interacción con este cliente -->
            <div class="panel">
                <div class="panel-heading">
                    <h3>Nueva Interacción con: <?= $escapar(trim($clienteSeleccionado["nombres"] . " " . ($clienteSeleccionado["apellidos"] ?? ""))) ?></h3>
                    <a href="crm_comercial.php" class="text-link">Cerrar</a>
                </div>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="registrar_interaccion">
                    <input type="hidden" name="id_cliente" value="<?= (int) $clienteSeleccionado["id_cliente"] ?>">

                    <div class="form-grid">
                        <div class="campo">
                            <label for="tipo_interaccion">Canal / Tipo de Interacción *</label>
                            <select id="tipo_interaccion" name="tipo_interaccion" required>
                                <option value="Llamada telefónica">📞 Llamada telefónica</option>
                                <option value="Mensaje de WhatsApp">💬 Mensaje de WhatsApp</option>
                                <option value="Correo electrónico">✉️ Correo electrónico</option>
                                <option value="Reunión virtual / Asesoría">🤝 Reunión / Asesoría</option>
                                <option value="Cotización enviada">📑 Cotización / Propuesta</option>
                                <option value="Visita presencial">🏢 Visita presencial</option>
                            </select>
                        </div>

                        <div class="campo">
                            <label for="resultado">Resultado / Estado</label>
                            <select id="resultado" name="resultado">
                                <option value="Interesado - En evaluación">Interesado - En evaluación</option>
                                <option value="Cotización enviada">Cotización enviada</option>
                                <option value="Llamar nuevamente">Llamar nuevamente</option>
                                <option value="Matrícula / Contrato acordado">Matrícula / Contrato acordado</option>
                                <option value="No contesta / Reprogramar">No contesta / Reprogramar</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="campo">
                            <label for="profesion_update">Profesión / Grado del contacto</label>
                            <input id="profesion_update" name="profesion" value="<?= $escapar($clienteSeleccionado["profesion"] ?? "") ?>" placeholder="Ej.: Abogado, Bach. Administración">
                        </div>

                        <div class="campo">
                            <label for="proxima_accion">Próxima acción / Fecha</label>
                            <input type="date" id="proxima_accion" name="proxima_accion" min="<?= date("Y-m-d") ?>">
                        </div>
                    </div>

                    <div class="campo">
                        <label for="detalle">Resumen de la Interacción *</label>
                        <textarea id="detalle" name="detalle" rows="3" required placeholder="Temas tratados, acuerdos, dudas sobre su tesis o servicio..."></textarea>
                    </div>

                    <button type="submit" class="btn-primary form-submit">Guardar Interacción en Historial</button>
                </form>
            </div>

            <!-- Línea de Tiempo del Historial de Interacciones -->
            <div class="panel">
                <div class="panel-heading">
                    <h3>Historial Cronológico de Interacciones (<?= count($historialInteracciones) ?>)</h3>
                    <div style="font-size:0.8rem; color:#64748b;">
                        Tel: <?= $escapar($clienteSeleccionado["telefono"] ?? "No registrado") ?> · 
                        Mail: <?= $escapar($clienteSeleccionado["correo"] ?? "No registrado") ?>
                    </div>
                </div>

                <div class="quick-list" style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($historialInteracciones as $int): ?>
                        <?php
                        $claseTipo = "tipo-llamada";
                        $tipoLow = mb_strtolower($int["tipo"], 'UTF-8');
                        if (strpos($tipoLow, 'whatsapp') !== false) $claseTipo = "tipo-whatsapp";
                        elseif (strpos($tipoLow, 'correo') !== false) $claseTipo = "tipo-correo";
                        elseif (strpos($tipoLow, 'reun') !== false) $claseTipo = "tipo-reunion";
                        elseif (strpos($tipoLow, 'cotiz') !== false) $claseTipo = "tipo-cotizacion";
                        ?>
                        <div class="crm-interaction-card <?= $claseTipo ?>">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <span class="crm-badge-tipo" style="background:#eff6ff; color:#1d4ed8;">
                                    <?= $escapar($int["tipo"]) ?>
                                </span>
                                <small style="color:#64748b; font-weight:600;">
                                    <?= date("d/m/Y H:i", strtotime($int["fecha_interaccion"])) ?>
                                </small>
                            </div>
                            <p style="margin:4px 0 8px; font-size:0.88rem; color:#1e293b; line-height:1.4;">
                                <?= nl2br($escapar($int["detalle"])) ?>
                            </p>
                            <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.75rem; color:#64748b;">
                                <span>Por: <strong><?= $escapar($int["usuario_nombre"]) ?></strong></span>
                                <?php if (!empty($int["proxima_accion"])): ?>
                                    <span style="color:#2563eb; font-weight:700;">Próxima: <?= date("d/m/Y", strtotime($int["proxima_accion"])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($historialInteracciones)): ?>
                        <p class="empty-state">No hay interacciones registradas aún para este cliente.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Directorio Comercial y Base de Datos de Contactos -->
        <section class="panel">
            <div class="panel-heading" style="flex-wrap: wrap; gap: 12px;">
                <h3>Base de Datos de Contactos y Seguimiento (<?= count($contactos) ?>)</h3>
                
                <!-- Filtros -->
                <form method="get" class="search-form" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <input name="buscar" placeholder="Buscar por nombre, correo, teléfono..." value="<?= $escapar($_GET["buscar"] ?? "") ?>" style="min-width: 170px;">
                    <select name="profesion">
                        <option value="">Todas las profesiones</option>
                        <?php foreach ($profesionesList as $p): ?>
                            <option value="<?= $escapar($p) ?>" <?= ($filtroProfesion === $p) ? "selected" : "" ?>>
                                <?= $escapar($p) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-secondary btn-sm">Filtrar</button>
                    <button type="button" class="btn-primary btn-sm" onclick="document.getElementById('modal-nuevo-contacto').style.display='flex'">+ Nuevo Contacto</button>
                </form>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Contacto / Titular</th>
                            <th>Teléfono / WhatsApp</th>
                            <th>Correo Electrónico</th>
                            <th>Profesión / Ocupación</th>
                            <th>Institución / Empresa</th>
                            <th>Historial</th>
                            <th>Próxima Acción</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contactos as $c): ?>
                            <tr>
                                <td>
                                    <strong><?= $escapar(trim($c["nombres"] . " " . ($c["apellidos"] ?? ""))) ?></strong>
                                    <small><?= $escapar(($c["tipo_documento"] ?? "DOC") . ": " . ($c["documento"] ?? "-")) ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($c["telefono"])): ?>
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <span style="font-weight:700; color:#0f172a;"><?= $escapar($c["telefono"]) ?></span>
                                            <?php 
                                            $telLimpio = preg_replace('/\D/', '', $c["telefono"]);
                                            if (strlen($telLimpio) === 9): ?>
                                                <a href="https://wa.me/51<?= $telLimpio ?>" target="_blank" title="Enviar WhatsApp" style="color:#16a34a; text-decoration:none; font-size:1.1rem;">💬</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">Sin teléfono</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($c["correo"])): ?>
                                        <a href="mailto:<?= $escapar($c["correo"]) ?>" style="color:#2563eb; text-decoration:none; font-size:0.85rem;">
                                            <?= $escapar($c["correo"]) ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">Sin correo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($c["profesion"])): ?>
                                        <span class="crm-meta-chip" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;">
                                            💼 <?= $escapar($c["profesion"]) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:0.8rem;">No indicada</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $escapar($c["empresa"] ?: "-") ?></td>
                                <td>
                                    <span class="crm-meta-chip">
                                        <?= (int) $c["total_interacciones"] ?> registro(s)
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($c["proxima_accion"])): ?>
                                        <span style="color:#2563eb; font-weight:700; font-size:0.8rem;">
                                            <?= date("d/m/Y", strtotime($c["proxima_accion"])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:0.8rem;">Sin programar</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a class="btn-action btn-action-primary" href="crm_comercial.php?cliente=<?= (int) $c["id_cliente"] ?>" title="Ver historial de interacciones">
                                        Historial
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($contactos)): ?>
                            <tr><td colspan="8" class="empty-state">No se encontraron contactos comerciales que coincidan con la búsqueda.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- Modal para Registrar Nuevo Contacto Comercial -->
<div id="modal-nuevo-contacto" style="display:none; position:fixed; inset:0; background:rgba(15,27,61,0.5); z-index:2000; align-items:center; justify-content:center;">
    <div style="background:#ffffff; border-radius:18px; width:min(540px, calc(100vw - 32px)); padding:24px; box-shadow:0 18px 48px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
            <h3 style="margin:0; font-size:1.15rem; color:#0e1b3d;">Nuevo Contacto Comercial</h3>
            <button type="button" onclick="document.getElementById('modal-nuevo-contacto').style.display='none'" style="background:none; border:none; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <form method="post" class="data-form">
            <input type="hidden" name="accion" value="guardar_contacto_crm">

            <div class="form-grid">
                <div class="campo">
                    <label for="nombres_crm">Nombres *</label>
                    <input id="nombres_crm" name="nombres" pattern="[\p{L}]+(?: [\p{L}]+)*" required placeholder="Ej.: Claudia Lorena">
                </div>

                <div class="campo">
                    <label for="apellidos_crm">Apellidos</label>
                    <input id="apellidos_crm" name="apellidos" pattern="[\p{L}]+(?: [\p{L}]+)*" placeholder="Ej.: Morales Díaz">
                </div>
            </div>

            <div class="form-grid">
                <div class="campo">
                    <label for="profesion_crm">Profesión / Grado Académico</label>
                    <input id="profesion_crm" name="profesion" placeholder="Ej.: Abogado, Ingeniero, Docente">
                </div>

                <div class="campo">
                    <label for="telefono_crm">Celular (9 dígitos)</label>
                    <input id="telefono_crm" name="telefono" type="tel" inputmode="numeric" maxlength="11" placeholder="9XX XXX XXX">
                </div>
            </div>

            <div class="form-grid">
                <div class="campo">
                    <label for="correo_crm">Correo Electrónico</label>
                    <input id="correo_crm" name="correo" type="email" placeholder="correo@ejemplo.com">
                </div>

                <div class="campo">
                    <label for="empresa_crm">Universidad / Empresa</label>
                    <input id="empresa_crm" name="empresa" placeholder="Ej.: UNMSM, PUCP">
                </div>
            </div>

            <div class="form-grid">
                <div class="campo">
                    <label for="tipo_doc_crm">Tipo Documento</label>
                    <select id="tipo_doc_crm" name="tipo_documento">
                        <option value="DNI">DNI (8 dígitos)</option>
                        <option value="RUC">RUC (11 dígitos)</option>
                        <option value="CE">Carné de Extranjería</option>
                    </select>
                </div>

                <div class="campo">
                    <label for="doc_crm">Número de Documento</label>
                    <input id="doc_crm" name="documento" type="text" inputmode="numeric" placeholder="Solo números">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:14px;">
                <button type="button" class="btn-secondary" onclick="document.getElementById('modal-nuevo-contacto').style.display='none'">Cancelar</button>
                <button type="submit" class="btn-primary">Guardar Contacto</button>
            </div>
        </form>
    </div>
</div>

<script>
window.addEventListener("click", (e) => {
    const modal = document.getElementById("modal-nuevo-contacto");
    if (e.target === modal) modal.style.display = "none";
});
</script>
</body>
</html>
