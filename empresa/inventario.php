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
    exit("No tienes permisos para acceder a Inventario.");
}

$mensaje = "";
$error = "";
$recursoEditar = null;
$recursos = [];
$responsables = [];
$estados = ["Disponible", "Asignado", "En mantenimiento", "Agotado", "Inactivo"];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $id = (int) ($_POST["id_recurso"] ?? 0);
                $cantidad = filter_var($_POST["cantidad"] ?? null, FILTER_VALIDATE_INT);
                $estado = trim($_POST["estado"] ?? "Disponible");
                $datos = [
                    ":codigo" => trim($_POST["codigo"] ?? ""),
                    ":nombre" => trim($_POST["nombre"] ?? ""),
                    ":categoria" => trim($_POST["categoria"] ?? ""),
                    ":descripcion" => trim($_POST["descripcion"] ?? "") ?: null,
                    ":cantidad" => $cantidad,
                    ":estado" => $estado,
                    ":ubicacion" => trim($_POST["ubicacion"] ?? "") ?: null,
                    ":responsable_id" => (int) ($_POST["responsable_id"] ?? 0) ?: null,
                    ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null,
                ];
                if ($datos[":codigo"] === "" || $datos[":nombre"] === "" || $datos[":categoria"] === "" ||
                    $cantidad === false || $cantidad < 0 || !in_array($estado, $estados, true)) {
                    throw new RuntimeException("Completa código, nombre, categoría, cantidad no negativa y un estado válido.");
                }
                if ($id > 0) {
                    $datos[":id_recurso"] = $id;
                    $conexion->prepare(
                        "UPDATE recursos SET codigo = :codigo, nombre = :nombre, categoria = :categoria,
                         descripcion = :descripcion, cantidad = :cantidad, estado = :estado,
                         ubicacion = :ubicacion, responsable_id = :responsable_id, observaciones = :observaciones
                         WHERE id_recurso = :id_recurso"
                    )->execute($datos);
                    $mensaje = "Recurso actualizado correctamente.";
                } else {
                    $datos[":fecha_registro"] = trim($_POST["fecha_registro"] ?? date("Y-m-d"));
                    $conexion->prepare(
                        "INSERT INTO recursos
                         (codigo, nombre, categoria, descripcion, cantidad, estado, ubicacion, responsable_id, fecha_registro, observaciones)
                         VALUES (:codigo, :nombre, :categoria, :descripcion, :cantidad, :estado, :ubicacion, :responsable_id, :fecha_registro, :observaciones)"
                    )->execute($datos);
                    $mensaje = "Recurso registrado correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $id = (int) ($_POST["id_recurso"] ?? 0);
                if ($id > 0) {
                    $stmt = $conexion->prepare(
                        "SELECT
                            (SELECT COUNT(*) FROM asignaciones_recursos WHERE recurso_id = :asignaciones)
                            + (SELECT COUNT(*) FROM reconteos WHERE recurso_id = :reconteos)"
                    );
                    $stmt->execute([":asignaciones" => $id, ":reconteos" => $id]);
                    if ((int) $stmt->fetchColumn() > 0) {
                        throw new RuntimeException("No se puede eliminar este recurso porque tiene asignaciones o reconteos relacionados.");
                    }
                    $stmt = $conexion->prepare("DELETE FROM recursos WHERE id_recurso = :id");
                    $stmt->execute([":id" => $id]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException("El recurso seleccionado no existe.");
                    }
                    $mensaje = "Recurso eliminado correctamente.";
                }
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM recursos WHERE id_recurso = :id");
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $recursoEditar = $stmt->fetch() ?: null;
        }
        $responsables = $conexion->query(
            "SELECT id_usuario, nombres, apellidos FROM usuarios WHERE estado = 1 ORDER BY nombres, apellidos"
        )->fetchAll();
        $recursos = $conexion->query(
            "SELECT r.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS responsable
             FROM recursos r LEFT JOIN usuarios u ON u.id_usuario = r.responsable_id
             ORDER BY r.nombre, r.id_recurso DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación. Verifica que el código no esté repetido.";
    }
}

