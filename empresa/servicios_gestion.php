<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
if ($conexion) {
    exigirPermiso($conexion, "servicios.gestionar");
}

$mensaje = "";
$error = "";
$servicioEditar = null;
$servicios = [];

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $idServicio = (int) ($_POST["id_servicio"] ?? 0);
                $datos = [
                    ":nombre" => trim($_POST["nombre"] ?? ""),
                    ":descripcion" => trim($_POST["descripcion"] ?? "") ?: null,
                    ":precio" => max(0, (float) ($_POST["precio"] ?? 0)),
                    ":estado" => trim($_POST["estado"] ?? "Activo"),
                ];
                if ($datos[":nombre"] === "") {
                    throw new RuntimeException("El nombre del servicio es obligatorio.");
                }
                if ($idServicio > 0) {
                    $datos[":id_servicio"] = $idServicio;
                    $sql = "UPDATE servicios SET nombre = :nombre, descripcion = :descripcion, precio = :precio, estado = :estado WHERE id_servicio = :id_servicio";
                    $conexion->prepare($sql)->execute($datos);
                    $mensaje = "Servicio actualizado correctamente.";
                } else {
                    $sql = "INSERT INTO servicios (nombre, descripcion, precio, estado) VALUES (:nombre, :descripcion, :precio, :estado)";
                    $conexion->prepare($sql)->execute($datos);
                    $mensaje = "Servicio registrado correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $idServicio = (int) ($_POST["id_servicio"] ?? 0);
                if ($idServicio > 0) {
                    $conexion->prepare("DELETE FROM servicios WHERE id_servicio = :id_servicio")->execute([":id_servicio" => $idServicio]);
                    $mensaje = "Servicio eliminado correctamente.";
                }
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM servicios WHERE id_servicio = :id_servicio");
            $stmt->execute([":id_servicio" => (int) $_GET["editar"]]);
            $servicioEditar = $stmt->fetch() ?: null;
        }

        $servicios = $conexion->query("SELECT * FROM servicios ORDER BY id_servicio DESC")->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación.";
    }
}

$valor = static function (string $campo) use ($servicioEditar): string {
    return htmlspecialchars((string) ($servicioEditar[$campo] ?? ""), ENT_QUOTES, "UTF-8");
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Servicios | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
    <div class="module-shell">
        <?php require "_navegacion.php"; ?>
        <main class="module-content">
            <div class="module-banner"><p class="eyebrow">Gestión comercial</p><h1>Servicios</h1><p>Administra el catálogo que podrá asociarse a oportunidades y ventas.</p></div>
            <?php if ($mensaje !== ""): ?><div class="notice success"><?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
            <?php if ($error !== ""): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
            <section class="crud-layout">
                <div class="panel"><div class="panel-heading"><h3><?= $servicioEditar ? "Editar servicio" : "Nuevo servicio" ?></h3><?php if ($servicioEditar): ?><a class="text-link" href="servicios_gestion.php">Cancelar</a><?php endif; ?></div>
                    <form method="post" class="data-form"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id_servicio" value="<?= (int) ($servicioEditar["id_servicio"] ?? 0) ?>">
                        <div class="campo"><label for="nombre">Nombre *</label><input id="nombre" name="nombre" required value="<?= $valor("nombre") ?>"></div>
                        <div class="campo"><label for="descripcion">Descripción</label><textarea id="descripcion" name="descripcion" rows="4"><?= $valor("descripcion") ?></textarea></div>
                        <div class="form-grid"><div class="campo"><label for="precio">Precio</label><input type="number" min="0" step="0.01" id="precio" name="precio" value="<?= $valor("precio") ?: "0.00" ?>"></div><div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><option <?= !$servicioEditar || $valor("estado") === "Activo" ? "selected" : "" ?>>Activo</option><option <?= $valor("estado") === "Inactivo" ? "selected" : "" ?>>Inactivo</option></select></div></div>
                        <button type="submit" class="btn-primary form-submit">Guardar servicio</button>
                    </form>
                </div>
                <div class="panel"><div class="panel-heading"><h3>Catálogo de servicios</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Servicio</th><th>Precio</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
                    <?php foreach ($servicios as $servicio): ?><tr><td><strong><?= htmlspecialchars($servicio["nombre"], ENT_QUOTES, "UTF-8") ?></strong><small><?= htmlspecialchars($servicio["descripcion"] ?? "", ENT_QUOTES, "UTF-8") ?></small></td><td>S/ <?= number_format((float) $servicio["precio"], 2) ?></td><td><span class="status-badge"><?= htmlspecialchars($servicio["estado"], ENT_QUOTES, "UTF-8") ?></span></td><td class="actions"><a class="text-link" href="servicios_gestion.php?editar=<?= (int) $servicio["id_servicio"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este servicio?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_servicio" value="<?= (int) $servicio["id_servicio"] ?>"><button class="link-button danger" type="submit">Eliminar</button></form></td></tr><?php endforeach; ?>
                    <?php if (empty($servicios)): ?><tr><td colspan="4" class="empty-state">No hay servicios registrados.</td></tr><?php endif; ?>
                </tbody></table></div></div>
            </section>
        </main>
    </div>
</body>
</html>
