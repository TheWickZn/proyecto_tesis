<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
require_once "../config/catalogos.php";

// Control de acceso basado en rol directo de sesion
$_rolActual = strtolower($_SESSION["rol"] ?? "");
if (!in_array($_rolActual, ["administrador", "asesor_academico", "coordinador_academico", "academico", "asesor", "legal", "abogado", "contabilidad", "finanzas"])) {
    http_response_code(403);
    exit("No tienes permisos para acceder al Repositorio Documental.");
}
// Puede ver contratos si es admin, finanzas o legal
$puedeVerContratos = in_array($_rolActual, ["administrador", "contabilidad", "finanzas", "legal", "abogado"]);
$condicionAccesoContrato = $puedeVerContratos ? "" : " AND id_contrato_academico IS NULL";

$mensaje = "";
$error = "";
$documentos = [];
$clientes = [];
$proyectos = [];
$contratos = [];
$documentosPendientes = [];
$categoriasCatalogo = [];
$estadosDocCatalogo = [];
$archivoGuardado = null;
$filtroProyecto = (int) ($_GET["proyecto"] ?? 0);
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");
$fechaValida = static function (string $fecha): bool {
    $validada = DateTimeImmutable::createFromFormat("!Y-m-d", $fecha);
    $errores = DateTimeImmutable::getLastErrors();
    return $validada !== false
        && ($errores === false || ($errores["warning_count"] === 0 && $errores["error_count"] === 0))
        && $validada->format("Y-m-d") === $fecha;
};

