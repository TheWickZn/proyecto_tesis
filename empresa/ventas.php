<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
if ($conexion) {
    exigirPermiso($conexion, "ventas.gestionar");
}

$mensaje = "";
$error = "";
$ventaEditar = null;
$ventas = [];
$clientes = [];
$servicios = [];

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $idVenta = (int) ($_POST["id_venta"] ?? 0);
                $datos = [
                    ":id_cliente" => (int) ($_POST["id_cliente"] ?? 0),
                    ":id_servicio" => (int) ($_POST["id_servicio"] ?? 0) ?: null,
                    ":id_oportunidad" => (int) ($_POST["id_oportunidad"] ?? 0) ?: null,
                    ":responsable_id" => (int) ($_POST["responsable_id"] ?? $_SESSION["id_usuario"]) ?: null,
                    ":monto" => max(0, (float) ($_POST["monto"] ?? 0)),
                    ":fecha" => $_POST["fecha"] ?? date("Y-m-d"),
                    ":estado" => trim($_POST["estado"] ?? "Pendiente"),
                    ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null,
                ];
                if ($datos[":id_cliente"] <= 0) {
                    throw new RuntimeException("Selecciona un cliente para registrar la venta.");
                }
                if ($idVenta > 0) {
                    $datos[":id_venta"] = $idVenta;
                    $sql = "UPDATE ventas SET id_cliente = :id_cliente, id_servicio = :id_servicio, id_oportunidad = :id_oportunidad,
                            responsable_id = :responsable_id, monto = :monto, fecha = :fecha, estado = :estado,
                            observaciones = :observaciones WHERE id_venta = :id_venta";
                    $conexion->prepare($sql)->execute($datos);
                    $mensaje = "Venta actualizada correctamente.";
                } else {
                    $sql = "INSERT INTO ventas (id_cliente, id_servicio, id_oportunidad, responsable_id, monto, fecha, estado, observaciones)
                            VALUES (:id_cliente, :id_servicio, :id_oportunidad, :responsable_id, :monto, :fecha, :estado, :observaciones)";
                    $conexion->prepare($sql)->execute($datos);
                    $mensaje = "Venta registrada correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $idVenta = (int) ($_POST["id_venta"] ?? 0);
                if ($idVenta > 0) {
                    $conexion->prepare("DELETE FROM ventas WHERE id_venta = :id_venta")->execute([":id_venta" => $idVenta]);
                    $mensaje = "Venta eliminada correctamente.";
                }
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM ventas WHERE id_venta = :id_venta");
            $stmt->execute([":id_venta" => (int) $_GET["editar"]]);
            $ventaEditar = $stmt->fetch() ?: null;
        }

        $clientes = $conexion->query("SELECT id_cliente, nombres, apellidos, empresa FROM clientes WHERE estado = 'Activo' ORDER BY apellidos, nombres")->fetchAll();
        $servicios = $conexion->query("SELECT id_servicio, nombre, precio FROM servicios WHERE estado = 'Activo' ORDER BY nombre")->fetchAll();
        $ventas = $conexion->query("SELECT v.*, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente, s.nombre AS servicio,
            CONCAT_WS(' ', u.nombres, u.apellidos) AS responsable
            FROM ventas v INNER JOIN clientes c ON c.id_cliente = v.id_cliente
            LEFT JOIN servicios s ON s.id_servicio = v.id_servicio
            LEFT JOIN usuarios u ON u.id_usuario = v.responsable_id
            ORDER BY v.fecha DESC, v.id_venta DESC")->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación.";
    }
}

