<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";

// Control de acceso basado en rol directo de sesion
$_rolActual = strtolower($_SESSION["rol"] ?? "");
if (!in_array($_rolActual, ["administrador", "trabajador", "operaciones", "personal_operativo"])) {
    http_response_code(403);
    exit("No tienes permisos para acceder a Psicologia.");
}
$puedeGestionar = true;

$mensaje = "";
$error = "";
$seguimientoEditar = null;
$seguimientos = [];
$profesionales = [];
$estados = ["Pendiente", "En proceso", "Completado", "Reprogramado"];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $id = (int) ($_POST["id_seguimiento"] ?? 0);
                $estado = trim($_POST["estado"] ?? "Pendiente");
                $datos = [
                    ":persona" => trim($_POST["persona"] ?? ""),
                    ":fecha_atencion" => trim($_POST["fecha_atencion"] ?? ""),
                    ":profesional_id" => (int) ($_POST["profesional_id"] ?? 0) ?: null,
                    ":tipo_seguimiento" => trim($_POST["tipo_seguimiento"] ?? ""),
                    ":estado" => $estado,
                    ":observaciones_generales" => trim($_POST["observaciones_generales"] ?? "") ?: null,
                    ":proxima_fecha" => trim($_POST["proxima_fecha"] ?? "") ?: null,
                ];
                if ($datos[":persona"] === "" || $datos[":fecha_atencion"] === "" ||
                    $datos[":tipo_seguimiento"] === "" || !in_array($estado, $estados, true)) {
                    throw new RuntimeException("Completa persona, fecha, tipo de seguimiento y un estado válido.");
                }
                if ($id > 0) {
                    $datos[":id_seguimiento"] = $id;
                    $conexion->prepare(
                        "UPDATE seguimientos_psicologia
                         SET persona = :persona, fecha_atencion = :fecha_atencion, profesional_id = :profesional_id,
                             tipo_seguimiento = :tipo_seguimiento, estado = :estado,
                             observaciones_generales = :observaciones_generales, proxima_fecha = :proxima_fecha
                         WHERE id_seguimiento = :id_seguimiento"
                    )->execute($datos);
                    $mensaje = "Seguimiento psicológico actualizado correctamente.";
                } else {
                    $conexion->prepare(
                        "INSERT INTO seguimientos_psicologia
                         (persona, fecha_atencion, profesional_id, tipo_seguimiento, estado, observaciones_generales, proxima_fecha)
                         VALUES (:persona, :fecha_atencion, :profesional_id, :tipo_seguimiento, :estado, :observaciones_generales, :proxima_fecha)"
                    )->execute($datos);
                    $mensaje = "Seguimiento psicológico registrado correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $id = (int) ($_POST["id_seguimiento"] ?? 0);
                if ($id > 0) {
                    $stmt = $conexion->prepare("DELETE FROM seguimientos_psicologia WHERE id_seguimiento = :id");
                    $stmt->execute([":id" => $id]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException("El seguimiento seleccionado no existe.");
                    }
                    $mensaje = "Seguimiento psicológico eliminado correctamente.";
                }
            }
        }

        if ($puedeGestionar && isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM seguimientos_psicologia WHERE id_seguimiento = :id");
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $seguimientoEditar = $stmt->fetch() ?: null;
        }
        $profesionales = $conexion->query(
            "SELECT id_usuario, nombres, apellidos FROM usuarios WHERE estado = 1 ORDER BY nombres, apellidos"
        )->fetchAll();
        $seguimientos = $conexion->query(
            "SELECT s.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS profesional
             FROM seguimientos_psicologia s
             LEFT JOIN usuarios u ON u.id_usuario = s.profesional_id
             ORDER BY s.fecha_atencion DESC, s.id_seguimiento DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación.";
    }
}

