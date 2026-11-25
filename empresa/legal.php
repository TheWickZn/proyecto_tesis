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
if (!in_array($_rolActual, ["administrador", "legal", "abogado"])) {
    http_response_code(403);
    exit("No tienes permisos para acceder al Area Legal.");
}

$mensaje = "";
$error = "";
$clientes = [];
$usuarios = [];
$casos = [];
$casoEditar = null;
$casoDocumentos = null;
$historial = [];
$documentos = [];
$archivoGuardado = null;
$estados = ["Pendiente", "En proceso", "En revisión", "Finalizado", "Archivado"];
$mimeExtensiones = ["application/pdf" => "pdf", "image/jpeg" => "jpg", "image/png" => "png"];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");
$registrarHistorial = static function (PDO $conexion, int $idCaso, int $idUsuario, string $accion, ?array $detalle = null): void {
    $conexion->prepare(
        "INSERT INTO historial_casos_legales (id_caso, usuario_id, accion, detalle)
         VALUES (:caso, :usuario, :accion, :detalle)"
    )->execute([
        ":caso" => $idCaso,
        ":usuario" => $idUsuario,
        ":accion" => $accion,
        ":detalle" => $detalle === null ? null : json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
};

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar") {
                $idCaso = (int) ($_POST["id_caso"] ?? 0);
                $idCliente = (int) ($_POST["id_cliente"] ?? 0);
                $numeroExpediente = trim($_POST["numero_expediente"] ?? "");
                $tipoProceso = trim($_POST["tipo_proceso"] ?? "");
                $responsableId = (int) ($_POST["responsable_id"] ?? 0) ?: null;
                $fechaInicio = trim($_POST["fecha_inicio"] ?? "");
                $estado = trim($_POST["estado"] ?? "Pendiente");
                $descripcion = trim($_POST["descripcion"] ?? "") ?: null;
                $observaciones = trim($_POST["observaciones"] ?? "") ?: null;
                $fecha = DateTime::createFromFormat("!Y-m-d", $fechaInicio);
                if ($idCliente <= 0 || $tipoProceso === "" || !$fecha || $fecha->format("Y-m-d") !== $fechaInicio ||
                    !in_array($estado, $estados, true)) {
                    throw new RuntimeException("Completa cliente, tipo de proceso y fecha válida; selecciona un estado permitido.");
                }

                $stmtCliente = $conexion->prepare("SELECT 1 FROM clientes WHERE id_cliente = :id AND estado = 'Activo'");
                $stmtCliente->execute([":id" => $idCliente]);
                if (!$stmtCliente->fetchColumn()) {
                    throw new RuntimeException("El cliente seleccionado no existe o está inactivo.");
                }
                $idExpediente = null;
                if ($numeroExpediente !== "") {
                    $stmtExpediente = $conexion->prepare(
                        "SELECT id_expediente FROM expedientes WHERE numero_expediente = :numero AND id_cliente = :cliente"
                    );
                    $stmtExpediente->execute([":numero" => $numeroExpediente, ":cliente" => $idCliente]);
                    $idExpediente = $stmtExpediente->fetchColumn();
                    if ($idExpediente === false) {
                        throw new RuntimeException("El expediente debe existir y pertenecer al cliente seleccionado.");
                    }
                }
                if ($responsableId !== null) {
                    $stmtUsuario = $conexion->prepare("SELECT 1 FROM usuarios WHERE id_usuario = :id AND estado = 1");
                    $stmtUsuario->execute([":id" => $responsableId]);
                    if (!$stmtUsuario->fetchColumn()) {
                        throw new RuntimeException("El responsable seleccionado no existe o está inactivo.");
                    }
                }

                $conexion->beginTransaction();
                $datos = [
                    ":id_cliente" => $idCliente,
                    ":id_expediente" => $idExpediente,
                    ":tipo_proceso" => $tipoProceso,
                    ":responsable_id" => $responsableId,
                    ":fecha_inicio" => $fechaInicio,
                    ":estado" => $estado,
                    ":descripcion" => $descripcion,
                    ":observaciones" => $observaciones,
                ];
                if ($idCaso > 0) {
                    $stmtAnterior = $conexion->prepare("SELECT * FROM casos_legales WHERE id_caso = :id FOR UPDATE");
                    $stmtAnterior->execute([":id" => $idCaso]);
                    $anterior = $stmtAnterior->fetch();
                    if (!$anterior) {
                        throw new RuntimeException("El caso legal seleccionado no existe.");
                    }
                    $datos[":id_caso"] = $idCaso;
                    $conexion->prepare(
                        "UPDATE casos_legales SET id_cliente = :id_cliente, id_expediente = :id_expediente,
                         tipo_proceso = :tipo_proceso, responsable_id = :responsable_id, fecha_inicio = :fecha_inicio,
                         estado = :estado, descripcion = :descripcion, observaciones = :observaciones
                         WHERE id_caso = :id_caso"
                    )->execute($datos);
                    $idCasoGuardado = $idCaso;
                    $evento = "actualizado";
                } else {
                    $conexion->prepare(
                        "INSERT INTO casos_legales
                         (id_cliente, id_expediente, tipo_proceso, responsable_id, fecha_inicio, estado, descripcion, observaciones)
                         VALUES (:id_cliente, :id_expediente, :tipo_proceso, :responsable_id, :fecha_inicio, :estado, :descripcion, :observaciones)"
                    )->execute($datos);
                    $idCasoGuardado = (int) $conexion->lastInsertId();
                    $anterior = null;
                    $evento = "creado";
                }
                $registrarHistorial($conexion, $idCasoGuardado, (int) $_SESSION["id_usuario"], $evento, [
                    "estado_anterior" => $anterior["estado"] ?? null,
                    "estado_nuevo" => $estado,
                    "tipo_proceso" => $tipoProceso,
                    "id_cliente" => $idCliente,
                    "id_expediente" => $idExpediente,
                ]);
                $conexion->commit();
                $mensaje = $idCaso > 0 ? "Caso legal actualizado correctamente." : "Caso legal registrado correctamente.";
            } elseif ($accion === "archivar") {
                $idCaso = (int) ($_POST["id_caso"] ?? 0);
                if ($idCaso <= 0) {
                    throw new RuntimeException("Selecciona un caso válido.");
                }
                $conexion->beginTransaction();
                $stmtCaso = $conexion->prepare("SELECT estado FROM casos_legales WHERE id_caso = :id FOR UPDATE");
                $stmtCaso->execute([":id" => $idCaso]);
                $estadoAnterior = $stmtCaso->fetchColumn();
                if ($estadoAnterior === false) {
                    throw new RuntimeException("El caso seleccionado no existe.");
                }
                $conexion->prepare("UPDATE casos_legales SET estado = 'Archivado' WHERE id_caso = :id")
                    ->execute([":id" => $idCaso]);
                $registrarHistorial($conexion, $idCaso, (int) $_SESSION["id_usuario"], "archivado", [
                    "estado_anterior" => $estadoAnterior,
                    "estado_nuevo" => "Archivado",
                ]);
                $conexion->commit();
                $mensaje = "Caso archivado. Su historial y documentos se conservaron.";
            } elseif ($accion === "subir_documento") {
                $idCaso = (int) ($_POST["id_caso"] ?? 0);
                $archivo = $_FILES["archivo"] ?? null;
                if ($idCaso <= 0 || !$archivo || !isset($archivo["error"], $archivo["tmp_name"])) {
                    throw new RuntimeException("Selecciona un caso y un archivo.");
                }
                if ((int) $archivo["error"] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException("No se pudo recibir el archivo. Verifica su tamaño y vuelve a intentarlo.");
                }
                if ((int) $archivo["size"] <= 0 || (int) $archivo["size"] > 8 * 1024 * 1024) {
                    throw new RuntimeException("El documento debe pesar como máximo 8 MB.");
                }
                $stmtCaso = $conexion->prepare("SELECT estado FROM casos_legales WHERE id_caso = :id");
                $stmtCaso->execute([":id" => $idCaso]);
                $estadoCaso = $stmtCaso->fetchColumn();
                if ($estadoCaso === false || $estadoCaso === "Archivado") {
                    throw new RuntimeException("El caso no existe o está archivado.");
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($archivo["tmp_name"]);
                if (!isset($mimeExtensiones[$mime])) {
                    throw new RuntimeException("Formato no permitido. Usa PDF, JPG o PNG.");
                }
                $nombreOriginal = basename(str_replace("\\", "/", (string) $archivo["name"]));
                if ($nombreOriginal === "" || strlen($nombreOriginal) > 255) {
                    throw new RuntimeException("El nombre original del archivo no es válido.");
                }
                $nombreAlmacenado = bin2hex(random_bytes(32));
                $directorio = __DIR__ . "/../storage/legal";
                if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) {
                    throw new RuntimeException("No se pudo preparar el almacenamiento privado de documentos legales.");
                }
                $archivoGuardado = $directorio . DIRECTORY_SEPARATOR . $nombreAlmacenado;
                if (!move_uploaded_file($archivo["tmp_name"], $archivoGuardado)) {
                    $archivoGuardado = null;
                    throw new RuntimeException("No se pudo almacenar el documento legal.");
                }
                $conexion->beginTransaction();
                $conexion->prepare(
                    "INSERT INTO documentos_legales
                     (id_caso, nombre_original, nombre_almacenado, mime_type, tamano_bytes, subido_por)
                     VALUES (:caso, :original, :almacenado, :mime, :tamano, :usuario)"
                )->execute([
                    ":caso" => $idCaso,
                    ":original" => $nombreOriginal,
                    ":almacenado" => $nombreAlmacenado,
                    ":mime" => $mime,
                    ":tamano" => (int) $archivo["size"],
                    ":usuario" => (int) $_SESSION["id_usuario"],
                ]);
                $registrarHistorial($conexion, $idCaso, (int) $_SESSION["id_usuario"], "documento agregado", [
                    "nombre" => $nombreOriginal,
                ]);
                $conexion->commit();
                $archivoGuardado = null;
                $mensaje = "Documento legal guardado correctamente.";
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare(
                "SELECT c.*, e.numero_expediente FROM casos_legales c
                 LEFT JOIN expedientes e ON e.id_expediente = c.id_expediente WHERE c.id_caso = :id"
            );
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $casoEditar = $stmt->fetch() ?: null;
        }
        if (isset($_GET["documentos"])) {
            $stmt = $conexion->prepare("SELECT id_caso, tipo_proceso, estado FROM casos_legales WHERE id_caso = :id");
            $stmt->execute([":id" => (int) $_GET["documentos"]]);
            $casoDocumentos = $stmt->fetch() ?: null;
            if ($casoDocumentos) {
                $stmt = $conexion->prepare(
                    "SELECT d.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario
                     FROM documentos_legales d LEFT JOIN usuarios u ON u.id_usuario = d.subido_por
                     WHERE d.id_caso = :id ORDER BY d.fecha_registro DESC, d.id_documento DESC"
                );
                $stmt->execute([":id" => (int) $casoDocumentos["id_caso"]]);
                $documentos = $stmt->fetchAll();
            }
        }
        if (isset($_GET["historial"])) {
            $stmt = $conexion->prepare(
                "SELECT h.*, CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario
                 FROM historial_casos_legales h LEFT JOIN usuarios u ON u.id_usuario = h.usuario_id
                 WHERE h.id_caso = :id ORDER BY h.fecha_registro DESC, h.id_historial DESC"
            );
            $stmt->execute([":id" => (int) $_GET["historial"]]);
            $historial = $stmt->fetchAll();
        }
        $clientes = $conexion->query(
            "SELECT id_cliente, nombres, apellidos FROM clientes WHERE estado = 'Activo' ORDER BY apellidos, nombres"
        )->fetchAll();
        $usuarios = $conexion->query(
            "SELECT id_usuario, nombres, apellidos FROM usuarios WHERE estado = 1 ORDER BY nombres, apellidos"
        )->fetchAll();
        $casos = $conexion->query(
            "SELECT c.*, COALESCE(e.numero_expediente, c.numero_expediente_legacy) AS numero_expediente,
                    CONCAT_WS(' ', cl.nombres, cl.apellidos) AS cliente,
                    CONCAT_WS(' ', u.nombres, u.apellidos) AS responsable
             FROM casos_legales c LEFT JOIN expedientes e ON e.id_expediente = c.id_expediente
             INNER JOIN clientes cl ON cl.id_cliente = c.id_cliente
             LEFT JOIN usuarios u ON u.id_usuario = c.responsable_id
             ORDER BY c.fecha_inicio DESC, c.id_caso DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        if ($archivoGuardado !== null && is_file($archivoGuardado) && !unlink($archivoGuardado)) {
            $error = "No se pudo completar la operación y el archivo temporal requiere revisión manual.";
        } else {
            $error = $e instanceof RuntimeException ? $e->getMessage() :
                "No se pudo completar la operación. Verifica que las migraciones estén aplicadas.";
        }
    }
}
$valor = static fn (string $campo): string => $escapar($casoEditar[$campo] ?? "");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área Legal | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Gestión legal</p><h1>Área Legal</h1><p>Registra casos y conserva sus documentos e historial de cambios.</p></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>

        <?php if ($casoDocumentos): ?>
        <section class="panel">
            <div class="panel-heading"><h3>Documentos: <?= $escapar($casoDocumentos["tipo_proceso"]) ?></h3><a class="text-link" href="legal.php">Volver a casos</a></div>
            <?php if ($casoDocumentos["estado"] !== "Archivado"): ?>
            <form method="post" enctype="multipart/form-data" class="data-form">
                <input type="hidden" name="accion" value="subir_documento"><input type="hidden" name="id_caso" value="<?= (int) $casoDocumentos["id_caso"] ?>">
                <div class="form-grid"><div class="campo"><label for="archivo">Documento PDF, JPG o PNG (máx. 8 MB) *</label><input id="archivo" name="archivo" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></div></div>
                <button class="btn-primary form-submit" type="submit">Guardar documento</button>
            </form>
            <?php endif; ?>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Archivo</th><th>Tipo</th><th>Fecha</th><th>Usuario</th><th>Acción</th></tr></thead><tbody>
                <?php foreach ($documentos as $documento): ?><tr><td><?= $escapar($documento["nombre_original"]) ?></td><td><?= $escapar($documento["mime_type"]) ?></td><td><?= $escapar($documento["fecha_registro"]) ?></td><td><?= $escapar($documento["usuario"]) ?></td><td><a class="text-link" href="legal_documento.php?id=<?= (int) $documento["id_documento"] ?>">Descargar</a></td></tr><?php endforeach; ?>
                <?php if (!$documentos): ?><tr><td colspan="5" class="empty-state">No hay documentos adjuntos.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
        <?php endif; ?>

        <?php if (isset($_GET["historial"])): ?>
        <section class="panel">
            <div class="panel-heading"><h3>Historial del caso</h3><a class="text-link" href="legal.php">Volver a casos</a></div>
            <div class="quick-list"><?php foreach ($historial as $evento): ?><div><strong><?= $escapar($evento["fecha_registro"]) ?> · <?= $escapar($evento["accion"]) ?></strong><small><?= $escapar($evento["usuario"] ?: "Usuario") ?></small><p><?= $escapar($evento["detalle"]) ?></p></div><?php endforeach; ?><?php if (!$historial): ?><p class="empty-state">No hay eventos registrados para este caso.</p><?php endif; ?></div>
        </section>
        <?php endif; ?>

        <!-- SECCIÓN 1: CASOS REGISTRADOS ARRIBA DE PRIMERAS -->
        <div class="panel" style="margin-bottom: 24px;">
            <div class="panel-heading">
                <h3>⚖️ Casos registrados</h3>
                <span style="font-size:0.8rem; color:#64748b; font-weight:500;">Listado general de procesos jurídicos</span>
            </div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Cliente / expediente</th><th>Proceso</th><th>Responsable</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($casos as $caso): ?><tr><td><?= $escapar($caso["cliente"]) ?><small><?= $escapar($caso["numero_expediente"] ?? "Sin expediente") ?></small></td><td><strong><?= $escapar($caso["tipo_proceso"]) ?></strong><small>Inicio: <?= $escapar($caso["fecha_inicio"]) ?></small></td><td><?= $escapar($caso["responsable"] ?: "Sin asignar") ?></td><td><span class="status-badge"><?= $escapar($caso["estado"]) ?></span></td><td class="actions"><a class="text-link" href="legal.php?editar=<?= (int) $caso["id_caso"] ?>">Editar</a><a class="text-link" href="legal.php?documentos=<?= (int) $caso["id_caso"] ?>">Documentos</a><a class="text-link" href="legal.php?historial=<?= (int) $caso["id_caso"] ?>">Historial</a><?php if ($caso["estado"] !== "Archivado"): ?><form method="post" onsubmit="return confirm('¿Archivar este caso? Se conservarán documentos e historial.');"><input type="hidden" name="accion" value="archivar"><input type="hidden" name="id_caso" value="<?= (int) $caso["id_caso"] ?>"><button class="link-button danger" type="submit">Archivar</button></form><?php endif; ?></td></tr><?php endforeach; ?>
                <?php if (!$casos): ?><tr><td colspan="5" class="empty-state">No hay casos registrados. Registra primero un cliente activo.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>

        <!-- SECCIÓN 2: FORMULARIO DESPLEGABLE PARA REGISTRAR / EDITAR CASO -->
        <details class="collapsible-card" <?= $casoEditar ? "open" : "" ?> style="margin-bottom: 28px;">
            <summary>
                <div class="summary-left">
                    <span class="summary-icon">⚖️</span>
                    <span class="summary-title"><?= $casoEditar ? "Editar Caso Legal" : "Registrar Nuevo Caso Legal" ?></span>
                    <span class="summary-badge"><?= $casoEditar ? "Modo edición activo" : "Nuevo expediente jurídico" ?></span>
                </div>
                <span class="summary-chevron">▼</span>
            </summary>
            <div class="collapsible-body">
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id_caso" value="<?= (int) ($casoEditar["id_caso"] ?? 0) ?>">
                    <div class="campo"><label for="id_cliente">Cliente *</label><select id="id_cliente" name="id_cliente" required><option value="">Seleccionar cliente</option><?php foreach ($clientes as $cliente): ?><option value="<?= (int) $cliente["id_cliente"] ?>" <?= (int) ($casoEditar["id_cliente"] ?? 0) === (int) $cliente["id_cliente"] ? "selected" : "" ?>><?= $escapar(trim($cliente["nombres"] . " " . ($cliente["apellidos"] ?? ""))) ?></option><?php endforeach; ?></select></div>
                    <div class="form-grid">
                        <div class="campo"><label for="numero_expediente">Expediente asociado</label><input id="numero_expediente" name="numero_expediente" value="<?= $valor("numero_expediente") ?>" placeholder="Debe pertenecer al cliente"></div>
                        <div class="campo"><label for="tipo_proceso">Tipo de proceso *</label><input id="tipo_proceso" name="tipo_proceso" maxlength="120" required value="<?= $valor("tipo_proceso") ?>"></div>
                        <div class="campo"><label for="responsable_id">Responsable</label><select id="responsable_id" name="responsable_id"><option value="">Sin asignar</option><?php foreach ($usuarios as $usuario): ?><option value="<?= (int) $usuario["id_usuario"] ?>" <?= (int) ($casoEditar["responsable_id"] ?? 0) === (int) $usuario["id_usuario"] ? "selected" : "" ?>><?= $escapar(trim($usuario["nombres"] . " " . ($usuario["apellidos"] ?? ""))) ?></option><?php endforeach; ?></select></div>
                        <div class="campo"><label for="fecha_inicio">Fecha de inicio *</label><input type="date" id="fecha_inicio" name="fecha_inicio" required value="<?= $valor("fecha_inicio") ?: date("Y-m-d") ?>"></div>
                    </div>
                    <div class="campo"><label for="estado">Estado</label><select id="estado" name="estado"><?php foreach ($estados as $estado): ?><option value="<?= $escapar($estado) ?>" <?= $valor("estado") === $estado || (!$casoEditar && $estado === "Pendiente") ? "selected" : "" ?>><?= $escapar($estado) ?></option><?php endforeach; ?></select></div>
                    <div class="campo"><label for="descripcion">Descripción</label><textarea id="descripcion" name="descripcion" rows="4"><?= $valor("descripcion") ?></textarea></div>
                    <div class="campo"><label for="observaciones">Observaciones</label><textarea id="observaciones" name="observaciones" rows="3"><?= $valor("observaciones") ?></textarea></div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button class="btn-primary form-submit" type="submit"><?= $casoEditar ? "Actualizar caso" : "Guardar caso" ?></button>
                        <?php if ($casoEditar): ?><a class="btn-secondary" href="legal.php" style="text-decoration:none; padding:8px 16px;">Cancelar</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </details>
    </main>
</div>
</body>
</html>
