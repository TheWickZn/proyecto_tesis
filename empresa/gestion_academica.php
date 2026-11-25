<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
require_once "../config/coordinacion_academica.php";

if ($conexion) {
    exigirPermiso($conexion, "tesis.gestionar");
}

$mensaje = "";
$error = "";
$proyectoEditar = null;
$asesorEditar = null;
$clientes = [];
$servicios = [];
$asesores = [];
$usuarios = [];
$usuariosAsesor = [];
$proyectos = [];
$hitosPorProyecto = [];
$proyectoDetalle = null;
$hitosDetalle = [];
$entregasDetalle = [];
$clienteActual = null;
$clienteFiltro = (int) ($_GET["cliente"] ?? 0);
$estadosProyecto = ["En proceso", "Pendiente", "Retrasado", "Finalizado"];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");
$fechaValida = static function (?string $fecha): bool {
    if ($fecha === null || $fecha === "") {
        return true;
    }
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
            if ($accion === "revisar_entrega") {
                $idEntrega = (int) ($_POST["id_entrega"] ?? 0);
                $idTesis = (int) ($_POST["id_tesis"] ?? 0);
                $decision = trim($_POST["decision"] ?? "");
                $observaciones = trim($_POST["observaciones_coordinador"] ?? "") ?: null;
                if ($idEntrega <= 0 || $idTesis <= 0 || !in_array($decision, ["Aprobado", "Observado"], true)
                    || ($decision === "Observado" && !$observaciones)
                    || ($observaciones !== null && strlen($observaciones) > 5000)) {
                    throw new RuntimeException("Selecciona una decisión válida y escribe las observaciones para una entrega observada.");
                }
                $stmtEntrega = $conexion->prepare(
                    "SELECT d.id_tesis, d.id_hito, d.estado, a.id_usuario AS usuario_asesor,
                            h.codigo, h.orden
                     FROM entregas_asesores d
                     INNER JOIN asesores_academicos a ON a.id_asesor = d.id_asesor
                     LEFT JOIN coordinacion_hitos h ON h.id_hito = d.id_hito
                     WHERE d.id_entrega = :entrega AND d.id_tesis = :tesis
                     LIMIT 1"
                );
                $stmtEntrega->execute([":entrega" => $idEntrega, ":tesis" => $idTesis]);
                $entrega = $stmtEntrega->fetch();
                if (!$entrega || $entrega["estado"] !== "En revisión" || (int) $entrega["usuario_asesor"] <= 0) {
                    throw new RuntimeException("La entrega ya fue revisada o su asesor no tiene una cuenta vinculada.");
                }
                if ($decision === "Aprobado" && $entrega["codigo"] === "entrega_final") {
                    $stmtEtapasPendientes = $conexion->prepare(
                        "SELECT COUNT(*) FROM coordinacion_hitos
                         WHERE id_tesis = :tesis AND orden < :orden AND estado <> 'Finalizado'"
                    );
                    $stmtEtapasPendientes->execute([
                        ":tesis" => $idTesis,
                        ":orden" => (int) $entrega["orden"],
                    ]);
                    if ((int) $stmtEtapasPendientes->fetchColumn() > 0) {
                        throw new RuntimeException("Completa y aprueba las etapas anteriores antes de aprobar la entrega final.");
                    }
                }

                $conexion->beginTransaction();
                $estadoHito = $decision === "Aprobado" ? "Finalizado" : "Observado";
                $actualizarEntrega = $conexion->prepare(
                    "UPDATE entregas_asesores
                     SET estado = :estado, observaciones_coordinador = :observaciones,
                         revisado_por = :revisor, fecha_revision = CURRENT_TIMESTAMP
                     WHERE id_entrega = :entrega AND estado = 'En revisión'"
                );
                $actualizarEntrega->execute([
                    ":estado" => $decision,
                    ":observaciones" => $observaciones,
                    ":revisor" => (int) $_SESSION["id_usuario"],
                    ":entrega" => $idEntrega,
                ]);
                if ($actualizarEntrega->rowCount() !== 1) {
                    throw new RuntimeException("Otra persona ya revisó esta entrega. Actualiza la página.");
                }
                if ($entrega["id_hito"] !== null) {
                    $conexion->prepare(
                        "UPDATE coordinacion_hitos
                         SET estado = :estado, observaciones = :observaciones, actualizado_por = :usuario
                         WHERE id_hito = :hito"
                    )->execute([
                        ":estado" => $estadoHito,
                        ":observaciones" => $observaciones,
                        ":usuario" => (int) $_SESSION["id_usuario"],
                        ":hito" => (int) $entrega["id_hito"],
                    ]);
                }
                $stmtUltimoHito = $conexion->prepare(
                    "SELECT estado FROM coordinacion_hitos WHERE id_tesis = :tesis ORDER BY orden DESC LIMIT 1"
                );
                $stmtUltimoHito->execute([":tesis" => $idTesis]);
                $estadoProyecto = $stmtUltimoHito->fetchColumn() === "Finalizado"
                    ? "Finalizado"
                    : "En proceso";
                $conexion->prepare("UPDATE proyectos_tesis SET estado = :estado WHERE id_tesis = :tesis")
                    ->execute([":estado" => $estadoProyecto, ":tesis" => $idTesis]);
                if ($estadoProyecto === "Finalizado") {
                    $conexion->prepare(
                        "UPDATE proyectos_asesores SET fecha_fin = CURDATE()
                         WHERE id_tesis = :tesis AND fecha_fin IS NULL"
                    )->execute([":tesis" => $idTesis]);
                }
                $conexion->prepare(
                    "INSERT INTO notificaciones (usuario_id, tipo, registro_id, mensaje, creado_por)
                     VALUES (:usuario, 'proyecto_academico', :proyecto, :mensaje, :creador)"
                )->execute([
                    ":usuario" => (int) $entrega["usuario_asesor"],
                    ":proyecto" => $idTesis,
                    ":mensaje" => "Coordinación " . ($decision === "Aprobado" ? "aprobó" : "observó") . " una entrega de tu proyecto.",
                    ":creador" => (int) $_SESSION["id_usuario"],
                ]);
                $conexion->commit();
                $mensaje = $decision === "Aprobado"
                    ? "Entrega aprobada y asesor notificado."
                    : "Entrega observada; el asesor recibió la notificación.";
            } elseif ($accion === "actualizar_hito") {
                $idHito = (int) ($_POST["id_hito"] ?? 0);
                $fechaObjetivo = trim($_POST["fecha_objetivo"] ?? "") ?: null;
                $estadoHito = trim($_POST["estado_hito"] ?? "");
                $observacionesHito = trim($_POST["observaciones_hito"] ?? "") ?: null;
                $estadosHito = ["Pendiente", "Pendiente de información", "En proceso", "En revisión", "Observado", "Correcciones", "Finalizado"];
                if ($idHito <= 0 || !in_array($estadoHito, $estadosHito, true) || !$fechaValida($fechaObjetivo)) {
                    throw new RuntimeException("Selecciona una etapa, estado y fecha objetivo válidos.");
                }
                $stmtHito = $conexion->prepare("SELECT id_tesis, codigo, orden FROM coordinacion_hitos WHERE id_hito = :id");
                $stmtHito->execute([":id" => $idHito]);
                $hitoActual = $stmtHito->fetch();
                if (!$hitoActual) {
                    throw new RuntimeException("La etapa seleccionada no existe.");
                }
                $idTesisHito = (int) $hitoActual["id_tesis"];
                if ($hitoActual["codigo"] === "entrega_final" && $estadoHito === "Finalizado") {
                    $stmtEtapasPendientes = $conexion->prepare(
                        "SELECT COUNT(*) FROM coordinacion_hitos
                         WHERE id_tesis = :tesis AND orden < :orden AND estado <> 'Finalizado'"
                    );
                    $stmtEtapasPendientes->execute([
                        ":tesis" => $idTesisHito,
                        ":orden" => (int) $hitoActual["orden"],
                    ]);
                    if ((int) $stmtEtapasPendientes->fetchColumn() > 0) {
                        throw new RuntimeException("Completa las etapas anteriores antes de marcar la entrega final.");
                    }
                }
                $conexion->beginTransaction();
                $conexion->prepare(
                    "UPDATE coordinacion_hitos
                     SET fecha_objetivo = :fecha, estado = :estado, observaciones = :observaciones, actualizado_por = :usuario
                     WHERE id_hito = :id"
                )->execute([
                    ":fecha" => $fechaObjetivo,
                    ":estado" => $estadoHito,
                    ":observaciones" => $observacionesHito,
                    ":usuario" => (int) $_SESSION["id_usuario"],
                    ":id" => $idHito,
                ]);
                $stmtFinal = $conexion->prepare(
                    "SELECT estado FROM coordinacion_hitos WHERE id_tesis = :tesis ORDER BY orden DESC LIMIT 1"
                );
                $stmtFinal->execute([":tesis" => $idTesisHito]);
                if ($stmtFinal->fetchColumn() === "Finalizado") {
                    $nuevoEstadoProyecto = "Finalizado";
                } else {
                    $nuevoEstadoProyecto = $estadoHito === "Pendiente de información" ? "Pendiente" : "En proceso";
                }
                $conexion->prepare("UPDATE proyectos_tesis SET estado = :estado WHERE id_tesis = :id")
                    ->execute([":estado" => $nuevoEstadoProyecto, ":id" => $idTesisHito]);
                if ($nuevoEstadoProyecto === "Finalizado") {
                    $conexion->prepare(
                        "UPDATE proyectos_asesores SET fecha_fin = CURDATE()
                         WHERE id_tesis = :tesis AND fecha_fin IS NULL"
                    )->execute([":tesis" => $idTesisHito]);
                }
                $conexion->commit();
                $mensaje = "Etapa y observaciones actualizadas.";
            } elseif ($accion === "guardar_servicio") {
                $idCliente = (int) ($_POST["id_cliente_servicio"] ?? 0);
                $nombre = trim($_POST["nombre_servicio"] ?? "");
                $montoRaw = trim($_POST["monto"] ?? "");
                $monto = $montoRaw === "" ? null : filter_var($montoRaw, FILTER_VALIDATE_FLOAT);
                $fechaInicio = trim($_POST["fecha_inicio_servicio"] ?? "") ?: null;
                $fechaFin = trim($_POST["fecha_fin_servicio"] ?? "") ?: null;
                $estado = trim($_POST["estado_servicio"] ?? "Activo");
                if ($idCliente <= 0 || $nombre === "" || strlen($nombre) > 180
                    || ($montoRaw !== "" && ($monto === false || $monto < 0))
                    || !$fechaValida($fechaInicio) || !$fechaValida($fechaFin)
                    || ($fechaInicio && $fechaFin && $fechaFin < $fechaInicio)
                    || !in_array($estado, ["Activo", "Suspendido", "Finalizado"], true)) {
                    throw new RuntimeException("Completa cliente, servicio y datos válidos. El monto debe ser positivo y las fechas coherentes.");
                }
                $stmtCliente = $conexion->prepare("SELECT 1 FROM clientes WHERE id_cliente = :id");
                $stmtCliente->execute([":id" => $idCliente]);
                if (!$stmtCliente->fetchColumn()) {
                    throw new RuntimeException("El cliente seleccionado no existe.");
                }
                $conexion->prepare(
                    "INSERT INTO servicios_clientes (id_cliente, nombre_servicio, descripcion, monto, fecha_inicio, fecha_fin, estado, observaciones)
                     VALUES (:cliente, :nombre, :descripcion, :monto, :inicio, :fin, :estado, :observaciones)"
                )->execute([
                    ":cliente" => $idCliente,
                    ":nombre" => $nombre,
                    ":descripcion" => trim($_POST["descripcion_servicio"] ?? "") ?: null,
                    ":monto" => $monto,
                    ":inicio" => $fechaInicio,
                    ":fin" => $fechaFin,
                    ":estado" => $estado,
                    ":observaciones" => trim($_POST["observaciones_servicio"] ?? "") ?: null,
                ]);
                $mensaje = "Servicio contratado registrado correctamente.";
            } elseif ($accion === "guardar_asesor") {
                $idAsesor = (int) ($_POST["id_asesor"] ?? 0);
                $nombres = trim($_POST["nombres_asesor"] ?? "");
                $apellidos = trim($_POST["apellidos_asesor"] ?? "");
                $idUsuario = (int) ($_POST["id_usuario"] ?? 0) ?: null;
                $estado = trim($_POST["estado_asesor"] ?? "Activo");
                if ($nombres === "" || $apellidos === "" || !in_array($estado, ["Activo", "Inactivo"], true)) {
                    throw new RuntimeException("Nombres, apellidos y un estado válido son obligatorios para el asesor.");
                }
                if ($idUsuario !== null) {
                    $stmtUsuario = $conexion->prepare("SELECT 1 FROM usuarios WHERE id_usuario = :id");
                    $stmtUsuario->execute([":id" => $idUsuario]);
                    if (!$stmtUsuario->fetchColumn()) {
                        throw new RuntimeException("La cuenta seleccionada no existe o está inactiva.");
                    }
                }
                $datosAsesor = [
                    ":usuario" => $idUsuario,
                    ":nombres" => $nombres,
                    ":apellidos" => $apellidos,
                    ":correo" => trim($_POST["correo_asesor"] ?? "") ?: null,
                    ":especialidad" => trim($_POST["especialidad"] ?? "") ?: null,
                    ":disponibilidad" => trim($_POST["disponibilidad"] ?? "") ?: null,
                    ":estado" => $estado,
                ];
                if ($idAsesor > 0) {
                    $datosAsesor[":id"] = $idAsesor;
                    $conexion->prepare(
                        "UPDATE asesores_academicos SET id_usuario=:usuario, nombres=:nombres, apellidos=:apellidos,
                         correo=:correo, especialidad=:especialidad, disponibilidad=:disponibilidad, estado=:estado
                         WHERE id_asesor=:id"
                    )->execute($datosAsesor);
                    $mensaje = "Asesor actualizado correctamente.";
                } else {
                    $conexion->prepare(
                        "INSERT INTO asesores_academicos (id_usuario, nombres, apellidos, correo, especialidad, disponibilidad, estado)
                         VALUES (:usuario, :nombres, :apellidos, :correo, :especialidad, :disponibilidad, :estado)"
                    )->execute($datosAsesor);
                    $mensaje = "Asesor registrado correctamente.";
                }
            } elseif ($accion === "guardar_proyecto") {
                $idTesis = (int) ($_POST["id_tesis"] ?? 0);
                $idCliente = (int) ($_POST["id_cliente_proyecto"] ?? 0);
                $idServicioCliente = (int) ($_POST["id_servicio_cliente"] ?? 0) ?: null;
                $idCoordinador = (int) ($_POST["coordinador_id"] ?? 0) ?: null;
                $idAsesor = (int) ($_POST["id_asesor_proyecto"] ?? 0) ?: null;
                $titulo = trim($_POST["titulo"] ?? "");
                $fechaInicio = trim($_POST["fecha_inicio"] ?? "") ?: null;
                $fechaEntrega = trim($_POST["fecha_entrega"] ?? "") ?: null;
                $estado = trim($_POST["estado_proyecto"] ?? "Pendiente");
                $estadoExistente = null;
                if ($idTesis > 0) {
                    $stmtEstadoExistente = $conexion->prepare("SELECT estado FROM proyectos_tesis WHERE id_tesis=:id");
                    $stmtEstadoExistente->execute([":id" => $idTesis]);
                    $estadoExistente = $stmtEstadoExistente->fetchColumn();
                }
                if ($idCliente <= 0 || $titulo === "" || strlen($titulo) > 255
                    || !$fechaValida($fechaInicio) || !$fechaValida($fechaEntrega)
                    || ($fechaInicio && $fechaEntrega && $fechaEntrega < $fechaInicio)
                    || (!in_array($estado, $estadosProyecto, true) && $estado !== $estadoExistente)) {
                    throw new RuntimeException("Completa cliente, título, estado y fechas válidas para el proyecto.");
                }
                $stmtCliente = $conexion->prepare("SELECT 1 FROM clientes WHERE id_cliente = :id");
                $stmtCliente->execute([":id" => $idCliente]);
                if (!$stmtCliente->fetchColumn()) {
                    throw new RuntimeException("El cliente seleccionado no existe.");
                }
                if ($idServicioCliente !== null) {
                    $stmtServicio = $conexion->prepare(
                        "SELECT 1 FROM servicios_clientes WHERE id_servicio_cliente = :servicio AND id_cliente = :cliente"
                    );
                    $stmtServicio->execute([":servicio" => $idServicioCliente, ":cliente" => $idCliente]);
                    if (!$stmtServicio->fetchColumn()) {
                        throw new RuntimeException("El servicio contratado no pertenece al cliente seleccionado.");
                    }
                }
                if ($idCoordinador !== null) {
                    $stmtCoordinador = $conexion->prepare("SELECT 1 FROM usuarios WHERE id_usuario = :id AND estado = 1");
                    $stmtCoordinador->execute([":id" => $idCoordinador]);
                    if (!$stmtCoordinador->fetchColumn()) {
                        throw new RuntimeException("El coordinador seleccionado no existe o está inactivo.");
                    }
                }
                if ($idAsesor !== null) {
                    $stmtAsesor = $conexion->prepare("SELECT 1 FROM asesores_academicos WHERE id_asesor = :id AND estado = 'Activo'");
                    $stmtAsesor->execute([":id" => $idAsesor]);
                    if (!$stmtAsesor->fetchColumn()) {
                        throw new RuntimeException("El asesor seleccionado no existe o está inactivo.");
                    }
                }

                $conexion->beginTransaction();
                if ($idTesis > 0) {
                    $stmtActual = $conexion->prepare(
                        "SELECT t.id_tesis, t.id_expediente, e.id_cliente
                         FROM proyectos_tesis t INNER JOIN expedientes e ON e.id_expediente=t.id_expediente
                         WHERE t.id_tesis=:id FOR UPDATE"
                    );
                    $stmtActual->execute([":id" => $idTesis]);
                    $actual = $stmtActual->fetch();
                    if (!$actual) {
                        throw new RuntimeException("El proyecto seleccionado no existe.");
                    }
                    $idExpediente = (int) $actual["id_expediente"];
                    if ((int) $actual["id_cliente"] !== $idCliente) {
                        throw new RuntimeException("No se puede cambiar el cliente de un proyecto existente.");
                    }
                    $conexion->prepare("UPDATE expedientes SET descripcion=:descripcion WHERE id_expediente=:id")
                        ->execute([":descripcion" => $titulo, ":id" => $idExpediente]);
                    $conexion->prepare(
                        "UPDATE proyectos_tesis SET id_servicio_cliente=:servicio, coordinador_id=:coordinador,
                         titulo=:titulo, estado=:estado, fecha_inicio=:inicio, fecha_entrega_estimada=:entrega,
                         entregables=:entregables, observaciones=:observaciones WHERE id_tesis=:id"
                    )->execute([
                        ":servicio" => $idServicioCliente,
                        ":coordinador" => $idCoordinador,
                        ":titulo" => $titulo,
                        ":estado" => $estado,
                        ":inicio" => $fechaInicio,
                        ":entrega" => $fechaEntrega,
                        ":entregables" => trim($_POST["entregables"] ?? "") ?: null,
                        ":observaciones" => trim($_POST["observaciones_proyecto"] ?? "") ?: null,
                        ":id" => $idTesis,
                    ]);
                } else {
                    $numeroExpediente = "ACA-" . date("YmdHis") . "-" . random_int(100, 999);
                    $conexion->prepare(
                        "INSERT INTO expedientes (id_cliente, numero_expediente, estado, descripcion, observaciones)
                         VALUES (:cliente, :numero, 'Abierto', :descripcion, :observaciones)"
                    )->execute([
                        ":cliente" => $idCliente,
                        ":numero" => $numeroExpediente,
                        ":descripcion" => $titulo,
                        ":observaciones" => "Expediente académico generado para este proyecto.",
                    ]);
                    $idExpediente = (int) $conexion->lastInsertId();
                    $conexion->prepare(
                        "INSERT INTO proyectos_tesis (id_expediente, id_servicio_cliente, coordinador_id, titulo, estado,
                         fecha_inicio, fecha_entrega_estimada, entregables, observaciones)
                         VALUES (:expediente, :servicio, :coordinador, :titulo, :estado, :inicio, :entrega, :entregables, :observaciones)"
                    )->execute([
                        ":expediente" => $idExpediente,
                        ":servicio" => $idServicioCliente,
                        ":coordinador" => $idCoordinador,
                        ":titulo" => $titulo,
                        ":estado" => $estado,
                        ":inicio" => $fechaInicio,
                        ":entrega" => $fechaEntrega,
                        ":entregables" => trim($_POST["entregables"] ?? "") ?: null,
                        ":observaciones" => trim($_POST["observaciones_proyecto"] ?? "") ?: null,
                    ]);
                    $idTesis = (int) $conexion->lastInsertId();
                }

                $stmtAsignacion = $conexion->prepare(
                    "SELECT id_asignacion, id_asesor FROM proyectos_asesores
                     WHERE id_tesis=:tesis AND fecha_fin IS NULL ORDER BY id_asignacion DESC LIMIT 1"
                );
                $stmtAsignacion->execute([":tesis" => $idTesis]);
                $asignacionActual = $stmtAsignacion->fetch();
                if ($asignacionActual && (int) $asignacionActual["id_asesor"] !== (int) ($idAsesor ?? 0)) {
                    $conexion->prepare("UPDATE proyectos_asesores SET fecha_fin=CURDATE() WHERE id_asignacion=:id")
                        ->execute([":id" => $asignacionActual["id_asignacion"]]);
                    $asignacionActual = null;
                }
                if ($idAsesor !== null && !$asignacionActual) {
                    $conexion->prepare(
                        "INSERT INTO proyectos_asesores (id_tesis, id_asesor, fecha_asignacion)
                         VALUES (:tesis, :asesor, CURDATE())"
                    )->execute([":tesis" => $idTesis, ":asesor" => $idAsesor]);
                }
                inicializarCronogramaAcademico($conexion, $idTesis, $idAsesor !== null);
                $conexion->prepare(
                    "UPDATE coordinacion_hitos SET estado = CASE
                        WHEN :tiene_asesor_1 = 1 AND estado = 'Pendiente de información' THEN 'En proceso'
                        WHEN :tiene_asesor_2 = 0 AND estado = 'En proceso' THEN 'Pendiente de información'
                        ELSE estado END
                     WHERE id_tesis = :tesis AND codigo = 'asignacion_asesor'"
                )->execute([
                    ":tiene_asesor_1" => $idAsesor !== null ? 1 : 0,
                    ":tiene_asesor_2" => $idAsesor !== null ? 1 : 0,
                    ":tesis" => $idTesis,
                ]);
                if ($idAsesor !== null) {
                    $conexion->prepare(
                        "UPDATE proyectos_tesis SET estado = 'En proceso'
                         WHERE id_tesis = :id AND estado = 'Pendiente'"
                    )->execute([":id" => $idTesis]);
                }
                $conexion->commit();
                $mensaje = "Proyecto académico guardado correctamente.";
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare(
                "SELECT t.*, e.id_cliente, pa.id_asesor
                 FROM proyectos_tesis t INNER JOIN expedientes e ON e.id_expediente=t.id_expediente
                 LEFT JOIN proyectos_asesores pa ON pa.id_tesis=t.id_tesis AND pa.fecha_fin IS NULL
                 WHERE t.id_tesis=:id ORDER BY pa.id_asignacion DESC LIMIT 1"
            );
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $proyectoEditar = $stmt->fetch() ?: null;
        }
        if (isset($_GET["editar_asesor"])) {
            $stmt = $conexion->prepare("SELECT * FROM asesores_academicos WHERE id_asesor=:id");
            $stmt->execute([":id" => (int) $_GET["editar_asesor"]]);
            $asesorEditar = $stmt->fetch() ?: null;
        }

        $clientes = $conexion->query(
            "SELECT id_cliente, nombres, apellidos, empresa, universidad, carrera FROM clientes ORDER BY nombres, apellidos"
        )->fetchAll();
        if ($clienteFiltro > 0) {
            $stmtClienteActual = $conexion->prepare("SELECT * FROM clientes WHERE id_cliente=:id");
            $stmtClienteActual->execute([":id" => $clienteFiltro]);
            $clienteActual = $stmtClienteActual->fetch() ?: null;
        }
        $usuarios = $conexion->query(
            "SELECT id_usuario, nombres, apellidos FROM usuarios WHERE estado=1 ORDER BY nombres, apellidos"
        )->fetchAll();
        $usuariosAsesor = $conexion->query(
            "SELECT id_usuario, nombres, apellidos, estado FROM usuarios ORDER BY nombres, apellidos"
        )->fetchAll();
        $asesores = $conexion->query(
            "SELECT a.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario,
             (SELECT COUNT(*) FROM proyectos_asesores pa
              INNER JOIN proyectos_tesis pt ON pt.id_tesis=pa.id_tesis AND pt.estado <> 'Finalizado'
              WHERE pa.id_asesor=a.id_asesor AND pa.fecha_fin IS NULL) AS proyectos_activos
             FROM asesores_academicos a LEFT JOIN usuarios u ON u.id_usuario=a.id_usuario
             ORDER BY a.estado DESC, a.nombres, a.apellidos"
        )->fetchAll();
        $sqlServicios = "SELECT sc.*, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente
             FROM servicios_clientes sc INNER JOIN clientes c ON c.id_cliente=sc.id_cliente";
        $parametrosServicios = [];
        if ($clienteFiltro > 0) {
            $sqlServicios .= " WHERE sc.id_cliente=:cliente";
            $parametrosServicios[":cliente"] = $clienteFiltro;
        }
        $sqlServicios .= " ORDER BY sc.id_servicio_cliente DESC";
        $stmtServiciosTodos = $conexion->prepare($sqlServicios);
        $stmtServiciosTodos->execute($parametrosServicios);
        $servicios = $stmtServiciosTodos->fetchAll();
        $sqlProyectos =
            "SELECT t.*, e.numero_expediente, e.id_cliente, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente,
             sc.nombre_servicio, CONCAT_WS(' ', coord.nombres, coord.apellidos) AS coordinador,
             (SELECT CONCAT_WS(' ', a.nombres, a.apellidos) FROM proyectos_asesores pa
              INNER JOIN asesores_academicos a ON a.id_asesor=pa.id_asesor
              WHERE pa.id_tesis=t.id_tesis ORDER BY pa.id_asignacion DESC LIMIT 1) AS asesor
             FROM proyectos_tesis t INNER JOIN expedientes e ON e.id_expediente=t.id_expediente
             INNER JOIN clientes c ON c.id_cliente=e.id_cliente
             LEFT JOIN servicios_clientes sc ON sc.id_servicio_cliente=t.id_servicio_cliente
             LEFT JOIN usuarios coord ON coord.id_usuario=t.coordinador_id";
        $parametrosProyectos = [];
        if ($clienteFiltro > 0) {
            $sqlProyectos .= " WHERE e.id_cliente=:cliente";
            $parametrosProyectos[":cliente"] = $clienteFiltro;
        }
        $sqlProyectos .= " ORDER BY t.fecha_entrega_estimada IS NULL, t.fecha_entrega_estimada, t.id_tesis DESC";
        $stmtProyectos = $conexion->prepare($sqlProyectos);
        $stmtProyectos->execute($parametrosProyectos);
        $proyectos = $stmtProyectos->fetchAll();
        foreach ($proyectos as $proyecto) {
            inicializarCronogramaAcademico(
                $conexion,
                (int) $proyecto["id_tesis"],
                trim((string) ($proyecto["asesor"] ?? "")) !== ""
            );
        }
        $stmtHitos = $conexion->prepare(
            "SELECT h.* FROM coordinacion_hitos h
             INNER JOIN proyectos_tesis t ON t.id_tesis = h.id_tesis
             INNER JOIN expedientes e ON e.id_expediente = t.id_expediente
             WHERE (:cliente = 0 OR e.id_cliente = :cliente_hitos)
             ORDER BY h.id_tesis, h.orden"
        );
        $stmtHitos->execute([":cliente" => $clienteFiltro, ":cliente_hitos" => $clienteFiltro]);
        foreach ($stmtHitos->fetchAll() as $hito) {
            $hitosPorProyecto[(int) $hito["id_tesis"]][] = $hito;
        }
        $idProyectoDetalle = (int) ($_GET["proyecto"] ?? 0);
        foreach ($proyectos as $proyecto) {
            if ((int) $proyecto["id_tesis"] === $idProyectoDetalle) {
                $proyectoDetalle = $proyecto;
                $hitosDetalle = $hitosPorProyecto[$idProyectoDetalle] ?? [];
                $stmtEntregasDetalle = $conexion->prepare(
                    "SELECT d.*, a.nombres AS asesor_nombres, a.apellidos AS asesor_apellidos,
                            h.titulo AS etapa
                     FROM entregas_asesores d
                     INNER JOIN asesores_academicos a ON a.id_asesor = d.id_asesor
                     LEFT JOIN coordinacion_hitos h ON h.id_hito = d.id_hito
                     WHERE d.id_tesis = :tesis
                     ORDER BY d.fecha_envio DESC, d.id_entrega DESC"
                );
                $stmtEntregasDetalle->execute([":tesis" => $idProyectoDetalle]);
                $entregasDetalle = $stmtEntregasDetalle->fetchAll();
                break;
            }
        }
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo completar la operación: " . $e->getMessage();
    }
}

