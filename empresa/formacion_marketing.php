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
    exit("No tienes permisos para acceder a Formacion.");
}

$mensaje = "";
$error = "";
$formacionEditar = null;
$formaciones = [];
$trabajadores = [];
$participantesSeleccionados = [];
$estados = ["Programada", "En curso", "Completada", "Cancelada"];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $id = (int) ($_POST["id_formacion"] ?? 0);
                $estado = trim($_POST["estado"] ?? "Programada");
                $duracionValor = trim($_POST["duracion_valor"] ?? "");
                $unidadDuracion = trim($_POST["duracion_unidad"] ?? "minutos");
                $factoresDuracion = ["minutos" => 1, "horas" => 60, "dias" => 1440, "meses" => 43200];
                $cantidadDuracion = $duracionValor === "" ? null : filter_var($duracionValor, FILTER_VALIDATE_INT);
                $participantes = array_values(array_unique(array_filter(array_map("intval", $_POST["participantes"] ?? []))));
                if (!array_key_exists($unidadDuracion, $factoresDuracion) ||
                    ($duracionValor !== "" && ($cantidadDuracion === false || $cantidadDuracion <= 0 ||
                        $cantidadDuracion > intdiv(2147483647, $factoresDuracion[$unidadDuracion])))) {
                    throw new RuntimeException("La duración debe ser un número entero positivo y usar una unidad válida.");
                }
                $datos = [
                    ":nombre" => trim($_POST["nombre"] ?? ""),
                    ":descripcion" => trim($_POST["descripcion"] ?? "") ?: null,
                    ":responsable_id" => (int) ($_POST["responsable_id"] ?? 0) ?: null,
                    ":fecha" => trim($_POST["fecha"] ?? ""),
                    ":duracion_minutos" => $cantidadDuracion === null ? null : $cantidadDuracion * $factoresDuracion[$unidadDuracion],
                    ":estado" => $estado,
                    ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null,
                ];
                if ($datos[":nombre"] === "" || $datos[":fecha"] === "" ||
                    !in_array($estado, $estados, true) ||
                    ($datos[":duracion_minutos"] !== null && $datos[":duracion_minutos"] <= 0)) {
                    throw new RuntimeException("Completa nombre, fecha y un estado válido; la duración debe ser mayor que cero.");
                }
                if ($participantes) {
                    $marcadores = implode(",", array_fill(0, count($participantes), "?"));
                    $stmtActivos = $conexion->prepare(
                        "SELECT COUNT(*) FROM usuarios WHERE estado = 1 AND id_usuario IN ({$marcadores})"
                    );
                    $stmtActivos->execute($participantes);
                    if ((int) $stmtActivos->fetchColumn() !== count($participantes)) {
                        throw new RuntimeException("Uno o más participantes no están activos o no existen.");
                    }
                }

                $conexion->beginTransaction();
                if ($id > 0) {
                    $datos[":id_formacion"] = $id;
                    $stmt = $conexion->prepare(
                        "UPDATE formaciones_marketing SET nombre = :nombre, descripcion = :descripcion,
                         responsable_id = :responsable_id, fecha = :fecha, duracion_minutos = :duracion_minutos,
                         estado = :estado, observaciones = :observaciones WHERE id_formacion = :id_formacion"
                    );
                    $stmt->execute($datos);
                    if ($stmt->rowCount() === 0) {
                        $existe = $conexion->prepare("SELECT 1 FROM formaciones_marketing WHERE id_formacion = :id");
                        $existe->execute([":id" => $id]);
                        if (!$existe->fetchColumn()) {
                            throw new RuntimeException("La formación seleccionada no existe.");
                        }
                    }
                    $idFormacion = $id;
                    $conexion->prepare("DELETE FROM formacion_participantes WHERE id_formacion = :id")
                        ->execute([":id" => $idFormacion]);
                } else {
                    $conexion->prepare(
                        "INSERT INTO formaciones_marketing
                         (nombre, descripcion, responsable_id, fecha, duracion_minutos, estado, observaciones)
                         VALUES (:nombre, :descripcion, :responsable_id, :fecha, :duracion_minutos, :estado, :observaciones)"
                    )->execute($datos);
                    $idFormacion = (int) $conexion->lastInsertId();
                }
                if ($participantes) {
                    $insertarParticipante = $conexion->prepare(
                        "INSERT INTO formacion_participantes (id_formacion, trabajador_id) VALUES (:formacion, :trabajador)"
                    );
                    foreach ($participantes as $idTrabajador) {
                        $insertarParticipante->execute([":formacion" => $idFormacion, ":trabajador" => $idTrabajador]);
                    }
                }
                $conexion->commit();
                $mensaje = $id > 0 ? "Formación actualizada correctamente." : "Formación registrada correctamente.";
            } elseif ($accion === "eliminar") {
                $id = (int) ($_POST["id_formacion"] ?? 0);
                if ($id > 0) {
                    $stmt = $conexion->prepare("DELETE FROM formaciones_marketing WHERE id_formacion = :id");
                    $stmt->execute([":id" => $id]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException("La formación seleccionada no existe.");
                    }
                    $mensaje = "Formación eliminada correctamente.";
                }
            }
        }

        if (isset($_GET["editar"])) {
            $id = (int) $_GET["editar"];
            $stmt = $conexion->prepare("SELECT * FROM formaciones_marketing WHERE id_formacion = :id");
            $stmt->execute([":id" => $id]);
            $formacionEditar = $stmt->fetch() ?: null;
            if ($formacionEditar) {
                $stmt = $conexion->prepare("SELECT trabajador_id FROM formacion_participantes WHERE id_formacion = :id");
                $stmt->execute([":id" => $id]);
                $participantesSeleccionados = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN));
            }
        }
        $trabajadores = $conexion->query(
            "SELECT id_usuario, nombres, apellidos FROM usuarios WHERE estado = 1 ORDER BY nombres, apellidos"
        )->fetchAll();
        $formaciones = $conexion->query(
            "SELECT f.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS responsable,
                    (SELECT COUNT(*) FROM formacion_participantes fp WHERE fp.id_formacion = f.id_formacion) AS total_participantes
             FROM formaciones_marketing f
             LEFT JOIN usuarios u ON u.id_usuario = f.responsable_id
             ORDER BY f.fecha DESC, f.id_formacion DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación.";
    }
}

