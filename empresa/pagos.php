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
if (!in_array($_rolActual, ["administrador", "contabilidad", "finanzas"])) {
    http_response_code(403);
    exit("No tienes permisos para acceder a Pagos.");
}
$puedeGestionar = true;

$mensaje = "";
$error = "";
$pagoEditar = null;
$pagos = [];

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $id = (int) ($_POST["id_movimiento"] ?? 0);
                $monto = filter_var($_POST["monto"] ?? null, FILTER_VALIDATE_FLOAT);
                $datos = [
                    ":categoria" => trim($_POST["categoria"] ?? ""),
                    ":concepto" => trim($_POST["concepto"] ?? ""),
                    ":persona_proveedor" => trim($_POST["persona_proveedor"] ?? "") ?: null,
                    ":monto" => $monto,
                    ":fecha" => trim($_POST["fecha"] ?? ""),
                    ":metodo_pago" => trim($_POST["metodo_pago"] ?? ""),
                    ":responsable_id" => (int) $_SESSION["id_usuario"],
                    ":numero_comprobante" => trim($_POST["numero_comprobante"] ?? "") ?: null,
                    ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null,
                ];
                if ($datos[":categoria"] === "" || $datos[":concepto"] === "" || $monto === false || $monto < 0 || $datos[":fecha"] === "" || $datos[":metodo_pago"] === "") {
                    throw new RuntimeException("Completa categoría, concepto, monto válido, fecha y método de pago.");
                }

                if ($id > 0) {
                    $datos[":id_movimiento"] = $id;
                    $stmt = $conexion->prepare(
                        "UPDATE movimientos_financieros
                         SET categoria = :categoria, concepto = :concepto, persona_proveedor = :persona_proveedor,
                             monto = :monto, fecha = :fecha, metodo_pago = :metodo_pago,
                             responsable_id = :responsable_id, numero_comprobante = :numero_comprobante,
                             observaciones = :observaciones
                         WHERE id_movimiento = :id_movimiento AND tipo = 'Ingreso'"
                    );
                    $stmt->execute($datos);
                    if ($stmt->rowCount() === 0) {
                        $existe = $conexion->prepare("SELECT 1 FROM movimientos_financieros WHERE id_movimiento = :id AND tipo = 'Ingreso'");
                        $existe->execute([":id" => $id]);
                        if (!$existe->fetchColumn()) {
                            throw new RuntimeException("El pago seleccionado no existe.");
                        }
                    }
                    $mensaje = "Pago actualizado correctamente.";
                } else {
                    $conexion->prepare(
                        "INSERT INTO movimientos_financieros
                         (tipo, categoria, concepto, persona_proveedor, monto, fecha, metodo_pago, responsable_id, numero_comprobante, observaciones)
                         VALUES ('Ingreso', :categoria, :concepto, :persona_proveedor, :monto, :fecha, :metodo_pago, :responsable_id, :numero_comprobante, :observaciones)"
                    )->execute($datos);
                    $mensaje = "Pago registrado correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $id = (int) ($_POST["id_movimiento"] ?? 0);
                if ($id > 0) {
                    $stmt = $conexion->prepare("DELETE FROM movimientos_financieros WHERE id_movimiento = :id AND tipo = 'Ingreso'");
                    $stmt->execute([":id" => $id]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException("El pago seleccionado no existe.");
                    }
                    $mensaje = "Pago eliminado correctamente.";
                }
            }
        }

        if ($puedeGestionar && isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM movimientos_financieros WHERE id_movimiento = :id AND tipo = 'Ingreso'");
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $pagoEditar = $stmt->fetch() ?: null;
        }
        $pagos = $conexion->query(
            "SELECT m.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS responsable
             FROM movimientos_financieros m
             LEFT JOIN usuarios u ON u.id_usuario = m.responsable_id
             WHERE m.tipo = 'Ingreso'
             ORDER BY m.fecha DESC, m.id_movimiento DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación.";
    }
}

