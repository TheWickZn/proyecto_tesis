<?php

$configuracionHosting = [];
$archivoConfiguracionHosting = __DIR__ . "/hosting.local.php";
if (is_file($archivoConfiguracionHosting)) {
    $configuracionHosting = require $archivoConfiguracionHosting;
    if (!is_array($configuracionHosting)) {
        throw new UnexpectedValueException("La configuración local del hosting debe retornar un arreglo.");
    }
}
$GLOBALS["configuracionHosting"] = $configuracionHosting;

require_once __DIR__ . "/migraciones.php";

$host = getenv("APP_DB_HOST") ?: ($configuracionHosting["APP_DB_HOST"] ?? (getenv("MYSQLHOST") ?: "localhost"));
$port = getenv("APP_DB_PORT") ?: ($configuracionHosting["APP_DB_PORT"] ?? (getenv("MYSQLPORT") ?: "3306"));
$dbname = getenv("APP_DB_NAME") ?: ($configuracionHosting["APP_DB_NAME"] ?? (getenv("MYSQLDATABASE") ?: "sistema_tesis"));
$user = getenv("APP_DB_USER") ?: ($configuracionHosting["APP_DB_USER"] ?? (getenv("MYSQLUSER") ?: "root"));
$password = getenv("APP_DB_PASSWORD") ?: ($configuracionHosting["APP_DB_PASSWORD"] ?? (getenv("MYSQLPASSWORD") ?: ""));

try {
    $conexion = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    ejecutarMigraciones($conexion);
} catch (Throwable $e) {
    $conexion = null;
}

function tablaExiste(PDO $conexion, string $nombreTabla): bool
{
    try {
        $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :tabla LIMIT 1";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([":tabla" => $nombreTabla]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
?>