<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
if (!$conexion) {
    http_response_code(503);
    exit("No se pudo conectar con la base de datos.");
}
exigirPermiso($conexion, "legal.gestionar");

$idDocumento = (int) ($_GET["id"] ?? 0);
$stmt = $conexion->prepare(
    "SELECT nombre_original, nombre_almacenado, mime_type FROM documentos_legales WHERE id_documento = :id"
);
$stmt->execute([":id" => $idDocumento]);
$documento = $stmt->fetch();
if (!$documento || !preg_match('/^[a-f0-9]{64}$/', $documento["nombre_almacenado"])) {
    http_response_code(404);
    exit("El documento legal no existe.");
}

$ruta = __DIR__ . "/../storage/legal/" . $documento["nombre_almacenado"];
if (!is_file($ruta) || !is_readable($ruta)) {
    http_response_code(404);
    exit("El archivo no está disponible en el almacenamiento.");
}
if (!in_array($documento["mime_type"], ["application/pdf", "image/jpeg", "image/png"], true)) {
    http_response_code(415);
    exit("El formato del archivo no está permitido.");
}

header("Content-Type: " . $documento["mime_type"]);
header("Content-Length: " . (string) filesize($ruta));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($documento["nombre_original"]));
header("X-Content-Type-Options: nosniff");
readfile($ruta);