$valor = static fn (string $campo): string => $escapar($seguimientoEditar[$campo] ?? "");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Psicología | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Personas</p><h1>Seguimientos de Psicología</h1><p>Registra atenciones con acceso restringido al personal autorizado del área.</p></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>
        <!-- SECCIÓN 1: ATENCIONES REGISTRADAS ARRIBA DE PRIMERAS -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-heading">
                <h3>🧠 Atenciones registradas</h3>
                <span style="font-size:0.8rem; color:#64748b; font-weight:500;">Historial de atenciones y acompañamiento</span>
            </div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Persona / fecha</th><th>Profesional</th><th>Seguimiento</th><th>Estado</th><th>Próxima fecha</th><?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($seguimientos as $seguimiento): ?><tr><td><strong><?= $escapar($seguimiento["persona"]) ?></strong><small><?= $escapar($seguimiento["fecha_atencion"]) ?></small></td><td><?= $escapar($seguimiento["profesional"] ?: "Sin asignar") ?></td><td><?= $escapar($seguimiento["tipo_seguimiento"]) ?><small><?= $escapar($seguimiento["observaciones_generales"]) ?></small></td><td><?= $escapar($seguimiento["estado"]) ?></td><td><?= $escapar($seguimiento["proxima_fecha"] ?: "—") ?></td><?php if ($puedeGestionar): ?><td class="actions"><a class="text-link" href="psicologia.php?editar=<?= (int) $seguimiento["id_seguimiento"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este seguimiento?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_seguimiento" value="<?= (int) $seguimiento["id_seguimiento"] ?>"><button type="submit" class="link-button danger">Eliminar</button></form></td><?php endif; ?></tr><?php endforeach; ?>
            <?php if (!$seguimientos): ?><tr><td colspan="<?= $puedeGestionar ? 6 : 5 ?>" class="empty-state">No hay seguimientos registrados.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>

        <!-- SECCIÓN 2: FORMULARIO DESPLEGABLE NUEVO SEGUIMIENTO -->
        <?php if ($puedeGestionar): ?>
        <details class="collapsible-card" <?= $seguimientoEditar ? "open" : "" ?> style="margin-bottom: 28px;">
            <summary>
                <div class="summary-left">
                    <span class="summary-icon">🧠</span>
                    <span class="summary-title"><?= $seguimientoEditar ? "Editar Seguimiento" : "Nuevo Seguimiento de Psicología" ?></span>
                    <span class="summary-badge"><?= $seguimientoEditar ? "Modo edición" : "Registrar atención" ?></span>
                </div>
                <span class="summary-chevron">▼</span>
            </summary>
            <div class="collapsible-body">
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id_seguimiento" value="<?= (int) ($seguimientoEditar["id_seguimiento"] ?? 0) ?>">
                    <div class="form-grid">
                        <div class="campo"><label for="persona">Persona *</label><input id="persona" name="persona" required value="<?= $valor("persona") ?>"></div>
                        <div class="campo"><label for="fecha_atencion">Fecha de atención *</label><input type="date" id="fecha_atencion" name="fecha_atencion" required value="<?= $valor("fecha_atencion") ?: date("Y-m-d") ?>"></div>
                        <div class="campo"><label for="profesional_id">Profesional</label><select id="profesional_id" name="profesional_id"><option value="">Sin asignar</option><?php foreach ($profesionales as $profesional): ?><option value="<?= (int) $profesional["id_usuario"] ?>" <?= (int) ($seguimientoEditar["profesional_id"] ?? 0) === (int) $profesional["id_usuario"] ? "selected" : "" ?>><?= $escapar(trim($profesional["nombres"] . " " . $profesional["apellidos"])) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="tipo_seguimiento">Tipo de seguimiento *</label><input id="tipo_seguimiento" name="tipo_seguimiento" required value="<?= $valor("tipo_seguimiento") ?>"></div>
                        <div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><?php foreach ($estados as $estado): ?><option value="<?= $escapar($estado) ?>" <?= $valor("estado") === $estado || (!$seguimientoEditar && $estado === "Pendiente") ? "selected" : "" ?>><?= $escapar($estado) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="proxima_fecha">Próxima fecha</label><input type="date" id="proxima_fecha" name="proxima_fecha" value="<?= $valor("proxima_fecha") ?>"></div>
                    </div>
                    <div class="campo"><label for="observaciones_generales">Observaciones</label><textarea id="observaciones_generales" name="observaciones_generales" rows="4"><?= $valor("observaciones_generales") ?></textarea></div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="submit" class="btn-primary form-submit"><?= $seguimientoEditar ? "Actualizar atención" : "Guardar seguimiento" ?></button>
                        <?php if ($seguimientoEditar): ?><a class="btn-secondary" href="psicologia.php" style="text-decoration:none; padding:8px 16px;">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
