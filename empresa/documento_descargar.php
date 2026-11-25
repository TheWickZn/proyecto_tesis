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
exigirPermiso($conexion, "documentos.gestionar");
$idDocumento = (int) ($_GET["id"] ?? 0);
$stmt = $conexion->prepare(
    "SELECT nombre_original, nombre_almacenado, mime_type, id_contrato_academico
     FROM documentos_empresa WHERE id_documento = :id AND estado = 1"
);
$stmt->execute([":id" => $idDocumento]);
$documento = $stmt->fetch();
if (!$documento || !preg_match('/^[a-f0-9]{64}\.(pdf|jpg|jpeg|png|doc|docx|xls|xlsx|ppt|pptx|txt)$/i', $documento["nombre_almacenado"])) {
    http_response_code(404);
    exit("El documento no existe.");
}
if (
    $documento["id_contrato_academico"] !== null
    && !usuarioTieneAlgunPermiso($conexion, ["contratos.ver", "contratos.gestionar", "finanzas.ver", "finanzas.gestionar", "legal.gestionar"])
) {
    http_response_code(403);
    exit("No tienes permiso para acceder a este documento contractual.");
}

$ruta = __DIR__ . "/../storage/documentos/" . $documento["nombre_almacenado"];
if (!is_file($ruta) || !is_readable($ruta)) {
    http_response_code(404);
    exit("El archivo no está disponible en el almacenamiento.");
}

$tiposPermitidos = [
    "application/pdf",
    "image/jpeg",
    "image/png",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.ms-excel",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.ms-powerpoint",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "text/plain",
    "application/octet-stream"
];

$mime = $documento["mime_type"] ?: "application/octet-stream";

header("Content-Type: " . $mime);
header("Content-Length: " . (string) filesize($ruta));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($documento["nombre_original"]));
header("X-Content-Type-Options: nosniff");
readfile($ruta);
exit;
