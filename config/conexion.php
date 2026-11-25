<?php

require_once __DIR__ . "/migraciones.php";

$host = getenv("APP_DB_HOST") ?: "localhost";
$dbname = getenv("APP_DB_NAME") ?: "sistema_tesis";
$user = getenv("APP_DB_USER") ?: "root";
$password = getenv("APP_DB_PASSWORD") ?: "";

try {
    $conexion = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
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