$valor = static fn (string $campo): string => $escapar($formacionEditar[$campo] ?? "");
$duracionGuardada = (int) ($formacionEditar["duracion_minutos"] ?? 0);
$duracionUnidad = "minutos";
$duracionValor = "";
foreach (["meses" => 43200, "dias" => 1440, "horas" => 60] as $unidad => $factor) {
    if ($duracionGuardada > 0 && $duracionGuardada % $factor === 0) {
        $duracionUnidad = $unidad;
        $duracionValor = (string) ($duracionGuardada / $factor);
        break;
    }
}
if ($duracionValor === "" && $duracionGuardada > 0) {
    $duracionValor = (string) $duracionGuardada;
}
$formatearDuracion = static function ($minutos): string {
    $minutos = (int) $minutos;
    foreach (["meses" => 43200, "días" => 1440, "horas" => 60] as $unidad => $factor) {
        if ($minutos > 0 && $minutos % $factor === 0) {
            return (string) ($minutos / $factor) . " " . $unidad;
        }
    }
    return $minutos . " minutos";
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formación de Marketing | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Personas</p><h1>Formación de Marketing</h1><p>Programa actividades y registra a sus participantes.</p></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>
        <!-- SECCIÓN 1: FORMACIONES REGISTRADAS ARRIBA DE PRIMERAS -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-heading">
                <h3>🎓 Formaciones registradas</h3>
                <span style="font-size:0.8rem; color:#64748b; font-weight:500;">Talleres y capacitaciones internas</span>
            </div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Actividad</th><th>Fecha</th><th>Responsable</th><th>Participantes</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
            <?php foreach ($formaciones as $formacion): ?><tr><td><strong><?= $escapar($formacion["nombre"]) ?></strong><small><?= $escapar($formacion["descripcion"]) ?></small></td><td><?= $escapar($formacion["fecha"]) ?><?php if ($formacion["duracion_minutos"]): ?><small><?= $escapar($formatearDuracion($formacion["duracion_minutos"])) ?></small><?php endif; ?></td><td><?= $escapar($formacion["responsable"] ?: "Sin asignar") ?></td><td><?= (int) $formacion["total_participantes"] ?></td><td><?= $escapar($formacion["estado"]) ?></td><td class="actions"><a class="text-link" href="formacion_marketing.php?editar=<?= (int) $formacion["id_formacion"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar esta formación y sus participantes?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_formacion" value="<?= (int) $formacion["id_formacion"] ?>"><button type="submit" class="link-button danger">Eliminar</button></form></td></tr><?php endforeach; ?>
            <?php if (!$formaciones): ?><tr><td colspan="6" class="empty-state">No hay formaciones registradas.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>

        <!-- SECCIÓN 2: FORMULARIO DESPLEGABLE NUEVA / EDITAR FORMACIÓN -->
        <details class="collapsible-card" <?= $formacionEditar ? "open" : "" ?> style="margin-bottom: 28px;">
            <summary>
                <div class="summary-left">
                    <span class="summary-icon">🎓</span>
                    <span class="summary-title"><?= $formacionEditar ? "Editar Formación" : "Registrar Nueva Formación" ?></span>
                    <span class="summary-badge"><?= $formacionEditar ? "Modo edición" : "Capacitación / Taller" ?></span>
                </div>
                <span class="summary-chevron">▼</span>
            </summary>
            <div class="collapsible-body">
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id_formacion" value="<?= (int) ($formacionEditar["id_formacion"] ?? 0) ?>">
                    <div class="form-grid">
                        <div class="campo"><label for="nombre">Nombre *</label><input id="nombre" name="nombre" required value="<?= $valor("nombre") ?>"></div>
                        <div class="campo"><label for="responsable_id">Responsable</label><select id="responsable_id" name="responsable_id"><option value="">Sin asignar</option><?php foreach ($trabajadores as $trabajador): ?><option value="<?= (int) $trabajador["id_usuario"] ?>" <?= (int) ($formacionEditar["responsable_id"] ?? 0) === (int) $trabajador["id_usuario"] ? "selected" : "" ?>><?= $escapar(trim($trabajador["nombres"] . " " . $trabajador["apellidos"])) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="fecha">Fecha *</label><input type="date" id="fecha" name="fecha" required value="<?= $valor("fecha") ?: date("Y-m-d") ?>"></div>
                        <div class="campo"><label for="duracion_valor">Duración</label><div class="form-grid"><input type="number" min="1" step="1" id="duracion_valor" name="duracion_valor" value="<?= $escapar($duracionValor) ?>" aria-label="Cantidad de duración"><select id="duracion_unidad" name="duracion_unidad" aria-label="Unidad de duración"><option value="minutos" <?= $duracionUnidad === "minutos" ? "selected" : "" ?>>Minutos</option><option value="horas" <?= $duracionUnidad === "horas" ? "selected" : "" ?>>Horas</option><option value="dias" <?= $duracionUnidad === "dias" ? "selected" : "" ?>>Días</option><option value="meses" <?= $duracionUnidad === "meses" ? "selected" : "" ?>>Meses (30 días)</option></select></div></div>
                        <div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><?php foreach ($estados as $estado): ?><option value="<?= $escapar($estado) ?>" <?= $valor("estado") === $estado || (!$formacionEditar && $estado === "Programada") ? "selected" : "" ?>><?= $escapar($estado) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="campo"><label for="descripcion">Descripción</label><textarea id="descripcion" name="descripcion" rows="3"><?= $valor("descripcion") ?></textarea></div>
                    <div class="campo"><label for="participantes">Participantes</label><select id="participantes" name="participantes[]" multiple size="5"><?php foreach ($trabajadores as $trabajador): ?><option value="<?= (int) $trabajador["id_usuario"] ?>" <?= in_array((int) $trabajador["id_usuario"], $participantesSeleccionados, true) ? "selected" : "" ?>><?= $escapar(trim($trabajador["nombres"] . " " . $trabajador["apellidos"])) ?></option><?php endforeach; ?></select></div>
                    <div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= $valor("observaciones") ?></textarea></div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="submit" class="btn-primary form-submit"><?= $formacionEditar ? "Actualizar formación" : "Guardar formación" ?></button>
                        <?php if ($formacionEditar): ?><a class="btn-secondary" href="formacion_marketing.php" style="text-decoration:none; padding:8px 16px;">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
    </main>
</div>
</body>
</html>
