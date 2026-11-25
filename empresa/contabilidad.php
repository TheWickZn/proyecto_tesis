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
    exit("No tienes permisos para acceder a Contabilidad.");
}
$puedeGestionar = true;

$mensaje = "";
$error = "";
$movimientoEditar = null;
$movimientos = [];
$tipos = ["Ingreso", "Egreso"];
$metodos = ["Efectivo", "Transferencia", "Tarjeta", "Billetera digital", "Otro"];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $id = (int) ($_POST["id_movimiento"] ?? 0);
                $tipo = trim($_POST["tipo"] ?? "");
                $monto = filter_var($_POST["monto"] ?? null, FILTER_VALIDATE_FLOAT);
                $metodo = trim($_POST["metodo_pago"] ?? "");
                $tipoPersona = trim($_POST["tipo_persona"] ?? "");
                $datos = [
                    ":tipo" => $tipo,
                    ":categoria" => trim($_POST["categoria"] ?? ""),
                    ":concepto" => trim($_POST["concepto"] ?? ""),
                    ":persona_proveedor" => trim($_POST["persona_proveedor"] ?? "") ?: null,
                    ":tipo_persona" => $tipoPersona,
                    ":monto" => $monto,
                    ":fecha" => trim($_POST["fecha"] ?? ""),
                    ":metodo_pago" => $metodo,
                    ":responsable_id" => (int) $_SESSION["id_usuario"],
                    ":numero_comprobante" => trim($_POST["numero_comprobante"] ?? "") ?: null,
                    ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null,
                ];
                if (!in_array($tipo, $tipos, true) || !in_array($metodo, $metodos, true) ||
                    $datos[":categoria"] === "" || $datos[":concepto"] === "" ||
                    $monto === false || $monto < 0 || $datos[":fecha"] === "") {
                    throw new RuntimeException("Completa tipo, categoría, concepto, monto válido, fecha y método de pago.");
                }
                if (!in_array($tipoPersona, ["Cliente", "Proveedor"], true)) {
                    throw new RuntimeException("Selecciona si el movimiento corresponde a un cliente o proveedor.");
                }

                if ($id > 0) {
                    $datos[":id_movimiento"] = $id;
                    $stmt = $conexion->prepare(
                        "UPDATE movimientos_financieros
                         SET tipo = :tipo, categoria = :categoria, concepto = :concepto,
                             persona_proveedor = :persona_proveedor, tipo_persona = :tipo_persona, monto = :monto, fecha = :fecha,
                             metodo_pago = :metodo_pago, responsable_id = :responsable_id,
                             numero_comprobante = :numero_comprobante, observaciones = :observaciones
                         WHERE id_movimiento = :id_movimiento"
                    );
                    $stmt->execute($datos);
                    if ($stmt->rowCount() === 0) {
                        $existe = $conexion->prepare("SELECT 1 FROM movimientos_financieros WHERE id_movimiento = :id");
                        $existe->execute([":id" => $id]);
                        if (!$existe->fetchColumn()) {
                            throw new RuntimeException("El movimiento seleccionado no existe.");
                        }
                    }
                    $mensaje = "Movimiento actualizado correctamente.";
                } else {
                    $conexion->prepare(
                        "INSERT INTO movimientos_financieros
                         (tipo, categoria, concepto, persona_proveedor, tipo_persona, monto, fecha, metodo_pago, responsable_id, numero_comprobante, observaciones)
                         VALUES (:tipo, :categoria, :concepto, :persona_proveedor, :tipo_persona, :monto, :fecha, :metodo_pago, :responsable_id, :numero_comprobante, :observaciones)"
                    )->execute($datos);
                    $mensaje = "Movimiento registrado correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $id = (int) ($_POST["id_movimiento"] ?? 0);
                if ($id > 0) {
                    $stmt = $conexion->prepare("DELETE FROM movimientos_financieros WHERE id_movimiento = :id");
                    $stmt->execute([":id" => $id]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException("El movimiento seleccionado no existe.");
                    }
                    $mensaje = "Movimiento eliminado correctamente.";
                }
            }
        }

        if ($puedeGestionar && isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM movimientos_financieros WHERE id_movimiento = :id");
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $movimientoEditar = $stmt->fetch() ?: null;
        }
        $movimientos = $conexion->query(
            "SELECT m.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS responsable
             FROM movimientos_financieros m
             LEFT JOIN usuarios u ON u.id_usuario = m.responsable_id
             ORDER BY m.fecha DESC, m.id_movimiento DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación.";
    }
}