$valorProyecto = static function (string $campo) use ($proyectoEditar): string {
    return htmlspecialchars((string) ($proyectoEditar[$campo] ?? ""), ENT_QUOTES, "UTF-8");
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión académica | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
    <style>
        .coord-board { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:14px; }
        .coord-card { display:block; text-decoration:none; color:inherit; border:1px solid #e2e8f0; border-left:5px solid #16a34a; border-radius:12px; padding:15px; background:#fff; transition:transform .15s,box-shadow .15s; }
        .coord-card:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(15,23,42,.08); }
        .coord-card.en-proceso { border-left-color:#16a34a; }
        .coord-card.pendiente-info { border-left-color:#eab308; }
        .coord-card.retrasado { border-left-color:#dc2626; }
        .coord-card.finalizado { border-left-color:#2563eb; }
        .coord-card small { display:block; color:#64748b; margin-top:5px; }
        .coord-card-status { font-weight:700; font-size:.8rem; }
        .coord-card.en-proceso .coord-card-status { color:#15803d; }
        .coord-card.pendiente-info .coord-card-status { color:#a16207; }
        .coord-card.retrasado .coord-card-status { color:#b91c1c; }
        .coord-card.finalizado .coord-card-status { color:#1d4ed8; }
        .coord-steps { display:grid; gap:12px; }
        .coord-step { border:1px solid #e2e8f0; border-radius:12px; padding:14px; }
        .coord-step-form { display:grid; grid-template-columns:1.2fr 1fr 1fr; gap:10px; align-items:end; }
        @media (max-width:760px) { .coord-step-form { grid-template-columns:1fr; } }
    </style>
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner">
            <p class="eyebrow">Clientes y gestión académica</p>
            <h1>Servicios, proyectos y asesores</h1>
            <p>Vincula servicios contratados y proyectos académicos con clientes, responsables y fechas.</p>
            <a class="btn-secondary btn-sm" href="clientes.php">Ficha de clientes</a>
        </div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>
        <section class="panel">
            <div class="panel-heading">
                <h3>Coordinación académica automática</h3>
                <span class="status-badge"><?= count($proyectos) ?> proyectos</span>
            </div>
            <p>Del CRM al proyecto, asesor, cronograma, entregables, revisión, observaciones, correcciones y entrega final. Selecciona una tarjeta para ver y actualizar su expediente.</p>
            <div class="coord-board">
                <?php foreach ($proyectos as $proyectoTablero): ?>
                    <?php
                    $hitosTablero = $hitosPorProyecto[(int) $proyectoTablero["id_tesis"]] ?? [];
                    $tieneRetraso = false;
                    $pendienteInfo = false;
                    $etapaFinalizada = false;
                    foreach ($hitosTablero as $hitoTablero) {
                        if (
                            $hitoTablero["fecha_objetivo"] !== null
                            && $hitoTablero["fecha_objetivo"] < date("Y-m-d")
                            && $hitoTablero["estado"] !== "Finalizado"
                        ) {
                            $tieneRetraso = true;
                        }
                        if ($hitoTablero["estado"] === "Pendiente de información") $pendienteInfo = true;
                        if ($hitoTablero["codigo"] === "entrega_final" && $hitoTablero["estado"] === "Finalizado") $etapaFinalizada = true;
                    }
                    if ($proyectoTablero["estado"] === "Finalizado" || $etapaFinalizada) {
                        $estadoTablero = "Finalizado";
                        $claseTablero = "finalizado";
                    } elseif ($tieneRetraso || $proyectoTablero["estado"] === "Retrasado") {
                        $estadoTablero = "Retrasado";
                        $claseTablero = "retrasado";
                    } elseif ($pendienteInfo || $proyectoTablero["estado"] === "Pendiente") {
                        $estadoTablero = "Pendiente de información";
                        $claseTablero = "pendiente-info";
                    } else {
                        $estadoTablero = "En proceso";
                        $claseTablero = "en-proceso";
                    }
                    ?>
                    <a class="coord-card <?= $claseTablero ?>" href="gestion_academica.php?proyecto=<?= (int) $proyectoTablero["id_tesis"] ?><?= $clienteFiltro > 0 ? "&amp;cliente=" . $clienteFiltro : "" ?>">
                        <span class="coord-card-status"><?= $escapar($estadoTablero) ?></span>
                        <strong><?= $escapar($proyectoTablero["titulo"]) ?></strong>
                        <small><?= $escapar($proyectoTablero["cliente"]) ?></small>
                        <small>Asesor: <?= $escapar($proyectoTablero["asesor"] ?: "Por asignar") ?></small>
                        <small>Entrega: <?= $escapar($proyectoTablero["fecha_entrega_estimada"] ?: "Sin fecha definida") ?></small>
                        <small><?= count($hitosTablero) ?> etapas · Ver expediente completo →</small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$proyectos): ?><p class="empty-state">Los proyectos creados desde el CRM aparecerán aquí.</p><?php endif; ?>
            </div>
        </section>
        <?php if ($proyectoDetalle): ?>
        <section class="panel">
            <div class="panel-heading">
                <h3>Expediente del proyecto: <?= $escapar($proyectoDetalle["titulo"]) ?></h3>
                <a class="text-link" href="gestion_academica.php<?= $clienteFiltro > 0 ? "?cliente=" . $clienteFiltro : "" ?>">Cerrar detalle</a>
            </div>
            <div class="quick-list">
                <p><strong>Cliente:</strong> <?= $escapar($proyectoDetalle["cliente"]) ?> · <strong>Expediente:</strong> <?= $escapar($proyectoDetalle["numero_expediente"]) ?></p>
                <p><strong>Servicio:</strong> <?= $escapar($proyectoDetalle["nombre_servicio"] ?: "Sin servicio") ?> · <strong>Asesor:</strong> <?= $escapar($proyectoDetalle["asesor"] ?: "Por asignar") ?> · <strong>Coordinador:</strong> <?= $escapar($proyectoDetalle["coordinador"] ?: "Sin asignar") ?></p>
                <p><strong>Inicio:</strong> <?= $escapar($proyectoDetalle["fecha_inicio"] ?: "No definido") ?> · <strong>Entrega:</strong> <?= $escapar($proyectoDetalle["fecha_entrega_estimada"] ?: "No definida") ?> · <strong>Estado:</strong> <?= $escapar($proyectoDetalle["estado"]) ?></p>
                <?php if ($proyectoDetalle["observaciones"]): ?><p><strong>Observaciones generales:</strong> <?= nl2br($escapar($proyectoDetalle["observaciones"])) ?></p><?php endif; ?>
                <?php if ($proyectoDetalle["entregables"]): ?><p><strong>Entregables:</strong> <?= nl2br($escapar($proyectoDetalle["entregables"])) ?></p><?php endif; ?>
                <p><a class="text-link" href="documentos.php?proyecto=<?= (int) $proyectoDetalle["id_tesis"] ?>">Abrir expediente documental</a>
                    <?php if (usuarioTieneAlgunPermiso($conexion, ["contratos.ver", "contratos.gestionar", "finanzas.ver", "finanzas.gestionar", "legal.gestionar"])): ?>
                    · <a class="text-link" href="contratos.php">Ver contrato y saldo</a>
                    <?php endif; ?>
                </p>
            </div>
            <h4>Cronograma, revisiones y correcciones</h4>
            <div class="coord-steps">
                <?php foreach ($hitosDetalle as $hito): ?>
                <div class="coord-step">
                    <form method="post" class="data-form">
                        <input type="hidden" name="accion" value="actualizar_hito">
                        <input type="hidden" name="id_hito" value="<?= (int) $hito["id_hito"] ?>">
                        <div class="panel-heading"><strong><?= (int) $hito["orden"] ?>. <?= $escapar($hito["titulo"]) ?></strong><span class="status-badge"><?= $escapar($hito["estado"]) ?></span></div>
                        <div class="coord-step-form">
                            <div class="campo"><label for="estado_hito_<?= (int) $hito["id_hito"] ?>">Estado</label><select id="estado_hito_<?= (int) $hito["id_hito"] ?>" name="estado_hito"><?php foreach (["Pendiente", "Pendiente de información", "En proceso", "En revisión", "Observado", "Correcciones", "Finalizado"] as $estadoHito): ?><option <?= $hito["estado"] === $estadoHito ? "selected" : "" ?>><?= $escapar($estadoHito) ?></option><?php endforeach; ?></select></div>
                            <div class="campo"><label for="fecha_objetivo_<?= (int) $hito["id_hito"] ?>">Fecha objetivo</label><input id="fecha_objetivo_<?= (int) $hito["id_hito"] ?>" type="date" name="fecha_objetivo" value="<?= $escapar($hito["fecha_objetivo"]) ?>"></div>
                            <button class="btn-primary form-submit" type="submit">Guardar etapa</button>
                        </div>
                        <div class="campo"><label for="observaciones_hito_<?= (int) $hito["id_hito"] ?>">Observaciones / instrucciones de corrección</label><textarea id="observaciones_hito_<?= (int) $hito["id_hito"] ?>" name="observaciones_hito" rows="2"><?= $escapar($hito["observaciones"]) ?></textarea></div>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php if (!$hitosDetalle): ?><p class="empty-state">No hay etapas registradas para este proyecto.</p><?php endif; ?>
            </div>
            <h4>Entregas de asesores</h4>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Etapa / entrega</th><th>Asesor</th><th>Versión y fecha</th><th>Archivo</th><th>Revisión</th></tr></thead>
                    <tbody>
                    <?php foreach ($entregasDetalle as $entrega): ?>
                    <tr>
                        <td><strong><?= $escapar($entrega["titulo"]) ?></strong><small><?= $escapar($entrega["etapa"] ?: "Etapa eliminada") ?></small>
                            <?php if ($entrega["comentario_asesor"]): ?><small>Asesor: <?= nl2br($escapar($entrega["comentario_asesor"])) ?></small><?php endif; ?>
                        </td>
                        <td><?= $escapar(trim($entrega["asesor_nombres"] . " " . $entrega["asesor_apellidos"])) ?></td>
                        <td>v<?= (int) $entrega["version"] ?><small><?= $escapar($entrega["fecha_envio"]) ?></small></td>
                        <td><a class="text-link" href="asesor_entrega_descargar.php?id=<?= (int) $entrega["id_entrega"] ?>">Descargar <?= $escapar($entrega["nombre_original"]) ?></a></td>
                        <td>
                            <strong><?= $escapar($entrega["estado"]) ?></strong>
                            <?php if ($entrega["observaciones_coordinador"]): ?><small><?= nl2br($escapar($entrega["observaciones_coordinador"])) ?></small><?php endif; ?>
                            <?php if ($entrega["estado"] === "En revisión"): ?>
                            <form method="post" action="gestion_academica.php?proyecto=<?= (int) $proyectoDetalle["id_tesis"] ?>" class="data-form">
                                <input type="hidden" name="accion" value="revisar_entrega">
                                <input type="hidden" name="id_entrega" value="<?= (int) $entrega["id_entrega"] ?>">
                                <input type="hidden" name="id_tesis" value="<?= (int) $proyectoDetalle["id_tesis"] ?>">
                                <div class="campo"><label for="decision_<?= (int) $entrega["id_entrega"] ?>">Decisión</label>
                                    <select id="decision_<?= (int) $entrega["id_entrega"] ?>" name="decision">
                                        <option value="Aprobado">Aprobar</option><option value="Observado">Observar</option>
                                    </select>
                                </div>
                                <div class="campo"><label for="observaciones_<?= (int) $entrega["id_entrega"] ?>">Observaciones / correcciones</label>
                                    <textarea id="observaciones_<?= (int) $entrega["id_entrega"] ?>" name="observaciones_coordinador" rows="2" maxlength="5000"></textarea>
                                </div>
                                <button class="btn-primary form-submit" type="submit">Guardar revisión</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$entregasDetalle): ?><tr><td colspan="5" class="empty-state">Aún no se han recibido entregas para este proyecto.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
        <?php if ($clienteActual): ?>
        <section class="panel">
            <div class="panel-heading"><h3>Ficha integral: <?= $escapar(trim($clienteActual["nombres"] . " " . ($clienteActual["apellidos"] ?? ""))) ?></h3>
                <a class="text-link" href="clientes.php?editar=<?= (int) $clienteActual["id_cliente"] ?>">Editar datos del cliente</a>
            </div>
            <div class="quick-list">
                <p><strong>Documento:</strong> <?= $escapar(trim(($clienteActual["tipo_documento"] ?? "DOC") . " " . ($clienteActual["documento"] ?? "No registrado"))) ?>
                    · <strong>Celular:</strong> <?= $escapar($clienteActual["telefono"] ?: "No registrado") ?>
                    · <strong>Correo:</strong> <?= $escapar($clienteActual["correo"] ?: "No registrado") ?></p>
                <p><strong>Universidad:</strong> <?= $escapar($clienteActual["universidad"] ?: "No registrada") ?>
                    · <strong>Carrera:</strong> <?= $escapar($clienteActual["carrera"] ?: "No registrada") ?>
                    · <strong>Estado:</strong> <?= $escapar($clienteActual["estado"]) ?></p>
                <?php if ($clienteActual["observaciones"]): ?><p><strong>Observaciones:</strong> <?= nl2br($escapar($clienteActual["observaciones"])) ?></p><?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="crud-layout">
            <div class="panel">
                <div class="panel-heading"><h3>Registrar servicio contratado</h3></div>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar_servicio">
                    <div class="form-grid">
                        <div class="campo"><label for="id_cliente_servicio">Cliente *</label>
                            <select id="id_cliente_servicio" name="id_cliente_servicio" required>
                                <option value="">Seleccionar cliente</option>
                                <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= (int) $cliente["id_cliente"] ?>" <?= $clienteFiltro === (int) $cliente["id_cliente"] ? "selected" : "" ?>>
                                    <?= $escapar(trim($cliente["nombres"] . " " . ($cliente["apellidos"] ?? ""))) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo"><label for="nombre_servicio">Servicio *</label><input id="nombre_servicio" name="nombre_servicio" maxlength="180" required></div>
                        <div class="campo"><label for="monto">Monto contratado</label><input id="monto" name="monto" type="number" min="0" step="0.01"></div>
                        <div class="campo"><label for="estado_servicio">Estado</label><select id="estado_servicio" name="estado_servicio"><option>Activo</option><option>Suspendido</option><option>Finalizado</option></select></div>
                        <div class="campo"><label for="fecha_inicio_servicio">Fecha de inicio</label><input id="fecha_inicio_servicio" name="fecha_inicio_servicio" type="date"></div>
                        <div class="campo"><label for="fecha_fin_servicio">Fecha de fin</label><input id="fecha_fin_servicio" name="fecha_fin_servicio" type="date"></div>
                    </div>
                    <div class="campo"><label for="descripcion_servicio">Datos relevantes / alcance</label><textarea id="descripcion_servicio" name="descripcion_servicio" rows="2"></textarea></div>
                    <div class="campo"><label for="observaciones_servicio">Observaciones</label><textarea id="observaciones_servicio" name="observaciones_servicio" rows="2"></textarea></div>
                    <button class="btn-primary form-submit" type="submit">Guardar servicio</button>
                </form>
            </div>

            <div class="panel">
                <div class="panel-heading"><h3><?= $proyectoEditar ? "Editar proyecto académico" : "Nuevo proyecto académico" ?></h3>
                    <?php if ($proyectoEditar): ?><a class="text-link" href="gestion_academica.php">Cancelar</a><?php endif; ?>
                </div>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar_proyecto">
                    <input type="hidden" name="id_tesis" value="<?= (int) ($proyectoEditar["id_tesis"] ?? 0) ?>">
                    <div class="form-grid">
                        <div class="campo"><label for="id_cliente_proyecto">Cliente *</label>
                            <select id="id_cliente_proyecto" name="id_cliente_proyecto" required>
                                <option value="">Seleccionar cliente</option>
                                <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= (int) $cliente["id_cliente"] ?>" <?= (int) ($proyectoEditar["id_cliente"] ?? $clienteFiltro) === (int) $cliente["id_cliente"] ? "selected" : "" ?>>
                                    <?= $escapar(trim($cliente["nombres"] . " " . ($cliente["apellidos"] ?? ""))) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo"><label for="id_servicio_cliente">Servicio contratado</label>
                            <select id="id_servicio_cliente" name="id_servicio_cliente">
                                <option value="">Sin servicio asociado</option>
                                <?php foreach ($servicios as $servicio): ?>
                                <option value="<?= (int) $servicio["id_servicio_cliente"] ?>" <?= (int) ($proyectoEditar["id_servicio_cliente"] ?? 0) === (int) $servicio["id_servicio_cliente"] ? "selected" : "" ?>>
                                    <?= $escapar($servicio["cliente"] . " · " . $servicio["nombre_servicio"]) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo"><label for="titulo">Nombre del proyecto *</label><input id="titulo" name="titulo" maxlength="255" required value="<?= $valorProyecto("titulo") ?>"></div>
                        <div class="campo"><label for="estado_proyecto">Estado</label>
                            <select id="estado_proyecto" name="estado_proyecto">
                                <?php if ($proyectoEditar && !in_array($proyectoEditar["estado"], $estadosProyecto, true)): ?>
                                <option selected><?= $escapar($proyectoEditar["estado"]) ?></option>
                                <?php endif; ?>
                                <?php foreach ($estadosProyecto as $estado): ?>
                                <option <?= ($proyectoEditar["estado"] ?? "Pendiente") === $estado ? "selected" : "" ?>><?= $escapar($estado) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo"><label for="id_asesor_proyecto">Asesor / especialista</label>
                            <select id="id_asesor_proyecto" name="id_asesor_proyecto">
                                <option value="">Sin asignar</option>
                                <?php foreach ($asesores as $asesor): if ($asesor["estado"] !== "Activo") continue; ?>
                                <option value="<?= (int) $asesor["id_asesor"] ?>" <?= (int) ($proyectoEditar["id_asesor"] ?? 0) === (int) $asesor["id_asesor"] ? "selected" : "" ?>>
                                    <?= $escapar(trim($asesor["nombres"] . " " . $asesor["apellidos"] . ($asesor["especialidad"] ? " · " . $asesor["especialidad"] : ""))) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo"><label for="coordinador_id">Coordinador</label>
                            <select id="coordinador_id" name="coordinador_id"><option value="">Sin asignar</option>
                                <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?= (int) $usuario["id_usuario"] ?>" <?= (int) ($proyectoEditar["coordinador_id"] ?? 0) === (int) $usuario["id_usuario"] ? "selected" : "" ?>>
                                    <?= $escapar(trim($usuario["nombres"] . " " . $usuario["apellidos"])) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo"><label for="fecha_inicio">Fecha de inicio</label><input id="fecha_inicio" name="fecha_inicio" type="date" value="<?= $valorProyecto("fecha_inicio") ?>"></div>
                        <div class="campo"><label for="fecha_entrega">Fecha de entrega</label><input id="fecha_entrega" name="fecha_entrega" type="date" value="<?= $valorProyecto("fecha_entrega_estimada") ?>"></div>
                    </div>
                    <div class="campo"><label for="entregables">Entregables</label><textarea id="entregables" name="entregables" rows="3"><?= $valorProyecto("entregables") ?></textarea></div>
                    <div class="campo"><label for="observaciones_proyecto">Observaciones</label><textarea id="observaciones_proyecto" name="observaciones_proyecto" rows="3"><?= $valorProyecto("observaciones") ?></textarea></div>
                    <button class="btn-primary form-submit" type="submit">Guardar proyecto</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading"><h3>Proyectos académicos (<?= count($proyectos) ?>)</h3>
                <?php if ($clienteFiltro > 0): ?><a class="text-link" href="gestion_academica.php">Ver todos</a><?php endif; ?>
            </div>
            <div class="table-wrap"><table class="data-table">
                <thead><tr><th>Proyecto / cliente</th><th>Servicio</th><th>Asesor / coordinador</th><th>Fechas</th><th>Estado / entregables</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($proyectos as $proyecto): ?>
                <tr>
                    <td><strong><?= $escapar($proyecto["titulo"]) ?></strong><small><?= $escapar($proyecto["cliente"]) ?></small></td>
                    <td><?= $escapar($proyecto["nombre_servicio"] ?: "Sin servicio asociado") ?></td>
                    <td><?= $escapar($proyecto["asesor"] ?: "Sin asesor") ?><small>Coordinador: <?= $escapar($proyecto["coordinador"] ?: "Sin asignar") ?></small></td>
                    <td><?= $escapar($proyecto["fecha_inicio"] ?: "Sin inicio") ?><small>Entrega: <?= $escapar($proyecto["fecha_entrega_estimada"] ?: "Sin fecha") ?></small></td>
                    <td><span class="status-badge"><?= $escapar($proyecto["estado"]) ?></span><small><?= $escapar($proyecto["entregables"] ?: "Sin entregables registrados") ?></small></td>
                    <td><a class="btn-action btn-action-primary" href="gestion_academica.php?editar=<?= (int) $proyecto["id_tesis"] ?>">Editar</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$proyectos): ?><tr><td colspan="6" class="empty-state">No hay proyectos académicos registrados.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>

        <section class="crud-layout">
            <div class="panel">
                <div class="panel-heading"><h3><?= $asesorEditar ? "Editar asesor / especialista" : "Registrar asesor / especialista" ?></h3>
                    <?php if ($asesorEditar): ?><a class="text-link" href="gestion_academica.php">Cancelar</a><?php endif; ?>
                </div>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar_asesor">
                    <input type="hidden" name="id_asesor" value="<?= (int) ($asesorEditar["id_asesor"] ?? 0) ?>">
                    <div class="form-grid">
                        <div class="campo"><label for="nombres_asesor">Nombres *</label><input id="nombres_asesor" name="nombres_asesor" required value="<?= $escapar($asesorEditar["nombres"] ?? "") ?>"></div>
                        <div class="campo"><label for="apellidos_asesor">Apellidos *</label><input id="apellidos_asesor" name="apellidos_asesor" required value="<?= $escapar($asesorEditar["apellidos"] ?? "") ?>"></div>
                        <div class="campo"><label for="correo_asesor">Correo</label><input id="correo_asesor" name="correo_asesor" type="email" value="<?= $escapar($asesorEditar["correo"] ?? "") ?>"></div>
                        <div class="campo"><label for="especialidad">Especialidad</label><input id="especialidad" name="especialidad" placeholder="Metodología, estadística..." value="<?= $escapar($asesorEditar["especialidad"] ?? "") ?>"></div>
                        <div class="campo"><label for="id_usuario">Cuenta del sistema (opcional)</label><select id="id_usuario" name="id_usuario"><option value="">Sin cuenta vinculada</option>
                            <?php foreach ($usuariosAsesor as $usuario): ?><option value="<?= (int) $usuario["id_usuario"] ?>" <?= (int) ($asesorEditar["id_usuario"] ?? 0) === (int) $usuario["id_usuario"] ? "selected" : "" ?>><?= $escapar(trim($usuario["nombres"] . " " . $usuario["apellidos"])) ?><?= (int) $usuario["estado"] === 1 ? "" : " (inactivo)" ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="campo"><label for="estado_asesor">Estado</label><select id="estado_asesor" name="estado_asesor"><option <?= ($asesorEditar["estado"] ?? "Activo") === "Activo" ? "selected" : "" ?>>Activo</option><option <?= ($asesorEditar["estado"] ?? "") === "Inactivo" ? "selected" : "" ?>>Inactivo</option></select></div>
                    </div>
                    <div class="campo"><label for="disponibilidad">Disponibilidad</label><textarea id="disponibilidad" name="disponibilidad" rows="2" placeholder="Días y horarios disponibles"><?= $escapar($asesorEditar["disponibilidad"] ?? "") ?></textarea></div>
                    <button class="btn-primary form-submit" type="submit"><?= $asesorEditar ? "Actualizar asesor" : "Guardar asesor" ?></button>
                </form>
            </div>
            <div class="panel">
                <div class="panel-heading"><h3>Asesores y especialistas</h3></div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Asesor</th><th>Especialidad / disponibilidad</th><th>Proyectos activos</th><th>Estado</th></tr></thead><tbody>
                    <?php foreach ($asesores as $asesor): ?>
                    <tr>
                        <td><strong><?= $escapar(trim($asesor["nombres"] . " " . $asesor["apellidos"])) ?></strong><small><?= $escapar($asesor["correo"] ?: $asesor["usuario"] ?: "") ?></small></td>
                        <td><?= $escapar($asesor["especialidad"] ?: "Sin especialidad") ?><small><?= nl2br($escapar($asesor["disponibilidad"] ?: "Disponibilidad no registrada")) ?></small></td>
                        <td><?= (int) $asesor["proyectos_activos"] ?></td>
                        <td>
                            <a class="btn-action btn-action-primary" href="gestion_academica.php?editar_asesor=<?= (int) $asesor["id_asesor"] ?>">Editar</a>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="accion" value="guardar_asesor">
                                <input type="hidden" name="id_asesor" value="<?= (int) $asesor["id_asesor"] ?>">
                                <input type="hidden" name="nombres_asesor" value="<?= $escapar($asesor["nombres"]) ?>">
                                <input type="hidden" name="apellidos_asesor" value="<?= $escapar($asesor["apellidos"]) ?>">
                                <input type="hidden" name="correo_asesor" value="<?= $escapar($asesor["correo"]) ?>">
                                <input type="hidden" name="especialidad" value="<?= $escapar($asesor["especialidad"]) ?>">
                                <input type="hidden" name="disponibilidad" value="<?= $escapar($asesor["disponibilidad"]) ?>">
                                <input type="hidden" name="id_usuario" value="<?= (int) ($asesor["id_usuario"] ?? 0) ?>">
                                <input type="hidden" name="estado_asesor" value="<?= $asesor["estado"] === "Activo" ? "Inactivo" : "Activo" ?>">
                                <button class="btn-action btn-action-primary" type="submit"><?= $asesor["estado"] === "Activo" ? "Desactivar" : "Activar" ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$asesores): ?><tr><td colspan="4" class="empty-state">No hay asesores registrados.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading"><h3>Servicios contratados (<?= count($servicios) ?>)</h3></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Cliente</th><th>Servicio / alcance</th><th>Monto</th><th>Fechas</th><th>Estado</th></tr></thead><tbody>
                <?php foreach ($servicios as $servicio): ?>
                <tr><td><?= $escapar($servicio["cliente"]) ?></td><td><strong><?= $escapar($servicio["nombre_servicio"]) ?></strong><small><?= $escapar($servicio["descripcion"] ?: $servicio["observaciones"] ?: "") ?></small></td><td><?= $servicio["monto"] !== null ? number_format((float) $servicio["monto"], 2) : "No indicado" ?></td><td><?= $escapar($servicio["fecha_inicio"] ?: "Sin inicio") ?><small><?= $escapar($servicio["fecha_fin"] ?: "Sin fin") ?></small></td><td><span class="status-badge"><?= $escapar($servicio["estado"]) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (!$servicios): ?><tr><td colspan="5" class="empty-state">No hay servicios contratados registrados.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </main>
</div>
<script>
document.addEventListener("DOMContentLoaded", () => {
    const cliente = document.getElementById("id_cliente_proyecto");
    const servicio = document.getElementById("id_servicio_cliente");
    const servicios = <?= json_encode(array_map(static function (array $item): array {
        return ["id" => (int) $item["id_servicio_cliente"], "cliente" => (int) $item["id_cliente"], "nombre" => $item["nombre_servicio"]];
    }, $servicios), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (!cliente || !servicio) return;
    const filtrarServicios = () => {
        const seleccionado = servicio.value;
        servicio.innerHTML = '<option value="">Sin servicio asociado</option>';
        servicios.filter(item => String(item.cliente) === cliente.value).forEach(item => {
            const option = document.createElement("option");
            option.value = item.id;
            option.textContent = item.nombre;
            option.selected = String(item.id) === seleccionado;
            servicio.appendChild(option);
        });
    };
    cliente.addEventListener("change", filtrarServicios);
    filtrarServicios();
});
</script>
</body>
</html>
