<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";

if ($conexion) {
    exigirAlgunPermiso($conexion, ["asesor.trabajo", "tesis.gestionar"]);
}

$mensaje = "";
$error = "";
$asesor = null;
$proyectos = [];
$hitos = [];
$entregas = [];
$archivoGuardado = null;
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");
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
        $stmtAsesor = $conexion->prepare(
            "SELECT id_asesor, nombres, apellidos
             FROM asesores_academicos
             WHERE id_usuario = :usuario AND estado = 'Activo'
             LIMIT 1"
        );
        $stmtAsesor->execute([":usuario" => (int) $_SESSION["id_usuario"]]);
        $asesor = $stmtAsesor->fetch();

        // Si no tiene perfil aún, auto-vincular para que el área académica nunca se bloquee
        if (!$asesor) {
            try {
                $stmtAuto = $conexion->prepare(
                    "INSERT INTO asesores_academicos (id_usuario, nombres, apellidos, especialidad, estado)
                     VALUES (:u, :nom, :ape, 'Asesoría Metodológica de Tesis', 'Activo')"
                );
                $stmtAuto->execute([
                    ":u" => (int) $_SESSION["id_usuario"],
                    ":nom" => $_SESSION["nombres"] ?? "Docente",
                    ":ape" => $_SESSION["apellidos"] ?? "Académico"
                ]);
                $idAsesor = (int) $conexion->lastInsertId();
                $asesor = ["id_asesor" => $idAsesor, "nombres" => $_SESSION["nombres"] ?? "Docente", "apellidos" => $_SESSION["apellidos"] ?? "Académico"];
            } catch (Throwable $e) {
                $idAsesor = 0;
            }
        } else {
            $idAsesor = (int) $asesor["id_asesor"];
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";

            // Acción 1: Calificación / Retroalimentación rápida estilo SENATI
            if ($accion === "revisar_entrega_senati") {
                $idEntrega = (int) ($_POST["id_entrega"] ?? 0);
                $decision = trim($_POST["decision"] ?? "");
                $observaciones = trim($_POST["observaciones"] ?? "") ?: null;

                if ($idEntrega > 0 && in_array($decision, ["Aprobado", "Observado", "En revisión"], true)) {
                    $stmtRev = $conexion->prepare(
                        "UPDATE entregas_asesores 
                         SET estado = :estado, observaciones_coordinador = :obs, revisado_por = :rev, fecha_revision = CURRENT_TIMESTAMP 
                         WHERE id_entrega = :id"
                    );
                    $stmtRev->execute([
                        ":estado" => $decision,
                        ":obs" => $observaciones,
                        ":rev" => (int) $_SESSION["id_usuario"],
                        ":id" => $idEntrega
                    ]);
                    $mensaje = "Trabajo evaluado con éxito (Estado: {$decision}). Retroalimentación registrada en el expediente.";
                }
            }

            // Acción 2: Subir nueva entrega de avance
            elseif ($accion === "subir_entrega") {
                $idTesis = (int) ($_POST["id_tesis"] ?? 0);
                $idHito = (int) ($_POST["id_hito"] ?? 0);
                $titulo = trim($_POST["titulo"] ?? "");
                $comentario = trim($_POST["comentario_asesor"] ?? "") ?: null;
                $archivo = $_FILES["archivo"] ?? null;
                if ($idTesis <= 0 || $idHito <= 0 || $titulo === "" || strlen($titulo) > 180) {
                    throw new RuntimeException("Selecciona el proyecto y la etapa, y escribe un título válido.");
                }
                if ($comentario !== null && strlen($comentario) > 5000) {
                    throw new RuntimeException("El comentario no puede superar los 5000 caracteres.");
                }
                if (!$archivo || !isset($archivo["error"], $archivo["tmp_name"])) {
                    throw new RuntimeException("Selecciona el archivo que vas a entregar.");
                }
                if ((int) $archivo["error"] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException("No se pudo recibir el archivo. Verifica el tamaño e inténtalo de nuevo.");
                }
                if ((int) $archivo["size"] <= 0 || (int) $archivo["size"] > 15 * 1024 * 1024) {
                    throw new RuntimeException("El archivo debe pesar como máximo 15 MB.");
                }
                $nombreOriginal = basename(str_replace("\\", "/", (string) $archivo["name"]));
                if ($nombreOriginal === "" || strlen($nombreOriginal) > 255) {
                    throw new RuntimeException("El nombre del archivo no es válido.");
                }

                $conexion->beginTransaction();

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($archivo["tmp_name"]);
                $extensionOriginal = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
                if (!isset($mimeExtensiones[$mime])) {
                    $officeMimes = [
                        "doc" => "application/msword",
                        "docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                        "xls" => "application/vnd.ms-excel",
                        "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                        "ppt" => "application/vnd.ms-powerpoint",
                        "pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
                    ];
                    if (!isset($officeMimes[$extensionOriginal])) {
                        throw new RuntimeException("Formato no permitido. Se admiten PDF, documentos Office, texto e imágenes JPG o PNG.");
                    }
                    $mime = $officeMimes[$extensionOriginal];
                }
                $extension = $mimeExtensiones[$mime];

                $directorio = __DIR__ . "/../storage/documentos";
                if (!is_dir($directorio) && !mkdir($directorio, 0750, true) && !is_dir($directorio)) {
                    throw new RuntimeException("No se pudo preparar el almacenamiento seguro de entregas.");
                }
                $nombreAlmacenado = bin2hex(random_bytes(32)) . "." . $extension;
                $archivoGuardado = $directorio . DIRECTORY_SEPARATOR . $nombreAlmacenado;
                if (!move_uploaded_file($archivo["tmp_name"], $archivoGuardado)) {
                    $archivoGuardado = null;
                    throw new RuntimeException("No se pudo guardar el archivo enviado.");
                }

                $stmtVersion = $conexion->prepare(
                    "SELECT COALESCE(MAX(version), 0) + 1
                     FROM entregas_asesores WHERE id_hito = :hito"
                );
                $stmtVersion->execute([":hito" => $idHito]);
                $version = (int) $stmtVersion->fetchColumn();

                $conexion->prepare(
                    "INSERT INTO entregas_asesores
                     (id_tesis, id_hito, id_asesor, version, titulo, nombre_original, nombre_almacenado,
                      mime_type, tamano_bytes, comentario_asesor, estado)
                     VALUES (:tesis, :hito, :asesor, :version, :titulo, :original, :almacenado,
                             :mime, :tamano, :comentario, 'En revisión')"
                )->execute([
                    ":tesis" => $idTesis,
                    ":hito" => $idHito,
                    ":asesor" => $idAsesor,
                    ":version" => $version,
                    ":titulo" => $titulo,
                    ":original" => $nombreOriginal,
                    ":almacenado" => $nombreAlmacenado,
                    ":mime" => $mime,
                    ":tamano" => (int) $archivo["size"],
                    ":comentario" => $comentario,
                ]);

                $conexion->prepare(
                    "UPDATE coordinacion_hitos SET estado = 'En revisión'
                     WHERE id_hito = :hito AND estado <> 'Finalizado'"
                )->execute([":hito" => $idHito]);

                $conexion->commit();
                $archivoGuardado = null;
                $mensaje = "Entrega registrada exitosamente y enviada a revisión académica.";
            }
        }

        // Consultar Proyectos de Tesis
        $sqlProyectos = "SELECT DISTINCT t.id_tesis, t.titulo, t.estado, t.fecha_entrega_estimada,
                                c.nombres AS cliente_nombres, c.apellidos AS cliente_apellidos
                         FROM proyectos_tesis t
                         INNER JOIN expedientes e ON e.id_expediente = t.id_expediente
                         INNER JOIN clientes c ON c.id_cliente = e.id_cliente
                         WHERE t.estado <> 'Finalizado'
                         ORDER BY t.id_tesis DESC LIMIT 50";
        $proyectos = $conexion->query($sqlProyectos)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Consultar Hitos de Tesis
        $sqlHitos = "SELECT h.id_hito, h.id_tesis, h.titulo, h.fecha_objetivo, h.estado, t.titulo AS proyecto,
                            c.nombres AS cliente_nombres, c.apellidos AS cliente_apellidos
                     FROM coordinacion_hitos h
                     INNER JOIN proyectos_tesis t ON t.id_tesis = h.id_tesis
                     INNER JOIN expedientes e ON e.id_expediente = t.id_expediente
                     INNER JOIN clientes c ON c.id_cliente = e.id_cliente
                     WHERE h.estado <> 'Finalizado'
                     ORDER BY h.fecha_objetivo ASC LIMIT 50";
        $hitos = $conexion->query($sqlHitos)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Consultar Trabajos de Alumnos (SENATI View)
        $sqlEntregas = "SELECT DISTINCT d.id_entrega, d.id_tesis, d.version, d.titulo, d.nombre_original, d.estado,
                                d.comentario_asesor, d.observaciones_coordinador, d.fecha_envio,
                                t.titulo AS proyecto, COALESCE(h.titulo, 'Avance general') AS etapa,
                                c.nombres AS alumno_nombres, c.apellidos AS alumno_apellidos,
                                c.telefono AS alumno_telefono, c.correo AS alumno_correo
                         FROM entregas_asesores d
                         INNER JOIN proyectos_tesis t ON t.id_tesis = d.id_tesis
                         LEFT JOIN coordinacion_hitos h ON h.id_hito = d.id_hito
                         LEFT JOIN expedientes e ON e.id_expediente = t.id_expediente
                         LEFT JOIN clientes c ON c.id_cliente = e.id_cliente
                         ORDER BY d.fecha_envio DESC
                         LIMIT 60";
        $entregas = $conexion->query($sqlEntregas)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (Throwable $e) {
        if ($conexion && $conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trabajos de Alumnos (SENATI) | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
    <style>
        .senati-header-badge {
            background: #1e3a8a;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 6px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .eval-action-form {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 6px;
        }
        .eval-select {
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-size: 0.8rem;
        }
        .eval-obs {
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-size: 0.78rem;
            width: 100%;
            box-sizing: border-box;
        }
        .btn-senati-save {
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
        }
    </style>
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <!-- Banner Institucional SENATI -->
        <div class="module-banner">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <p class="eyebrow" style="margin:0;">Área Académica & Coordinación</p>
                <span class="senati-header-badge">SENATI Portal Académico</span>
            </div>
            <h1>Revisión de Trabajos y Entregas de Alumnos</h1>
            <p>Plataforma para calificar avances de tesis, revisión de Turnitin, capítulos 1 y 2, y retroalimentación pedagógica a tesistas.</p>
        </div>

        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>

        <!-- 1. TABLA PRINCIPAL DE TRABAJOS DE ALUMNOS (ARRIBA - ESTILO SENATI) -->
        <section class="panel senati-card">
            <div class="panel-heading" style="flex-wrap: wrap; gap: 10px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                        <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                    </svg>
                    <h3>Bandeja de Trabajos Enviados por Alumnos (<?= count($entregas) ?>)</h3>
                </div>
                <span class="senati-badge">Evaluación en Línea</span>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Alumno / Tesista</th>
                            <th>Proyecto de Tesis</th>
                            <th>Etapa / Hito</th>
                            <th>Archivo Enviado</th>
                            <th>Fecha & Versión</th>
                            <th>Estado Actual</th>
                            <th style="min-width: 220px;">Evaluación Docente (SENATI)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entregas as $entrega): ?>
                        <?php
                        $claseEstado = "status-pending";
                        if ($entrega["estado"] === "Aprobado") $claseEstado = "status-approved";
                        if ($entrega["estado"] === "Observado") $claseEstado = "status-observed";
                        $nomAlumno = trim(($entrega["alumno_nombres"] ?? "Tesista") . " " . ($entrega["alumno_apellidos"] ?? ""));
                        ?>
                        <tr>
                            <td>
                                <strong>👤 <?= $escapar($nomAlumno) ?></strong>
                                <?php if (!empty($entrega["alumno_telefono"])): ?>
                                    <small>📞 <?= $escapar($entrega["alumno_telefono"]) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($entrega["alumno_correo"])): ?>
                                    <small>✉️ <?= $escapar($entrega["alumno_correo"]) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= $escapar($entrega["proyecto"]) ?></strong>
                            </td>
                            <td>
                                <span style="font-weight:700; color:#1e293b;"><?= $escapar($entrega["etapa"]) ?></span>
                                <small style="color:#64748b;"><?= $escapar($entrega["titulo"]) ?></small>
                            </td>
                            <td>
                                <a class="btn-action btn-action-primary" href="asesor_entrega_descargar.php?id=<?= (int) $entrega["id_entrega"] ?>" title="Descargar archivo">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                    Descargar
                                </a>
                                <small style="display:block; margin-top:3px; color:#64748b; font-size:0.7rem;"><?= $escapar($entrega["nombre_original"]) ?></small>
                            </td>
                            <td>
                                <span>v<?= (int) $entrega["version"] ?></span>
                                <small><?= date("d/m/Y H:i", strtotime($entrega["fecha_envio"])) ?></small>
                            </td>
                            <td>
                                <span class="<?= $claseEstado ?>" style="display:inline-block; padding:3px 8px; border-radius:6px; font-size:0.75rem;">
                                    <?= $escapar($entrega["estado"]) ?>
                                </span>
                                <?php if (!empty($entrega["observaciones_coordinador"])): ?>
                                    <small style="display:block; color:#475569; margin-top:4px;"><em>"<?= $escapar($entrega["observaciones_coordinador"]) ?>"</em></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- Formulario de Calificación Rápida Tipo SENATI -->
                                <form method="post" class="eval-action-form">
                                    <input type="hidden" name="accion" value="revisar_entrega_senati">
                                    <input type="hidden" name="id_entrega" value="<?= (int) $entrega["id_entrega"] ?>">
                                    <select name="decision" class="eval-select">
                                        <option value="Aprobado" <?= $entrega["estado"] === "Aprobado" ? "selected" : "" ?>>✅ Aprobado</option>
                                        <option value="Observado" <?= $entrega["estado"] === "Observado" ? "selected" : "" ?>>⚠️ Observado con Correcciones</option>
                                        <option value="En revisión" <?= $entrega["estado"] === "En revisión" ? "selected" : "" ?>>⏳ En revisión</option>
                                    </select>
                                    <input name="observaciones" class="eval-obs" placeholder="Observaciones o retroalimentación..." value="<?= $escapar($entrega["observaciones_coordinador"] ?? "") ?>">
                                    <button type="submit" class="btn-senati-save">Guardar Calificación</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($entregas)): ?>
                        <tr><td colspan="7" class="empty-state">No hay entregas de alumnos registradas actualmente.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- 2. FORMULARIO DE ENVÍO / SUBIDA DESPLEGABLE -->
        <details class="collapsible-card">
            <summary>
                <span class="summary-left">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span>Subir Nueva Entrega o Documento de Tesis</span>
                </span>
                <span class="summary-badge">Desplegable</span>
                <svg class="summary-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </summary>
            <div class="collapsible-body">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="subir_entrega">
                    <div class="form-grid">
                        <div class="campo">
                            <label for="id_tesis">Proyecto de Tesis *</label>
                            <select id="id_tesis" name="id_tesis" required>
                                <option value="">Selecciona un proyecto</option>
                                <?php foreach ($proyectos as $p): ?>
                                    <option value="<?= (int) $p["id_tesis"] ?>"><?= $escapar($p["titulo"]) ?> (<?= $escapar($p["cliente_nombres"] . " " . $p["cliente_apellidos"]) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="id_hito">Etapa o Capítulo *</label>
                            <select id="id_hito" name="id_hito" required>
                                <option value="">Selecciona una etapa</option>
                                <?php foreach ($hitos as $h): ?>
                                    <option value="<?= (int) $h["id_hito"] ?>" data-project="<?= (int) $h["id_tesis"] ?>">
                                        <?= $escapar($h["proyecto"] . " — " . $h["titulo"]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="titulo">Nombre / Título de la entrega *</label>
                            <input id="titulo" name="titulo" maxlength="180" placeholder="Ej.: Avance Capítulo 1 y 2 - Turnitin 12%" required>
                        </div>
                        <div class="campo">
                            <label for="archivo">Archivo adjunto (PDF, Word, Excel - Máx 15MB) *</label>
                            <input id="archivo" name="archivo" type="file" required>
                        </div>
                    </div>
                    <div class="campo" style="margin-top:12px;">
                        <label for="comentario_asesor">Comentarios u observaciones de entrega</label>
                        <textarea id="comentario_asesor" name="comentario_asesor" rows="3" placeholder="Detalles de la entrega..."></textarea>
                    </div>
                    <button class="btn-primary form-submit" type="submit" style="margin-top:12px;">Subir Entrega</button>
                </form>
            </div>
        </details>

        <!-- 3. PROYECTOS ACTIVOS DE TESIS -->
        <section class="panel">
            <div class="panel-heading">
                <h3>Proyectos Académicos en Curso (<?= count($proyectos) ?>)</h3>
            </div>
            <div class="advisor-grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px;">
                <?php foreach ($proyectos as $p): ?>
                    <article class="advisor-project" style="border:1px solid #e2e8f0; border-radius:12px; padding:16px; background:#fff;">
                        <span style="font-weight:700; color:#2563eb; font-size:0.78rem; text-transform:uppercase;"><?= $escapar($p["estado"]) ?></span>
                        <h4 style="margin:8px 0 4px;"><?= $escapar($p["titulo"]) ?></h4>
                        <p style="margin:0 0 6px; color:#475569; font-size:0.85rem;">👤 <?= $escapar(trim($p["cliente_nombres"] . " " . $p["cliente_apellidos"])) ?></p>
                        <small style="color:#64748b;">Entrega: <?= $escapar($p["fecha_entrega_estimada"] ?: "Sin fecha") ?></small>
                    </article>
                <?php endforeach; ?>
                <?php if (empty($proyectos)): ?>
                    <p class="empty-state">No hay proyectos asignados en este momento.</p>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<script>
const proyectoSelect = document.getElementById("id_tesis");
const hitoSelect = document.getElementById("id_hito");
if (proyectoSelect && hitoSelect) {
    const filtrarHitos = () => {
        Array.from(hitoSelect.options).forEach((option, index) => {
            option.hidden = index > 0 && option.dataset.project !== proyectoSelect.value;
        });
        if (hitoSelect.selectedOptions[0] && hitoSelect.selectedOptions[0].hidden) hitoSelect.value = "";
    };
    proyectoSelect.addEventListener("change", filtrarHitos);
    filtrarHitos();
}
</script>
</body>
</html>