$valor = static function (string $campo) use ($ventaEditar): string {
    return htmlspecialchars((string) ($ventaEditar[$campo] ?? ""), ENT_QUOTES, "UTF-8");
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Gestión comercial</p><h1>Ventas</h1><p>Registra ventas relacionadas con clientes, servicios y oportunidades.</p></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <section class="crud-layout">
            <div class="panel"><div class="panel-heading"><h3><?= $ventaEditar ? "Editar venta" : "Nueva venta" ?></h3><?php if ($ventaEditar): ?><a class="text-link" href="ventas.php">Cancelar</a><?php endif; ?></div>
                <form method="post" class="data-form"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id_venta" value="<?= (int) ($ventaEditar["id_venta"] ?? 0) ?>">
                    <div class="campo"><label for="id_cliente">Cliente *</label><select id="id_cliente" name="id_cliente" required><option value="">Seleccionar cliente</option><?php foreach ($clientes as $cliente): ?><option value="<?= (int) $cliente["id_cliente"] ?>" <?= (int) ($ventaEditar["id_cliente"] ?? 0) === (int) $cliente["id_cliente"] ? "selected" : "" ?>><?= htmlspecialchars(trim($cliente["nombres"] . " " . ($cliente["apellidos"] ?? "")), ENT_QUOTES, "UTF-8") ?><?= $cliente["empresa"] ? " - " . htmlspecialchars($cliente["empresa"], ENT_QUOTES, "UTF-8") : "" ?></option><?php endforeach; ?></select></div>
                    <div class="campo"><label for="id_servicio">Servicio</label><select id="id_servicio" name="id_servicio"><option value="">Sin servicio</option><?php foreach ($servicios as $servicio): ?><option value="<?= (int) $servicio["id_servicio"] ?>" <?= (int) ($ventaEditar["id_servicio"] ?? 0) === (int) $servicio["id_servicio"] ? "selected" : "" ?>><?= htmlspecialchars($servicio["nombre"], ENT_QUOTES, "UTF-8") ?> - S/ <?= number_format((float) $servicio["precio"], 2) ?></option><?php endforeach; ?></select></div>
                    <div class="form-grid"><div class="campo"><label for="monto">Monto</label><input type="number" min="0" step="0.01" id="monto" name="monto" value="<?= $valor("monto") ?: "0.00" ?>"></div><div class="campo"><label for="fecha">Fecha</label><input type="date" id="fecha" name="fecha" required value="<?= $valor("fecha") ?: date("Y-m-d") ?>"></div></div>
                    <div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><option <?= !$ventaEditar || $valor("estado") === "Pendiente" ? "selected" : "" ?>>Pendiente</option><option <?= $valor("estado") === "Confirmada" ? "selected" : "" ?>>Confirmada</option><option <?= $valor("estado") === "Pagada" ? "selected" : "" ?>>Pagada</option><option <?= $valor("estado") === "Cancelada" ? "selected" : "" ?>>Cancelada</option></select></div>
                    <div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= $valor("observaciones") ?></textarea></div><button type="submit" class="btn-primary form-submit">Guardar venta</button>
                </form>
            </div>
            <div class="panel"><div class="panel-heading"><h3>Ventas registradas</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Cliente</th><th>Servicio</th><th>Monto</th><th>Fecha</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($ventas as $venta): ?><tr><td><strong><?= htmlspecialchars($venta["cliente"], ENT_QUOTES, "UTF-8") ?></strong><small><?= htmlspecialchars($venta["responsable"] ?? "", ENT_QUOTES, "UTF-8") ?></small></td><td><?= htmlspecialchars($venta["servicio"] ?? "-", ENT_QUOTES, "UTF-8") ?></td><td>S/ <?= number_format((float) $venta["monto"], 2) ?></td><td><?= htmlspecialchars($venta["fecha"], ENT_QUOTES, "UTF-8") ?></td><td><span class="status-badge status-<?= strtolower($venta["estado"]) ?>"><?= htmlspecialchars($venta["estado"], ENT_QUOTES, "UTF-8") ?></span></td><td class="actions"><a class="text-link" href="ventas.php?editar=<?= (int) $venta["id_venta"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar esta venta?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_venta" value="<?= (int) $venta["id_venta"] ?>"><button class="link-button danger" type="submit">Eliminar</button></form></td></tr><?php endforeach; ?>
                <?php if (empty($ventas)): ?><tr><td colspan="6" class="empty-state">No hay ventas registradas.</td></tr><?php endif; ?></tbody></table></div></div>
        </section>
    </main>
</div>
</body>
</html>
