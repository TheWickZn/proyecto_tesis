<?php
session_start();
if (!isset($_SESSION["id_usuario"])) { header("Location: ../auth/login.php"); exit; }
require_once "../config/conexion.php";
require_once "../config/seguridad.php";
if ($conexion) {
    exigirPermiso($conexion, "legal.gestionar");
}
$idExpediente = (int) ($_GET["expediente"] ?? $_POST["id_expediente"] ?? 0);
$mensaje = "";
$error = "";
$expediente = null;
$recibos = [];
$reciboEditar = null;

if (!$conexion) { $error = "No se pudo conectar con la base de datos."; }
else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $idRecibo = (int) ($_POST["id_recibo"] ?? 0);
                $monto = filter_var($_POST["monto"] ?? null, FILTER_VALIDATE_FLOAT);
                $datos = [":id_expediente" => $idExpediente, ":numero_recibo" => trim($_POST["numero_recibo"] ?? ""), ":fecha" => $_POST["fecha"] ?? date("Y-m-d"), ":monto" => $monto, ":estado" => trim($_POST["estado"] ?? "Pendiente"), ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null];
                $fechaRecibo = DateTime::createFromFormat("!Y-m-d", (string) $datos[":fecha"]);
                if ($idExpediente <= 0 || $datos[":numero_recibo"] === "" || $monto === false || $monto < 0 ||
                    !$fechaRecibo || $fechaRecibo->format("Y-m-d") !== $datos[":fecha"] ||
                    !in_array($datos[":estado"], ["Pendiente", "Pagado", "Anulado"], true)) {
                    throw new RuntimeException("Completa número, fecha válida, monto no negativo y estado permitido.");
                }
                $stmtExpedienteActivo = $conexion->prepare("SELECT 1 FROM expedientes WHERE id_expediente = :id AND estado <> 'Archivado'");
                $stmtExpedienteActivo->execute([":id" => $idExpediente]);
                if (!$stmtExpedienteActivo->fetchColumn()) {
                    throw new RuntimeException("No se pueden registrar cambios en un expediente archivado.");
                }
                if ($idRecibo > 0) {
                    $datos[":id_recibo"] = $idRecibo;
                    $stmt = $conexion->prepare("UPDATE recibos_expedientes SET numero_recibo = :numero_recibo, fecha = :fecha, monto = :monto, estado = :estado, observaciones = :observaciones WHERE id_recibo = :id_recibo AND id_expediente = :id_expediente");
                    $stmt->execute($datos);
                    if ($stmt->rowCount() === 0) {
                        $existe = $conexion->prepare("SELECT 1 FROM recibos_expedientes WHERE id_recibo = :id AND id_expediente = :expediente");
                        $existe->execute([":id" => $idRecibo, ":expediente" => $idExpediente]);
                        if (!$existe->fetchColumn()) throw new RuntimeException("El recibo seleccionado no existe para este expediente.");
                    }
                    $mensaje = "Recibo actualizado correctamente.";
                } else {
                    $conexion->prepare("INSERT INTO recibos_expedientes (id_expediente, numero_recibo, fecha, monto, estado, observaciones) VALUES (:id_expediente, :numero_recibo, :fecha, :monto, :estado, :observaciones)")->execute($datos);
                    $mensaje = "Recibo registrado correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $id = (int) ($_POST["id_recibo"] ?? 0);
                if ($id > 0) {
                    $stmt = $conexion->prepare("DELETE FROM recibos_expedientes WHERE id_recibo = :id AND id_expediente = :expediente");
                    $stmt->execute([":id" => $id, ":expediente" => $idExpediente]);
                    if ($stmt->rowCount() !== 1) throw new RuntimeException("El recibo seleccionado no existe para este expediente.");
                    $mensaje = "Recibo eliminado correctamente.";
                }
            }
        }
        $stmt = $conexion->prepare("SELECT e.numero_expediente, e.estado, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente FROM expedientes e INNER JOIN clientes c ON c.id_cliente=e.id_cliente WHERE e.id_expediente=:id");
        $stmt->execute([":id" => $idExpediente]);
        $expediente = $stmt->fetch() ?: null;
        if (!$expediente) throw new RuntimeException("El expediente indicado no existe.");
        $stmt = $conexion->prepare("SELECT * FROM recibos_expedientes WHERE id_expediente=:id ORDER BY fecha DESC, id_recibo DESC");
        $stmt->execute([":id" => $idExpediente]);
        $recibos = $stmt->fetchAll();
        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM recibos_expedientes WHERE id_recibo = :id AND id_expediente = :expediente");
            $stmt->execute([":id" => (int) $_GET["editar"], ":expediente" => $idExpediente]);
            $reciboEditar = $stmt->fetch() ?: null;
        }
    } catch (Throwable $e) { $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación."; }
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Recibos | GROUP_CEA</title><link rel="stylesheet" href="../css/estilos.css"></head>
<body class="dashboard-body"><div class="module-shell"><?php require "_navegacion.php"; ?><main class="module-content"><div class="module-banner"><p class="eyebrow">Gestión legal</p><h1>Recibos del expediente</h1><p><?= $expediente ? htmlspecialchars($expediente["numero_expediente"] . " - " . $expediente["cliente"], ENT_QUOTES, "UTF-8") : "Consulta de recibos" ?></p></div><?php if ($mensaje !== ""): ?><div class="notice success"><?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?><?php if ($error !== ""): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?><section class="crud-layout"><div class="panel"><div class="panel-heading"><h3><?= $reciboEditar ? "Editar recibo" : "Nuevo recibo" ?></h3><?php if ($reciboEditar): ?><a class="text-link" href="recibos.php?expediente=<?= $idExpediente ?>">Cancelar</a><?php endif; ?></div><form method="post" class="data-form"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id_expediente" value="<?= $idExpediente ?>"><input type="hidden" name="id_recibo" value="<?= (int) ($reciboEditar["id_recibo"] ?? 0) ?>"><div class="campo"><label for="numero_recibo">Número de recibo *</label><input id="numero_recibo" name="numero_recibo" required value="<?= htmlspecialchars($reciboEditar["numero_recibo"] ?? "", ENT_QUOTES, "UTF-8") ?>"></div><div class="form-grid"><div class="campo"><label for="fecha">Fecha</label><input type="date" id="fecha" name="fecha" required value="<?= htmlspecialchars($reciboEditar["fecha"] ?? date("Y-m-d"), ENT_QUOTES, "UTF-8") ?>"></div><div class="campo"><label for="monto">Monto</label><input type="number" min="0" step="0.01" id="monto" name="monto" value="<?= htmlspecialchars((string) ($reciboEditar["monto"] ?? "0.00"), ENT_QUOTES, "UTF-8") ?>"></div></div><div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><?php foreach (["Pendiente", "Pagado", "Anulado"] as $estado): ?><option <?= ($reciboEditar["estado"] ?? "Pendiente") === $estado ? "selected" : "" ?>><?= $estado ?></option><?php endforeach; ?></select></div><div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= htmlspecialchars($reciboEditar["observaciones"] ?? "", ENT_QUOTES, "UTF-8") ?></textarea></div><button class="btn-primary form-submit" type="submit"><?= $reciboEditar ? "Guardar cambios" : "Registrar recibo" ?></button></form></div><div class="panel"><div class="panel-heading"><h3>Historial de recibos</h3><a class="text-link" href="expedientes_gestion.php">Volver a expedientes</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Número</th><th>Fecha</th><th>Monto</th><th>Estado</th><th>Acción</th></tr></thead><tbody><?php foreach ($recibos as $recibo): ?><tr><td><?= htmlspecialchars($recibo["numero_recibo"], ENT_QUOTES, "UTF-8") ?></td><td><?= htmlspecialchars($recibo["fecha"], ENT_QUOTES, "UTF-8") ?></td><td>S/ <?= number_format((float) $recibo["monto"], 2) ?></td><td><span class="status-badge"><?= htmlspecialchars($recibo["estado"], ENT_QUOTES, "UTF-8") ?></span></td><td class="actions"><a class="text-link" href="recibos.php?expediente=<?= $idExpediente ?>&editar=<?= (int) $recibo["id_recibo"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este recibo?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_recibo" value="<?= (int) $recibo["id_recibo"] ?>"><input type="hidden" name="id_expediente" value="<?= $idExpediente ?>"><button class="link-button danger" type="submit">Eliminar</button></form></td></tr><?php endforeach; ?><?php if (empty($recibos)): ?><tr><td colspan="5" class="empty-state">No hay recibos registrados.</td></tr><?php endif; ?></tbody></table></div></div></section></main></div></body></html>
