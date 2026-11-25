<?php

function usuarioTienePermiso(PDO $conexion, string $permiso): bool
{
    if (!isset($_SESSION["id_usuario"])) {
        return false;
    }

    try {
        $stmtAdministrador = $conexion->prepare(
            "SELECT 1
             FROM usuarios u
             INNER JOIN roles r ON r.id_rol = u.id_rol
             WHERE u.id_usuario = :usuario
               AND u.estado = 1
               AND r.codigo = 'administrador'
             LIMIT 1"
        );
        $stmtAdministrador->execute([":usuario" => (int) $_SESSION["id_usuario"]]);
        if ($stmtAdministrador->fetchColumn()) {
            return true;
        }

        $sql = "SELECT 1
                FROM usuarios u
                INNER JOIN roles r ON r.id_rol = u.id_rol
                INNER JOIN rol_permisos rp ON rp.id_rol = r.id_rol
                INNER JOIN permisos p ON p.id_permiso = rp.id_permiso
                WHERE u.id_usuario = :usuario
                  AND u.estado = 1
                  AND p.codigo = :permiso
                LIMIT 1";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([
            ":usuario" => $_SESSION["id_usuario"],
            ":permiso" => $permiso,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function usuarioTieneAlgunPermiso(PDO $conexion, array $permisos): bool
{
    foreach ($permisos as $permiso) {
        if (usuarioTienePermiso($conexion, $permiso)) {
            return true;
        }
    }

    return false;
}

function exigirPermiso(PDO $conexion, string $permiso): void
{
    if (!usuarioTienePermiso($conexion, $permiso)) {
        http_response_code(403);
        exit("No tienes permisos para acceder a este módulo.");
    }
}

function exigirAlgunPermiso(PDO $conexion, array $permisos): void
{
    if (!usuarioTieneAlgunPermiso($conexion, $permisos)) {
        http_response_code(403);
        exit("No tienes permisos para acceder a este módulo.");
    }
}
