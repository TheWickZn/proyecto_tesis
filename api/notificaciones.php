<?php

session_start();
require_once __DIR__ . "/../config/conexion.php";
require_once __DIR__ . "/../config/seguridad.php";

header("Content-Type: application/json; charset=utf-8");

function responderNotificaciones(array $datos, int $estado = 200): void
{
    http_response_code($estado);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION["id_usuario"])) {
    responderNotificaciones(["error" => "Inicia sesión para consultar tus notificaciones."], 401);
}
if (!$conexion) {
    responderNotificaciones(["error" => "No hay conexión a la base de datos."], 500);
}
$permisosNotificaciones = ["crm.gestionar", "tesis.gestionar", "asesor.trabajo", "dashboard.gerencial"];
if (!usuarioTieneAlgunPermiso($conexion, $permisosNotificaciones)) {
    responderNotificaciones(["error" => "No tienes permiso para consultar notificaciones."], 403);
}

$usuario = (int) $_SESSION["id_usuario"];
$puedeCoordinar = usuarioTienePermiso($conexion, "tesis.gestionar");
$puedeTrabajarComoAsesor = usuarioTienePermiso($conexion, "asesor.trabajo");
$urlNotificacion = static function (array $notificacion) use ($puedeCoordinar, $puedeTrabajarComoAsesor): string {
    if ($notificacion["tipo"] === "proyecto_academico") {
        if ($puedeCoordinar) {
            return "../empresa/gestion_academica.php?proyecto=" . (int) $notificacion["registro_id"];
        }
        if ($puedeTrabajarComoAsesor) {
            $idProyecto = (int) $notificacion["registro_id"];
            return "../empresa/asesor.php?proyecto=" . $idProyecto . "#proyecto-" . $idProyecto;
        }
        return "../empresa/dashboard_gerencial.php";
    }
    $pagina = $notificacion["tipo"] === "prospecto" ? "prospectos.php" : "clientes.php";
    return "../empresa/" . $pagina . "?editar=" . (int) $notificacion["registro_id"];
};
$accion = $_GET["action"] ?? $_POST["action"] ?? "listar";

try {
    if ($_SERVER["REQUEST_METHOD"] === "POST" && $accion === "leer") {
        $id = (int) ($_POST["id"] ?? 0);
        if ($id <= 0) {
            responderNotificaciones(["error" => "Selecciona una notificación válida."], 422);
        }

        $stmt = $conexion->prepare(
            "UPDATE notificaciones SET leida_en = CURRENT_TIMESTAMP
             WHERE id_notificacion = :id AND usuario_id = :usuario AND leida_en IS NULL"
        );
        $stmt->execute([":id" => $id, ":usuario" => $usuario]);
        responderNotificaciones(["success" => true]);
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && $accion === "leer_todas") {
        $stmt = $conexion->prepare(
            "UPDATE notificaciones SET leida_en = CURRENT_TIMESTAMP
             WHERE usuario_id = :usuario AND leida_en IS NULL"
        );
        $stmt->execute([":usuario" => $usuario]);
        responderNotificaciones(["success" => true]);
    }

    if ($_SERVER["REQUEST_METHOD"] !== "GET" || $accion !== "listar") {
        responderNotificaciones(["error" => "Acción no soportada."], 405);
    }

    $stmt = $conexion->prepare(
        "SELECT id_notificacion, tipo, registro_id, mensaje, fecha_registro, leida_en
         FROM notificaciones
         WHERE usuario_id = :usuario
         ORDER BY id_notificacion DESC
         LIMIT 12"
    );
    $stmt->execute([":usuario" => $usuario]);
    $notificaciones = $stmt->fetchAll();
    foreach ($notificaciones as &$notificacion) {
        $notificacion["url"] = $urlNotificacion($notificacion);
    }
    unset($notificacion);

    $stmt = $conexion->prepare(
        "SELECT COUNT(*) FROM notificaciones WHERE usuario_id = :usuario AND leida_en IS NULL"
    );
    $stmt->execute([":usuario" => $usuario]);
    $sinLeer = (int) $stmt->fetchColumn();

    $desde = max(0, (int) ($_GET["desde"] ?? 0));
    $stmt = $conexion->prepare(
        "SELECT id_notificacion, tipo, registro_id, mensaje, fecha_registro
         FROM notificaciones
         WHERE usuario_id = :usuario AND id_notificacion > :desde
         ORDER BY id_notificacion ASC
         LIMIT 50"
    );
    $stmt->execute([":usuario" => $usuario, ":desde" => $desde]);
    $nuevas = $stmt->fetchAll();
    foreach ($nuevas as &$notificacion) {
        $notificacion["url"] = $urlNotificacion($notificacion);
    }
    unset($notificacion);

    responderNotificaciones([
        "sin_leer" => $sinLeer,
        "notificaciones" => $notificaciones,
        "nuevas" => $nuevas,
    ]);
} catch (Throwable $e) {
    responderNotificaciones(["error" => "No se pudieron cargar las notificaciones."], 500);
}
