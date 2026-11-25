<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    http_response_code(401);
    exit("Inicia sesión para descargar este archivo.");
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";

if (!$conexion) {
    http_response_code(503);
    exit("No se pudo conectar con la base de datos.");
}

$usuario = (int) $_SESSION["id_usuario"];
$puedeCoordinar = usuarioTienePermiso($conexion, "tesis.gestionar");
$puedeTrabajarComoAsesor = usuarioTienePermiso($conexion, "asesor.trabajo");
if (!$puedeCoordinar && !$puedeTrabajarComoAsesor) {
    http_response_code(403);
    exit("No tienes permiso para descargar entregas académicas.");
}

$idEntrega = (int) ($_GET["id"] ?? 0);
if ($idEntrega <= 0) {
    http_response_code(404);
    exit("La entrega solicitada no existe.");
}

try {
    $sql = "SELECT d.nombre_original, d.nombre_almacenado, d.mime_type, d.id_asesor
            FROM entregas_asesores d";
    if (!$puedeCoordinar) {
        $sql .= " INNER JOIN asesores_academicos a ON a.id_asesor = d.id_asesor
                  WHERE a.id_usuario = :usuario AND d.id_entrega = :entrega";
    } else {
        $sql .= " WHERE d.id_entrega = :entrega";
    }
    $stmt = $conexion->prepare($sql);
    $parametros = [":entrega" => $idEntrega];
    if (!$puedeCoordinar) {
        $parametros[":usuario"] = $usuario;
    }
    $stmt->execute($parametros);
    $entrega = $stmt->fetch();
    if (!$entrega) {
        http_response_code(404);
        exit("La entrega no existe o no tienes acceso.");
    }

    $directorio = realpath(__DIR__ . "/../storage/documentos");
    $rutaArchivo = $directorio === false
        ? false
        : realpath($directorio . DIRECTORY_SEPARATOR . basename($entrega["nombre_almacenado"]));
    if ($rutaArchivo === false || strpos($rutaArchivo, $directorio . DIRECTORY_SEPARATOR) !== 0 || !is_file($rutaArchivo)) {
        http_response_code(404);
        exit("El archivo de la entrega no está disponible.");
    }

    $nombre = str_replace(["\r", "\n", "\""], "", basename($entrega["nombre_original"]));
    header("Content-Type: " . $entrega["mime_type"]);
    header("Content-Length: " . (string) filesize($rutaArchivo));
    header("X-Content-Type-Options: nosniff");
    header("Content-Disposition: attachment; filename=\"archivo-entrega\"; filename*=UTF-8''" . rawurlencode($nombre));
    header("Cache-Control: private, no-store");
    readfile($rutaArchivo);
} catch (Throwable $e) {
    error_log("Error al descargar entrega académica: " . $e->getMessage());
    http_response_code(500);
    exit("No se pudo descargar el archivo.");
}