$valor = static fn (string $campo): string => $escapar($recursoEditar[$campo] ?? "");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Gestión operativa</p><h1>Inventario</h1><p>Registra los recursos, sus existencias, ubicación y responsable.</p></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>
        <!-- SECCIÓN 1: RECURSOS REGISTRADOS ARRIBA DE PRIMERAS -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-heading">
                <h3>📦 Recursos registrados</h3>
                <span style="font-size:0.8rem; color:#64748b; font-weight:500;">Inventario de activos y materiales</span>
            </div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Recurso</th><th>Categoría / ubicación</th><th>Cantidad</th><th>Responsable</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
            <?php foreach ($recursos as $recurso): ?><tr><td><strong><?= $escapar($recurso["nombre"]) ?></strong><small><?= $escapar($recurso["codigo"]) ?> · Registro <?= $escapar($recurso["fecha_registro"]) ?></small></td><td><?= $escapar($recurso["categoria"]) ?><small><?= $escapar($recurso["ubicacion"] ?: "Sin ubicación") ?></small></td><td><?= (int) $recurso["cantidad"] ?></td><td><?= $escapar($recurso["responsable"] ?: "Sin asignar") ?></td><td><?= $escapar($recurso["estado"]) ?></td><td class="actions"><a class="text-link" href="inventario.php?editar=<?= (int) $recurso["id_recurso"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este recurso?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_recurso" value="<?= (int) $recurso["id_recurso"] ?>"><button type="submit" class="link-button danger">Eliminar</button></form></td></tr><?php endforeach; ?>
            <?php if (!$recursos): ?><tr><td colspan="6" class="empty-state">No hay recursos registrados.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>

        <!-- SECCIÓN 2: FORMULARIO DESPLEGABLE REGISTRAR / EDITAR RECURSO -->
        <details class="collapsible-card" <?= $recursoEditar ? "open" : "" ?> style="margin-bottom: 28px;">
            <summary>
                <div class="summary-left">
                    <span class="summary-icon">📦</span>
                    <span class="summary-title"><?= $recursoEditar ? "Editar Recurso de Inventario" : "Registrar Nuevo Recurso" ?></span>
                    <span class="summary-badge"><?= $recursoEditar ? "Modo edición" : "Nuevo registro en inventario" ?></span>
                </div>
                <span class="summary-chevron">▼</span>
            </summary>
            <div class="collapsible-body">
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id_recurso" value="<?= (int) ($recursoEditar["id_recurso"] ?? 0) ?>">
                    <div class="form-grid">
                        <div class="campo"><label for="codigo">Código *</label><input id="codigo" name="codigo" required value="<?= $valor("codigo") ?>"></div>
                        <div class="campo"><label for="nombre">Nombre *</label><input id="nombre" name="nombre" required value="<?= $valor("nombre") ?>"></div>
                        <div class="campo"><label for="categoria">Categoría *</label><input id="categoria" name="categoria" required value="<?= $valor("categoria") ?>"></div>
                        <div class="campo"><label for="cantidad">Cantidad *</label><input type="number" min="0" step="1" id="cantidad" name="cantidad" required value="<?= $valor("cantidad") ?: "0" ?>"></div>
                        <div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><?php foreach ($estados as $estado): ?><option value="<?= $escapar($estado) ?>" <?= $valor("estado") === $estado || (!$recursoEditar && $estado === "Disponible") ? "selected" : "" ?>><?= $escapar($estado) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="ubicacion">Ubicación</label><input id="ubicacion" name="ubicacion" value="<?= $valor("ubicacion") ?>"></div>
                        <div class="campo"><label for="responsable_id">Responsable</label><select id="responsable_id" name="responsable_id"><option value="">Sin asignar</option><?php foreach ($responsables as $responsable): ?><option value="<?= (int) $responsable["id_usuario"] ?>" <?= (int) ($recursoEditar["responsable_id"] ?? 0) === (int) $responsable["id_usuario"] ? "selected" : "" ?>><?= $escapar(trim($responsable["nombres"] . " " . $responsable["apellidos"])) ?></option><?php endforeach; ?></select></div>
                        <?php if (!$recursoEditar): ?><div class="campo"><label for="fecha_registro">Fecha de registro</label><input type="date" id="fecha_registro" name="fecha_registro" value="<?= date("Y-m-d") ?>"></div><?php endif; ?>
                    </div>
                    <div class="campo"><label for="descripcion">Descripción</label><textarea id="descripcion" name="descripcion" rows="3"><?= $valor("descripcion") ?></textarea></div>
                    <div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= $valor("observaciones") ?></textarea></div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="submit" class="btn-primary form-submit"><?= $recursoEditar ? "Actualizar recurso" : "Guardar recurso" ?></button>
                        <?php if ($recursoEditar): ?><a class="btn-secondary" href="inventario.php" style="text-decoration:none; padding:8px 16px;">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
    </main>
</div>
</body>
</html>