$mimeExtensiones = [
    "application/pdf" => "pdf",
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "application/msword" => "doc",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document" => "docx",
    "application/vnd.ms-excel" => "xls",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" => "xlsx",
    "application/vnd.ms-powerpoint" => "ppt",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation" => "pptx",
    "text/plain" => "txt",
];

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        // Cargar catálogos dinámicos desde la base de datos
        $categoriasCatalogo = obtenerCatalogo($conexion, "categoria_documento");
        $estadosDocCatalogo = obtenerCatalogo($conexion, "estado_documento");

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";

            if ($accion === "registrar_pendiente") {
                $tituloPendiente = trim($_POST["titulo_pendiente"] ?? "");
                $detallePendiente = trim($_POST["detalle_pendiente"] ?? "") ?: null;
                $idClientePendiente = (int) ($_POST["cliente_pendiente"] ?? 0);
                $idContratoPendiente = (int) ($_POST["contrato_pendiente"] ?? 0) ?: null;
                $fechaLimite = trim($_POST["fecha_limite"] ?? "") ?: null;
                if ($tituloPendiente === "" || strlen($tituloPendiente) > 180 || $idClientePendiente <= 0
                    || ($detallePendiente !== null && strlen($detallePendiente) > 500)
                    || ($fechaLimite !== null && !$fechaValida($fechaLimite))) {
                    throw new RuntimeException("Completa un documento, cliente y una fecha límite válida.");
                }
                $stmtClientePendiente = $conexion->prepare(
                    "SELECT 1 FROM clientes WHERE id_cliente = :id AND estado = 'Activo'"
                );
                $stmtClientePendiente->execute([":id" => $idClientePendiente]);
                if (!$stmtClientePendiente->fetchColumn()) {
                    throw new RuntimeException("El cliente seleccionado no existe o está inactivo.");
                }
                if ($idContratoPendiente !== null) {
                    if (!$puedeVerContratos) {
                        http_response_code(403);
                        throw new RuntimeException("No tienes permiso para vincular pendientes a contratos.");
                    }
                    $stmtContratoPendiente = $conexion->prepare(
                        "SELECT 1 FROM contratos_academicos
                         WHERE id_contrato = :contrato AND id_cliente = :cliente AND estado <> 'Cancelado'"
                    );
                    $stmtContratoPendiente->execute([
                        ":contrato" => $idContratoPendiente,
                        ":cliente" => $idClientePendiente,
                    ]);
                    if (!$stmtContratoPendiente->fetchColumn()) {
                        throw new RuntimeException("El contrato no corresponde al cliente seleccionado o está cancelado.");
                    }
                }
                $conexion->prepare(
                    "INSERT INTO documentos_pendientes
                     (titulo, detalle, id_cliente, id_contrato, fecha_limite, creado_por)
                     VALUES (:titulo, :detalle, :cliente, :contrato, :fecha_limite, :usuario)"
                )->execute([
                    ":titulo" => $tituloPendiente,
                    ":detalle" => $detallePendiente,
                    ":cliente" => $idClientePendiente,
                    ":contrato" => $idContratoPendiente,
                    ":fecha_limite" => $fechaLimite,
                    ":usuario" => (int) $_SESSION["id_usuario"],
                ]);
                $mensaje = "Documento pendiente registrado.";
            } elseif ($accion === "actualizar_pendiente") {
                $idPendiente = (int) ($_POST["id_pendiente"] ?? 0);
                $estadoPendiente = trim($_POST["estado_pendiente"] ?? "");
                if ($idPendiente <= 0 || !in_array($estadoPendiente, ["Pendiente", "Completado", "Cancelado"], true)) {
                    throw new RuntimeException("Selecciona un documento pendiente y un estado válido.");
                }
                $condicionPendientesContrato = $puedeVerContratos ? "" : " AND id_contrato IS NULL";
                $stmtActualizarPendiente = $conexion->prepare(
                    "UPDATE documentos_pendientes
                     SET estado = :estado,
                         resuelto_por = CASE WHEN :estado_resuelto = 'Completado' THEN :usuario_resuelto ELSE NULL END,
                         fecha_resolucion = CASE WHEN :fecha_resuelta = 'Completado' THEN CURRENT_TIMESTAMP ELSE NULL END
                     WHERE id_pendiente = :id{$condicionPendientesContrato}"
                );
                $stmtActualizarPendiente->execute([
                    ":estado" => $estadoPendiente,
                    ":estado_resuelto" => $estadoPendiente,
                    ":usuario_resuelto" => (int) $_SESSION["id_usuario"],
                    ":fecha_resuelta" => $estadoPendiente,
                    ":id" => $idPendiente,
                ]);
                if ($stmtActualizarPendiente->rowCount() !== 1) {
                    $stmtExistePendiente = $conexion->prepare(
                        "SELECT 1 FROM documentos_pendientes
                         WHERE id_pendiente = :id{$condicionPendientesContrato}"
                    );
                    $stmtExistePendiente->execute([":id" => $idPendiente]);
                    if (!$stmtExistePendiente->fetchColumn()) {
                        throw new RuntimeException("El pendiente no existe o no tienes permiso para modificarlo.");
                    }
                }
                $mensaje = "Estado del documento pendiente actualizado.";
            } elseif ($accion === "subir") {
                $titulo = trim($_POST["titulo"] ?? "");
                $categoria = trim($_POST["categoria"] ?? "");
                $estadoDocumento = trim($_POST["estado_documento"] ?? "Vigente");
                $version = trim($_POST["version"] ?? "1.0") ?: "1.0";
                $fechaDocumento = trim($_POST["fecha_documento"] ?? "") ?: date("Y-m-d");
                $descripcion = trim($_POST["descripcion"] ?? "") ?: null;
                $idCliente = (int) ($_POST["id_cliente"] ?? 0) ?: null;
                $idTesis = (int) ($_POST["id_tesis"] ?? 0) ?: null;
                $idContrato = (int) ($_POST["id_contrato"] ?? 0) ?: null;
                $archivo = $_FILES["archivo"] ?? null;
                if ($idContrato !== null && !$puedeVerContratos) {
                    http_response_code(403);
                    throw new RuntimeException("No tienes permiso para vincular documentos a contratos.");
                }

                if (!$archivo || !isset($archivo["error"], $archivo["tmp_name"])) {
                    throw new RuntimeException("Selecciona un archivo para subir.");
                }

                $nombreOriginal = basename(str_replace("\\", "/", (string) $archivo["name"]));
                if ($nombreOriginal === "" || strlen($nombreOriginal) > 255) {
                    throw new RuntimeException("El nombre del archivo no es válido.");
                }

                // Si el título está vacío, usar el nombre original sin extensión
                if ($titulo === "") {
                    $titulo = pathinfo($nombreOriginal, PATHINFO_FILENAME);
                }

                if ($categoria === "") {
                    throw new RuntimeException("Debes especificar una categoría para el documento.");
                }

                // Asegurar que la categoría quede registrada en el catálogo dinámico de la base de datos
                $categoria = asegurarOpcionEnCatalogo($conexion, "categoria_documento", $categoria);

                if ((int) $archivo["error"] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException("Error al recibir el archivo. Verifica el tamaño e inténtalo de nuevo.");
                }

                // Límite de 15 MB
                if ((int) $archivo["size"] <= 0 || (int) $archivo["size"] > 15 * 1024 * 1024) {
                    throw new RuntimeException("El archivo debe pesar como máximo 15 MB.");
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($archivo["tmp_name"]);

                // Fallback para tipos MIME complejos de Office
                $extOriginal = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
                if (!isset($mimeExtensiones[$mime])) {
                    if ($extOriginal === "docx") $mime = "application/vnd.openxmlformats-officedocument.wordprocessingml.document";
                    elseif ($extOriginal === "xlsx") $mime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
                    elseif ($extOriginal === "pptx") $mime = "application/vnd.openxmlformats-officedocument.presentationml.presentation";
                    elseif ($extOriginal === "doc") $mime = "application/msword";
                    elseif ($extOriginal === "xls") $mime = "application/vnd.ms-excel";
                    else {
                        throw new RuntimeException("Formato no permitido ({$extOriginal}). Se admiten PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG y PNG.");
                    }
                }

                $extensionFinal = $mimeExtensiones[$mime] ?? $extOriginal;

                if ($idCliente !== null) {
                    $stmtCliente = $conexion->prepare("SELECT 1 FROM clientes WHERE id_cliente = :id AND estado = 'Activo'");
                    $stmtCliente->execute([":id" => $idCliente]);
                    if (!$stmtCliente->fetchColumn()) {
                        throw new RuntimeException("El cliente seleccionado no existe o está inactivo.");
                    }
                }
                if ($idTesis !== null) {
                    $stmtProyecto = $conexion->prepare(
                        "SELECT e.id_cliente FROM proyectos_tesis t
                         INNER JOIN expedientes e ON e.id_expediente = t.id_expediente
                         WHERE t.id_tesis = :id"
                    );
                    $stmtProyecto->execute([":id" => $idTesis]);
                    $clienteProyecto = $stmtProyecto->fetchColumn();
                    if ($clienteProyecto === false || ($idCliente !== null && (int) $clienteProyecto !== $idCliente)) {
                        throw new RuntimeException("El proyecto seleccionado no existe o no corresponde al cliente.");
                    }
                    $idCliente = (int) $clienteProyecto;
                }
                if ($idContrato !== null) {
                    $stmtContrato = $conexion->prepare(
                        "SELECT id_cliente, id_tesis FROM contratos_academicos WHERE id_contrato = :id"
                    );
                    $stmtContrato->execute([":id" => $idContrato]);
                    $contrato = $stmtContrato->fetch();
                    if (!$contrato || ($idCliente !== null && (int) $contrato["id_cliente"] !== $idCliente)) {
                        throw new RuntimeException("El contrato seleccionado no existe o no corresponde al cliente.");
                    }
                    if ($idTesis !== null && $contrato["id_tesis"] !== null && (int) $contrato["id_tesis"] !== $idTesis) {
                        throw new RuntimeException("El contrato seleccionado está vinculado a otro proyecto.");
                    }
                    $idCliente = (int) $contrato["id_cliente"];
                    $idTesis = $idTesis ?? (int) ($contrato["id_tesis"] ?? 0) ?: null;
                }

                $nombreAlmacenado = bin2hex(random_bytes(32)) . "." . $extensionFinal;
                $directorio = __DIR__ . "/../storage/documentos";
                if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) {
                    throw new RuntimeException("No se pudo preparar el almacenamiento de documentos.");
                }

                $archivoGuardado = $directorio . DIRECTORY_SEPARATOR . $nombreAlmacenado;
                if (!move_uploaded_file($archivo["tmp_name"], $archivoGuardado)) {
                    $archivoGuardado = null;
                    throw new RuntimeException("No se pudo almacenar el archivo en el servidor.");
                }

                // Guardar en la base de datos con metadata completa y escalable
                $stmtInsert = $conexion->prepare(
                    "INSERT INTO documentos_empresa
                     (nombre_original, titulo, nombre_almacenado, mime_type, tamano_bytes, categoria, 
                      estado_documento, version, fecha_documento, descripcion, id_cliente, id_tesis,
                      id_contrato_academico, subido_por, estado)
                     VALUES (:original, :titulo, :almacenado, :mime, :tamano, :categoria, 
                             :estado_doc, :version, :fecha_doc, :descripcion, :cliente, :tesis,
                             :contrato, :usuario, 1)"
                );
                $stmtInsert->execute([
                    ":original" => $nombreOriginal,
                    ":titulo" => $titulo,
                    ":almacenado" => $nombreAlmacenado,
                    ":mime" => $mime,
                    ":tamano" => (int) $archivo["size"],
                    ":categoria" => $categoria,
                    ":estado_doc" => $estadoDocumento,
                    ":version" => $version,
                    ":fecha_doc" => $fechaDocumento,
                    ":descripcion" => $descripcion,
                    ":cliente" => $idCliente,
                    ":tesis" => $idTesis,
                    ":contrato" => $idContrato,
                    ":usuario" => (int) $_SESSION["id_usuario"],
                ]);

                $archivoGuardado = null;
                $mensaje = "Documento '{$titulo}' subido y almacenado correctamente en la base de datos.";
            } elseif ($accion === "cambiar_estado") {
                $idDocumento = (int) ($_POST["id_documento"] ?? 0);
                $nuevoEstado = trim($_POST["nuevo_estado"] ?? "");
                if ($idDocumento <= 0 || $nuevoEstado === "") {
                    throw new RuntimeException("Datos de estado no válidos.");
                }
                $stmt = $conexion->prepare(
                    "UPDATE documentos_empresa SET estado_documento = :estado
                     WHERE id_documento = :id AND estado = 1{$condicionAccesoContrato}"
                );
                $stmt->execute([":estado" => $nuevoEstado, ":id" => $idDocumento]);
                if ($stmt->rowCount() !== 1) {
                    $stmtExiste = $conexion->prepare(
                        "SELECT 1 FROM documentos_empresa
                         WHERE id_documento = :id AND estado = 1{$condicionAccesoContrato}"
                    );
                    $stmtExiste->execute([":id" => $idDocumento]);
                    if (!$stmtExiste->fetchColumn()) {
                        throw new RuntimeException("El documento no existe o no tienes permiso para modificarlo.");
                    }
                }
                $mensaje = "Estado del documento actualizado a '{$nuevoEstado}'.";
            } elseif ($accion === "archivar") {
                $idDocumento = (int) ($_POST["id_documento"] ?? 0);
                if ($idDocumento <= 0) {
                    throw new RuntimeException("Selecciona un documento válido.");
                }
                $stmt = $conexion->prepare(
                    "UPDATE documentos_empresa SET estado = 0, estado_documento = 'Archivado'
                     WHERE id_documento = :id AND estado = 1{$condicionAccesoContrato}"
                );
                $stmt->execute([":id" => $idDocumento]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException("El documento no existe o ya fue archivado.");
                }
                $mensaje = "Documento archivado correctamente. El archivo se conserva seguro en el repositorio.";
            }
        }

        // Consultar clientes para el selector
        $clientes = $conexion->query(
            "SELECT id_cliente, nombres, apellidos, empresa FROM clientes WHERE estado = 'Activo' ORDER BY nombres, apellidos"
        )->fetchAll();
        $proyectos = $conexion->query(
            "SELECT t.id_tesis, t.titulo, e.id_cliente, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente
             FROM proyectos_tesis t
             INNER JOIN expedientes e ON e.id_expediente = t.id_expediente
             INNER JOIN clientes c ON c.id_cliente = e.id_cliente
             WHERE t.estado <> 'Cancelado' ORDER BY t.titulo"
        )->fetchAll();
        if ($puedeVerContratos) {
            $contratos = $conexion->query(
                "SELECT id_contrato, id_cliente, id_tesis, numero_contrato, titulo
                 FROM contratos_academicos WHERE estado <> 'Cancelado' ORDER BY fecha_contrato DESC"
            )->fetchAll();
        }
        $condicionPendientesContrato = $puedeVerContratos ? "" : " AND dp.id_contrato IS NULL";
        $documentosPendientes = $conexion->query(
            "SELECT dp.*, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente,
                    ca.numero_contrato, ca.titulo AS titulo_contrato,
                    CONCAT_WS(' ', creador.nombres, creador.apellidos) AS creado_por_nombre,
                    CONCAT_WS(' ', resolutor.nombres, resolutor.apellidos) AS resuelto_por_nombre
             FROM documentos_pendientes dp
             INNER JOIN clientes c ON c.id_cliente = dp.id_cliente
             LEFT JOIN contratos_academicos ca ON ca.id_contrato = dp.id_contrato
             LEFT JOIN usuarios creador ON creador.id_usuario = dp.creado_por
             LEFT JOIN usuarios resolutor ON resolutor.id_usuario = dp.resuelto_por
             WHERE 1 = 1{$condicionPendientesContrato}
             ORDER BY FIELD(dp.estado, 'Pendiente', 'Completado', 'Cancelado'),
                      dp.fecha_limite IS NULL, dp.fecha_limite, dp.id_pendiente DESC"
        )->fetchAll();

        // Filtros de búsqueda
        $buscar = trim($_GET["buscar"] ?? "");
        $filtroCategoria = trim($_GET["categoria"] ?? "");
        $filtroEstado = trim($_GET["estado_doc"] ?? "");

        $condiciones = ["d.estado = 1"];
        $parametros = [];

        if ($buscar !== "") {
            $condiciones[] = "(d.titulo LIKE :buscar OR d.nombre_original LIKE :buscar OR d.descripcion LIKE :buscar OR CONCAT_WS(' ', c.nombres, c.apellidos) LIKE :buscar)";
            $parametros[":buscar"] = "%{$buscar}%";
        }
        if ($filtroCategoria !== "") {
            $condiciones[] = "d.categoria = :categoria";
            $parametros[":categoria"] = $filtroCategoria;
        }
        if ($filtroEstado !== "") {
            $condiciones[] = "d.estado_documento = :estado_doc";
            $parametros[":estado_doc"] = $filtroEstado;
        }
        if ($filtroProyecto > 0) {
            $condiciones[] = "d.id_tesis = :proyecto";
            $parametros[":proyecto"] = $filtroProyecto;
        }
        if (!$puedeVerContratos) {
            $condiciones[] = "d.id_contrato_academico IS NULL";
        }

        $whereSql = "WHERE " . implode(" AND ", $condiciones);

        $stmtDocs = $conexion->prepare(
            "SELECT d.*, 
                    CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario,
                    CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente,
                    c.empresa AS cliente_empresa,
                    t.titulo AS proyecto,
                    ca.numero_contrato
             FROM documentos_empresa d
             INNER JOIN usuarios u ON u.id_usuario = d.subido_por
             LEFT JOIN clientes c ON c.id_cliente = d.id_cliente
             LEFT JOIN proyectos_tesis t ON t.id_tesis = d.id_tesis
             LEFT JOIN contratos_academicos ca ON ca.id_contrato = d.id_contrato_academico
             {$whereSql}
             ORDER BY d.fecha_registro DESC, d.id_documento DESC"
        );
        $stmtDocs->execute($parametros);
        $documentos = $stmtDocs->fetchAll();

    } catch (Throwable $e) {
        if ($archivoGuardado !== null && is_file($archivoGuardado) && !unlink($archivoGuardado)) {
            $error = "No se pudo completar la operación y el archivo temporal requiere revisión.";
        } else {
            $error = $e instanceof RuntimeException ? $e->getMessage() : "Error en el servidor: " . $e->getMessage();
        }
    }
}