$valor = static fn (string $campo): string => $escapar($movimientoEditar[$campo] ?? "");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contabilidad | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Gestión financiera</p><h1>Contabilidad</h1><p>Registra ingresos y egresos en el mismo libro financiero que alimenta Pagos.</p></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>
        <!-- SECCIÓN 1: MOVIMIENTOS REGISTRADOS ARRIBA DE PRIMERAS -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-heading">
                <h3>📊 Movimientos registrados</h3>
                <span style="font-size:0.8rem; color:#64748b; font-weight:500;">Libro diario de ingresos y egresos</span>
            </div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha / concepto</th><th>Tipo y categoría</th><th>Persona</th><th>Monto</th><th>Método</th><th>Responsable</th><?php if ($puedeGestionar): ?><th>Acciones</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($movimientos as $movimiento): ?><tr><td><strong><?= $escapar($movimiento["concepto"]) ?></strong><small><?= $escapar($movimiento["fecha"]) ?></small></td><td><?= $escapar($movimiento["tipo"]) ?><small><?= $escapar($movimiento["categoria"]) ?></small></td><td><?= $escapar($movimiento["tipo_persona"] ? $movimiento["tipo_persona"] . ": " : "") ?><?= $escapar($movimiento["persona_proveedor"] ?: "—") ?></td><td>S/ <?= number_format((float) $movimiento["monto"], 2) ?></td><td><?= $escapar($movimiento["metodo_pago"]) ?></td><td><?= $escapar($movimiento["responsable"] ?: "—") ?></td><?php if ($puedeGestionar): ?><td class="actions"><a class="text-link" href="contabilidad.php?editar=<?= (int) $movimiento["id_movimiento"] ?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar este movimiento?');"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id_movimiento" value="<?= (int) $movimiento["id_movimiento"] ?>"><button type="submit" class="link-button danger">Eliminar</button></form></td><?php endif; ?></tr><?php endforeach; ?>
            <?php if (!$movimientos): ?><tr><td colspan="<?= $puedeGestionar ? 7 : 6 ?>" class="empty-state">No hay movimientos registrados.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>

        <!-- SECCIÓN 2: FORMULARIO DESPLEGABLE NUEVO MOVIMIENTO -->
        <?php if ($puedeGestionar): ?>
        <details class="collapsible-card" <?= $movimientoEditar ? "open" : "" ?> style="margin-bottom: 28px;">
            <summary>
                <div class="summary-left">
                    <span class="summary-icon">💰</span>
                    <span class="summary-title"><?= $movimientoEditar ? "Editar Movimiento Financiero" : "Nuevo Movimiento Financiero" ?></span>
                    <span class="summary-badge"><?= $movimientoEditar ? "Modo edición" : "Registrar ingreso o egreso" ?></span>
                </div>
                <span class="summary-chevron">▼</span>
            </summary>
            <div class="collapsible-body">
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id_movimiento" value="<?= (int) ($movimientoEditar["id_movimiento"] ?? 0) ?>">
                    <div class="form-grid">
                        <div class="campo"><label for="tipo">Tipo *</label><select id="tipo" name="tipo" required><?php foreach ($tipos as $tipo): ?><option value="<?= $escapar($tipo) ?>" <?= $valor("tipo") === $tipo || (!$movimientoEditar && $tipo === "Ingreso") ? "selected" : "" ?>><?= $escapar($tipo) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="categoria">Categoría *</label><input id="categoria" name="categoria" required value="<?= $valor("categoria") ?>"></div>
                        <div class="campo"><label for="concepto">Concepto *</label><input id="concepto" name="concepto" required value="<?= $valor("concepto") ?>"></div>
                        <div class="campo"><label for="tipo_persona">Tipo de persona *</label><select id="tipo_persona" name="tipo_persona" required><option value="">Seleccionar</option><option value="Cliente" <?= $valor("tipo_persona") === "Cliente" ? "selected" : "" ?>>Cliente</option><option value="Proveedor" <?= $valor("tipo_persona") === "Proveedor" ? "selected" : "" ?>>Proveedor</option></select></div>
                        <div class="campo"><label for="persona_proveedor">Nombre de cliente o proveedor</label><input id="persona_proveedor" name="persona_proveedor" value="<?= $valor("persona_proveedor") ?>"></div>
                        <div class="campo"><label for="monto">Monto *</label><input type="number" min="0" step="0.01" id="monto" name="monto" required value="<?= $valor("monto") ?>"></div>
                        <div class="campo"><label for="fecha">Fecha *</label><input type="date" id="fecha" name="fecha" required value="<?= $valor("fecha") ?: date("Y-m-d") ?>"></div>
                        <div class="campo"><label for="metodo_pago">Método de pago *</label><select id="metodo_pago" name="metodo_pago" required><?php foreach ($metodos as $metodo): ?><option value="<?= $escapar($metodo) ?>" <?= $valor("metodo_pago") === $metodo ? "selected" : "" ?>><?= $escapar($metodo) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="numero_comprobante">N.º de comprobante</label><input id="numero_comprobante" name="numero_comprobante" value="<?= $valor("numero_comprobante") ?>"></div>
                    </div>
                    <div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= $valor("observaciones") ?></textarea></div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="submit" class="btn-primary form-submit"><?= $movimientoEditar ? "Actualizar movimiento" : "Guardar movimiento" ?></button>
                        <?php if ($movimientoEditar): ?><a class="btn-secondary" href="contabilidad.php" style="text-decoration:none; padding:8px 16px;">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
