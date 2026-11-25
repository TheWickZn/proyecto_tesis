<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
if ($conexion) {
    exigirPermiso($conexion, "horarios.gestionar");
}

$mensaje = "";
$error = "";
$horarioEditar = null;
$horarios = [];
$trabajadores = [];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $id = (int) ($_POST["id_horario"] ?? 0);
                $datos = [
                    ":trabajador_id" => (int) ($_POST["trabajador_id"] ?? 0),
                    ":actividad" => trim($_POST["actividad"] ?? ""),
                    ":area" => trim($_POST["area"] ?? ""),
                    ":fecha" => trim($_POST["fecha"] ?? ""),
                    ":hora_inicio" => trim($_POST["hora_inicio"] ?? ""),
                    ":hora_finalizacion" => trim($_POST["hora_finalizacion"] ?? ""),
                    ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null,
                ];
                if ($datos[":trabajador_id"] <= 0 || $datos[":actividad"] === "" || $datos[":area"] === "" ||
                    $datos[":fecha"] === "" || $datos[":hora_inicio"] === "" ||
                    $datos[":hora_finalizacion"] <= $datos[":hora_inicio"]) {
                    throw new RuntimeException("Completa los campos y verifica que la hora final sea posterior a la inicial.");
                }
                if ($id > 0) {
                    $datos[":id_horario"] = $id;
                    $conexion->prepare(
                        "UPDATE horarios SET trabajador_id = :trabajador_id, actividad = :actividad, area = :area,
                         fecha = :fecha, hora_inicio = :hora_inicio, hora_finalizacion = :hora_finalizacion,
                         observaciones = :observaciones WHERE id_horario = :id_horario"
                    )->execute($datos);
                    $mensaje = "Horario actualizado correctamente.";
                } else {
                    $conexion->prepare(
                        "INSERT INTO horarios (trabajador_id, actividad, area, fecha, hora_inicio, hora_finalizacion, observaciones)
                         VALUES (:trabajador_id, :actividad, :area, :fecha, :hora_inicio, :hora_finalizacion, :observaciones)"
                    )->execute($datos);
                    $mensaje = "Horario registrado correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $id = (int) ($_POST["id_horario"] ?? 0);
                if ($id > 0) {
                    $stmt = $conexion->prepare("DELETE FROM horarios WHERE id_horario = :id");
                    $stmt->execute([":id" => $id]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException("El horario seleccionado no existe.");
                    }
                    $mensaje = "Horario eliminado correctamente.";
                }
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM horarios WHERE id_horario = :id");
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $horarioEditar = $stmt->fetch() ?: null;
        }
        $trabajadores = $conexion->query(
            "SELECT id_usuario, nombres, apellidos FROM usuarios WHERE estado = 1 ORDER BY nombres, apellidos"
        )->fetchAll();
        $horarios = $conexion->query(
            "SELECT h.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS trabajador
             FROM horarios h INNER JOIN usuarios u ON u.id_usuario = h.trabajador_id
             ORDER BY h.fecha DESC, h.hora_inicio DESC, h.id_horario DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación.";
    }
}

$valor = static fn (string $campo): string => $escapar($horarioEditar[$campo] ?? "");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horarios | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Gestión operativa</p><h1>Horarios</h1><p>Programa actividades por trabajador, área, fecha y horario.</p></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>
        <section class="crud-layout">
            <div class="panel">
                <div class="panel-heading"><h3><?= $horarioEditar ? "Editar horario" : "Nuevo horario" ?></h3><?php if ($horarioEditar): ?><a class="text-link" href="horarios.php">Cancelar</a><?php endif; ?></div>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id_horario" value="<?= (int) ($horarioEditar["id_horario"] ?? 0) ?>">
                    <div class="campo"><label for="trabajador_id">Trabajador *</label><select id="trabajador_id" name="trabajador_id" required><option value="">Seleccionar trabajador</option><?php foreach ($trabajadores as $trabajador): ?><option value="<?= (int) $trabajador["id_usuario"] ?>" <?= (int) ($horarioEditar["trabajador_id"] ?? 0) === (int) $trabajador["id_usuario"] ? "selected" : "" ?>><?= $escapar(trim($trabajador["nombres"] . " " . $trabajador["apellidos"])) ?></option><?php endforeach; ?></select></div>
                    <div class="form-grid">
                        <div class="campo"><label for="actividad">Actividad *</label><input id="actividad" name="actividad" required value="<?= $valor("actividad") ?>"></div>
                        <div class="campo"><label for="area">Área *</label><input id="area" name="area" required value="<?= $valor("area") ?>"></div>
                        <div class="campo"><label for="fecha">Fecha *</label><input type="date" id="fecha" name="fecha" required value="<?= $valor("fecha") ?: date("Y-m-d") ?>"></div>
                        <div class="campo"><label for="hora_inicio">Hora inicial *</label><input type="time" id="hora_inicio" name="hora_inicio" required value="<?= $valor("hora_inicio") ?>"></div>
                        <div class="campo"><label for="hora_finalizacion">Hora final *</label><input type="time" id="hora_finalizacion" name="hora_finalizacion" required value="<?= $valor("hora_finalizacion") ?>"></div>
                    </div>
                    <div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= $valor("observaciones") ?></textarea></div>
                    <button type="submit" class="btn-primary form-submit">Guardar horario</button>
                </form>
            </div>
            <div class="panel">
                <div class="panel-heading"><h3>Horarios registrados</h3></div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha y hora</th><th>Trabajador</th><th>Área / actividad</th><th>Observaciones</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($horarios as $horario): ?><tr><td><?= $escapar($horario["fecha"]) ?><small><?= $escapar(substr($horario["hora_inicio"], 0, 5) . " - " . substr($horario["hora_finalizacion"], 0, 5)) ?></small></td><td><?= $escapar($horario["trabajador"]) ?></td><td><strong><?= $escapar($horario["area"]) ?></strong><small><?= $escapar($horario["actividad"]) ?></small></td><td><?= $escapar($horario["observaciones"]) ?></td><td class="actions"><a class="text-link" href="horarios.php?editar=<?= (int) $horario["id_horario"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este horario?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_horario" value="<?= (int) $horario["id_horario"] ?>"><button type="submit" class="link-button danger">Eliminar</button></form></td></tr><?php endforeach; ?>
                <?php if (!$horarios): ?><tr><td colspan="5" class="empty-state">No hay horarios registrados.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