// Formateador de bytes legible
$formatearBytes = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . " MB";
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . " KB";
    }
    return $bytes . " B";
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Documentos | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner">
            <p class="eyebrow">Repositorio Digital y Tesis</p>
            <h1>Gestión de Documentos</h1>
            <p>Almacena, clasifica y gestiona documentos, tesis, informes, propuestas y contratos de clientes de manera segura y escalable.</p>
        </div>

        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>

        <!-- SECCIÓN 1: VISTA PRINCIPAL CON DOCUMENTOS EN EL REPOSITORIO Y PENDIENTES ARRIBA -->
        <div style="display: flex; flex-direction: column; gap: 24px; margin-bottom: 28px;">
            <!-- Panel de Documentos Registrados y Filtros -->
            <div class="panel">
                <div class="panel-heading" style="flex-wrap: wrap; gap: 12px;">
                    <h3>📁 Documentos en el Repositorio (<?= count($documentos) ?>)</h3>
                    <!-- Formulario de Búsqueda y Filtros -->
                    <form method="get" class="search-form" style="display:flex; gap:8px; flex-wrap:wrap;">
                        <?php if ($filtroProyecto > 0): ?><input type="hidden" name="proyecto" value="<?= $filtroProyecto ?>"><?php endif; ?>
                        <input name="buscar" placeholder="Buscar título, cliente..." value="<?= $escapar($_GET["buscar"] ?? "") ?>" style="min-width: 140px;">
                        <select name="categoria">
                            <option value="">Todas las categorías</option>
                            <?php foreach ($categoriasCatalogo as $cat): ?>
                                <option value="<?= $escapar($cat["nombre"]) ?>" <?= ($filtroCategoria === $cat["nombre"]) ? "selected" : "" ?>>
                                    <?= $escapar($cat["nombre"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="estado_doc">
                            <option value="">Todos los estados</option>
                            <?php foreach ($estadosDocCatalogo as $est): ?>
                                <option value="<?= $escapar($est["nombre"]) ?>" <?= ($filtroEstado === $est["nombre"]) ? "selected" : "" ?>>
                                    <?= $escapar($est["nombre"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-secondary btn-sm">Filtrar</button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Documento</th>
                                <th>Categoría</th>
                                <th>Estado</th>
                                <th>Cliente / Subido por</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documentos as $doc): ?>
                                <?php
                                $estadoDoc = $doc["estado_documento"] ?? "Vigente";
                                $claseBadge = "badge-vigente";
                                if (stripos($estadoDoc, "revisi") !== false) $claseBadge = "badge-revision";
                                elseif (stripos($estadoDoc, "borrador") !== false) $claseBadge = "badge-borrador";
                                elseif (stripos($estadoDoc, "aprobado") !== false) $claseBadge = "badge-aprobado";
                                elseif (stripos($estadoDoc, "archivado") !== false) $claseBadge = "badge-archivado";
                                elseif (stripos($estadoDoc, "observado") !== false) $claseBadge = "badge-observado";

                                $ext = strtoupper(pathinfo($doc["nombre_original"], PATHINFO_EXTENSION));
                                ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; gap: 10px; align-items: flex-start;">
                                            <div class="file-icon-badge" style="width:34px; height:34px; font-size:0.65rem; flex-shrink:0;">
                                                <?= $escapar($ext ?: "FILE") ?>
                                            </div>
                                            <div>
                                                <strong><?= $escapar($doc["titulo"] ?: $doc["nombre_original"]) ?></strong>
                                                <small style="color:#64748b;"><?= $escapar($doc["nombre_original"]) ?> · v<?= $escapar($doc["version"] ?? "1.0") ?> · <?= $formatearBytes((int)$doc["tamano_bytes"]) ?></small>
                                                <?php if (!empty($doc["descripcion"])): ?>
                                                    <small style="color:#475569; font-style:italic;"><?= $escapar($doc["descripcion"]) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size:0.82rem; font-weight:600; color:#1e293b; background:#f1f5f9; padding:3px 8px; border-radius:6px;">
                                            <?= $escapar($doc["categoria"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-doc <?= $claseBadge ?>"><?= $escapar($estadoDoc) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($doc["cliente"])): ?>
                                            <strong><?= $escapar($doc["cliente"]) ?></strong>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">General</span>
                                        <?php endif; ?>
                                        <?php if (!empty($doc["proyecto"])): ?><small>Proyecto: <?= $escapar($doc["proyecto"]) ?></small><?php endif; ?>
                                        <?php if (!empty($doc["numero_contrato"])): ?><small>Contrato: #<?= $escapar($doc["numero_contrato"]) ?></small><?php endif; ?>
                                        <small>Por: <?= $escapar($doc["usuario"]) ?></small>
                                    </td>
                                    <td>
                                        <small><?= date("d/m/Y", strtotime($doc["fecha_registro"])) ?></small>
                                    </td>
                                    <td class="actions">
                                        <a class="btn-action btn-action-primary" href="documento_descargar.php?id=<?= (int) $doc["id_documento"] ?>" title="Descargar archivo">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                            Descargar
                                        </a>

                                        <!-- Cambiar estado rápido -->
                                        <form method="post" style="display:inline;" onchange="this.submit()">
                                            <input type="hidden" name="accion" value="cambiar_estado">
                                            <input type="hidden" name="id_documento" value="<?= (int) $doc["id_documento"] ?>">
                                            <select name="nuevo_estado" style="font-size:0.75rem; padding:3px 6px; border-radius:6px; border:1px solid #cbd5e1; background:#fff; cursor:pointer;" title="Cambiar estado del documento">
                                                <option value="" disabled selected>Estado...</option>
                                                <?php foreach ($estadosDocCatalogo as $est): ?>
                                                    <option value="<?= $escapar($est["nombre"]) ?>" <?= $estadoDoc === $est["nombre"] ? "selected" : "" ?>>
                                                        <?= $escapar($est["nombre"]) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>

                                        <!-- Archivar -->
                                        <form method="post" onsubmit="return confirm('¿Archivar este documento? Se mantendrá en el repositorio histórico.');" style="display:inline;">
                                            <input type="hidden" name="accion" value="archivar">
                                            <input type="hidden" name="id_documento" value="<?= (int) $doc["id_documento"] ?>">
                                            <button class="btn-action btn-action-danger" type="submit" title="Archivar documento">
                                                Archivar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$documentos): ?>
                                <tr><td colspan="6" class="empty-state">No hay documentos que coincidan con la búsqueda.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel de Control de Documentos Pendientes -->
            <div class="panel" id="pendientes-documentales">
                <div class="panel-heading">
                    <h3>📋 Control de documentos pendientes</h3>
                    <span style="font-size:0.8rem; color:#64748b; font-weight:500;">Requerimientos solicitados a clientes</span>
                </div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Documento requerido</th><th>Cliente / contrato</th><th>Fecha límite</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
                <?php foreach ($documentosPendientes as $pendiente): ?>
                    <tr>
                        <td><strong><?= $escapar($pendiente["titulo"]) ?></strong><?php if ($pendiente["detalle"]): ?><small><?= $escapar($pendiente["detalle"]) ?></small><?php endif; ?><small>Registrado por: <?= $escapar($pendiente["creado_por_nombre"] ?: "Usuario inactivo") ?></small></td>
                        <td><?= $escapar($pendiente["cliente"]) ?><?php if ($pendiente["numero_contrato"]): ?><small>Contrato #<?= $escapar($pendiente["numero_contrato"]) ?> · <?= $escapar($pendiente["titulo_contrato"]) ?></small><?php endif; ?></td>
                        <td><?= $escapar($pendiente["fecha_limite"] ?: "Sin fecha") ?></td>
                        <td><?= $escapar($pendiente["estado"]) ?><?php if ($pendiente["fecha_resolucion"]): ?><small><?= $escapar($pendiente["fecha_resolucion"]) ?> · <?= $escapar($pendiente["resuelto_por_nombre"] ?: "Usuario inactivo") ?></small><?php endif; ?></td>
                        <td><form method="post" class="compact-form"><input type="hidden" name="accion" value="actualizar_pendiente"><input type="hidden" name="id_pendiente" value="<?= (int) $pendiente["id_pendiente"] ?>"><select name="estado_pendiente" aria-label="Estado del pendiente"><?php foreach (["Pendiente", "Completado", "Cancelado"] as $estadoPendiente): ?><option value="<?= $escapar($estadoPendiente) ?>" <?= $pendiente["estado"] === $estadoPendiente ? "selected" : "" ?>><?= $escapar($estadoPendiente) ?></option><?php endforeach; ?></select><button class="btn-action btn-action-primary" type="submit">Guardar</button></form></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$documentosPendientes): ?><tr><td colspan="5" class="empty-state">No hay documentos pendientes registrados.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </div>

        <!-- SECCIÓN 2: FORMULARIOS DESPLEGABLES (LIMPIOS Y ORDENADOS) -->
        <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 30px;">
            <!-- Desplegable: Subir Nuevo Documento -->
            <details class="collapsible-card" id="panel-subida">
                <summary>
                    <div class="summary-left">
                        <span class="summary-icon">📤</span>
                        <span class="summary-title">Subir Nuevo Documento al Repositorio</span>
                        <span class="summary-badge">Hasta 15 MB · PDF, DOCX, XLSX, PPTX, JPG, PNG</span>
                    </div>
                    <span class="summary-chevron">▼</span>
                </summary>
                <div class="collapsible-body">
                    <form method="post" enctype="multipart/form-data" class="data-form" id="form-subir-documento">
                        <input type="hidden" name="accion" value="subir">

                        <!-- Zona Drag and Drop Interactiva -->
                        <div class="dropzone-container" id="dropzone">
                            <input id="input-archivo" name="archivo" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.txt" required>
                            <div class="dropzone-icon">
                                <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                            </div>
                            <p class="dropzone-title">Arrastra tu documento aquí</p>
                            <p class="dropzone-subtitle">o haz clic para seleccionar (PDF, Word, Excel, Imágenes)</p>

                            <!-- Vista previa del archivo seleccionado -->
                            <div class="file-selected-box" id="file-preview" style="display: none;">
                                <div class="file-selected-info">
                                    <div class="file-icon-badge" id="file-badge">DOC</div>
                                    <div style="text-align: left;">
                                        <div class="file-selected-name" id="file-name">archivo.pdf</div>
                                        <div class="file-selected-size" id="file-size">0 KB</div>
                                    </div>
                                </div>
                                <button type="button" id="btn-remover-archivo" class="btn-action btn-action-danger" style="padding:4px 8px; font-size:0.75rem;">Cambiar</button>
                            </div>
                        </div>

                        <div class="campo" style="margin-top: 16px;">
                            <label for="titulo">Título del Documento *</label>
                            <input id="titulo" name="titulo" type="text" maxlength="180" required placeholder="Ej.: Tesis de Grado - Capítulo 1">
                        </div>

                        <div class="form-grid">
                            <!-- Categoría con Combobox escalable (permite elegir o escribir nueva) -->
                            <div class="campo">
                                <label for="categoria">Categoría *</label>
                                <div class="combobox-wrap">
                                    <input id="categoria" name="categoria" list="lista-categorias" required placeholder="Selecciona o escribe..." autocomplete="off">
                                    <datalist id="lista-categorias">
                                        <?php foreach ($categoriasCatalogo as $cat): ?>
                                            <option value="<?= $escapar($cat["nombre"]) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <span class="combobox-hint">Puedes elegir de la lista o escribir una nueva categoría.</span>
                            </div>

                            <!-- Estado del Documento -->
                            <div class="campo">
                                <label for="estado_documento">Estado del Documento</label>
                                <select id="estado_documento" name="estado_documento">
                                    <?php if (!empty($estadosDocCatalogo)): ?>
                                        <?php foreach ($estadosDocCatalogo as $est): ?>
                                            <option value="<?= $escapar($est["nombre"]) ?>" <?= $est["nombre"] === "Vigente" ? "selected" : "" ?>>
                                                <?= $escapar($est["nombre"]) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="Vigente">Vigente</option>
                                        <option value="En revisión">En revisión</option>
                                        <option value="Aprobado">Aprobado</option>
                                        <option value="Borrador">Borrador</option>
                                        <option value="Observado">Observado</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="campo">
                                <label for="version">Versión</label>
                                <input id="version" name="version" type="text" value="1.0" maxlength="20" placeholder="Ej.: 1.0, 2.1">
                            </div>

                            <div class="campo">
                                <label for="id_cliente">Cliente / Titular Relacionado</label>
                                <select id="id_cliente" name="id_cliente">
                                    <option value="">Sin cliente asociado</option>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <option value="<?= (int) $cliente["id_cliente"] ?>">
                                            <?= $escapar(trim($cliente["nombres"] . " " . ($cliente["apellidos"] ?? ""))) ?>
                                            <?= $cliente["empresa"] ? " · " . $escapar($cliente["empresa"]) : "" ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="id_tesis">Proyecto asociado</label>
                                <select id="id_tesis" name="id_tesis">
                                    <option value="">Sin proyecto asociado</option>
                                    <?php foreach ($proyectos as $proyecto): ?>
                                        <option value="<?= (int) $proyecto["id_tesis"] ?>" data-cliente="<?= (int) $proyecto["id_cliente"] ?>" <?= $filtroProyecto === (int) $proyecto["id_tesis"] ? "selected" : "" ?>>
                                            <?= $escapar($proyecto["cliente"] . " · " . $proyecto["titulo"]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($puedeVerContratos): ?>
                            <div class="campo">
                                <label for="id_contrato">Contrato asociado</label>
                                <select id="id_contrato" name="id_contrato">
                                    <option value="">Sin contrato asociado</option>
                                    <?php foreach ($contratos as $contrato): ?>
                                        <option value="<?= (int) $contrato["id_contrato"] ?>"
                                            data-cliente="<?= (int) $contrato["id_cliente"] ?>"
                                            data-proyecto="<?= (int) ($contrato["id_tesis"] ?? 0) ?>">
                                            <?= $escapar("#" . $contrato["numero_contrato"] . " · " . $contrato["titulo"]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="campo">
                            <label for="descripcion">Descripción / Observaciones</label>
                            <textarea id="descripcion" name="descripcion" maxlength="500" rows="3" placeholder="Detalles sobre el contenido, autor, avance o versión..."></textarea>
                        </div>

                        <button class="btn-primary form-submit" type="submit" style="width: 100%; margin-top: 10px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                            Guardar Documento en Base de Datos
                        </button>
                    </form>
                </div>
            </details>

            <!-- Desplegable: Registrar Documento Pendiente -->
            <details class="collapsible-card">
                <summary>
                    <div class="summary-left">
                        <span class="summary-icon">📝</span>
                        <span class="summary-title">Registrar Requerimiento de Documento Pendiente</span>
                        <span class="summary-badge">Seguimiento a clientes</span>
                    </div>
                    <span class="summary-chevron">▼</span>
                </summary>
                <div class="collapsible-body">
                    <form method="post" class="data-form">
                        <input type="hidden" name="accion" value="registrar_pendiente">
                        <div class="form-grid">
                            <div class="campo"><label for="titulo_pendiente">Documento requerido *</label><input id="titulo_pendiente" name="titulo_pendiente" maxlength="180" required placeholder="Ej.: DNI vigente"></div>
                            <div class="campo"><label for="cliente_pendiente">Cliente *</label><select id="cliente_pendiente" name="cliente_pendiente" required><option value="">Seleccionar cliente</option><?php foreach ($clientes as $cliente): ?><option value="<?= (int) $cliente["id_cliente"] ?>"><?= $escapar(trim($cliente["nombres"] . " " . ($cliente["apellidos"] ?? ""))) ?><?= $cliente["empresa"] ? " · " . $escapar($cliente["empresa"]) : "" ?></option><?php endforeach; ?></select></div>
                            <?php if ($puedeVerContratos): ?><div class="campo"><label for="contrato_pendiente">Contrato (opcional)</label><select id="contrato_pendiente" name="contrato_pendiente"><option value="">Solo para el cliente</option><?php foreach ($contratos as $contrato): ?><option value="<?= (int) $contrato["id_contrato"] ?>" data-cliente="<?= (int) $contrato["id_cliente"] ?>">#<?= $escapar($contrato["numero_contrato"]) ?> · <?= $escapar($contrato["titulo"]) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                            <div class="campo"><label for="fecha_limite">Fecha límite</label><input id="fecha_limite" name="fecha_limite" type="date"></div>
                        </div>
                        <div class="campo"><label for="detalle_pendiente">Detalle</label><textarea id="detalle_pendiente" name="detalle_pendiente" maxlength="500" rows="2"></textarea></div>
                        <button class="btn-primary form-submit" type="submit">Guardar pendiente</button>
                    </form>
                </div>
            </details>
        </div>
    </main>
</div>

<!-- Script interactivo de Drag and Drop y Previsualización -->
<script>
document.addEventListener("DOMContentLoaded", () => {
    const clienteSelect = document.getElementById("id_cliente");
    const proyectoSelect = document.getElementById("id_tesis");
    const contratoSelect = document.getElementById("id_contrato");
    const clientePendienteSelect = document.getElementById("cliente_pendiente");
    const contratoPendienteSelect = document.getElementById("contrato_pendiente");
    const filtrarVinculos = () => {
        if (proyectoSelect) {
            Array.from(proyectoSelect.options).forEach(option => {
                option.hidden = Boolean(option.value) && clienteSelect.value !== "" && option.dataset.cliente !== clienteSelect.value;
            });
            if (proyectoSelect.selectedOptions[0] && proyectoSelect.selectedOptions[0].hidden) proyectoSelect.value = "";
        }
        if (contratoSelect) {
            Array.from(contratoSelect.options).forEach(option => {
                const clienteCoincide = clienteSelect.value === "" || option.dataset.cliente === clienteSelect.value;
                const proyectoCoincide = !proyectoSelect.value || option.dataset.proyecto === proyectoSelect.value || option.dataset.proyecto === "0";
                option.hidden = Boolean(option.value) && (!clienteCoincide || !proyectoCoincide);
            });
            if (contratoSelect.selectedOptions[0] && contratoSelect.selectedOptions[0].hidden) contratoSelect.value = "";
        }
    };
    if (clienteSelect) clienteSelect.addEventListener("change", filtrarVinculos);
    if (proyectoSelect) proyectoSelect.addEventListener("change", filtrarVinculos);
    filtrarVinculos();
    if (clientePendienteSelect && contratoPendienteSelect) {
        clientePendienteSelect.addEventListener("change", () => {
            Array.from(contratoPendienteSelect.options).forEach(option => {
                option.hidden = Boolean(option.value) && option.dataset.cliente !== clientePendienteSelect.value;
            });
            if (contratoPendienteSelect.selectedOptions[0] && contratoPendienteSelect.selectedOptions[0].hidden) {
                contratoPendienteSelect.value = "";
            }
        });
    }

    const dropzone = document.getElementById("dropzone");
    const inputArchivo = document.getElementById("input-archivo");
    const filePreview = document.getElementById("file-preview");
    const fileName = document.getElementById("file-name");
    const fileSize = document.getElementById("file-size");
    const fileBadge = document.getElementById("file-badge");
    const btnRemover = document.getElementById("btn-remover-archivo");
    const inputTitulo = document.getElementById("titulo");

    const actualizarVistaArchivo = (file) => {
        if (!file) {
            filePreview.style.display = "none";
            return;
        }

        fileName.textContent = file.name;
        const kb = (file.size / 1024).toFixed(1);
        fileSize.textContent = kb > 1024 ? (kb / 1024).toFixed(2) + " MB" : kb + " KB";

        const ext = file.name.split('.').pop().toUpperCase();
        fileBadge.textContent = ext.slice(0, 4) || "FILE";

        filePreview.style.display = "flex";

        // Autocompletar título si está vacío
        if (inputTitulo && inputTitulo.value.trim() === "") {
            const nombreLimpio = file.name.replace(/\.[^/.]+$/, "").replace(/[_-]/g, " ");
            inputTitulo.value = nombreLimpio.charAt(0).toUpperCase() + nombreLimpio.slice(1);
        }
    };

    inputArchivo.addEventListener("change", (e) => {
        if (e.target.files && e.target.files[0]) {
            actualizarVistaArchivo(e.target.files[0]);
        }
    });

    // Drag and drop feedback
    ["dragenter", "dragover"].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add("is-dragover");
        });
    });

    ["dragleave", "drop"].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove("is-dragover");
        });
    });

    dropzone.addEventListener("drop", (e) => {
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            inputArchivo.files = e.dataTransfer.files;
            actualizarVistaArchivo(e.dataTransfer.files[0]);
        }
    });

    btnRemover.addEventListener("click", (e) => {
        e.stopPropagation();
        inputArchivo.value = "";
        filePreview.style.display = "none";
    });
});
</script>
</body>
</html>
