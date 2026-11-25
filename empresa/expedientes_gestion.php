<?php
session_start();
if (!isset($_SESSION["id_usuario"])) { header("Location: ../auth/login.php"); exit; }
require_once "../config/conexion.php";
require_once "../config/seguridad.php";
if ($conexion) {
    exigirAlgunPermiso($conexion, ["expedientes.gestionar", "legal.gestionar", "tesis.gestionar", "documentos.gestionar"]);
}

$mensaje = "";
$error = "";
$clientes = [];
$expedientes = [];
$expedienteEditar = null;

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar_expediente") {
                $id = (int) ($_POST["id_expediente"] ?? 0);
                $datos = [
                    ":id_cliente" => (int) ($_POST["id_cliente"] ?? 0),
                    ":numero_expediente" => trim($_POST["numero_expediente"] ?? ""),
                    ":estado" => trim($_POST["estado"] ?? "Abierto"),
                    ":descripcion" => trim($_POST["descripcion"] ?? "") ?: null,
                    ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null,
                ];
                if ($datos[":id_cliente"] <= 0 || $datos[":numero_expediente"] === "" ||
                    !in_array($datos[":estado"], ["Abierto", "Cerrado", "Archivado"], true)) {
                    throw new RuntimeException("Cliente, número de expediente y un estado válido son obligatorios.");
                }
                $stmtCliente = $conexion->prepare("SELECT 1 FROM clientes WHERE id_cliente = :id AND estado = 'Activo'");
                $stmtCliente->execute([":id" => $datos[":id_cliente"]]);
                if (!$stmtCliente->fetchColumn()) {
                    throw new RuntimeException("El cliente seleccionado no existe o está inactivo.");
                }
                if ($id > 0) {
                    $datos[":id_expediente"] = $id;
                    $conexion->prepare("UPDATE expedientes SET id_cliente=:id_cliente, numero_expediente=:numero_expediente, estado=:estado, descripcion=:descripcion, observaciones=:observaciones WHERE id_expediente=:id_expediente")->execute($datos);
                    $mensaje = "Expediente actualizado correctamente.";
                } else {
                    $conexion->prepare("INSERT INTO expedientes (id_cliente, numero_expediente, estado, descripcion, observaciones) VALUES (:id_cliente, :numero_expediente, :estado, :descripcion, :observaciones)")->execute($datos);
                    $mensaje = "Expediente registrado correctamente.";
                }
            } elseif ($accion === "archivar_expediente") {
                $id = (int) ($_POST["id_expediente"] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException("Selecciona un expediente válido.");
                }
                $stmt = $conexion->prepare("UPDATE expedientes SET estado = 'Archivado' WHERE id_expediente = :id AND estado <> 'Archivado'");
                $stmt->execute([":id" => $id]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException("El expediente no existe o ya fue archivado.");
                }
                $mensaje = "Expediente archivado. Sus recibos y casos vinculados se conservaron.";
            } elseif ($accion === "guardar_recibo") {
                $monto = filter_var($_POST["monto_recibo"] ?? null, FILTER_VALIDATE_FLOAT);
                $datos = [
                    ":id_expediente" => (int) ($_POST["id_expediente_recibo"] ?? 0),
                    ":numero_recibo" => trim($_POST["numero_recibo"] ?? ""),
                    ":fecha" => $_POST["fecha_recibo"] ?? date("Y-m-d"),
                    ":monto" => $monto,
                    ":estado" => trim($_POST["estado_recibo"] ?? "Pendiente"),
                    ":observaciones" => trim($_POST["observaciones_recibo"] ?? "") ?: null,
                ];
                $fechaRecibo = DateTime::createFromFormat("!Y-m-d", (string) $datos[":fecha"]);
                if ($datos[":id_expediente"] <= 0 || $datos[":numero_recibo"] === "" ||
                    $monto === false || $monto < 0 || !$fechaRecibo ||
                    $fechaRecibo->format("Y-m-d") !== $datos[":fecha"] ||
                    !in_array($datos[":estado"], ["Pendiente", "Pagado", "Anulado"], true)) {
                    throw new RuntimeException("Completa expediente, número, fecha, monto no negativo y estado válidos.");
                }
                $stmtExpediente = $conexion->prepare("SELECT 1 FROM expedientes WHERE id_expediente = :id AND estado <> 'Archivado'");
                $stmtExpediente->execute([":id" => $datos[":id_expediente"]]);
                if (!$stmtExpediente->fetchColumn()) {
                    throw new RuntimeException("Selecciona un expediente abierto.");
                }
                $conexion->prepare("INSERT INTO recibos_expedientes (id_expediente, numero_recibo, fecha, monto, estado, observaciones) VALUES (:id_expediente, :numero_recibo, :fecha, :monto, :estado, :observaciones)")->execute($datos);
                $mensaje = "Recibo registrado correctamente.";
            } elseif ($accion === "eliminar_recibo") {
                $id = (int) ($_POST["id_recibo"] ?? 0);
                if ($id > 0) { $conexion->prepare("DELETE FROM recibos_expedientes WHERE id_recibo=:id")->execute([":id" => $id]); $mensaje = "Recibo eliminado correctamente."; }
            }
        }
        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM expedientes WHERE id_expediente=:id");
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $expedienteEditar = $stmt->fetch() ?: null;
        }
        $clientes = $conexion->query("SELECT id_cliente, nombres, apellidos, empresa FROM clientes WHERE estado='Activo' ORDER BY apellidos, nombres")->fetchAll();
        $expedientes = $conexion->query("SELECT e.*, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente FROM expedientes e INNER JOIN clientes c ON c.id_cliente=e.id_cliente ORDER BY e.id_expediente DESC")->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación. Verifica que el número no esté repetido.";
    }
}
$valor = static function (string $campo) use ($expedienteEditar): string { return htmlspecialchars((string) ($expedienteEditar[$campo] ?? ""), ENT_QUOTES, "UTF-8"); };
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Expedientes | GROUP_CEA</title><link rel="stylesheet" href="../css/estilos.css"></head>
<body class="dashboard-body"><div class="module-shell"><?php require "_navegacion.php"; ?><main class="module-content">
<div class="module-banner"><p class="eyebrow">Gestión legal</p><h1>Expedientes</h1><p>Centraliza casos de clientes y sus recibos generales.</p></div>
<?php if ($mensaje !== ""): ?><div class="notice success"><?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?><?php if ($error !== ""): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
<section class="crud-layout"><div class="panel"><div class="panel-heading"><h3><?= $expedienteEditar ? "Editar expediente" : "Nuevo expediente" ?></h3><?php if ($expedienteEditar): ?><a class="text-link" href="expedientes_gestion.php">Cancelar</a><?php endif; ?></div>
<form method="post" class="data-form"><input type="hidden" name="accion" value="guardar_expediente"><input type="hidden" name="id_expediente" value="<?= (int) ($expedienteEditar["id_expediente"] ?? 0) ?>"><div class="campo"><label for="id_cliente">Cliente *</label><select id="id_cliente" name="id_cliente" required><option value="">Seleccionar cliente</option><?php foreach ($clientes as $cliente): ?><option value="<?= (int) $cliente["id_cliente"] ?>" <?= (int) ($expedienteEditar["id_cliente"] ?? 0) === (int) $cliente["id_cliente"] ? "selected" : "" ?>><?= htmlspecialchars(trim($cliente["nombres"] . " " . ($cliente["apellidos"] ?? "")), ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></div><div class="campo"><label for="numero_expediente">Número de expediente *</label><input id="numero_expediente" name="numero_expediente" required value="<?= $valor("numero_expediente") ?>"></div><div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><option <?= !$expedienteEditar || $valor("estado") === "Abierto" ? "selected" : "" ?>>Abierto</option><option <?= $valor("estado") === "Cerrado" ? "selected" : "" ?>>Cerrado</option><option <?= $valor("estado") === "Archivado" ? "selected" : "" ?>>Archivado</option></select></div><div class="campo"><label for="descripcion">Descripción</label><textarea id="descripcion" name="descripcion" rows="4"><?= $valor("descripcion") ?></textarea></div><div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= $valor("observaciones") ?></textarea></div><button class="btn-primary form-submit" type="submit">Guardar expediente</button></form></div>
<div class="panel"><div class="panel-heading"><h3>Expedientes registrados</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Número</th><th>Cliente</th><th>Estado</th><th>Recibos</th><th>Acciones</th></tr></thead><tbody><?php foreach ($expedientes as $expediente): ?><tr><td><strong><?= htmlspecialchars($expediente["numero_expediente"], ENT_QUOTES, "UTF-8") ?></strong><small><?= htmlspecialchars($expediente["descripcion"] ?? "", ENT_QUOTES, "UTF-8") ?></small></td><td><?= htmlspecialchars($expediente["cliente"], ENT_QUOTES, "UTF-8") ?></td><td><span class="status-badge"><?= htmlspecialchars($expediente["estado"], ENT_QUOTES, "UTF-8") ?></span></td><td><a class="text-link" href="recibos.php?expediente=<?= (int) $expediente["id_expediente"] ?>">Gestionar recibos</a></td><td class="actions"><a class="text-link" href="expedientes_gestion.php?editar=<?= (int) $expediente["id_expediente"] ?>">Editar</a><?php if ($expediente["estado"] !== "Archivado"): ?><form method="post" onsubmit="return confirm('¿Archivar este expediente? Se conservarán recibos y casos vinculados.');"><input type="hidden" name="accion" value="archivar_expediente"><input type="hidden" name="id_expediente" value="<?= (int) $expediente["id_expediente"] ?>"><button class="link-button danger" type="submit">Archivar</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (empty($expedientes)): ?><tr><td colspan="5" class="empty-state">No hay expedientes. Registra primero un cliente.</td></tr><?php endif; ?></tbody></table></div></div></section>
</main></div></body></html>
