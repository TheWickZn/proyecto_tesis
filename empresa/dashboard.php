<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
require_once "../config/catalogos.php";

$puedeGestionarDocumentos = $conexion && usuarioTienePermiso($conexion, "documentos.gestionar");
$puedeGestionarCRM = $conexion && usuarioTienePermiso($conexion, "crm.gestionar");
$puedeGestionarClientes = $puedeGestionarCRM;
$puedeGestionarUsuarios = $conexion && usuarioTienePermiso($conexion, "usuarios.gestionar");
$puedeGestionarTesis = $conexion && usuarioTienePermiso($conexion, "tesis.gestionar");
$puedeGestionarLegal = $conexion && usuarioTienePermiso($conexion, "legal.gestionar");

$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

// Métricas y datos para el Panel de Inicio
$totalDocumentos = 0;
$documentosMes = 0;
$documentosEnRevision = 0;
$totalClientes = 0;
$espacioBytes = 0;
$proximasAcciones = [];
$ultimosDocumentos = [];
$datosCategorias = [];
$datosMensuales = [];
$datosTendencia = [];

if ($conexion) {
    try {
        // 1. Total Documentos
        $stmt = $conexion->query("SELECT COUNT(*) FROM documentos_empresa WHERE estado = 1");
        $totalDocumentos = (int) $stmt->fetchColumn();

        // 2. Documentos este mes
        $stmt = $conexion->query(
            "SELECT COUNT(*) FROM documentos_empresa 
             WHERE estado = 1 
               AND MONTH(fecha_registro) = MONTH(CURRENT_DATE()) 
               AND YEAR(fecha_registro) = YEAR(CURRENT_DATE())"
        );
        $documentosMes = (int) $stmt->fetchColumn();

        // 3. Documentos en revisión o borrador
        $stmt = $conexion->query(
            "SELECT COUNT(*) FROM documentos_empresa 
             WHERE estado = 1 AND LOWER(COALESCE(estado_documento, 'Vigente')) IN ('en revisión', 'en revision', 'borrador', 'observado')"
        );
        $documentosEnRevision = (int) $stmt->fetchColumn();

        // 4. Total clientes activos
        $stmt = $conexion->query("SELECT COUNT(*) FROM clientes WHERE estado = 'Activo'");
        $totalClientes = (int) $stmt->fetchColumn();

        // 5. Espacio en disco utilizado
        $stmt = $conexion->query("SELECT COALESCE(SUM(tamano_bytes), 0) FROM documentos_empresa WHERE estado = 1");
        $espacioBytes = (int) $stmt->fetchColumn();

        // 6. Distribución de documentos por Categoría (para gráfico de Pie/Donut)
        $stmt = $conexion->query(
            "SELECT COALESCE(categoria, 'Sin categoría') AS categoria, COUNT(*) AS total 
             FROM documentos_empresa 
             WHERE estado = 1 
             GROUP BY categoria 
             ORDER BY total DESC 
             LIMIT 6"
        );
        $datosCategorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 7. Flujo mensual de documentos (para gráfico de barras - últimos 6 meses)
        $stmt = $conexion->query(
            "SELECT DATE_FORMAT(fecha_registro, '%Y-%m') AS periodo,
                    DATE_FORMAT(fecha_registro, '%b %Y') AS etiqueta,
                    COUNT(*) AS total
             FROM documentos_empresa
             WHERE estado = 1
               AND fecha_registro >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY periodo, etiqueta
             ORDER BY periodo ASC"
        );
        $datosMensuales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 8. Actividad de seguimiento y revisiones (para gráfico de líneas y puntos)
        $stmt = $conexion->query(
            "SELECT DATE(fecha_registro) AS fecha, COUNT(*) AS docs
             FROM documentos_empresa
             WHERE estado = 1 AND fecha_registro >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
             GROUP BY DATE(fecha_registro)
             ORDER BY fecha ASC"
        );
        $datosTendencia = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 9. Últimos documentos registrados
        $stmt = $conexion->query(
            "SELECT d.*, 
                    CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario,
                    CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente,
                    COALESCE(d.estado_documento, 'Vigente') AS estado_doc
             FROM documentos_empresa d
             INNER JOIN usuarios u ON u.id_usuario = d.subido_por
             LEFT JOIN clientes c ON c.id_cliente = d.id_cliente
             WHERE d.estado = 1
             ORDER BY d.fecha_registro DESC, d.id_documento DESC
             LIMIT 6"
        );
        $ultimosDocumentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 10. Próximas acciones / revisiones pendientes
        $stmt = $conexion->query(
            "SELECT p.id_prospecto, p.nombres, p.apellidos, p.proxima_accion, p.servicio, p.estado
             FROM prospectos p
             WHERE p.proxima_accion IS NOT NULL 
               AND p.proxima_accion >= CURDATE()
             ORDER BY p.proxima_accion ASC
             LIMIT 5"
        );
        $proximasAcciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
        // En caso de que alguna tabla no esté lista aún
    }
}

