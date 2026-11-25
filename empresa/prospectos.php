<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
require_once "../config/notificaciones.php";
require_once "../config/catalogos.php";
require_once "../config/coordinacion_academica.php";

if ($conexion) {
    exigirPermiso($conexion, "crm.gestionar");
}

$mensaje = "";
$error = "";
$prospectoEditar = null;
$prospectoSeguimiento = null;
$vendedores = [];
$prospectos = [];
$campanasActivas = [];
$resumenEstados = [];
$resumenVendedores = [];
$resumenIndicadores = [];
$filtroVendedor = 0;
$usuarioActual = (int) $_SESSION["id_usuario"];
$puedeVerTodosProspectos = false;
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

$fuentesCatalogo = [];
$estadosCatalogo = [];
$estados = ["Nuevo", "Contactado", "Interesado", "En Evaluación", "Cotizado", "Negociación", "Ganado", "Perdido"];

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        $puedeVerTodosProspectos = usuarioTienePermiso($conexion, "crm.ver_todos_prospectos");

        // Cargar catálogos dinámicos desde la BD para evitar opciones hardcodeadas
        $fuentesCatalogo = obtenerCatalogo($conexion, "fuente_prospecto");
        $estadosCatalogo = obtenerCatalogo($conexion, "estado_prospecto");
        $campanasActivas = $conexion->query(
            "SELECT id_campana, nombre, canal FROM campanas_marketing
             WHERE estado = 'Activa' ORDER BY fecha_inicio DESC, nombre"
        )->fetchAll();
        if (!empty($estadosCatalogo)) {
            $estados = array_column($estadosCatalogo, "nombre");
        }

        if ($puedeVerTodosProspectos) {
            $vendedores = $conexion->query(
                "SELECT u.id_usuario, u.nombres, u.apellidos FROM usuarios u
                 INNER JOIN roles r ON r.id_rol = u.id_rol
                 WHERE u.estado = 1 AND r.codigo IN ('ventas', 'vendedor') ORDER BY u.nombres, u.apellidos"
            )->fetchAll();
        } else {
            $stmtVendedor = $conexion->prepare("SELECT id_usuario, nombres, apellidos FROM usuarios WHERE id_usuario = :id AND estado = 1");
            $stmtVendedor->execute([":id" => $usuarioActual]);
            $vendedores = $stmtVendedor->fetchAll();
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $idProspecto = (int) ($_POST["id_prospecto"] ?? 0);
                $nombres = trim($_POST["nombres"] ?? "");
                $apellidos = trim($_POST["apellidos"] ?? "") ?: null;
                $telefonoRaw = trim($_POST["telefono"] ?? "");
                $telefonoLimpio = preg_replace('/\s+/', '', $telefonoRaw) ?: null;
                $dni = trim($_POST["dni"] ?? "") ?: null;
                $fechaNacimiento = trim($_POST["fecha_nacimiento"] ?? "") ?: null;
                $correo = trim($_POST["correo"] ?? "") ?: null;
                $universidad = trim($_POST["universidad"] ?? "") ?: null;
                $carrera = trim($_POST["carrera"] ?? "") ?: null;
                $servicio = trim($_POST["servicio"] ?? "") ?: null;
                $fuente = trim($_POST["fuente"] ?? "") ?: null;
                $idCampana = (int) ($_POST["id_campana"] ?? 0) ?: null;
                $estado = trim($_POST["estado"] ?? "Nuevo");
                $observaciones = trim($_POST["observaciones"] ?? "") ?: null;
                $proximaAccion = trim($_POST["proxima_accion"] ?? "") ?: null;
                $vendedorId = $puedeVerTodosProspectos ? ((int) ($_POST["vendedor_id"] ?? 0) ?: null) : $usuarioActual;

                if ($nombres === "" || !preg_match("/^[\\p{L}]+(?:[ '\\x{2019}.-][\\p{L}]+)*$/u", $nombres)) {
                    throw new RuntimeException("El nombre es obligatorio y solo puede contener letras, espacios, apóstrofes, puntos o guiones.");
                }
                if ($apellidos !== null && !preg_match("/^[\\p{L}]+(?:[ '\\x{2019}.-][\\p{L}]+)*$/u", $apellidos)) {
                    throw new RuntimeException("Los apellidos solo pueden contener letras, espacios, apóstrofes, puntos o guiones.");
                }

                if ($telefonoLimpio !== null && !preg_match('/^9[0-9]{8}$/', $telefonoLimpio)) {
                    throw new RuntimeException("El celular debe tener 9 dígitos y comenzar obligatoriamente con el número 9.");
                }
                if ($dni !== null && !preg_match('/^[0-9]{8}$/D', $dni)) {
                    throw new RuntimeException("El DNI debe contener exactamente 8 números.");
                }
                if ($fechaNacimiento !== null) {
                    $fechaNacimientoValidada = DateTimeImmutable::createFromFormat("!Y-m-d", $fechaNacimiento);
                    $erroresFecha = DateTimeImmutable::getLastErrors();
                    if (
                        !$fechaNacimientoValidada
                        || ($erroresFecha !== false && ($erroresFecha["warning_count"] > 0 || $erroresFecha["error_count"] > 0))
                        || $fechaNacimientoValidada->format("Y-m-d") !== $fechaNacimiento
                        || $fechaNacimientoValidada > new DateTimeImmutable("today")
                    ) {
                        throw new RuntimeException("La fecha de nacimiento debe ser válida y no puede ser futura.");
                    }
                }

                // 4. RESTRICCIÓN: Calendario de próxima acción no puede ser anterior a hoy
                if ($proximaAccion !== null && $proximaAccion < date("Y-m-d")) {
                    throw new RuntimeException("La fecha de próxima acción no puede ser anterior a la actual (" . date("d/m/Y") . ").");
                }

                if (!in_array($estado, $estados, true)) {
                    // Si el estado vino del catálogo dinámico
                    $estado = asegurarOpcionEnCatalogo($conexion, "estado_prospecto", $estado);
                }

                // 5. ESCALABILIDAD BD: Si se escribió una nueva fuente en el combobox, registrarla en la base de datos
                if ($fuente !== null && $fuente !== "") {
                    $fuente = asegurarOpcionEnCatalogo($conexion, "fuente_prospecto", $fuente);
                }
                if ($idCampana !== null) {
                    $stmtCampana = $conexion->prepare(
                        "SELECT canal FROM campanas_marketing WHERE id_campana = :id AND estado = 'Activa'"
                    );
                    $stmtCampana->execute([":id" => $idCampana]);
                    $canalCampana = $stmtCampana->fetchColumn();
                    if ($canalCampana === false) {
                        throw new RuntimeException("Selecciona una campaña activa válida.");
                    }
                    $fuente = asegurarOpcionEnCatalogo($conexion, "fuente_prospecto", (string) $canalCampana);
                }

                if ($puedeVerTodosProspectos && $vendedorId !== null) {
                    $stmtVendedorValido = $conexion->prepare(
                        "SELECT COUNT(*) FROM usuarios u INNER JOIN roles r ON r.id_rol = u.id_rol
                         WHERE u.id_usuario = :id AND u.estado = 1 AND r.codigo IN ('ventas', 'vendedor')"
                    );
                    $stmtVendedorValido->execute([":id" => $vendedorId]);
                    if ((int) $stmtVendedorValido->fetchColumn() !== 1) {
                        throw new RuntimeException("Selecciona un vendedor activo válido.");
                    }
                }

                $datos = [
                    ":nombres" => $nombres,
                    ":apellidos" => $apellidos,
                    ":telefono" => $telefonoLimpio,
                    ":dni" => $dni,
                    ":fecha_nacimiento" => $fechaNacimiento,
                    ":correo" => $correo,
                    ":universidad" => $universidad,
                    ":carrera" => $carrera,
                    ":servicio" => $servicio,
                    ":fuente" => $fuente,
                    ":id_campana" => $idCampana,
                    ":vendedor_id" => $vendedorId,
                    ":estado" => $estado,
                    ":observaciones" => $observaciones,
                    ":proxima_accion" => $proximaAccion,
                ];

                if ($idProspecto > 0) {
                    $stmtAnterior = $conexion->prepare(
                        "SELECT estado, vendedor_id FROM prospectos WHERE id_prospecto = :id" .
                        ($puedeVerTodosProspectos ? "" : " AND vendedor_id = :usuario")
                    );
                    $parametrosAnterior = [":id" => $idProspecto];
                    if (!$puedeVerTodosProspectos) {
                        $parametrosAnterior[":usuario"] = $usuarioActual;
                    }
                    $stmtAnterior->execute($parametrosAnterior);
                    $estadoAnterior = $stmtAnterior->fetchColumn();
                    if ($estadoAnterior === false) {
                        throw new RuntimeException("El prospecto no existe o no tienes acceso a él.");
                    }
                    $datos[":id_prospecto"] = $idProspecto;
                    $conexion->prepare(
                        "UPDATE prospectos SET nombres = :nombres, apellidos = :apellidos, telefono = :telefono,
                         dni = :dni, fecha_nacimiento = :fecha_nacimiento, correo = :correo,
                         universidad = :universidad, carrera = :carrera, servicio = :servicio,
                         fuente = :fuente, id_campana = :id_campana, vendedor_id = :vendedor_id, estado = :estado,
                         observaciones = :observaciones, proxima_accion = :proxima_accion
                         WHERE id_prospecto = :id_prospecto"
                    )->execute($datos);

                    if ($estadoAnterior !== $datos[":estado"]) {
                        $conexion->prepare(
                            "INSERT INTO seguimientos_prospectos (id_prospecto, usuario_id, fecha, accion, observaciones, proxima_accion)
                             VALUES (:id, :usuario, CURDATE(), :accion, 'Cambio de estado registrado', :proxima)"
                        )->execute([
                            ":id" => $idProspecto,
                            ":usuario" => (int) $_SESSION["id_usuario"],
                            ":accion" => "Estado cambiado de {$estadoAnterior} a {$datos[":estado"]}",
                            ":proxima" => $datos[":proxima_accion"],
                        ]);
                    }
                    $mensaje = "Prospecto actualizado correctamente.";
                } else {
                    $datos[":creado_por"] = (int) $_SESSION["id_usuario"];
                    $conexion->beginTransaction();
                    $conexion->prepare(
                        "INSERT INTO prospectos (nombres, apellidos, telefono, dni, fecha_nacimiento, correo,
                         universidad, carrera, servicio, fuente, id_campana, vendedor_id, estado, observaciones, proxima_accion, creado_por)
                         VALUES (:nombres, :apellidos, :telefono, :dni, :fecha_nacimiento, :correo,
                         :universidad, :carrera, :servicio,
                         :fuente, :id_campana, :vendedor_id, :estado, :observaciones, :proxima_accion, :creado_por)"
                    )->execute($datos);
                    notificarNuevoRegistroComercial(
                        $conexion,
                        "prospecto",
                        (int) $conexion->lastInsertId(),
                        trim($datos[":nombres"] . " " . ($datos[":apellidos"] ?? "")),
                        $usuarioActual,
                        $datos[":vendedor_id"]
                    );
                    $conexion->commit();
                    $mensaje = "Prospecto registrado correctamente.";
                }
            } elseif ($accion === "convertir") {
                $idProspecto = (int) ($_POST["id_prospecto"] ?? 0);
                $conexion->beginTransaction();
                $stmtProspecto = $conexion->prepare(
                    "SELECT * FROM prospectos WHERE id_prospecto = :id" .
                    ($puedeVerTodosProspectos ? "" : " AND vendedor_id = :usuario") . " FOR UPDATE"
                );
                $parametrosProspecto = [":id" => $idProspecto];
                if (!$puedeVerTodosProspectos) {
                    $parametrosProspecto[":usuario"] = $usuarioActual;
                }
                $stmtProspecto->execute($parametrosProspecto);
                $prospecto = $stmtProspecto->fetch();
                if (!$prospecto) {
                    throw new RuntimeException("El prospecto no existe o no tienes acceso a él.");
                }
                if ($prospecto["estado"] !== "Ganado") {
                    throw new RuntimeException("Solo se pueden convertir prospectos con estado Ganado.");
                }
                if ((int) ($prospecto["id_cliente"] ?? 0) > 0) {
                    throw new RuntimeException("Este prospecto ya fue convertido a cliente.");
                }

                $conexion->prepare(
                    "INSERT INTO clientes (nombres, apellidos, tipo_documento, documento, telefono, correo, fecha_nacimiento, universidad, carrera, estado, observaciones)
                     VALUES (:nombres, :apellidos, :tipo_documento, :documento, :telefono, :correo, :fecha_nacimiento, :universidad, :carrera, 'Activo', :observaciones)"
                )->execute([
                    ":nombres" => $prospecto["nombres"],
                    ":apellidos" => $prospecto["apellidos"],
                    ":tipo_documento" => $prospecto["dni"] ? "DNI" : null,
                    ":documento" => $prospecto["dni"] ?: null,
                    ":telefono" => $prospecto["telefono"],
                    ":correo" => $prospecto["correo"],
                    ":fecha_nacimiento" => $prospecto["fecha_nacimiento"],
                    ":universidad" => $prospecto["universidad"],
                    ":carrera" => $prospecto["carrera"],
                    ":observaciones" => $prospecto["observaciones"],
                ]);
                $idCliente = (int) $conexion->lastInsertId();
                $idServicioCliente = null;
                if (trim((string) ($prospecto["servicio"] ?? "")) !== "") {
                    $conexion->prepare(
                        "INSERT INTO servicios_clientes (id_cliente, nombre_servicio, estado, observaciones)
                         VALUES (:cliente, :servicio, 'Activo', :observaciones)"
                    )->execute([
                        ":cliente" => $idCliente,
                        ":servicio" => function_exists("mb_substr")
                            ? mb_substr(trim($prospecto["servicio"]), 0, 180)
                            : substr(trim($prospecto["servicio"]), 0, 180),
                        ":observaciones" => "Servicio de interés registrado al convertir el prospecto.",
                    ]);
                    $idServicioCliente = (int) $conexion->lastInsertId();
                }
                $nombreProyecto = trim((string) ($prospecto["servicio"] ?? ""));
                if ($nombreProyecto === "") {
                    $nombreProyecto = "Proyecto académico · " . trim((string) ($prospecto["carrera"] ?? ""));
                }
                if ($nombreProyecto === "Proyecto académico ·") {
                    $nombreProyecto = "Acompañamiento académico · " . trim($prospecto["nombres"] . " " . ($prospecto["apellidos"] ?? ""));
                }
                $asesorAutomatico = $conexion->query(
                    "SELECT a.id_asesor
                     FROM asesores_academicos a
                     LEFT JOIN proyectos_asesores pa ON pa.id_asesor = a.id_asesor AND pa.fecha_fin IS NULL
                     LEFT JOIN proyectos_tesis t ON t.id_tesis = pa.id_tesis AND t.estado <> 'Finalizado'
                     WHERE a.estado = 'Activo'
                     GROUP BY a.id_asesor
                     ORDER BY COUNT(t.id_tesis), a.id_asesor
                     LIMIT 1"
                )->fetchColumn();
                $asesorAutomatico = $asesorAutomatico === false ? null : (int) $asesorAutomatico;
                $numeroExpediente = "ACA-" . date("YmdHis") . "-" . random_int(100, 999);
                $conexion->prepare(
                    "INSERT INTO expedientes (id_cliente, numero_expediente, estado, descripcion, observaciones)
                     VALUES (:cliente, :numero, 'Abierto', :descripcion, :observaciones)"
                )->execute([
                    ":cliente" => $idCliente,
                    ":numero" => $numeroExpediente,
                    ":descripcion" => $nombreProyecto,
                    ":observaciones" => "Expediente académico generado automáticamente al convertir el prospecto ganado.",
                ]);
                $idExpediente = (int) $conexion->lastInsertId();
                $conexion->prepare(
                    "INSERT INTO proyectos_tesis
                     (id_expediente, id_servicio_cliente, titulo, estado, fecha_inicio, observaciones)
                     VALUES (:expediente, :servicio, :titulo, :estado, CURDATE(), :observaciones)"
                )->execute([
                    ":expediente" => $idExpediente,
                    ":servicio" => $idServicioCliente,
                    ":titulo" => function_exists("mb_substr") ? mb_substr($nombreProyecto, 0, 255) : substr($nombreProyecto, 0, 255),
                    ":estado" => $asesorAutomatico !== null ? "En proceso" : "Pendiente",
                    ":observaciones" => $asesorAutomatico !== null
                        ? "Proyecto iniciado desde el CRM y asignado automáticamente al asesor con menor carga activa."
                        : "Proyecto iniciado automáticamente desde el CRM. Pendiente asignación de asesor y coordinación.",
                ]);
                $idProyecto = (int) $conexion->lastInsertId();
                if ($asesorAutomatico !== null) {
                    $conexion->prepare(
                        "INSERT INTO proyectos_asesores (id_tesis, id_asesor, fecha_asignacion)
                         VALUES (:tesis, :asesor, CURDATE())"
                    )->execute([":tesis" => $idProyecto, ":asesor" => $asesorAutomatico]);
                }
                inicializarCronogramaAcademico($conexion, $idProyecto, $asesorAutomatico !== null);
                $conexion->prepare("UPDATE prospectos SET id_cliente = :cliente WHERE id_prospecto = :id")
                    ->execute([":cliente" => $idCliente, ":id" => $idProspecto]);
                notificarNuevoRegistroComercial(
                    $conexion,
                    "cliente",
                    $idCliente,
                    trim($prospecto["nombres"] . " " . ($prospecto["apellidos"] ?? "")),
                    $usuarioActual
                );
                $conexion->commit();
                $mensaje = $asesorAutomatico !== null
                    ? "Prospecto convertido. Se generó el expediente y el proyecto académico; se asignó al asesor con menor carga y se creó su cronograma."
                    : "Prospecto convertido. Se generó el expediente y proyecto académico con cronograma; queda pendiente la asignación de asesor.";
            } elseif ($accion === "seguimiento") {
                $idProspecto = (int) ($_POST["id_prospecto"] ?? 0);
                $actividad = trim($_POST["accion_seguimiento"] ?? "");
                $proximaAccionSeg = trim($_POST["proxima_accion"] ?? "") ?: null;
                $notasSeg = trim($_POST["observaciones_seguimiento"] ?? "") ?: null;

                if ($idProspecto <= 0 || $actividad === "") {
                    throw new RuntimeException("Describe la actividad realizada para guardar el seguimiento.");
                }

                if ($proximaAccionSeg !== null && $proximaAccionSeg < date("Y-m-d")) {
                    throw new RuntimeException("La próxima acción no puede ser anterior a la actual (" . date("d/m/Y") . ").");
                }

                $stmtAcceso = $conexion->prepare(
                    "SELECT 1 FROM prospectos WHERE id_prospecto = :id" .
                    ($puedeVerTodosProspectos ? "" : " AND vendedor_id = :usuario")
                );
                $parametrosAcceso = [":id" => $idProspecto];
                if (!$puedeVerTodosProspectos) {
                    $parametrosAcceso[":usuario"] = $usuarioActual;
                }
                $stmtAcceso->execute($parametrosAcceso);
                if (!$stmtAcceso->fetchColumn()) {
                    throw new RuntimeException("No tienes permiso para registrar seguimiento en este prospecto.");
                }

                $conexion->prepare(
                    "INSERT INTO seguimientos_prospectos (id_prospecto, usuario_id, fecha, accion, observaciones, proxima_accion)
                     VALUES (:id, :usuario, CURDATE(), :accion, :obs, :proxima)"
                )->execute([
                    ":id" => $idProspecto,
                    ":usuario" => (int) $_SESSION["id_usuario"],
                    ":accion" => $actividad,
                    ":obs" => $notasSeg,
                    ":proxima" => $proximaAccionSeg,
                ]);

                if ($proximaAccionSeg !== null) {
                    $conexion->prepare("UPDATE prospectos SET proxima_accion = :proxima WHERE id_prospecto = :id")
                        ->execute([":proxima" => $proximaAccionSeg, ":id" => $idProspecto]);
                }

                $mensaje = "Seguimiento guardado correctamente.";
            } elseif ($accion === "eliminar") {
                $idProspecto = (int) ($_POST["id_prospecto"] ?? 0);
                if ($idProspecto > 0) {
                    $stmt = $conexion->prepare("DELETE FROM prospectos WHERE id_prospecto = :id" .
                        ($puedeVerTodosProspectos ? "" : " AND vendedor_id = :usuario"));
                    $paramsDel = [":id" => $idProspecto];
                    if (!$puedeVerTodosProspectos) {
                        $paramsDel[":usuario"] = $usuarioActual;
                    }
                    $stmt->execute($paramsDel);
                    $mensaje = "Prospecto eliminado correctamente.";
                }
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare(
                "SELECT * FROM prospectos WHERE id_prospecto = :id" .
                ($puedeVerTodosProspectos ? "" : " AND vendedor_id = :usuario")
            );
            $parametrosConsulta = [":id" => (int) $_GET["editar"]];
            if (!$puedeVerTodosProspectos) {
                $parametrosConsulta[":usuario"] = $usuarioActual;
            }
            $stmt->execute($parametrosConsulta);
            $prospectoEditar = $stmt->fetch() ?: null;
        }

        if (isset($_GET["seguimiento"])) {
            $stmt = $conexion->prepare(
                "SELECT * FROM prospectos WHERE id_prospecto = :id" .
                ($puedeVerTodosProspectos ? "" : " AND vendedor_id = :usuario")
            );
            $parametrosConsulta = [":id" => (int) $_GET["seguimiento"]];
            if (!$puedeVerTodosProspectos) {
                $parametrosConsulta[":usuario"] = $usuarioActual;
            }
            $stmt->execute($parametrosConsulta);
            $prospectoSeguimiento = $stmt->fetch() ?: null;
        }

        $busqueda = trim($_GET["buscar"] ?? "");
        $filtroEstado = $_GET["estado"] ?? "";
        $filtroVendedor = $puedeVerTodosProspectos ? (int) ($_GET["vendedor"] ?? 0) : $usuarioActual;
        $condiciones = $puedeVerTodosProspectos ? [] : ["p.vendedor_id = :usuario"];
        $parametros = $puedeVerTodosProspectos ? [] : [":usuario" => $usuarioActual];
        if ($busqueda !== "") {
            $condiciones[] = "CONCAT_WS(' ', p.nombres, p.apellidos, p.telefono, p.dni, p.correo,
                p.universidad, p.carrera, p.servicio, p.fuente) LIKE :buscar";
            $parametros[":buscar"] = "%{$busqueda}%";
        }
        if (in_array($filtroEstado, $estados, true)) {
            $condiciones[] = "p.estado = :estado";
            $parametros[":estado"] = $filtroEstado;
        }
        if ($filtroVendedor > 0) {
            $condiciones[] = "p.vendedor_id = :vendedor";
            $parametros[":vendedor"] = $filtroVendedor;
        }
        $where = $condiciones ? "WHERE " . implode(" AND ", $condiciones) : "";
        $stmt = $conexion->prepare(
            "SELECT p.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS vendedor, c.id_cliente AS cliente_convertido
             FROM prospectos p LEFT JOIN usuarios u ON u.id_usuario = p.vendedor_id
             LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
             {$where} ORDER BY p.proxima_accion IS NULL, p.proxima_accion, p.id_prospecto DESC"
        );
        $stmt->execute($parametros);
        $prospectos = $stmt->fetchAll();

        $sqlResumenEstados = "SELECT estado, COUNT(*) AS total FROM prospectos" .
            ($puedeVerTodosProspectos ? "" : " WHERE vendedor_id = :usuario") . " GROUP BY estado";
        $stmtResumenEstados = $conexion->prepare($sqlResumenEstados);
        $stmtResumenEstados->execute($puedeVerTodosProspectos ? [] : [":usuario" => $usuarioActual]);
        $resumenEstados = $stmtResumenEstados->fetchAll();

        $sqlIndicadores = "SELECT
            COUNT(*) AS total,
            SUM(estado = 'Nuevo') AS nuevos,
            SUM(estado = 'Contactado') AS contactados,
            SUM(estado = 'Interesado') AS interesados,
            SUM(estado = 'Cotizado') AS cotizados,
            SUM(estado IN ('Negociación', 'En Negocio')) AS en_negocio,
            SUM(estado = 'Ganado') AS cerrados
            FROM prospectos" . ($puedeVerTodosProspectos ? "" : " WHERE vendedor_id = :usuario");
        $stmtIndicadores = $conexion->prepare($sqlIndicadores);
        $stmtIndicadores->execute($puedeVerTodosProspectos ? [] : [":usuario" => $usuarioActual]);
        $resumenIndicadores = $stmtIndicadores->fetch() ?: [];

        $rolesResumenVendedor = $puedeVerTodosProspectos ? "r.codigo IN ('ventas', 'vendedor')" : "u.id_usuario = :usuario";
        $stmtResumenVendedores = $conexion->prepare(
            "SELECT u.id_usuario, CONCAT_WS(' ', u.nombres, u.apellidos) AS vendedor,
             COUNT(p.id_prospecto) AS total,
             SUM(p.estado = 'Interesado') AS interesados,
             SUM(p.estado = 'Cotizado') AS cotizados,
             SUM(p.estado = 'Ganado') AS ganados,
             SUM(p.estado NOT IN ('Ganado', 'Perdido') AND (p.proxima_accion IS NULL OR p.proxima_accion <= CURDATE())) AS pendientes
             FROM usuarios u
             INNER JOIN roles r ON r.id_rol = u.id_rol
             LEFT JOIN prospectos p ON p.vendedor_id = u.id_usuario
             WHERE u.estado = 1 AND {$rolesResumenVendedor}
             GROUP BY u.id_usuario, u.nombres, u.apellidos ORDER BY total DESC, vendedor"
        );
        $stmtResumenVendedores->execute($puedeVerTodosProspectos ? [] : [":usuario" => $usuarioActual]);
        $resumenVendedores = $stmtResumenVendedores->fetchAll();

        if ($prospectoSeguimiento) {
            $stmt = $conexion->prepare(
                "SELECT s.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario
                 FROM seguimientos_prospectos s LEFT JOIN usuarios u ON u.id_usuario = s.usuario_id
                 WHERE s.id_prospecto = :id ORDER BY s.fecha DESC, s.id_seguimiento DESC"
            );
            $stmt->execute([":id" => (int) $prospectoSeguimiento["id_prospecto"]]);
            $historial = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : "Error en el servidor: " . $e->getMessage();
    }
}

