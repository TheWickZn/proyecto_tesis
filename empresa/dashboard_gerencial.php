<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";

if ($conexion) {
    exigirPermiso($conexion, "dashboard.gerencial");
}
$puedeGestionarAcademia = $conexion && usuarioTienePermiso($conexion, "tesis.gestionar");
$puedeVerVentas = $conexion && usuarioTienePermiso($conexion, "crm.gestionar");
$puedeVerContratos = $conexion && usuarioTieneAlgunPermiso(
    $conexion,
    ["contratos.ver", "contratos.gestionar", "finanzas.ver", "finanzas.gestionar", "legal.gestionar"]
);

$error = "";
$mensaje = "";
$campanaEditar = null;
$kpis = [
    "ventas_mes" => 0,
    "clientes_nuevos" => 0,
    "prospectos" => 0,
    "proyectos_activos" => 0,
    "por_cobrar" => 0,
    "conversion" => 0,
    "ingresos_mes" => 0,
    "cuotas_vencidas" => 0,
    "monto_vencido" => 0,
    "cuotas_proximas" => 0,
    "entregas_proximas" => 0,
    "proyectos_retrasados" => 0,
    "seguimientos_vencidos" => 0,
    "comisiones_mes" => 0,
    "comisiones_pendientes" => 0,
    "cantidad_comisiones_pendientes" => 0,
    "contratos_sin_responsable" => 0,
];
$ventasVendedor = [];
$ventasServicio = [];
$prospectosEstado = [];
$productividadAsesores = [];
$campanas = [];
$tasasComision = [];
$comisionesPorVendedor = [];
$comisionesPendientes = [];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");
$moneda = static fn ($valor): string => "S/ " . number_format((float) ($valor ?? 0), 2);
$fechaValida = static function (string $fecha): bool {
    $validada = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);
    $errores = DateTimeImmutable::getLastErrors();
    return $validada !== false
        && ($errores === false || ($errores["warning_count"] === 0 && $errores["error_count"] === 0))
        && $validada->format("Y-m-d") === $fecha;
};

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar_campana") {
                $idCampana = (int) ($_POST["id_campana"] ?? 0);
                $nombre = trim($_POST["nombre"] ?? "");
                $canal = trim($_POST["canal"] ?? "");
                $inversion = filter_var($_POST["inversion"] ?? "0", FILTER_VALIDATE_FLOAT);
                $fechaInicio = trim($_POST["fecha_inicio"] ?? "");
                $fechaFin = trim($_POST["fecha_fin"] ?? "") ?: null;
                $estado = trim($_POST["estado"] ?? "Activa");
                $canales = ["Facebook", "TikTok", "WhatsApp", "Instagram", "Google", "Referido", "Otro"];
                if ($nombre === "" || strlen($nombre) > 150 || !in_array($canal, $canales, true)
                    || $inversion === false || $inversion < 0 || !$fechaValida($fechaInicio)
                    || ($fechaFin !== null && (!$fechaValida($fechaFin) || $fechaFin < $fechaInicio))
                    || !in_array($estado, ["Activa", "Pausada", "Finalizada"], true)) {
                    throw new RuntimeException("Revisa el nombre, canal, inversión, fechas y estado de la campaña.");
                }
                $datosCampana = [
                    ":nombre" => $nombre,
                    ":canal" => $canal,
                    ":inversion" => $inversion,
                    ":inicio" => $fechaInicio,
                    ":fin" => $fechaFin,
                    ":estado" => $estado,
                ];
                if ($idCampana > 0) {
                    $datosCampana[":id"] = $idCampana;
                    $conexion->prepare(
                        "UPDATE campanas_marketing
                         SET nombre = :nombre, canal = :canal, inversion = :inversion,
                             fecha_inicio = :inicio, fecha_fin = :fin, estado = :estado
                         WHERE id_campana = :id"
                    )->execute($datosCampana);
                    $mensaje = "Campaña actualizada.";
                } else {
                    $datosCampana[":usuario"] = (int) $_SESSION["id_usuario"];
                    $conexion->prepare(
                        "INSERT INTO campanas_marketing
                         (nombre, canal, inversion, fecha_inicio, fecha_fin, estado, creado_por)
                         VALUES (:nombre, :canal, :inversion, :inicio, :fin, :estado, :usuario)"
                    )->execute($datosCampana);
                    $mensaje = "Campaña registrada. Usa el enlace de captación para asociar prospectos.";
                }
            } elseif ($accion === "guardar_comision") {
                $idUsuarioComision = (int) ($_POST["id_usuario"] ?? 0);
                $porcentaje = filter_var($_POST["comision_porcentaje"] ?? null, FILTER_VALIDATE_FLOAT);
                if ($idUsuarioComision <= 0 || $porcentaje === false || $porcentaje < 0 || $porcentaje > 100) {
                    throw new RuntimeException("Indica un vendedor activo y una comisión entre 0% y 100%.");
                }
                $stmtVendedor = $conexion->prepare(
                    "SELECT 1 FROM usuarios u INNER JOIN roles r ON r.id_rol = u.id_rol
                     WHERE u.id_usuario = :id AND u.estado = 1 AND r.codigo IN ('ventas', 'vendedor')"
                );
                $stmtVendedor->execute([":id" => $idUsuarioComision]);
                if (!$stmtVendedor->fetchColumn()) {
                    throw new RuntimeException("El usuario seleccionado no es un vendedor activo.");
                }
                $conexion->prepare(
                    "UPDATE usuarios SET comision_porcentaje = :porcentaje WHERE id_usuario = :id"
                )->execute([
                    ":porcentaje" => number_format((float) $porcentaje, 2, ".", ""),
                    ":id" => $idUsuarioComision,
                ]);
                $mensaje = "Tasa actualizada. Se aplicará a los pagos futuros del responsable.";
            } elseif ($accion === "liquidar_comision") {
                $idComision = (int) ($_POST["id_comision"] ?? 0);
                if ($idComision <= 0) {
                    throw new RuntimeException("Selecciona una comisión pendiente válida.");
                }
                $conexion->beginTransaction();
                $stmtComision = $conexion->prepare(
                    "SELECT c.id_comision, c.id_usuario, c.monto_comision,
                            CONCAT_WS(' ', u.nombres, u.apellidos) AS vendedor
                     FROM comisiones_ventas c
                     INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
                     WHERE c.id_comision = :id AND c.estado = 'Pendiente'
                     FOR UPDATE"
                );
                $stmtComision->execute([":id" => $idComision]);
                $comision = $stmtComision->fetch();
                if (!$comision) {
                    throw new RuntimeException("La comisión ya fue liquidada o no existe.");
                }
                $conexion->prepare(
                    "INSERT INTO movimientos_financieros
                     (tipo, categoria, concepto, persona_proveedor, tipo_persona, monto, fecha,
                      metodo_pago, responsable_id, observaciones)
                     VALUES ('Egreso', 'Comisiones', :concepto, :persona, NULL, :monto, CURDATE(),
                             'Otro', :responsable, :observaciones)"
                )->execute([
                    ":concepto" => "Liquidación de comisión · " . $comision["vendedor"],
                    ":persona" => trim($comision["vendedor"]),
                    ":monto" => $comision["monto_comision"],
                    ":responsable" => (int) $_SESSION["id_usuario"],
                    ":observaciones" => "Liquidación automática de comisión por pago recibido.",
                ]);
                $idMovimiento = (int) $conexion->lastInsertId();
                $actualizarComision = $conexion->prepare(
                    "UPDATE comisiones_ventas
                     SET estado = 'Liquidada', fecha_liquidacion = CURRENT_TIMESTAMP,
                         liquidada_por = :usuario, id_movimiento = :movimiento
                     WHERE id_comision = :id AND estado = 'Pendiente'"
                );
                $actualizarComision->execute([
                    ":usuario" => (int) $_SESSION["id_usuario"],
                    ":movimiento" => $idMovimiento,
                    ":id" => $idComision,
                ]);
                if ($actualizarComision->rowCount() !== 1) {
                    throw new RuntimeException("La comisión cambió de estado. No se pudo confirmar la liquidación.");
                }
                $conexion->commit();
                $mensaje = "Comisión liquidada y registrada como egreso financiero.";
            } else {
                throw new RuntimeException("La acción solicitada no es válida.");
            }
        }

        if (isset($_GET["editar_campana"])) {
            $stmtCampana = $conexion->prepare("SELECT * FROM campanas_marketing WHERE id_campana = :id");
            $stmtCampana->execute([":id" => (int) $_GET["editar_campana"]]);
            $campanaEditar = $stmtCampana->fetch() ?: null;
        }

        $kpis["ventas_mes"] = (float) $conexion->query(
            "SELECT COALESCE(SUM(monto), 0) FROM ventas
             WHERE estado IN ('Confirmada', 'Pagada')
               AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND fecha < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
        )->fetchColumn();
        $kpis["clientes_nuevos"] = (int) $conexion->query(
            "SELECT COUNT(*) FROM clientes WHERE fecha_registro >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        )->fetchColumn();
        $kpis["prospectos"] = (int) $conexion->query("SELECT COUNT(*) FROM prospectos")->fetchColumn();
        $kpis["proyectos_activos"] = (int) $conexion->query(
            "SELECT COUNT(*) FROM proyectos_tesis WHERE estado NOT IN ('Finalizado', 'Cancelado')"
        )->fetchColumn();
        $kpis["por_cobrar"] = (float) $conexion->query(
            "SELECT COALESCE(SUM(c.monto_total - COALESCE(p.total_pagado, 0)), 0)
             FROM contratos_academicos c
             LEFT JOIN (
                 SELECT id_contrato, SUM(monto) AS total_pagado
                 FROM pagos_contrato_academico WHERE estado = 'Registrado' GROUP BY id_contrato
             ) p ON p.id_contrato = c.id_contrato
             WHERE c.estado <> 'Cancelado'"
        )->fetchColumn();
        $kpis["contratos_sin_responsable"] = (int) $conexion->query(
            "SELECT COUNT(*) FROM contratos_academicos
             WHERE estado <> 'Cancelado' AND responsable_id IS NULL"
        )->fetchColumn();
        $conversion = $conexion->query(
            "SELECT COUNT(*), COALESCE(SUM(estado = 'Ganado'), 0) FROM prospectos"
        )->fetch(PDO::FETCH_NUM);
        $kpis["conversion"] = (int) ($conversion[0] ?? 0) > 0
            ? ((float) $conversion[1] / (int) $conversion[0]) * 100
            : 0;
        $kpis["ingresos_mes"] = (float) $conexion->query(
            "SELECT COALESCE(SUM(monto), 0) FROM movimientos_financieros
             WHERE tipo = 'Ingreso' AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND fecha < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
        )->fetchColumn();
        $kpis["cuotas_vencidas"] = (int) $conexion->query(
            "SELECT COUNT(*) FROM cuotas_contrato_academico q
             INNER JOIN contratos_academicos c ON c.id_contrato = q.id_contrato
             WHERE c.estado <> 'Cancelado' AND q.fecha_vencimiento < CURDATE()
               AND q.monto > COALESCE((SELECT SUM(p.monto) FROM pagos_contrato_academico p
                                       WHERE p.id_cuota = q.id_cuota AND p.estado = 'Registrado'), 0)"
        )->fetchColumn();
        $kpis["monto_vencido"] = (float) $conexion->query(
            "SELECT COALESCE(SUM(q.monto - COALESCE((SELECT SUM(p.monto) FROM pagos_contrato_academico p
                                                     WHERE p.id_cuota = q.id_cuota AND p.estado = 'Registrado'), 0)), 0)
             FROM cuotas_contrato_academico q
             INNER JOIN contratos_academicos c ON c.id_contrato = q.id_contrato
             WHERE c.estado <> 'Cancelado' AND q.fecha_vencimiento < CURDATE()
               AND q.monto > COALESCE((SELECT SUM(p.monto) FROM pagos_contrato_academico p
                                       WHERE p.id_cuota = q.id_cuota AND p.estado = 'Registrado'), 0)"
        )->fetchColumn();
        $kpis["cuotas_proximas"] = (int) $conexion->query(
            "SELECT COUNT(*) FROM cuotas_contrato_academico q
             INNER JOIN contratos_academicos c ON c.id_contrato = q.id_contrato
             WHERE c.estado <> 'Cancelado' AND q.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               AND q.monto > COALESCE((SELECT SUM(p.monto) FROM pagos_contrato_academico p
                                       WHERE p.id_cuota = q.id_cuota AND p.estado = 'Registrado'), 0)"
        )->fetchColumn();
        $kpis["entregas_proximas"] = (int) $conexion->query(
            "SELECT COUNT(*) FROM coordinacion_hitos
             WHERE estado <> 'Finalizado' AND fecha_objetivo BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
        )->fetchColumn();
        $kpis["proyectos_retrasados"] = (int) $conexion->query(
            "SELECT COUNT(DISTINCT id_tesis) FROM coordinacion_hitos
             WHERE estado <> 'Finalizado' AND fecha_objetivo < CURDATE()"
        )->fetchColumn();
        $kpis["seguimientos_vencidos"] = (int) $conexion->query(
            "SELECT COUNT(*) FROM prospectos
             WHERE proxima_accion < CURDATE() AND estado NOT IN ('Ganado', 'Perdido')"
        )->fetchColumn();

        $ventasVendedor = $conexion->query(
            "SELECT COALESCE(CONCAT_WS(' ', u.nombres, u.apellidos), 'Sin asignar') AS vendedor,
                    SUM(v.monto) AS total, COUNT(*) AS cantidad
             FROM ventas v LEFT JOIN usuarios u ON u.id_usuario = v.responsable_id
             WHERE v.estado IN ('Confirmada', 'Pagada')
               AND v.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND v.fecha < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
             GROUP BY v.responsable_id, u.nombres, u.apellidos ORDER BY total DESC LIMIT 8"
        )->fetchAll();
        $ventasServicio = $conexion->query(
            "SELECT COALESCE(s.nombre, 'Sin servicio') AS servicio, SUM(v.monto) AS total, COUNT(*) AS cantidad
             FROM ventas v LEFT JOIN servicios s ON s.id_servicio = v.id_servicio
             WHERE v.estado IN ('Confirmada', 'Pagada')
               AND v.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND v.fecha < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
             GROUP BY v.id_servicio, s.nombre ORDER BY total DESC LIMIT 8"
        )->fetchAll();
        $prospectosEstado = $conexion->query(
            "SELECT estado, COUNT(*) AS cantidad FROM prospectos GROUP BY estado ORDER BY cantidad DESC"
        )->fetchAll();
        $productividadAsesores = $conexion->query(
            "SELECT CONCAT_WS(' ', a.nombres, a.apellidos) AS asesor,
                    COUNT(d.id_entrega) AS entregas_aprobadas
             FROM asesores_academicos a
             LEFT JOIN entregas_asesores d ON d.id_asesor = a.id_asesor
                AND d.estado = 'Aprobado'
                AND d.fecha_revision >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                AND d.fecha_revision < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
             GROUP BY a.id_asesor ORDER BY entregas_aprobadas DESC, asesor LIMIT 8"
        )->fetchAll();
        $kpis["comisiones_mes"] = (float) $conexion->query(
            "SELECT COALESCE(SUM(monto_comision), 0) FROM comisiones_ventas
             WHERE fecha_pago >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND fecha_pago < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
        )->fetchColumn();
        $stmtPendientesComision = $conexion->query(
            "SELECT COUNT(*), COALESCE(SUM(monto_comision), 0)
             FROM comisiones_ventas WHERE estado = 'Pendiente'"
        );
        $resumenPendientesComision = $stmtPendientesComision->fetch(PDO::FETCH_NUM);
        $kpis["cantidad_comisiones_pendientes"] = (int) ($resumenPendientesComision[0] ?? 0);
        $kpis["comisiones_pendientes"] = (float) ($resumenPendientesComision[1] ?? 0);
        $tasasComision = $conexion->query(
            "SELECT u.id_usuario, u.nombres, u.apellidos, u.comision_porcentaje
             FROM usuarios u INNER JOIN roles r ON r.id_rol = u.id_rol
             WHERE u.estado = 1 AND r.codigo IN ('ventas', 'vendedor')
             ORDER BY u.nombres, u.apellidos"
        )->fetchAll();
        $comisionesPorVendedor = $conexion->query(
            "SELECT CONCAT_WS(' ', u.nombres, u.apellidos) AS vendedor,
                    SUM(c.monto_comision) AS total,
                    SUM(CASE WHEN c.estado = 'Pendiente' THEN c.monto_comision ELSE 0 END) AS pendientes
             FROM comisiones_ventas c INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
             WHERE c.fecha_pago >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND c.fecha_pago < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
             GROUP BY c.id_usuario, u.nombres, u.apellidos
             ORDER BY total DESC"
        )->fetchAll();
        $comisionesPendientes = $conexion->query(
            "SELECT c.id_comision, c.monto_base, c.porcentaje, c.monto_comision, c.fecha_pago,
                    CONCAT_WS(' ', u.nombres, u.apellidos) AS vendedor, ct.numero_contrato
             FROM comisiones_ventas c
             INNER JOIN usuarios u ON u.id_usuario = c.id_usuario
             INNER JOIN pagos_contrato_academico p ON p.id_pago = c.id_pago
             INNER JOIN contratos_academicos ct ON ct.id_contrato = p.id_contrato
             WHERE c.estado = 'Pendiente'
             ORDER BY c.fecha_pago, c.id_comision
             LIMIT 100"
        )->fetchAll();
        $campanas = $conexion->query(
            "SELECT c.id_campana, c.nombre, c.canal, c.inversion, c.fecha_inicio, c.fecha_fin, c.estado,
                    COUNT(p.id_prospecto) AS leads,
                    COALESCE(SUM(p.id_cliente IS NOT NULL OR p.estado = 'Ganado'), 0) AS clientes_generados
             FROM campanas_marketing c
             LEFT JOIN prospectos p ON p.id_campana = c.id_campana
             GROUP BY c.id_campana, c.nombre, c.canal, c.inversion, c.fecha_inicio, c.fecha_fin, c.estado
             ORDER BY c.fecha_inicio DESC, c.id_campana DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : "No se pudieron cargar todas las métricas. Verifica que las migraciones de pagos, coordinación y campañas estén aplicadas.";
        if (!($e instanceof RuntimeException)) {
            error_log("Error en el dashboard gerencial: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Gerencial | CEA Group</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
    <style>
        .manager-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px; }
        .manager-kpi { border:1px solid #e2e8f0; border-radius:14px; padding:16px; background:#fff; }
        .manager-kpi span { display:block; color:#64748b; font-size:.84rem; }
        .manager-kpi strong { display:block; margin-top:8px; color:#0f172a; font-size:1.55rem; }
        .manager-columns { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; }
        .manager-panel { min-width:0; }
        .manager-table-wrap { overflow-x:auto; }
        .manager-alert { border-left:4px solid #dc2626; padding:10px 12px; background:#fef2f2; border-radius:8px; margin:8px 0; }
        .manager-alert.warning { border-color:#d97706; background:#fffbeb; }
        .campaign-form { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; align-items:end; }
        .muted { color:#64748b; }
    </style>
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner">
            <p class="eyebrow">GROUP_CEA · Gerencia</p>
            <h1>Dashboard Gerencial</h1>
            <p>Indicadores calculados desde ventas, CRM, proyectos, pagos y campañas registrados en el sistema.</p>
        </div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>

        <section class="manager-kpis">
            <article class="manager-kpi"><span>Ventas del mes</span><strong><?= $moneda($kpis["ventas_mes"]) ?></strong></article>
            <article class="manager-kpi"><span>Clientes nuevos</span><strong><?= number_format($kpis["clientes_nuevos"]) ?></strong></article>
            <article class="manager-kpi"><span>Prospectos</span><strong><?= number_format($kpis["prospectos"]) ?></strong></article>
            <article class="manager-kpi"><span>Proyectos activos</span><strong><?= number_format($kpis["proyectos_activos"]) ?></strong></article>
            <article class="manager-kpi"><span>Por cobrar</span><strong><?= $moneda($kpis["por_cobrar"]) ?></strong></article>
            <article class="manager-kpi"><span>Conversión CRM</span><strong><?= number_format($kpis["conversion"], 1) ?>%</strong></article>
        </section>

        <section class="panel">
            <div class="panel-heading"><h2>Alertas y sugerencias</h2></div>
            <?php if ($kpis["cuotas_vencidas"]): ?>
                <p class="manager-alert"><strong><?= number_format($kpis["cuotas_vencidas"]) ?> cuotas vencidas</strong> por <?= $moneda($kpis["monto_vencido"]) ?>. Prioriza el seguimiento de cobranza.</p>
            <?php endif; ?>
            <?php if ($kpis["cuotas_proximas"]): ?>
                <p class="manager-alert warning"><strong><?= number_format($kpis["cuotas_proximas"]) ?> cuotas vencen en los próximos 7 días.</strong> Envía recordatorios preventivos.</p>
            <?php endif; ?>
            <?php if ($kpis["proyectos_retrasados"]): ?>
                <p class="manager-alert"><strong><?= number_format($kpis["proyectos_retrasados"]) ?> proyectos tienen etapas retrasadas.</strong> Revisa su cronograma y carga del asesor.</p>
            <?php endif; ?>
            <?php if ($kpis["seguimientos_vencidos"]): ?>
                <p class="manager-alert warning"><strong><?= number_format($kpis["seguimientos_vencidos"]) ?> seguimientos comerciales vencidos.</strong> Contacta esos prospectos para evitar perder oportunidades.</p>
            <?php endif; ?>
            <?php if ($kpis["contratos_sin_responsable"]): ?>
                <p class="manager-alert warning"><strong><?= number_format($kpis["contratos_sin_responsable"]) ?> contratos sin responsable comercial.</strong> Asígnales un vendedor para calcular comisiones en pagos futuros.</p>
            <?php endif; ?>
            <?php if (!$kpis["cuotas_vencidas"] && !$kpis["cuotas_proximas"] && !$kpis["proyectos_retrasados"] && !$kpis["seguimientos_vencidos"] && !$kpis["contratos_sin_responsable"]): ?>
                <p class="empty-state">No hay alertas operativas vencidas con los datos actuales.</p>
            <?php endif; ?>
        </section>

        <div class="manager-columns">
            <section class="panel manager-panel">
                <div class="panel-heading"><h2>Comercial</h2><?php if ($puedeVerVentas): ?><a class="text-link" href="ventas.php">Ventas</a><?php endif; ?></div>
                <h3>Ventas por vendedor (mes)</h3>
                <div class="manager-table-wrap"><table class="data-table"><thead><tr><th>Vendedor</th><th>Ventas</th><th>Monto</th></tr></thead><tbody>
                    <?php foreach ($ventasVendedor as $fila): ?><tr><td><?= $escapar($fila["vendedor"]) ?></td><td><?= (int) $fila["cantidad"] ?></td><td><?= $moneda($fila["total"]) ?></td></tr><?php endforeach; ?>
                    <?php if (!$ventasVendedor): ?><tr><td colspan="3" class="empty-state">Sin ventas confirmadas este mes.</td></tr><?php endif; ?>
                </tbody></table></div>
                <h3>Ventas por servicio (mes)</h3>
                <div class="manager-table-wrap"><table class="data-table"><thead><tr><th>Servicio</th><th>Ventas</th><th>Monto</th></tr></thead><tbody>
                    <?php foreach ($ventasServicio as $fila): ?><tr><td><?= $escapar($fila["servicio"]) ?></td><td><?= (int) $fila["cantidad"] ?></td><td><?= $moneda($fila["total"]) ?></td></tr><?php endforeach; ?>
                    <?php if (!$ventasServicio): ?><tr><td colspan="3" class="empty-state">Sin ventas confirmadas este mes.</td></tr><?php endif; ?>
                </tbody></table></div>
                <h3>Prospectos por estado</h3>
                <div class="manager-table-wrap"><table class="data-table"><thead><tr><th>Estado</th><th>Cantidad</th></tr></thead><tbody>
                    <?php foreach ($prospectosEstado as $fila): ?><tr><td><?= $escapar($fila["estado"]) ?></td><td><?= (int) $fila["cantidad"] ?></td></tr><?php endforeach; ?>
                    <?php if (!$prospectosEstado): ?><tr><td colspan="2" class="empty-state">Aún no hay prospectos registrados.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>

            <section class="panel manager-panel">
                <div class="panel-heading"><h2>Académica</h2><?php if ($puedeGestionarAcademia): ?><a class="text-link" href="gestion_academica.php">Coordinación</a><?php endif; ?></div>
                <p><strong><?= number_format($kpis["proyectos_activos"]) ?></strong> proyectos activos · <strong><?= number_format($kpis["entregas_proximas"]) ?></strong> entregas en los próximos 7 días · <strong><?= number_format($kpis["proyectos_retrasados"]) ?></strong> proyectos retrasados.</p>
                <h3>Productividad de asesores (entregas aprobadas este mes)</h3>
                <div class="manager-table-wrap"><table class="data-table"><thead><tr><th>Asesor</th><th>Entregas aprobadas</th></tr></thead><tbody>
                    <?php foreach ($productividadAsesores as $fila): ?><tr><td><?= $escapar($fila["asesor"]) ?></td><td><?= (int) $fila["entregas_aprobadas"] ?></td></tr><?php endforeach; ?>
                    <?php if (!$productividadAsesores): ?><tr><td colspan="2" class="empty-state">Sin asesores académicos registrados.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>

            <section class="panel manager-panel">
                <div class="panel-heading"><h2>Finanzas</h2><?php if ($puedeVerContratos): ?><a class="text-link" href="contratos.php">Contratos y saldos</a><?php endif; ?></div>
                <p>Ingresos registrados este mes: <strong><?= $moneda($kpis["ingresos_mes"]) ?></strong></p>
                <p>Cuentas por cobrar: <strong><?= $moneda($kpis["por_cobrar"]) ?></strong></p>
                <p>Cuotas vencidas: <strong><?= number_format($kpis["cuotas_vencidas"]) ?></strong> (<?= $moneda($kpis["monto_vencido"]) ?>)</p>
                <p>Próximas cuotas pendientes: <strong><?= number_format($kpis["cuotas_proximas"]) ?></strong></p>
                <p>Comisiones generadas este mes: <strong><?= $moneda($kpis["comisiones_mes"]) ?></strong></p>
                <p>Comisiones pendientes de liquidar: <strong><?= $moneda($kpis["comisiones_pendientes"]) ?></strong> · <?= number_format($kpis["cantidad_comisiones_pendientes"]) ?> registros</p>
                <h3>Comisiones por vendedor (mes)</h3>
                <div class="manager-table-wrap"><table class="data-table"><thead><tr><th>Vendedor</th><th>Total</th><th>Pendiente</th></tr></thead><tbody>
                    <?php foreach ($comisionesPorVendedor as $fila): ?><tr><td><?= $escapar($fila["vendedor"]) ?></td><td><?= $moneda($fila["total"]) ?></td><td><?= $moneda($fila["pendientes"]) ?></td></tr><?php endforeach; ?>
                    <?php if (!$comisionesPorVendedor): ?><tr><td colspan="3" class="empty-state">Sin comisiones generadas este mes.</td></tr><?php endif; ?>
                </tbody></table></div>
                <h3>Configurar porcentajes por vendedor</h3>
                <p class="muted">La tasa queda guardada en cada comisión al registrar el pago. No se recalculan pagos anteriores.</p>
                <div class="manager-table-wrap"><table class="data-table"><thead><tr><th>Vendedor</th><th>Tasa (%)</th><th>Acción</th></tr></thead><tbody>
                    <?php foreach ($tasasComision as $vendedor): ?>
                    <tr><td><?= $escapar(trim($vendedor["nombres"] . " " . $vendedor["apellidos"])) ?></td>
                        <td colspan="2"><form method="post" class="compact-form">
                            <input type="hidden" name="accion" value="guardar_comision">
                            <input type="hidden" name="id_usuario" value="<?= (int) $vendedor["id_usuario"] ?>">
                            <input type="number" name="comision_porcentaje" min="0" max="100" step="0.01" value="<?= $escapar($vendedor["comision_porcentaje"]) ?>" required aria-label="Porcentaje de comisión">
                            <button class="btn-action btn-action-primary" type="submit">Guardar tasa</button>
                        </form></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$tasasComision): ?><tr><td colspan="3" class="empty-state">No hay vendedores activos para configurar.</td></tr><?php endif; ?>
                </tbody></table></div>
                <h3>Comisiones pendientes</h3>
                <div class="manager-table-wrap"><table class="data-table"><thead><tr><th>Vendedor / contrato</th><th>Pago y tasa</th><th>Comisión</th><th>Acción</th></tr></thead><tbody>
                    <?php foreach ($comisionesPendientes as $comision): ?>
                    <tr>
                        <td><?= $escapar($comision["vendedor"]) ?><small>#<?= $escapar($comision["numero_contrato"]) ?></small></td>
                        <td><?= $escapar($comision["fecha_pago"]) ?><small><?= $moneda($comision["monto_base"]) ?> × <?= number_format((float) $comision["porcentaje"], 2) ?>%</small></td>
                        <td><?= $moneda($comision["monto_comision"]) ?></td>
                        <td><form method="post" onsubmit="return confirm('¿Registrar esta comisión como liquidada y crear el egreso?');">
                            <input type="hidden" name="accion" value="liquidar_comision">
                            <input type="hidden" name="id_comision" value="<?= (int) $comision["id_comision"] ?>">
                            <button class="btn-action btn-action-primary" type="submit">Liquidar</button>
                        </form></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$comisionesPendientes): ?><tr><td colspan="4" class="empty-state">No hay comisiones pendientes de liquidación.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>

            <section class="panel manager-panel">
                <div class="panel-heading"><h2>Marketing</h2><span class="status-badge"><?= count($campanas) ?> campañas</span></div>
                <p class="muted">El CPL es la inversión total de la campaña dividida entre los prospectos asociados.</p>
                <div class="manager-table-wrap"><table class="data-table"><thead><tr><th>Campaña / canal</th><th>Leads</th><th>CPL</th><th>Clientes</th></tr></thead><tbody>
                    <?php foreach ($campanas as $campana): ?>
                        <?php $leads = (int) $campana["leads"]; ?>
                        <tr>
                            <td><strong><?= $escapar($campana["nombre"]) ?></strong><small><?= $escapar($campana["canal"]) ?> · <?= $escapar($campana["estado"]) ?></small></td>
                            <td><?= $leads ?></td>
                            <td><?= $leads > 0 ? $moneda((float) $campana["inversion"] / $leads) : "Sin leads" ?></td>
                            <td><?= (int) $campana["clientes_generados"] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$campanas): ?><tr><td colspan="4" class="empty-state">Registra una campaña para medir CPL y clientes generados.</td></tr><?php endif; ?>
                </tbody></table></div>
                <h3><?= $campanaEditar ? "Editar campaña" : "Registrar campaña" ?></h3>
                <form method="post">
                    <input type="hidden" name="accion" value="guardar_campana">
                    <input type="hidden" name="id_campana" value="<?= (int) ($campanaEditar["id_campana"] ?? 0) ?>">
                    <div class="campaign-form">
                        <div class="campo"><label for="nombre">Nombre</label><input id="nombre" name="nombre" maxlength="150" required value="<?= $escapar($campanaEditar["nombre"] ?? "") ?>"></div>
                        <div class="campo"><label for="canal">Canal</label><select id="canal" name="canal"><?php foreach (["Facebook", "TikTok", "WhatsApp", "Instagram", "Google", "Referido", "Otro"] as $canal): ?><option <?= ($campanaEditar["canal"] ?? "Facebook") === $canal ? "selected" : "" ?>><?= $escapar($canal) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="inversion">Inversión (S/)</label><input id="inversion" name="inversion" type="number" min="0" step="0.01" required value="<?= $escapar($campanaEditar["inversion"] ?? "0.00") ?>"></div>
                        <div class="campo"><label for="fecha_inicio">Inicio</label><input id="fecha_inicio" name="fecha_inicio" type="date" required value="<?= $escapar($campanaEditar["fecha_inicio"] ?? date("Y-m-d")) ?>"></div>
                        <div class="campo"><label for="fecha_fin">Fin</label><input id="fecha_fin" name="fecha_fin" type="date" value="<?= $escapar($campanaEditar["fecha_fin"] ?? "") ?>"></div>
                        <div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><?php foreach (["Activa", "Pausada", "Finalizada"] as $estado): ?><option <?= ($campanaEditar["estado"] ?? "Activa") === $estado ? "selected" : "" ?>><?= $escapar($estado) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <button class="btn-primary form-submit" type="submit"><?= $campanaEditar ? "Actualizar campaña" : "Guardar campaña" ?></button>
                    <?php if ($campanaEditar): ?><a class="text-link" href="dashboard_gerencial.php">Cancelar</a><?php endif; ?>
                </form>
                <?php if ($campanas): ?>
                <h3>Enlaces para captar prospectos</h3>
                <?php foreach ($campanas as $campana): if ($campana["estado"] !== "Activa") continue; ?>
                    <p><strong><?= $escapar($campana["nombre"]) ?>:</strong>
                        <a class="text-link" href="../solicitud_prospecto.php?campana=<?= (int) $campana["id_campana"] ?>">Abrir formulario</a>
                        · <a class="text-link" href="dashboard_gerencial.php?editar_campana=<?= (int) $campana["id_campana"] ?>">Editar</a>
                    </p>
                <?php endforeach; endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