// Formatear tamaño de almacenamiento
$formatearTamano = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . " GB";
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . " MB";
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . " KB";
    }
    return $bytes . " B";
};

// Generar datos por defecto para gráficos si la base de datos está recién iniciada
if (empty($datosCategorias)) {
    $datosCategorias = [
        ["categoria" => "Tesis", "total" => 12],
        ["categoria" => "Proyecto de Tesis", "total" => 8],
        ["categoria" => "Informe de Avance", "total" => 6],
        ["categoria" => "Contrato", "total" => 5],
        ["categoria" => "Artículos", "total" => 4],
        ["categoria" => "Otros", "total" => 2],
    ];
}

if (empty($datosMensuales)) {
    $meses = ["Nov", "Dic", "Ene", "Feb", "Mar", "Abr"];
    foreach ($meses as $idx => $mes) {
        $datosMensuales[] = [
            "etiqueta" => $mes,
            "total" => [4, 7, 11, 15, 12, 18][$idx]
        ];
    }
}

$etiquetasCat = json_encode(array_column($datosCategorias, "categoria"));
$totalesCat = json_encode(array_map('intval', array_column($datosCategorias, "total")));

$etiquetasMes = json_encode(array_column($datosMensuales, "etiqueta"));
$totalesMes = json_encode(array_map('intval', array_column($datosMensuales, "total")));

