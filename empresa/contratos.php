<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
require_once "../config/comisiones.php";

// Control de acceso basado en rol directo de sesion
$_rolActual = strtolower($_SESSION["rol"] ?? "");
if (!in_array($_rolActual, ["administrador", "contabilidad", "finanzas", "legal", "abogado"])) {
    http_response_code(403);
    exit("No tienes permisos para acceder a Contratos.");
}
$puedeGestionar = in_array($_rolActual, ["administrador", "contabilidad", "finanzas"]);
$puedeGestionarResponsables = $puedeGestionar || ($_rolActual === "administrador");
$puedeGestionarFirma = in_array($_rolActual, ["administrador", "contabilidad", "finanzas", "legal", "abogado"]);
$mensaje = "";
$error = "";
$clientes = [];
$proyectos = [];
$contratos = [];
$contratosPorVencer = [];
$contratosSinFirma = [];
$cuotas = [];
$pagos = [];
$vendedores = [];
$metodosPago = ["Efectivo", "Transferencia", "Tarjeta", "Billetera digital", "Otro"];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");
$fechaValida = static function (string $fecha): bool {
    $validada = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);
    $errores = DateTimeImmutable::getLastErrors();
    return $validada !== false
        && ($errores === false || ($errores["warning_count"] === 0 && $errores["error_count"] === 0))
        && $validada->format("Y-m-d") === $fecha;
};

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            $puedeActualizarFirma = $accion === "actualizar_firma_cliente" && $puedeGestionarFirma;
            if (!$puedeGestionar && !($accion === "actualizar_responsable" && $puedeGestionarResponsables)
                && !$puedeActualizarFirma) {
                http_response_code(403);
                throw new RuntimeException("No tienes permiso para modificar contratos o registrar pagos.");
            }
            if ($accion === "crear_contrato") {
                $idCliente = (int) ($_POST["id_cliente"] ?? 0);
                $idTesis = (int) ($_POST["id_tesis"] ?? 0) ?: null;
                $titulo = trim($_POST["titulo"] ?? "");
                $montoTotal = filter_var($_POST["monto_total"] ?? null, FILTER_VALIDATE_FLOAT);
                $adelanto = filter_var($_POST["adelanto"] ?? "0", FILTER_VALIDATE_FLOAT);
                $cantidadCuotas = (int) ($_POST["cantidad_cuotas"] ?? 0);
                $fechaContrato = trim($_POST["fecha_contrato"] ?? "");
                $fechaFin = trim($_POST["fecha_fin"] ?? "") ?: null;
                $primerVencimiento = trim($_POST["primer_vencimiento"] ?? "");
                $metodoAdelanto = trim($_POST["metodo_adelanto"] ?? "Otro");
                $comprobanteAdelanto = trim($_POST["comprobante_adelanto"] ?? "") ?: null;
                $responsableId = (int) ($_POST["responsable_id"] ?? 0) ?: null;
                if (
                    $idCliente <= 0 || $titulo === "" || strlen($titulo) > 180
                    || $montoTotal === false || $montoTotal <= 0 || $montoTotal > 9999999999.99
                    || $adelanto === false || $adelanto < 0 || $adelanto > $montoTotal
                    || $cantidadCuotas < 0 || $cantidadCuotas > 60
                    || !$fechaValida($fechaContrato)
                    || ($fechaFin !== null && (!$fechaValida($fechaFin) || $fechaFin < $fechaContrato))
                    || ($montoTotal > $adelanto && ($cantidadCuotas < 1 || !$fechaValida($primerVencimiento)))
                    || ($montoTotal == $adelanto && $cantidadCuotas !== 0)
                    || ($adelanto > 0 && !in_array($metodoAdelanto, $metodosPago, true))
                    || ($comprobanteAdelanto !== null && strlen($comprobanteAdelanto) > 100)
                ) {
                    throw new RuntimeException("Revisa el cliente, título, monto, adelanto y calendario. El saldo debe tener entre 1 y 60 cuotas.");
                }
                if ($responsableId !== null) {
                    $stmtResponsable = $conexion->prepare(
                        "SELECT 1 FROM usuarios u INNER JOIN roles r ON r.id_rol = u.id_rol
                         WHERE u.id_usuario = :id AND u.estado = 1 AND r.codigo IN ('ventas', 'vendedor')"
                    );
                    $stmtResponsable->execute([":id" => $responsableId]);
                    if (!$stmtResponsable->fetchColumn()) {
                        throw new RuntimeException("Selecciona un vendedor activo válido para atribuir futuras comisiones.");
                    }
                }
                $saldoInicialCentimos = (int) round(($montoTotal - $adelanto) * 100);
                if ($cantidadCuotas > $saldoInicialCentimos) {
                    throw new RuntimeException("La cantidad de cuotas no puede superar los céntimos pendientes.");
                }

                $stmtCliente = $conexion->prepare("SELECT CONCAT_WS(' ', nombres, apellidos) FROM clientes WHERE id_cliente = :id AND estado = 'Activo'");
                $stmtCliente->execute([":id" => $idCliente]);
                $cliente = $stmtCliente->fetchColumn();
                if ($cliente === false) {
                    throw new RuntimeException("El cliente seleccionado no existe o está inactivo.");
                }
                if ($idTesis !== null) {
                    $stmtProyecto = $conexion->prepare(
                        "SELECT 1 FROM proyectos_tesis t INNER JOIN expedientes e ON e.id_expediente = t.id_expediente
                         WHERE t.id_tesis = :tesis AND e.id_cliente = :cliente"
                    );
                    $stmtProyecto->execute([":tesis" => $idTesis, ":cliente" => $idCliente]);
                    if (!$stmtProyecto->fetchColumn()) {
                        throw new RuntimeException("El proyecto seleccionado no corresponde al cliente.");
                    }
                }

                $numeroContrato = trim($_POST["numero_contrato"] ?? "");
                if ($numeroContrato === "") {
                    $numeroContrato = "CEA-" . date("Ymd") . "-" . strtoupper(bin2hex(random_bytes(3)));
                }
                if (strlen($numeroContrato) > 80) {
                    throw new RuntimeException("El número de contrato no puede superar 80 caracteres.");
                }

                $conexion->beginTransaction();
                $conexion->prepare(
                    "INSERT INTO contratos_academicos
                     (id_cliente, id_tesis, responsable_id, numero_contrato, titulo, monto_total, adelanto,
                      fecha_contrato, fecha_fin, estado_firma_cliente, observaciones, creado_por)
                     VALUES (:cliente, :tesis, :responsable, :numero, :titulo, :total, :adelanto,
                             :fecha, :fecha_fin, 'Pendiente', :observaciones, :usuario)"
                )->execute([
                    ":cliente" => $idCliente,
                    ":tesis" => $idTesis,
                    ":responsable" => $responsableId,
                    ":numero" => $numeroContrato,
                    ":titulo" => $titulo,
                    ":total" => $montoTotal,
                    ":adelanto" => $adelanto,
                    ":fecha" => $fechaContrato,
                    ":fecha_fin" => $fechaFin,
                    ":observaciones" => trim($_POST["observaciones"] ?? "") ?: null,
                    ":usuario" => (int) $_SESSION["id_usuario"],
                ]);
                $idContrato = (int) $conexion->lastInsertId();

                if ($adelanto > 0) {
                    $conexion->prepare(
                        "INSERT INTO movimientos_financieros
                         (tipo, categoria, concepto, persona_proveedor, monto, fecha, metodo_pago, responsable_id, numero_comprobante, observaciones)
                         VALUES ('Ingreso', 'Adelanto de contrato', :concepto, :cliente, :monto, :fecha, :metodo, :usuario, :comprobante, :observaciones)"
                    )->execute([
                        ":concepto" => "Adelanto · " . $numeroContrato,
                        ":cliente" => $cliente,
                        ":monto" => $adelanto,
                        ":fecha" => $fechaContrato,
                        ":metodo" => $metodoAdelanto,
                        ":usuario" => (int) $_SESSION["id_usuario"],
                        ":comprobante" => $comprobanteAdelanto,
                        ":observaciones" => "Adelanto registrado al crear el contrato.",
                    ]);
                    $idMovimiento = (int) $conexion->lastInsertId();
                    $conexion->prepare(
                        "INSERT INTO pagos_contrato_academico
                         (id_contrato, registrado_por, numero_comprobante, fecha_pago, monto, metodo_pago, observaciones, id_movimiento)
                         VALUES (:contrato, :usuario, :comprobante, :fecha, :monto, :metodo, :observaciones, :movimiento)"
                    )->execute([
                        ":contrato" => $idContrato,
                        ":usuario" => (int) $_SESSION["id_usuario"],
                        ":comprobante" => $comprobanteAdelanto,
                        ":fecha" => $fechaContrato,
                        ":monto" => $adelanto,
                        ":metodo" => $metodoAdelanto,
                        ":observaciones" => "Adelanto inicial.",
                        ":movimiento" => $idMovimiento,
                    ]);
                    $idPagoAdelanto = (int) $conexion->lastInsertId();
                    registrarComisionPorPago($conexion, $idPagoAdelanto, $idContrato, (float) $adelanto, $fechaContrato);
                }

                if ($saldoInicialCentimos > 0) {
                    $cuotaBase = intdiv($saldoInicialCentimos, $cantidadCuotas);
                    $centimosRestantes = $saldoInicialCentimos % $cantidadCuotas;
                    $fechaBase = new DateTimeImmutable($primerVencimiento);
                    $insertarCuota = $conexion->prepare(
                        "INSERT INTO cuotas_contrato_academico (id_contrato, numero_cuota, concepto, fecha_vencimiento, monto)
                         VALUES (:contrato, :numero, :concepto, :vencimiento, :monto)"
                    );
                    for ($i = 0; $i < $cantidadCuotas; $i++) {
                        $fechaMes = $fechaBase->modify("first day of this month")->modify("+{$i} months");
                        $dia = min((int) $fechaBase->format("d"), (int) $fechaMes->format("t"));
                        $fechaCuota = $fechaMes->setDate((int) $fechaMes->format("Y"), (int) $fechaMes->format("m"), $dia);
                        $montoCuota = $cuotaBase + ($i < $centimosRestantes ? 1 : 0);
                        $insertarCuota->execute([
                            ":contrato" => $idContrato,
                            ":numero" => $i + 1,
                            ":concepto" => "Cuota " . ($i + 1),
                            ":vencimiento" => $fechaCuota->format("Y-m-d"),
                            ":monto" => number_format($montoCuota / 100, 2, ".", ""),
                        ]);
                    }
                }
                $conexion->commit();
                $mensaje = "Contrato registrado con su plan de cuotas y saldo inicial.";
            } elseif ($accion === "registrar_pago") {
                $idContrato = (int) ($_POST["id_contrato_pago"] ?? 0);
                $idCuota = (int) ($_POST["id_cuota"] ?? 0) ?: null;
                $monto = filter_var($_POST["monto_pago"] ?? null, FILTER_VALIDATE_FLOAT);
                $fechaPago = trim($_POST["fecha_pago"] ?? "");
                $metodo = trim($_POST["metodo_pago"] ?? "");
                $comprobante = trim($_POST["numero_comprobante"] ?? "") ?: null;
                if ($idContrato <= 0 || $monto === false || $monto <= 0 || $monto > 9999999999.99
                    || !$fechaValida($fechaPago) || !in_array($metodo, $metodosPago, true)
                    || ($comprobante !== null && strlen($comprobante) > 100)) {
                    throw new RuntimeException("Completa contrato, monto positivo, fecha y método de pago válidos.");
                }
                $conexion->beginTransaction();
                $stmtContrato = $conexion->prepare(
                    "SELECT numero_contrato, monto_total, estado FROM contratos_academicos
                     WHERE id_contrato = :id AND estado <> 'Cancelado' FOR UPDATE"
                );
                $stmtContrato->execute([":id" => $idContrato]);
                $contrato = $stmtContrato->fetch();
                if (!$contrato) {
                    throw new RuntimeException("El contrato seleccionado no existe o está cancelado.");
                }
                $stmtPagado = $conexion->prepare(
                    "SELECT COALESCE(SUM(monto), 0) FROM pagos_contrato_academico
                     WHERE id_contrato = :id AND estado = 'Registrado'"
                );
                $stmtPagado->execute([":id" => $idContrato]);
                $totalPagado = (float) $stmtPagado->fetchColumn();
                $saldo = (float) $contrato["monto_total"] - $totalPagado;
                if ($monto > $saldo + 0.001) {
                    throw new RuntimeException("El pago supera el saldo pendiente del contrato.");
                }

                if ($idCuota !== null) {
                    $stmtCuota = $conexion->prepare(
                        "SELECT concepto, monto FROM cuotas_contrato_academico
                         WHERE id_cuota = :cuota AND id_contrato = :contrato FOR UPDATE"
                    );
                    $stmtCuota->execute([":cuota" => $idCuota, ":contrato" => $idContrato]);
                    $cuota = $stmtCuota->fetch();
                    $stmtCuotaPagada = $conexion->prepare(
                        "SELECT COALESCE(SUM(monto), 0) FROM pagos_contrato_academico
                         WHERE id_cuota = :cuota AND estado = 'Registrado'"
                    );
                    $stmtCuotaPagada->execute([":cuota" => $idCuota]);
                    $cuotaPagada = (float) $stmtCuotaPagada->fetchColumn();
                    if (!$cuota || $monto > (float) $cuota["monto"] - $cuotaPagada + 0.001) {
                        throw new RuntimeException("El pago supera el saldo de la cuota o la cuota no pertenece al contrato.");
                    }
                    $conceptoPago = $contrato["numero_contrato"] . " · " . $cuota["concepto"];
                } else {
                    $conceptoPago = $contrato["numero_contrato"] . " · Abono";
                }

                $stmtCliente = $conexion->prepare(
                    "SELECT CONCAT_WS(' ', nombres, apellidos) FROM clientes
                     WHERE id_cliente = (SELECT id_cliente FROM contratos_academicos WHERE id_contrato = :id)"
                );
                $stmtCliente->execute([":id" => $idContrato]);
                $nombreCliente = (string) $stmtCliente->fetchColumn();
                $conexion->prepare(
                    "INSERT INTO movimientos_financieros
                     (tipo, categoria, concepto, persona_proveedor, monto, fecha, metodo_pago, responsable_id, numero_comprobante, observaciones)
                     VALUES ('Ingreso', 'Pago de contrato', :concepto, :cliente, :monto, :fecha, :metodo, :usuario, :comprobante, :observaciones)"
                )->execute([
                    ":concepto" => $conceptoPago,
                    ":cliente" => $nombreCliente,
                    ":monto" => $monto,
                    ":fecha" => $fechaPago,
                    ":metodo" => $metodo,
                    ":usuario" => (int) $_SESSION["id_usuario"],
                    ":comprobante" => $comprobante,
                    ":observaciones" => trim($_POST["observaciones_pago"] ?? "") ?: null,
                ]);
                $idMovimiento = (int) $conexion->lastInsertId();
                $conexion->prepare(
                    "INSERT INTO pagos_contrato_academico
                     (id_contrato, id_cuota, registrado_por, numero_comprobante, fecha_pago, monto, metodo_pago, observaciones, id_movimiento)
                     VALUES (:contrato, :cuota, :usuario, :comprobante, :fecha, :monto, :metodo, :observaciones, :movimiento)"
                )->execute([
                    ":contrato" => $idContrato,
                    ":cuota" => $idCuota,
                    ":usuario" => (int) $_SESSION["id_usuario"],
                    ":comprobante" => $comprobante,
                    ":fecha" => $fechaPago,
                    ":monto" => $monto,
                    ":metodo" => $metodo,
                    ":observaciones" => trim($_POST["observaciones_pago"] ?? "") ?: null,
                    ":movimiento" => $idMovimiento,
                ]);
                $idPago = (int) $conexion->lastInsertId();
                registrarComisionPorPago($conexion, $idPago, $idContrato, (float) $monto, $fechaPago);
                $conexion->prepare(
                    "UPDATE contratos_academicos
                     SET estado = CASE WHEN :pagado >= monto_total THEN 'Completado' ELSE estado END
                     WHERE id_contrato = :id"
                )->execute([":pagado" => $totalPagado + $monto, ":id" => $idContrato]);
                $conexion->commit();
                $mensaje = "Pago registrado y agregado al libro financiero.";
            } elseif ($accion === "actualizar_firma_cliente") {
                $idContrato = (int) ($_POST["id_contrato"] ?? 0);
                $estadoFirma = trim($_POST["estado_firma_cliente"] ?? "");
                $fechaFirma = trim($_POST["fecha_firma_cliente"] ?? "") ?: null;
                if ($idContrato <= 0 || !in_array($estadoFirma, ["Sin seguimiento", "Pendiente", "Firmado"], true)
                    || ($estadoFirma === "Firmado" && ($fechaFirma === null || !$fechaValida($fechaFirma)))) {
                    throw new RuntimeException("Selecciona un contrato y un estado de firma válido; registra la fecha si ya se firmó.");
                }
                $stmtFirma = $conexion->prepare(
                    "UPDATE contratos_academicos
                     SET estado_firma_cliente = :estado,
                         fecha_firma_cliente = :fecha,
                         firma_cliente_registrada_por = :usuario
                     WHERE id_contrato = :id"
                );
                $stmtFirma->execute([
                    ":estado" => $estadoFirma === "Sin seguimiento" ? null : $estadoFirma,
                    ":fecha" => $estadoFirma === "Firmado" ? $fechaFirma : null,
                    ":usuario" => $estadoFirma === "Firmado" ? (int) $_SESSION["id_usuario"] : null,
                    ":id" => $idContrato,
                ]);
                if ($stmtFirma->rowCount() === 0) {
                    $stmtExisteContrato = $conexion->prepare(
                        "SELECT 1 FROM contratos_academicos WHERE id_contrato = :id"
                    );
                    $stmtExisteContrato->execute([":id" => $idContrato]);
                    if (!$stmtExisteContrato->fetchColumn()) {
                        throw new RuntimeException("El contrato seleccionado no existe.");
                    }
                }
                $mensaje = "Estado de firma del cliente actualizado.";
            } elseif ($accion === "actualizar_fecha_fin") {
                $idContrato = (int) ($_POST["id_contrato"] ?? 0);
                $fechaFin = trim($_POST["fecha_fin"] ?? "") ?: null;
                if ($idContrato <= 0 || ($fechaFin !== null && !$fechaValida($fechaFin))) {
                    throw new RuntimeException("Selecciona un contrato y una fecha de fin válida.");
                }
                $stmtContrato = $conexion->prepare(
                    "SELECT fecha_contrato FROM contratos_academicos WHERE id_contrato = :id"
                );
                $stmtContrato->execute([":id" => $idContrato]);
                $fechaContratoExistente = $stmtContrato->fetchColumn();
                if ($fechaContratoExistente === false) {
                    throw new RuntimeException("El contrato seleccionado no existe.");
                }
                if ($fechaFin !== null && $fechaFin < $fechaContratoExistente) {
                    throw new RuntimeException("La fecha de fin no puede ser anterior a la fecha del contrato.");
                }
                $conexion->prepare(
                    "UPDATE contratos_academicos SET fecha_fin = :fecha_fin WHERE id_contrato = :id"
                )->execute([":fecha_fin" => $fechaFin, ":id" => $idContrato]);
                $mensaje = "Fecha de fin del contrato actualizada.";
            } elseif ($accion === "actualizar_responsable") {
                $idContrato = (int) ($_POST["id_contrato"] ?? 0);
                $responsableId = (int) ($_POST["responsable_id"] ?? 0) ?: null;
                if ($idContrato <= 0) {
                    throw new RuntimeException("Selecciona un contrato válido.");
                }
                if ($responsableId !== null) {
                    $stmtResponsable = $conexion->prepare(
                        "SELECT 1 FROM usuarios u INNER JOIN roles r ON r.id_rol = u.id_rol
                         WHERE u.id_usuario = :id AND u.estado = 1 AND r.codigo IN ('ventas', 'vendedor')"
                    );
                    $stmtResponsable->execute([":id" => $responsableId]);
                    if (!$stmtResponsable->fetchColumn()) {
                        throw new RuntimeException("Selecciona un vendedor activo válido.");
                    }
                }
                $stmtContratoActualizado = $conexion->prepare(
                    "UPDATE contratos_academicos SET responsable_id = :responsable WHERE id_contrato = :id"
                );
                $stmtContratoActualizado->execute([":responsable" => $responsableId, ":id" => $idContrato]);
                if ($stmtContratoActualizado->rowCount() === 0) {
                    $stmtExiste = $conexion->prepare("SELECT 1 FROM contratos_academicos WHERE id_contrato = :id");
                    $stmtExiste->execute([":id" => $idContrato]);
                    if (!$stmtExiste->fetchColumn()) {
                        throw new RuntimeException("El contrato seleccionado no existe.");
                    }
                }
                $mensaje = "Responsable actualizado. La nueva asignación aplica solo a pagos futuros.";
            } elseif ($accion === "cambiar_estado") {
                $idContrato = (int) ($_POST["id_contrato"] ?? 0);
                $estado = trim($_POST["estado"] ?? "");
                if ($idContrato <= 0 || !in_array($estado, ["Vigente", "Completado", "Cancelado"], true)) {
                    throw new RuntimeException("Selecciona un contrato y estado válidos.");
                }
                if ($estado === "Completado") {
                    $stmtSaldoContrato = $conexion->prepare(
                        "SELECT c.monto_total - COALESCE(
                            (SELECT SUM(p.monto) FROM pagos_contrato_academico p
                             WHERE p.id_contrato = c.id_contrato AND p.estado = 'Registrado'), 0
                         ) AS saldo
                         FROM contratos_academicos c WHERE c.id_contrato = :id"
                    );
                    $stmtSaldoContrato->execute([":id" => $idContrato]);
                    $saldoContrato = $stmtSaldoContrato->fetchColumn();
                    if ($saldoContrato === false) {
                        throw new RuntimeException("El contrato seleccionado no existe.");
                    }
                    if ((float) $saldoContrato > 0.001) {
                        throw new RuntimeException("No se puede completar un contrato con saldo pendiente.");
                    }
                }
                $stmt = $conexion->prepare("UPDATE contratos_academicos SET estado = :estado WHERE id_contrato = :id");
                $stmt->execute([":estado" => $estado, ":id" => $idContrato]);
                if ($stmt->rowCount() === 0) {
                    $existe = $conexion->prepare("SELECT 1 FROM contratos_academicos WHERE id_contrato = :id");
                    $existe->execute([":id" => $idContrato]);
                    if (!$existe->fetchColumn()) {
                        throw new RuntimeException("El contrato seleccionado no existe.");
                    }
                }
                $mensaje = "Estado del contrato actualizado.";
            }
        }

        $clientes = $conexion->query(
            "SELECT id_cliente, CONCAT_WS(' ', nombres, apellidos) AS nombre FROM clientes
             WHERE estado = 'Activo' ORDER BY nombres, apellidos"
        )->fetchAll();
        $proyectos = $conexion->query(
            "SELECT t.id_tesis, e.id_cliente, t.titulo, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente
             FROM proyectos_tesis t INNER JOIN expedientes e ON e.id_expediente = t.id_expediente
             INNER JOIN clientes c ON c.id_cliente = e.id_cliente
             WHERE t.estado <> 'Cancelado' ORDER BY t.id_tesis DESC"
        )->fetchAll();
        $vendedores = $conexion->query(
            "SELECT u.id_usuario, u.nombres, u.apellidos
             FROM usuarios u INNER JOIN roles r ON r.id_rol = u.id_rol
             WHERE u.estado = 1 AND r.codigo IN ('ventas', 'vendedor')
             ORDER BY u.nombres, u.apellidos"
        )->fetchAll();
        $contratos = $conexion->query(
            "SELECT c.*, CONCAT_WS(' ', cl.nombres, cl.apellidos) AS cliente, t.titulo AS proyecto,
             CONCAT_WS(' ', resp.nombres, resp.apellidos) AS responsable,
             (SELECT COALESCE(SUM(p.monto), 0) FROM pagos_contrato_academico p
              WHERE p.id_contrato = c.id_contrato AND p.estado = 'Registrado') AS total_pagado
             FROM contratos_academicos c
             INNER JOIN clientes cl ON cl.id_cliente = c.id_cliente
             LEFT JOIN proyectos_tesis t ON t.id_tesis = c.id_tesis
             LEFT JOIN usuarios resp ON resp.id_usuario = c.responsable_id
             ORDER BY c.fecha_contrato DESC, c.id_contrato DESC"
        )->fetchAll();
        $contratosPorVencer = $conexion->query(
            "SELECT c.id_contrato, c.numero_contrato, c.titulo, c.fecha_fin,
                    CONCAT_WS(' ', cl.nombres, cl.apellidos) AS cliente,
                    DATEDIFF(c.fecha_fin, CURDATE()) AS dias_restantes
             FROM contratos_academicos c
             INNER JOIN clientes cl ON cl.id_cliente = c.id_cliente
             WHERE c.estado = 'Vigente'
               AND c.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY c.fecha_fin, c.numero_contrato"
        )->fetchAll();
        $contratosSinFirma = $conexion->query(
            "SELECT c.id_contrato, c.numero_contrato, c.titulo,
                    CONCAT_WS(' ', cl.nombres, cl.apellidos) AS cliente
             FROM contratos_academicos c
             INNER JOIN clientes cl ON cl.id_cliente = c.id_cliente
             WHERE c.estado <> 'Cancelado' AND c.estado_firma_cliente = 'Pendiente'
             ORDER BY c.fecha_contrato, c.numero_contrato"
        )->fetchAll();
        $cuotas = $conexion->query(
            "SELECT q.*, c.numero_contrato, c.id_contrato, CONCAT_WS(' ', cl.nombres, cl.apellidos) AS cliente,
             q.monto - (SELECT COALESCE(SUM(p.monto), 0) FROM pagos_contrato_academico p
                        WHERE p.id_cuota = q.id_cuota AND p.estado = 'Registrado') AS saldo_cuota,
             CASE
                WHEN q.monto <= (SELECT COALESCE(SUM(p.monto), 0) FROM pagos_contrato_academico p
                                 WHERE p.id_cuota = q.id_cuota AND p.estado = 'Registrado') THEN 'Pagada'
                WHEN q.fecha_vencimiento < CURDATE() THEN 'Vencido'
                WHEN q.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Próximo pago'
                ELSE 'Al día'
             END AS estado_pago
             FROM cuotas_contrato_academico q
             INNER JOIN contratos_academicos c ON c.id_contrato = q.id_contrato
             INNER JOIN clientes cl ON cl.id_cliente = c.id_cliente
             WHERE c.estado <> 'Cancelado'
             ORDER BY q.fecha_vencimiento, c.numero_contrato"
        )->fetchAll();
        $pagos = $conexion->query(
            "SELECT p.*, c.numero_contrato, q.concepto, CONCAT_WS(' ', cl.nombres, cl.apellidos) AS cliente
             FROM pagos_contrato_academico p
             INNER JOIN contratos_academicos c ON c.id_contrato = p.id_contrato
             INNER JOIN clientes cl ON cl.id_cliente = c.id_cliente
             LEFT JOIN cuotas_contrato_academico q ON q.id_cuota = p.id_cuota
             ORDER BY p.fecha_pago DESC, p.id_pago DESC LIMIT 100"
        )->fetchAll();
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación. Verifica los datos ingresados.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contratos y saldos | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
    <style>
        .balance-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:12px; }
        .balance-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; }
        .balance-card small { display:block; color:#64748b; margin-top:5px; }
        .payment-status { display:inline-block; border-radius:999px; padding:4px 9px; font-size:.78rem; font-weight:700; }
        .payment-status.overdue { background:#fee2e2; color:#991b1b; }
        .payment-status.soon { background:#fef3c7; color:#92400e; }
        .payment-status.paid { background:#dcfce7; color:#166534; }
        .payment-status.current { background:#dbeafe; color:#1e40af; }
        .compact-form { display:flex; gap:7px; align-items:center; flex-wrap:wrap; }
    </style>
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner">
            <p class="eyebrow">Gestión de contratos y pagos</p>
            <h1>Contratos, cuotas y saldos</h1>
            <p>Registra adelantos y calendarios; el saldo se calcula desde los pagos confirmados.</p>
            <a class="btn-secondary btn-sm" href="pagos.php">Ver ingresos generales</a>
        </div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>
        <?php if ($contratosSinFirma): ?>
        <section class="panel" style="border-color:#f59e0b;background:#fffbeb;" aria-label="Contratos pendientes de firma del cliente">
            <div class="panel-heading"><h3>🔔 Contratos pendientes de firma del cliente</h3></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Contrato</th><th>Cliente</th><th>Acción requerida</th></tr></thead><tbody>
            <?php foreach ($contratosSinFirma as $contratoSinFirma): ?>
                <tr>
                    <td>#<?= $escapar($contratoSinFirma["numero_contrato"]) ?> · <?= $escapar($contratoSinFirma["titulo"]) ?></td>
                    <td><?= $escapar($contratoSinFirma["cliente"]) ?></td>
                    <td>Falta firma del contrato del cliente <?= $escapar($contratoSinFirma["cliente"]) ?>.</td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <?php endif; ?>

        <?php if ($contratosPorVencer): ?>
        <section class="panel" style="border-color:#f59e0b;background:#fffbeb;" aria-label="Contratos próximos a vencer">
            <div class="panel-heading"><h3>🔔 Contratos que vencen en los próximos 7 días</h3></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Contrato</th><th>Cliente</th><th>Fecha de fin</th><th>Aviso</th></tr></thead><tbody>
            <?php foreach ($contratosPorVencer as $contratoProximo): ?>
                <tr>
                    <td>#<?= $escapar($contratoProximo["numero_contrato"]) ?> · <?= $escapar($contratoProximo["titulo"]) ?></td>
                    <td><?= $escapar($contratoProximo["cliente"]) ?></td>
                    <td><?= $escapar($contratoProximo["fecha_fin"]) ?></td>
                    <td><?= (int) $contratoProximo["dias_restantes"] === 0 ? "Vence hoy" : "Vence en " . (int) $contratoProximo["dias_restantes"] . " días" ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
        <?php endif; ?>

        <!-- SECCIÓN 1: CONTRATOS, CUOTAS Y PAGOS ARRIBA DE PRIMERAS -->
        <section class="panel">
            <div class="panel-heading"><h3>Contratos y saldo pendiente</h3></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Contrato / cliente</th><th>Proyecto</th><th>Responsable comercial</th><th>Vencimiento</th><th>Firma del cliente</th><th>Total</th><th>Adelanto / pagado</th><th>Saldo</th><th>Próximo pago</th><th>Estado</th><?php if ($puedeGestionar): ?><th>Gestión</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($contratos as $contrato): $saldoContrato = max(0, (float) $contrato["monto_total"] - (float) $contrato["total_pagado"]); ?>
                <?php
                $proximo = null;
                foreach ($cuotas as $itemCuota) {
                    if ((int) $itemCuota["id_contrato"] === (int) $contrato["id_contrato"] && (float) $itemCuota["saldo_cuota"] > 0) {
                        $proximo = $itemCuota;
                        break;
                    }
                }
                ?>
                <tr>
                    <td><strong>#<?= $escapar($contrato["numero_contrato"]) ?> · <?= $escapar($contrato["titulo"]) ?></strong><small><?= $escapar($contrato["cliente"]) ?> · <?= $escapar($contrato["fecha_contrato"]) ?></small></td>
                    <td><?= $escapar($contrato["proyecto"] ?: "Sin proyecto asociado") ?></td>
                    <td><?= $escapar($contrato["responsable"] ?: "Sin asignar") ?><?php if ($puedeGestionarResponsables): ?><form method="post" class="compact-form"><input type="hidden" name="accion" value="actualizar_responsable"><input type="hidden" name="id_contrato" value="<?= (int) $contrato["id_contrato"] ?>"><select name="responsable_id" aria-label="Responsable comercial"><option value="">Sin asignar</option><?php foreach ($vendedores as $vendedor): ?><option value="<?= (int) $vendedor["id_usuario"] ?>" <?= (int) ($contrato["responsable_id"] ?? 0) === (int) $vendedor["id_usuario"] ? "selected" : "" ?>><?= $escapar(trim($vendedor["nombres"] . " " . $vendedor["apellidos"])) ?></option><?php endforeach; ?></select><button class="btn-action btn-action-primary" type="submit">Guardar</button></form><?php endif; ?></td>
                    <td><?php if ($puedeGestionar): ?><form method="post" class="compact-form"><input type="hidden" name="accion" value="actualizar_fecha_fin"><input type="hidden" name="id_contrato" value="<?= (int) $contrato["id_contrato"] ?>"><input type="date" name="fecha_fin" aria-label="Fecha de fin del contrato" value="<?= $escapar($contrato["fecha_fin"]) ?>" min="<?= $escapar($contrato["fecha_contrato"]) ?>"><button class="btn-action btn-action-primary" type="submit">Guardar</button></form><?php else: ?><?= $escapar($contrato["fecha_fin"] ?: "Sin fecha definida") ?><?php endif; ?></td>
                    <td><?php if ($puedeGestionarFirma): ?><form method="post" class="compact-form"><input type="hidden" name="accion" value="actualizar_firma_cliente"><input type="hidden" name="id_contrato" value="<?= (int) $contrato["id_contrato"] ?>"><select name="estado_firma_cliente" aria-label="Estado de firma del cliente"><option value="Sin seguimiento" <?= empty($contrato["estado_firma_cliente"]) ? "selected" : "" ?>>Sin seguimiento</option><option value="Pendiente" <?= $contrato["estado_firma_cliente"] === "Pendiente" ? "selected" : "" ?>>Pendiente</option><option value="Firmado" <?= $contrato["estado_firma_cliente"] === "Firmado" ? "selected" : "" ?>>Firmado</option></select><input type="date" name="fecha_firma_cliente" aria-label="Fecha de firma del cliente" value="<?= $escapar($contrato["fecha_firma_cliente"] ?: date("Y-m-d")) ?>"><button class="btn-action btn-action-primary" type="submit">Guardar</button></form><?php else: ?><?= $escapar($contrato["estado_firma_cliente"] ?: "Sin seguimiento") ?><?php if ($contrato["fecha_firma_cliente"]): ?><small><?= $escapar($contrato["fecha_firma_cliente"]) ?></small><?php endif; ?><?php endif; ?></td>
                    <td>S/ <?= number_format((float) $contrato["monto_total"], 2) ?></td>
                    <td>S/ <?= number_format((float) $contrato["adelanto"], 2) ?> inicial<small>Total pagado: S/ <?= number_format((float) $contrato["total_pagado"], 2) ?></small></td>
                    <td><strong>S/ <?= number_format($saldoContrato, 2) ?></strong></td>
                    <td><?php if ($proximo): ?><span class="payment-status <?= $proximo["estado_pago"] === "Vencido" ? "overdue" : ($proximo["estado_pago"] === "Próximo pago" ? "soon" : "current") ?>"><?= $escapar($proximo["estado_pago"]) ?></span><small><?= $escapar($proximo["fecha_vencimiento"]) ?> · S/ <?= number_format((float) $proximo["saldo_cuota"], 2) ?></small><?php else: ?><span class="payment-status paid">Sin saldo</span><?php endif; ?></td>
                    <td><?= $escapar($contrato["estado"]) ?></td>
                    <?php if ($puedeGestionar): ?><td><form method="post" class="compact-form"><input type="hidden" name="accion" value="cambiar_estado"><input type="hidden" name="id_contrato" value="<?= (int) $contrato["id_contrato"] ?>"><select name="estado" aria-label="Estado del contrato"><?php foreach (["Vigente", "Completado", "Cancelado"] as $estado): ?><option <?= $contrato["estado"] === $estado ? "selected" : "" ?>><?= $estado ?></option><?php endforeach; ?></select><button class="btn-action btn-action-primary" type="submit">Guardar</button></form></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$contratos): ?><tr><td colspan="<?= $puedeGestionar ? 11 : 10 ?>" class="empty-state">Aún no hay contratos registrados.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>

        <section class="crud-layout">
            <div class="panel">
                <div class="panel-heading"><h3>Calendario de cuotas</h3></div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Vencimiento</th><th>Contrato / cliente</th><th>Cuota</th><th>Monto</th><th>Saldo</th><th>Estado</th></tr></thead><tbody>
                <?php foreach ($cuotas as $cuota): ?><tr><td><?= $escapar($cuota["fecha_vencimiento"]) ?></td><td>#<?= $escapar($cuota["numero_contrato"]) ?><small><?= $escapar($cuota["cliente"]) ?></small></td><td><?= $escapar($cuota["concepto"]) ?></td><td>S/ <?= number_format((float) $cuota["monto"], 2) ?></td><td>S/ <?= number_format(max(0, (float) $cuota["saldo_cuota"]), 2) ?></td><td><span class="payment-status <?= $cuota["estado_pago"] === "Vencido" ? "overdue" : ($cuota["estado_pago"] === "Próximo pago" ? "soon" : ($cuota["estado_pago"] === "Pagada" ? "paid" : "current")) ?>"><?= $escapar($cuota["estado_pago"]) ?></span></td></tr><?php endforeach; ?>
                <?php if (!$cuotas): ?><tr><td colspan="6" class="empty-state">No hay cuotas programadas.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
            <div class="panel">
                <div class="panel-heading"><h3>Últimos pagos</h3></div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha / contrato</th><th>Cliente</th><th>Aplicación</th><th>Monto</th><th>Comprobante</th></tr></thead><tbody>
                <?php foreach ($pagos as $pago): ?><tr><td><?= $escapar($pago["fecha_pago"]) ?><small>#<?= $escapar($pago["numero_contrato"]) ?></small></td><td><?= $escapar($pago["cliente"]) ?></td><td><?= $escapar($pago["concepto"] ?: "Abono") ?></td><td>S/ <?= number_format((float) $pago["monto"], 2) ?><small><?= $escapar($pago["metodo_pago"]) ?></small></td><td><?= $escapar($pago["numero_comprobante"] ?: "—") ?></td></tr><?php endforeach; ?>
                <?php if (!$pagos): ?><tr><td colspan="5" class="empty-state">No se han registrado pagos asociados a contratos.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </section>
    
        <!-- SECCIÓN 2: FORMULARIOS DESPLEGABLES (REGISTRAR CONTRATO Y REGISTRAR PAGO/ABONO) -->
        <?php if ($puedeGestionar): ?>
        <div style="display: flex; flex-direction: column; gap: 16px; margin-top: 24px; margin-bottom: 28px;">
            <!-- Desplegable Registrar Contrato -->
            <details class="collapsible-card">
                <summary>
                    <div class="summary-left">
                        <span class="summary-icon">📝</span>
                        <span class="summary-title">Registrar Nuevo Contrato Institucional</span>
                        <span class="summary-badge">Definir precio, cuotas y alcance</span>
                    </div>
                    <span class="summary-chevron">▼</span>
                </summary>
                <div class="collapsible-body">
                    <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="crear_contrato">
                    <div class="form-grid">
                        <div class="campo"><label for="id_cliente">Cliente *</label><select id="id_cliente" name="id_cliente" required><option value="">Seleccionar</option><?php foreach ($clientes as $cliente): ?><option value="<?= (int) $cliente["id_cliente"] ?>"><?= $escapar($cliente["nombre"]) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="id_tesis">Proyecto</label><select id="id_tesis" name="id_tesis"><option value="">Sin proyecto vinculado</option><?php foreach ($proyectos as $proyecto): ?><option value="<?= (int) $proyecto["id_tesis"] ?>" data-cliente="<?= (int) $proyecto["id_cliente"] ?>"><?= $escapar($proyecto["cliente"] . " · " . $proyecto["titulo"]) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="responsable_id">Asesor/vendedor responsable de comisión</label><select id="responsable_id" name="responsable_id"><option value="">Sin asignar</option><?php foreach ($vendedores as $vendedor): ?><option value="<?= (int) $vendedor["id_usuario"] ?>"><?= $escapar(trim($vendedor["nombres"] . " " . $vendedor["apellidos"])) ?></option><?php endforeach; ?></select><small>Sin responsable no se generan comisiones. La tasa se aplica a pagos nuevos.</small></div>
                        <div class="campo"><label for="numero_contrato">N.º de contrato</label><input id="numero_contrato" name="numero_contrato" maxlength="80" placeholder="Generado automáticamente si queda vacío"></div>
                        <div class="campo"><label for="titulo">Servicio / alcance *</label><input id="titulo" name="titulo" maxlength="180" required></div>
                        <div class="campo"><label for="monto_total">Precio total (S/) *</label><input id="monto_total" name="monto_total" type="number" min="0.01" step="0.01" required></div>
                        <div class="campo"><label for="adelanto">Adelanto (S/)</label><input id="adelanto" name="adelanto" type="number" min="0" step="0.01" value="0"></div>
                        <div class="campo"><label for="metodo_adelanto">Método de adelanto</label><select id="metodo_adelanto" name="metodo_adelanto"><?php foreach ($metodosPago as $metodo): ?><option><?= $escapar($metodo) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="comprobante_adelanto">Comprobante del adelanto</label><input id="comprobante_adelanto" name="comprobante_adelanto" maxlength="100"></div>
                        <div class="campo"><label for="cantidad_cuotas">Cuotas restantes</label><input id="cantidad_cuotas" name="cantidad_cuotas" type="number" min="0" max="60" value="1"></div>
                        <div class="campo"><label for="fecha_contrato">Fecha de contrato *</label><input id="fecha_contrato" name="fecha_contrato" type="date" value="<?= date("Y-m-d") ?>" required></div>
                        <div class="campo"><label for="fecha_fin">Fecha de fin</label><input id="fecha_fin" name="fecha_fin" type="date"><small>Se usa para avisar cuando el contrato esté próximo a vencer.</small></div>
                        <div class="campo"><label for="primer_vencimiento">Primer vencimiento</label><input id="primer_vencimiento" name="primer_vencimiento" type="date" value="<?= date("Y-m-d", strtotime("+1 month")) ?>"></div>
                    </div>
                    <div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="2"></textarea></div>
                    <button class="btn-primary form-submit" type="submit">Crear contrato y cuotas</button>
                </form>
                </div>
            </details>

            <!-- Desplegable Registrar Pago / Abono -->
            <details class="collapsible-card">
                <summary>
                    <div class="summary-left">
                        <span class="summary-icon">💳</span>
                        <span class="summary-title">Registrar Pago o Abono de Cuota</span>
                        <span class="summary-badge">Aplicar a contrato o cuota programada</span>
                    </div>
                    <span class="summary-chevron">▼</span>
                </summary>
                <div class="collapsible-body">
                    <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="registrar_pago">
                    <div class="form-grid">
                        <div class="campo"><label for="id_contrato_pago">Contrato *</label><select id="id_contrato_pago" name="id_contrato_pago" required><option value="">Seleccionar contrato</option><?php foreach ($contratos as $contrato): $saldoContrato = max(0, (float) $contrato["monto_total"] - (float) $contrato["total_pagado"]); if ($saldoContrato <= 0 || $contrato["estado"] === "Cancelado") continue; ?><option value="<?= (int) $contrato["id_contrato"] ?>">#<?= $escapar($contrato["numero_contrato"]) ?> · <?= $escapar($contrato["cliente"]) ?> · Saldo S/ <?= number_format($saldoContrato, 2) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="id_cuota">Aplicar a cuota (opcional)</label><select id="id_cuota" name="id_cuota"><option value="">Abono general</option><?php foreach ($cuotas as $cuota): if ((float) $cuota["saldo_cuota"] <= 0) continue; ?><option value="<?= (int) $cuota["id_cuota"] ?>" data-contrato="<?= (int) $cuota["id_contrato"] ?>">#<?= $escapar($cuota["numero_contrato"]) ?> · <?= $escapar($cuota["concepto"]) ?> · S/ <?= number_format((float) $cuota["saldo_cuota"], 2) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="monto_pago">Monto (S/) *</label><input id="monto_pago" name="monto_pago" type="number" min="0.01" step="0.01" required></div>
                        <div class="campo"><label for="fecha_pago">Fecha *</label><input id="fecha_pago" name="fecha_pago" type="date" value="<?= date("Y-m-d") ?>" required></div>
                        <div class="campo"><label for="metodo_pago">Método *</label><select id="metodo_pago" name="metodo_pago" required><?php foreach ($metodosPago as $metodo): ?><option><?= $escapar($metodo) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="numero_comprobante">N.º comprobante</label><input id="numero_comprobante" name="numero_comprobante" maxlength="100"></div>
                    </div>
                    <div class="campo"><label for="observaciones_pago">Observaciones</label><textarea id="observaciones_pago" name="observaciones_pago" rows="2"></textarea></div>
                    <button class="btn-primary form-submit" type="submit">Registrar pago</button>
                </form>
                </div>
            </details>
        </div>
        <?php endif; ?>
    
    </main>
</div>
<script>
document.addEventListener("DOMContentLoaded", () => {
    const cliente = document.getElementById("id_cliente");
    const proyecto = document.getElementById("id_tesis");
    if (cliente && proyecto) {
        cliente.addEventListener("change", () => {
            Array.from(proyecto.options).forEach(option => {
                option.hidden = Boolean(option.value) && option.dataset.cliente !== cliente.value;
            });
            if (proyecto.selectedOptions[0] && proyecto.selectedOptions[0].hidden) proyecto.value = "";
        });
    }
    const contrato = document.getElementById("id_contrato_pago");
    const cuota = document.getElementById("id_cuota");
    if (contrato && cuota) {
        contrato.addEventListener("change", () => {
            Array.from(cuota.options).forEach(option => {
                option.hidden = Boolean(option.value) && option.dataset.contrato !== contrato.value;
            });
            if (cuota.selectedOptions[0] && cuota.selectedOptions[0].hidden) cuota.value = "";
        });
    }
});
</script>
</body>
</html>