$valor = static function (string $campo) use ($prospectoEditar): string {
    return htmlspecialchars((string) ($prospectoEditar[$campo] ?? ""), ENT_QUOTES, "UTF-8");
};

$etapasCrm = [
    ["nombre" => "Nuevos", "campo" => "nuevos", "color" => "#3b82f6"],
    ["nombre" => "Contactados", "campo" => "contactados", "color" => "#06b6d4"],
    ["nombre" => "Interesados", "campo" => "interesados", "color" => "#eab308"],
    ["nombre" => "Cotizados", "campo" => "cotizados", "color" => "#d97706"],
    ["nombre" => "En negociación", "campo" => "en_negocio", "color" => "#6366f1"],
    ["nombre" => "Cerrados ganados", "campo" => "cerrados", "color" => "#16a34a"],
];
$maxEtapaCrm = max(1, ...array_map(
    static fn (array $etapa): int => (int) ($resumenIndicadores[$etapa["campo"]] ?? 0),
    $etapasCrm
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prospectos | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
    <style>
        .prospecto-publico-link { display: inline-flex; margin-top: 12px; }
        .crm-etapas { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 16px; }
        .crm-etapa { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; }
        .crm-etapa-cabecera { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .crm-etapa-cabecera strong { font-size: 1.15rem; }
        .crm-etapa-barra { background: #e2e8f0; border-radius: 999px; height: 7px; overflow: hidden; }
        .crm-etapa-barra span { display: block; height: 100%; border-radius: inherit; }
        .prospecto-sin-seguimiento td { background: #fff1f2; }
        .prospecto-cerrado td { background: #f0fdf4; }
        .estado-interesado { background: #fef3c7; color: #92400e; }
        .estado-cerrado { background: #dcfce7; color: #166534; }
        .seguimiento-vencido { color: #b91c1c; font-weight: 700; }
    </style>
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner">
            <p class="eyebrow">Gestión comercial y académica</p>
            <h1>Prospectos y Solicitudes</h1>
            <p>Registro, asignación y seguimiento de interesados. <?= $puedeVerTodosProspectos ? "Acceso completo a todos los registros del equipo." : "Solo consultas los prospectos asignados a tu cuenta." ?></p>
            <a class="btn-secondary btn-sm prospecto-publico-link" href="../solicitud_prospecto.php" target="_blank" rel="noopener">Abrir formulario externo para compartir</a>
        </div>

        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>

        <!-- Embudo de prospectos y Resumen de vendedor arriba -->
<section class="module-board">
            <div class="panel">
                <h3>Embudo de prospectos · <?= (int) ($resumenIndicadores["total"] ?? 0) ?> en total</h3>
                <div class="crm-etapas">
                    <?php foreach ($etapasCrm as $etapa): ?>
                        <?php
                        $totalEtapa = (int) ($resumenIndicadores[$etapa["campo"]] ?? 0);
                        $anchoBarra = $totalEtapa > 0 ? max(4, (int) round($totalEtapa / $maxEtapaCrm * 100)) : 0;
                        ?>
                        <div class="crm-etapa">
                            <div class="crm-etapa-cabecera">
                                <span><?= $escapar($etapa["nombre"]) ?></span>
                                <strong><?= $totalEtapa ?></strong>
                            </div>
                            <div class="crm-etapa-barra" role="img" aria-label="<?= $escapar($etapa["nombre"]) ?>: <?= $totalEtapa ?>">
                                <span style="width:<?= $anchoBarra ?>%; background:<?= $etapa["color"] ?>;"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <details style="margin-top:16px;">
                    <summary>Ver todos los estados</summary>
                    <div class="quick-list">
                        <?php foreach ($resumenEstados as $fila): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span><?= $escapar($fila["estado"]) ?></span>
                                <strong><?= (int) $fila["total"] ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>

            <div class="panel">
                <h3>Resumen por vendedor / asesor</h3>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Asesor</th>
                                <th>Total</th>
                                <th>Interesados</th>
                                <th>Cotizados</th>
                                <th>Ganados</th>
                                <th>Pendientes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($resumenVendedores as $fila): ?>
                            <tr>
                                <td><?= $escapar($fila["vendedor"]) ?></td>
                                <td><strong><?= (int) $fila["total"] ?></strong></td>
                                <td><?= (int) $fila["interesados"] ?></td>
                                <td><?= (int) $fila["cotizados"] ?></td>
                                <td><?= (int) $fila["ganados"] ?></td>
                                <td><span style="color:#e11d48; font-weight:700;"><?= (int) $fila["pendientes"] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$resumenVendedores): ?>
                            <tr><td colspan="6" class="empty-state">No hay asesores comerciales activos.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>


        <?php if ($prospectoSeguimiento): ?>
        <section class="crud-layout">
            <div class="panel">
                <div class="panel-heading">
                    <h3>Registrar seguimiento: <?= $escapar(trim($prospectoSeguimiento["nombres"] . " " . ($prospectoSeguimiento["apellidos"] ?? ""))) ?></h3>
                    <a class="text-link" href="prospectos.php">Cerrar</a>
                </div>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="seguimiento">
                    <input type="hidden" name="id_prospecto" value="<?= (int) $prospectoSeguimiento["id_prospecto"] ?>">
                    <div class="form-grid">
                        <div class="campo">
                            <label for="fecha">Fecha</label>
                            <input type="date" id="fecha" name="fecha" value="<?= date("Y-m-d") ?>" required>
                        </div>
                        <div class="campo">
                            <label for="accion_seguimiento">Actividad *</label>
                            <input id="accion_seguimiento" name="accion_seguimiento" required placeholder="Llamada, revisión de borrador, reunión...">
                        </div>
                        <div class="campo">
                            <label for="proxima_accion_seg">Próxima acción</label>
                            <input type="date" id="proxima_accion_seg" name="proxima_accion" min="<?= date("Y-m-d") ?>">
                        </div>
                    </div>
                    <div class="campo">
                        <label for="observaciones_seguimiento">Notas de seguimiento</label>
                        <textarea id="observaciones_seguimiento" name="observaciones_seguimiento" rows="3" placeholder="Detalles de lo conversado o acordado..."></textarea>
                    </div>
                    <button class="btn-primary form-submit" type="submit">Guardar seguimiento</button>
                </form>
            </div>
            <div class="panel">
                <h3>Historial de Seguimientos</h3>
                <div class="quick-list">
                    <?php foreach ($historial ?? [] as $registro): ?>
                        <div style="border-left: 3px solid #2563eb; padding-left: 10px; margin-bottom: 10px;">
                            <strong><?= $escapar($registro["fecha"]) ?> · <?= $escapar($registro["accion"]) ?></strong>
                            <p style="margin: 4px 0;"><?= $escapar($registro["observaciones"] ?: "Sin notas adicionales") ?></p>
                            <small><?= $escapar($registro["usuario"] ?: "Usuario") ?><?php if ($registro["proxima_accion"]): ?> · Próxima: <?= $escapar($registro["proxima_accion"]) ?><?php endif; ?></small>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($historial)): ?>
                        <p class="empty-state">Aún no hay seguimientos registrados.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="crud-layout">
            <!-- Formulario de Nuevo / Editar Prospecto -->
            <details class="collapsible-card" <?= $prospectoEditar ? "open" : "" ?>>
                <summary>
                    <span class="summary-left">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                        <span><?= $prospectoEditar ? "Editar Prospecto" : "Registrar Nuevo Prospecto" ?></span>
                    </span>
                    <span class="summary-badge">Desplegable</span>
                    <svg class="summary-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </summary>
                <div class="collapsible-body">
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id_prospecto" value="<?= (int) ($prospectoEditar["id_prospecto"] ?? 0) ?>">
                    
                    <div class="form-grid">
                        <!-- Restricción Nombres: Solo letras y espacios -->
                        <div class="campo">
                            <label for="nombres">Nombres *</label>
                            <input id="nombres" name="nombres" pattern="[\p{L}]+(?:[ '’.-][\p{L}]+)*" maxlength="100" title="Usa letras, espacios, apóstrofes, puntos o guiones." required value="<?= $valor("nombres") ?>" placeholder="Ej.: Juan Carlos">
                        </div>

                        <!-- Restricción Apellidos: Solo letras y espacios -->
                        <div class="campo">
                            <label for="apellidos">Apellidos</label>
                            <input id="apellidos" name="apellidos" pattern="[\p{L}]+(?:[ '’.-][\p{L}]+)*" maxlength="100" title="Usa letras, espacios, apóstrofes, puntos o guiones." value="<?= $valor("apellidos") ?>" placeholder="Ej.: Pérez Gómez">
                        </div>

                        <!-- Restricción Celular: 9 dígitos, empieza con 9, formateo visual cada 3 dígitos -->
                        <div class="campo">
                            <label for="telefono">Celular (9 dígitos)</label>
                            <input id="telefono" name="telefono" type="tel" inputmode="numeric" maxlength="11" placeholder="9XX XXX XXX" value="<?= $valor("telefono") ?>" title="Debe tener 9 dígitos y comenzar con 9.">
                        </div>

                        <div class="campo">
                            <label for="correo">Correo electrónico</label>
                            <input type="email" id="correo" name="correo" value="<?= $valor("correo") ?>" placeholder="ejemplo@correo.com">
                        </div>

                        <div class="campo">
                            <label for="dni">DNI peruano</label>
                            <input id="dni" name="dni" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" value="<?= $valor("dni") ?>" placeholder="8 dígitos">
                        </div>

                        <div class="campo">
                            <label for="fecha_nacimiento">Fecha de nacimiento</label>
                            <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" max="<?= date("Y-m-d") ?>" value="<?= $valor("fecha_nacimiento") ?>">
                        </div>

                        <div class="campo">
                            <label for="universidad">Universidad / Institución</label>
                            <input id="universidad" name="universidad" value="<?= $valor("universidad") ?>" placeholder="Ej.: UNMSM, PUCP, UCV...">
                        </div>

                        <div class="campo">
                            <label for="carrera">Carrera / Especialidad</label>
                            <input id="carrera" name="carrera" value="<?= $valor("carrera") ?>" placeholder="Ej.: Derecho, Ing. Sistemas...">
                        </div>

                        <div class="campo">
                            <label for="servicio">Servicio de interés</label>
                            <input id="servicio" name="servicio" value="<?= $valor("servicio") ?>" placeholder="Ej.: Tesis de Licenciatura, Asesoría...">
                        </div>

                        <!-- FUENTE: Combobox interactivo que permite elegir o escribir una nueva caja de texto -->
                        <div class="campo">
                            <label for="fuente">Fuente de captación / procedencia</label>
                            <div class="combobox-wrap">
                                <input id="fuente" name="fuente" list="lista-fuentes-prospectos" placeholder="Selecciona o escribe..." value="<?= $valor("fuente") ?>" autocomplete="off">
                                <datalist id="lista-fuentes-prospectos">
                                    <?php foreach ($fuentesCatalogo as $fuenteItem): ?>
                                        <option value="<?= $escapar($fuenteItem["nombre"]) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <span class="combobox-hint">Puedes elegir de la lista o escribir una fuente nueva.</span>
                        </div>
                        <div class="campo">
                            <label for="id_campana">Campaña asociada</label>
                            <select id="id_campana" name="id_campana">
                                <option value="">Sin campaña</option>
                                <?php foreach ($campanasActivas as $campana): ?>
                                    <option value="<?= (int) $campana["id_campana"] ?>" <?= (int) ($prospectoEditar["id_campana"] ?? 0) === (int) $campana["id_campana"] ? "selected" : "" ?>>
                                        <?= $escapar($campana["nombre"] . " · " . $campana["canal"]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="combobox-hint">La campaña asignada medirá leads, costo por lead y clientes generados.</span>
                        </div>

                        <?php if ($puedeVerTodosProspectos): ?>
                        <div class="campo">
                            <label for="vendedor_id">Asignado a (Asesor / Vendedor)</label>
                            <select id="vendedor_id" name="vendedor_id">
                                <option value="">Sin asignar</option>
                                <?php foreach ($vendedores as $vendedor): ?>
                                    <option value="<?= (int) $vendedor["id_usuario"] ?>" <?= (int) ($prospectoEditar["vendedor_id"] ?? 0) === (int) $vendedor["id_usuario"] ? "selected" : "" ?>>
                                        <?= $escapar(trim($vendedor["nombres"] . " " . ($vendedor["apellidos"] ?? ""))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="vendedor_id" value="<?= $usuarioActual ?>">
                        <?php endif; ?>

                        <!-- Estado cargado dinámicamente desde el catálogo -->
                        <div class="campo">
                            <label for="estado">Estado</label>
                            <select id="estado" name="estado">
                                <?php foreach ($estados as $est): ?>
                                    <option value="<?= $escapar($est) ?>" <?= ($prospectoEditar["estado"] ?? "Nuevo") === $est ? "selected" : "" ?>>
                                        <?= $escapar($est) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Restricción Calendario: min fecha actual para evitar fechas anteriores -->
                        <div class="campo">
                            <label for="proxima_accion">Próxima acción (Fecha)</label>
                            <input type="date" id="proxima_accion" name="proxima_accion" min="<?= date("Y-m-d") ?>" value="<?= $valor("proxima_accion") ?>">
                        </div>
                    </div>

                    <div class="campo">
                        <label for="observaciones">Observaciones / Notas</label>
                        <textarea id="observaciones" name="observaciones" rows="3" placeholder="Detalles de contacto, temas de investigación, etc."><?= $valor("observaciones") ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary form-submit">
                        <?= $prospectoEditar ? "Actualizar Prospecto" : "Guardar Prospecto" ?>
                    </button>
                </form>
                </div>
            </details>

            <!-- Tabla de Prospectos Registrados -->
            <div class="panel">
                <div class="panel-heading" style="flex-wrap:wrap; gap:12px;">
                    <h3>Listado de Prospectos (<?= count($prospectos) ?>)</h3>
                </div>
                <details class="collapsible-card" style="margin-bottom:16px;" <?= (!empty($_GET["buscar"]) || !empty($_GET["estado"]) || !empty($_GET["vendedor"])) ? "open" : "" ?>>
                    <summary>
                        <span class="summary-left">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <span>Consultas y Búsqueda Avanzada de Prospectos</span>
                        </span>
                        <span class="summary-badge">Filtros</span>
                        <svg class="summary-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </summary>
                    <div class="collapsible-body">
                        <form method="get" class="search-form" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <input name="buscar" placeholder="Nombre, universidad, fuente..." value="<?= $escapar($_GET["buscar"] ?? "") ?>">
                        <select name="estado">
                            <option value="">Todos los estados</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?= $escapar($estado) ?>" <?= ($_GET["estado"] ?? "") === $estado ? "selected" : "" ?>><?= $escapar($estado) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($puedeVerTodosProspectos): ?>
                        <select name="vendedor">
                            <option value="0">Todos los vendedores</option>
                            <?php foreach ($vendedores as $vendedor): ?>
                                <option value="<?= (int) $vendedor["id_usuario"] ?>" <?= $filtroVendedor === (int) $vendedor["id_usuario"] ? "selected" : "" ?>>
                                    <?= $escapar(trim($vendedor["nombres"] . " " . ($vendedor["apellidos"] ?? ""))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        <button type="submit" class="btn-secondary btn-sm">Filtrar</button>
                    </form>
                    </div>
                </details>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Prospecto</th>
                                <th>Universidad / Carrera</th>
                                <th>Fuente</th>
                                <th>Estado</th>
                                <th>Próxima acción</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($prospectos as $prospecto): ?>
                            <?php
                            $esCerrado = $prospecto["estado"] === "Ganado";
                            $sinSeguimiento = !$esCerrado
                                && $prospecto["estado"] !== "Perdido"
                                && (empty($prospecto["proxima_accion"]) || $prospecto["proxima_accion"] < date("Y-m-d"));
                            $claseFila = $esCerrado ? "prospecto-cerrado" : ($sinSeguimiento ? "prospecto-sin-seguimiento" : "");
                            $claseEstado = $esCerrado
                                ? "estado-cerrado"
                                : ($prospecto["estado"] === "Interesado" ? "estado-interesado" : "");
                            ?>
                            <tr class="<?= $claseFila ?>">
                                <td>
                                    <strong><?= $escapar(trim($prospecto["nombres"] . " " . ($prospecto["apellidos"] ?? ""))) ?></strong>
                                    <small><?= $escapar($prospecto["telefono"]) ?> <?= $prospecto["correo"] ? "· " . $escapar($prospecto["correo"]) : "" ?></small>
                                    <small>DNI: <?= $escapar($prospecto["dni"] ?: "No indicado") ?> · Nacimiento: <?= $prospecto["fecha_nacimiento"] ? $escapar(date("d/m/Y", strtotime($prospecto["fecha_nacimiento"]))) : "No indicada" ?></small>
                                    <small>Contacto: <?= $escapar(date("d/m/Y", strtotime($prospecto["fecha_registro"]))) ?></small>
                                </td>
                                <td>
                                    <?= $escapar($prospecto["universidad"] ?: "-") ?>
                                    <small><?= $escapar($prospecto["carrera"] ?: "") ?><?= $prospecto["servicio"] ? " · " . $escapar($prospecto["servicio"]) : "" ?></small>
                                </td>
                                <td>
                                    <span style="font-size:0.8rem; font-weight:600; color:#3b82f6; background:#eff6ff; padding:2px 7px; border-radius:5px;">
                                        <?= $escapar($prospecto["fuente"] ?: "No indicada") ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $claseEstado ?>"><?= $escapar($prospecto["estado"]) ?></span>
                                </td>
                                <td>
                                    <?php if ($esCerrado || $prospecto["estado"] === "Perdido"): ?>
                                        No aplica
                                    <?php elseif (empty($prospecto["proxima_accion"])): ?>
                                        <span class="seguimiento-vencido">Sin programar</span>
                                    <?php elseif ($prospecto["proxima_accion"] < date("Y-m-d")): ?>
                                        <span class="seguimiento-vencido">Vencido: <?= $escapar(date("d/m/Y", strtotime($prospecto["proxima_accion"]))) ?></span>
                                    <?php else: ?>
                                        <?= $escapar(date("d/m/Y", strtotime($prospecto["proxima_accion"]))) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a class="btn-action btn-action-primary" href="prospectos.php?editar=<?= (int) $prospecto["id_prospecto"] ?>" title="Editar">Editar</a>
                                    <a class="btn-action btn-action-primary" href="prospectos.php?seguimiento=<?= (int) $prospecto["id_prospecto"] ?>" title="Seguimiento">Seguimiento</a>
                                    <?php if ($esCerrado && empty($prospecto["cliente_convertido"])): ?>
                                    <form method="post" onsubmit="return confirm('¿Convertir este prospecto ganado en cliente?');" style="display:inline;">
                                        <input type="hidden" name="accion" value="convertir">
                                        <input type="hidden" name="id_prospecto" value="<?= (int) $prospecto["id_prospecto"] ?>">
                                        <button class="btn-action btn-action-primary" type="submit">Convertir en cliente</button>
                                    </form>
                                    <?php elseif (!empty($prospecto["cliente_convertido"])): ?>
                                    <a class="btn-action btn-action-primary" href="clientes.php?editar=<?= (int) $prospecto["cliente_convertido"] ?>">Ver cliente</a>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('¿Eliminar este prospecto y su historial?');" style="display:inline;">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id_prospecto" value="<?= (int) $prospecto["id_prospecto"] ?>">
                                        <button class="btn-action btn-action-danger" type="submit" title="Eliminar">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$prospectos): ?>
                            <tr><td colspan="6" class="empty-state">No hay prospectos que coincidan con la consulta.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        
    </main>
</div>
</body>
</html>