// Días para el gráfico de líneas
$diasTendencia = [];
$valoresTendencia = [];
if (!empty($datosTendencia)) {
    foreach ($datosTendencia as $row) {
        $diasTendencia[] = date("d/m", strtotime($row["fecha"]));
        $valoresTendencia[] = (int) $row["docs"];
    }
} else {
    for ($i = 6; $i >= 0; $i--) {
        $diasTendencia[] = date("d/m", strtotime("-{$i} days"));
        $valoresTendencia[] = rand(1, 5);
    }
}
$jsonDiasTendencia = json_encode($diasTendencia);
$jsonValoresTendencia = json_encode($valoresTendencia);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio | Sistema de Gestión de Documentos</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
    <!-- Chart.js para diagramas y gráficos de pie, barras y puntos -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="dashboard-body">
    <div class="app-shell">
        <?php require "_navegacion.php"; ?>

        <main class="main-panel">
            <!-- Hero de Bienvenida — Inicio GROUP_CEA -->
            <section class="inicio-hero">
                <div class="inicio-hero-content">
                    <div class="hero-status-pill">
                        <span class="hero-pulse-dot"></span>
                        Sistema Activo
                        <span class="hero-divider">·</span>
                        <?= date('d M Y') ?>
                    </div>
                    <h1>Bienvenido, <span class="hero-name"><?= $escapar($_SESSION["nombres"] ?? $_SESSION["usuario"] ?? "Usuario") ?></span></h1>
                    <p>Panel de control de GROUP_CEA &mdash; gestión documental, expedientes, pipeline académico y comercial.</p>
                </div>
                <div class="inicio-hero-actions">
                    <?php if ($puedeGestionarDocumentos): ?>
                    <a href="documentos.php" class="btn-hero-primary">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Subir Documento
                    </a>
                    <?php endif; ?>
                    <?php
                    $tabPipelineRapido = "ventas";
                    if ($conexion && usuarioTienePermiso($conexion, "tesis.gestionar")) {
                        $tabPipelineRapido = "tesis";
                    } elseif ($conexion && usuarioTienePermiso($conexion, "legal.gestionar")) {
                        $tabPipelineRapido = "legal";
                    } elseif (!$puedeGestionarCRM) {
                        $tabPipelineRapido = "";
                    }
                    ?>
                    <?php if ($tabPipelineRapido !== ""): ?>
                    <a href="pipeline.php?tab=<?= $tabPipelineRapido ?>" class="btn-hero-secondary">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="5" height="13" rx="1"/><rect x="10.5" y="8" width="5" height="8" rx="1"/><rect x="18" y="5" width="5" height="11" rx="1"/></svg>
                        Ver Pipelines
                    </a>
                    <?php endif; ?>
                    <?php if ($puedeGestionarCRM): ?>
                    <a href="crm_comercial.php" class="btn-hero-secondary">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        CRM Comercial
                    </a>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Tarjetas de Métricas KPI -->
            <section class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon blue">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    </div>
                    <div class="kpi-data">
                        <span class="kpi-value"><?= number_format($totalDocumentos) ?></span>
                        <span class="kpi-label">Documentos Activos</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon green">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </div>
                    <div class="kpi-data">
                        <span class="kpi-value"><?= number_format($documentosMes) ?></span>
                        <span class="kpi-label">Subidos este mes</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon amber">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    </div>
                    <div class="kpi-data">
                        <span class="kpi-value"><?= number_format($documentosEnRevision) ?></span>
                        <span class="kpi-label">En Revisión / Borrador</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon purple">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    </div>
                    <div class="kpi-data">
                        <span class="kpi-value"><?= number_format($totalClientes) ?></span>
                        <span class="kpi-label">Clientes / Titulares</span>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path></svg>
                    </div>
                    <div class="kpi-data">
                        <span class="kpi-value"><?= $formatearTamano($espacioBytes) ?></span>
                        <span class="kpi-label">Almacenamiento Usado</span>
                    </div>
                </div>
            </section>

            <!-- Accesos Rápidos -->
            <section class="quick-actions-bar">
                <div class="quick-actions-heading">
                    <h3>Accesos Rápidos</h3>
                    <span style="font-size: 0.8rem; color: #64748b;">Acciones frecuentes del sistema</span>
                </div>
                <div class="quick-actions-flex">
                    <?php if ($puedeGestionarDocumentos): ?>
                    <a href="documentos.php" class="quick-action-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                        <span>Subir Documento</span>
                    </a>
                    <a href="documentos.php" class="quick-action-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <span>Explorar Archivos</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($puedeGestionarCRM): ?>
                    <a href="clientes.php" class="quick-action-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <span>Nuevo Cliente</span>
                    </a>
                    <a href="prospectos.php" class="quick-action-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
                        <span>Nuevo Prospecto</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($puedeGestionarDocumentos || $puedeGestionarTesis || $puedeGestionarLegal): ?>
                    <a href="expedientes_gestion.php" class="quick-action-link">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                        <span>Expedientes</span>
                    </a>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Sección de Diagramas y Gráficos (Pie, Barras, Líneas/Puntos) -->
            <section class="charts-grid">
                <!-- Gráfico 1: Pie / Donut de Categorías -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3>Documentos por Categoría</h3>
                        <span class="status-badge" style="background:#eff6ff; color:#1d4ed8; font-size:0.75rem;">Gráfico Circular</span>
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="chartCategorias"></canvas>
                    </div>
                </div>

                <!-- Gráfico 2: Barras Mensuales -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3>Flujo Mensual de Documentos</h3>
                        <span class="status-badge" style="background:#f0fdf4; color:#16a34a; font-size:0.75rem;">Gráfico de Barras</span>
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="chartMensual"></canvas>
                    </div>
                </div>

                <!-- Gráfico 3: Líneas y Puntos de Actividad Reciente -->
                <div class="chart-card">
                    <div class="chart-card-header">
                        <h3>Tendencia de Actividad Documental</h3>
                        <span class="status-badge" style="background:#faf5ff; color:#7c3aed; font-size:0.75rem;">Líneas y Puntos</span>
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="chartTendencia"></canvas>
                    </div>
                </div>
            </section>

            <!-- Grilla inferior: Documentos Recientes y Próximas Acciones -->
            <section class="crud-layout" style="grid-template-columns: 2fr 1.2fr; gap: 20px;">
                <!-- Últimos Documentos -->
                <div class="panel">
                    <div class="panel-heading">
                        <h3>Documentos Recientes</h3>
                        <a href="documentos.php" class="text-link" style="font-weight: 600;">Ver todos los documentos &rarr;</a>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Documento</th>
                                    <th>Categoría</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ultimosDocumentos as $doc): ?>
                                    <?php
                                    $estadoDoc = $doc["estado_doc"] ?? "Vigente";
                                    $claseBadge = "badge-vigente";
                                    if (stripos($estadoDoc, "revisi") !== false) $claseBadge = "badge-revision";
                                    elseif (stripos($estadoDoc, "borrador") !== false) $claseBadge = "badge-borrador";
                                    elseif (stripos($estadoDoc, "aprobado") !== false) $claseBadge = "badge-aprobado";
                                    elseif (stripos($estadoDoc, "archivado") !== false) $claseBadge = "badge-archivado";
                                    elseif (stripos($estadoDoc, "observado") !== false) $claseBadge = "badge-observado";
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= $escapar($doc["titulo"] ?: $doc["nombre_original"]) ?></strong>
                                            <small><?= $escapar($doc["cliente"] ? "Titular: " . $doc["cliente"] : $doc["nombre_original"]) ?></small>
                                        </td>
                                        <td>
                                            <span style="font-size:0.82rem; font-weight:600; color:#475569;"><?= $escapar($doc["categoria"]) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge-doc <?= $claseBadge ?>"><?= $escapar($estadoDoc) ?></span>
                                        </td>
                                        <td>
                                            <small><?= date("d/m/Y", strtotime($doc["fecha_registro"])) ?></small>
                                        </td>
                                        <td>
                                            <a href="documento_descargar.php?id=<?= (int) $doc["id_documento"] ?>" class="btn-action btn-action-primary" title="Descargar documento">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                                Descargar
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($ultimosDocumentos)): ?>
                                    <tr>
                                        <td colspan="5" class="empty-state">No se han registrado documentos aún.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Próximas Acciones / Calendario de Vencimientos -->
                <div class="panel">
                    <div class="panel-heading">
                        <h3>Agenda de Próximas Acciones</h3>
                        <a href="prospectos.php" class="text-link" style="font-weight:600;">Gestionar</a>
                    </div>
                    <div class="quick-list">
                        <?php foreach ($proximasAcciones as $accion): ?>
                            <div style="border-left: 3px solid #2563eb; padding-left: 12px; margin-bottom: 12px;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong><?= $escapar(trim($accion["nombres"] . " " . ($accion["apellidos"] ?? ""))) ?></strong>
                                    <span style="font-size:0.75rem; font-weight:700; color:#1d4ed8; background:#eff6ff; padding:2px 8px; border-radius:6px;">
                                        <?= date("d/m/Y", strtotime($accion["proxima_accion"])) ?>
                                    </span>
                                </div>
                                <p style="margin:4px 0; font-size:0.84rem; color:#475569;">
                                    <?= $escapar($accion["servicio"] ?: "Seguimiento programado") ?>
                                </p>
                                <small style="color:#64748b;">Estado: <?= $escapar($accion["estado"]) ?></small>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($proximasAcciones)): ?>
                            <p class="empty-state">No hay acciones pendientes en los próximos días.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Script de inicialización de Chart.js -->
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        // Paleta de colores profesionales
        const colores = ['#1d4ed8', '#2563eb', '#0284c7', '#059669', '#d97706', '#7c3aed', '#ec4899', '#64748b'];

        // 1. Gráfico Circular (Pie / Donut)
        const canvasCat = document.getElementById("chartCategorias");
        if (canvasCat && typeof Chart !== "undefined") {
            new Chart(canvasCat, {
                type: "doughnut",
                data: {
                    labels: <?= $etiquetasCat ?>,
                    datasets: [{
                        data: <?= $totalesCat ?>,
                        backgroundColor: colores.slice(0, <?= count($datosCategorias) ?>),
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: "bottom",
                            labels: { boxWidth: 12, font: { size: 11, family: "'Inter', sans-serif" } }
                        }
                    },
                    cutout: "60%"
                }
            });
        }

        // 2. Gráfico de Barras (Flujo mensual)
        const canvasMes = document.getElementById("chartMensual");
        if (canvasMes && typeof Chart !== "undefined") {
            new Chart(canvasMes, {
                type: "bar",
                data: {
                    labels: <?= $etiquetasMes ?>,
                    datasets: [{
                        label: "Documentos",
                        data: <?= $totalesMes ?>,
                        backgroundColor: "rgba(37, 99, 235, 0.8)",
                        borderRadius: 8,
                        hoverBackgroundColor: "#2563eb"
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        },
                        x: {
                            grid: { display: false }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }

        // 3. Gráfico de Líneas y Puntos (Tendencia de actividad)
        const canvasTendencia = document.getElementById("chartTendencia");
        if (canvasTendencia && typeof Chart !== "undefined") {
            new Chart(canvasTendencia, {
                type: "line",
                data: {
                    labels: <?= $jsonDiasTendencia ?>,
                    datasets: [{
                        label: "Actividad",
                        data: <?= $jsonValoresTendencia ?>,
                        borderColor: "#2563eb",
                        backgroundColor: "rgba(37, 99, 235, 0.08)",
                        pointBackgroundColor: "#2563eb",
                        pointBorderColor: "#ffffff",
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        tension: 0.35,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        },
                        x: {
                            grid: { display: false }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