$valor = static fn (string $campo): string => htmlspecialchars((string) ($pagoEditar[$campo] ?? ""), ENT_QUOTES, "UTF-8");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagos | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Gestión financiera</p><h1>Pagos recibidos</h1><p>Los pagos quedan guardados como ingresos en el registro financiero común.</p><a class="btn-secondary btn-sm" href="contratos.php">Gestionar contratos, cuotas y saldos</a></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
        <!-- SECCIÓN 1: PAGOS REGISTRADOS ARRIBA DE PRIMERAS -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-heading">
                <h3>💳 Pagos registrados</h3>
                <span style="font-size:0.8rem; color:#64748b; font-weight:500;">Historial de ingresos validados</span>
            </div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha / concepto</th><th>Pagador</th><th>Monto</th><th>Método</th><th>Comprobante</th><?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($pagos as $pago): ?>
                <tr><td><strong><?= htmlspecialchars($pago["concepto"], ENT_QUOTES, "UTF-8") ?></strong><small><?= htmlspecialchars($pago["fecha"] . " · " . $pago["categoria"], ENT_QUOTES, "UTF-8") ?></small></td><td><?= htmlspecialchars($pago["persona_proveedor"] ?? "—", ENT_QUOTES, "UTF-8") ?></td><td>S/ <?= number_format((float) $pago["monto"], 2) ?></td><td><?= htmlspecialchars($pago["metodo_pago"], ENT_QUOTES, "UTF-8") ?></td><td><?= htmlspecialchars($pago["numero_comprobante"] ?? "—", ENT_QUOTES, "UTF-8") ?></td><?php if ($puedeGestionar): ?><td class="actions"><a class="text-link" href="pagos.php?editar=<?= (int) $pago["id_movimiento"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este pago?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_movimiento" value="<?= (int) $pago["id_movimiento"] ?>"><button class="link-button danger" type="submit">Eliminar</button></form></td><?php endif; ?></tr>
            <?php endforeach; ?>
            <?php if (!$pagos): ?><tr><td colspan="<?= $puedeGestionar ? 6 : 5 ?>" class="empty-state">No hay pagos registrados.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>

        <!-- SECCIÓN 2: FORMULARIO DESPLEGABLE REGISTRAR PAGO -->
        <?php if ($puedeGestionar): ?>
        <details class="collapsible-card" <?= $pagoEditar ? "open" : "" ?> style="margin-bottom: 28px;">
            <summary>
                <div class="summary-left">
                    <span class="summary-icon">💵</span>
                    <span class="summary-title"><?= $pagoEditar ? "Editar Pago" : "Registrar Pago" ?></span>
                    <span class="summary-badge"><?= $pagoEditar ? "Modo edición" : "Nuevo cobro de cliente" ?></span>
                </div>
                <span class="summary-chevron">▼</span>
            </summary>
            <div class="collapsible-body">
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id_movimiento" value="<?= (int) ($pagoEditar["id_movimiento"] ?? 0) ?>">
                    <div class="form-grid">
                        <div class="campo"><label for="categoria">Categoría *</label><input id="categoria" name="categoria" required value="<?= $valor("categoria") ?>"></div>
                        <div class="campo"><label for="concepto">Concepto *</label><input id="concepto" name="concepto" required value="<?= $valor("concepto") ?>"></div>
                        <div class="campo"><label for="persona_proveedor">Cliente / pagador</label><input id="persona_proveedor" name="persona_proveedor" value="<?= $valor("persona_proveedor") ?>"></div>
                        <div class="campo"><label for="monto">Monto *</label><input type="number" min="0" step="0.01" id="monto" name="monto" required value="<?= $valor("monto") ?>"></div>
                        <div class="campo"><label for="fecha">Fecha *</label><input type="date" id="fecha" name="fecha" required value="<?= $valor("fecha") ?: date("Y-m-d") ?>"></div>
                        <div class="campo"><label for="metodo_pago">Método de pago *</label><select id="metodo_pago" name="metodo_pago" required><?php foreach (["Efectivo", "Transferencia", "Tarjeta", "Billetera digital", "Otro"] as $metodo): ?><option value="<?= htmlspecialchars($metodo, ENT_QUOTES, "UTF-8") ?>" <?= $valor("metodo_pago") === $metodo ? "selected" : "" ?>><?= htmlspecialchars($metodo, ENT_QUOTES, "UTF-8") ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="numero_comprobante">N.º de comprobante</label><input id="numero_comprobante" name="numero_comprobante" value="<?= $valor("numero_comprobante") ?>"></div>
                    </div>
                    <div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= $valor("observaciones") ?></textarea></div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="submit" class="btn-primary form-submit"><?= $pagoEditar ? "Actualizar pago" : "Guardar pago" ?></button>
                        <?php if ($pagoEditar): ?><a class="btn-secondary" href="pagos.php" style="text-decoration:none; padding:8px 16px;">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
