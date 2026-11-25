<?php

function notificarNuevoRegistroComercial(
    PDO $conexion,
    string $tipo,
    int $registroId,
    string $nombre,
    int $creadoPor,
    ?int $asignadoA = null
): void {
    $tiposPermitidos = [
        "prospecto" => "Nuevo prospecto recibido",
        "cliente" => "Nuevo cliente registrado",
    ];
    if (!isset($tiposPermitidos[$tipo]) || $registroId <= 0 || $creadoPor <= 0) {
        throw new InvalidArgumentException("No se pudo preparar la notificación del registro comercial.");
    }

    $stmt = $conexion->prepare(
        "SELECT DISTINCT u.id_usuario
         FROM usuarios u
         LEFT JOIN roles r ON r.id_rol = u.id_rol
         LEFT JOIN rol_permisos rp ON rp.id_rol = r.id_rol
         LEFT JOIN permisos p ON p.id_permiso = rp.id_permiso
         WHERE u.estado = 1
           AND u.id_usuario <> :creador
           AND (
               r.codigo = 'administrador'
               OR p.codigo = 'crm.ver_todos_prospectos'
               OR u.id_usuario = :asignado
           )"
    );
    $stmt->execute([
        ":creador" => $creadoPor,
        ":asignado" => $asignadoA,
    ]);
    $destinatarios = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$destinatarios) {
        return;
    }

    $mensaje = $tiposPermitidos[$tipo] . ": " . trim($nombre);
    $insertar = $conexion->prepare(
        "INSERT INTO notificaciones (usuario_id, tipo, registro_id, mensaje, creado_por)
         VALUES (:usuario, :tipo, :registro, :mensaje, :creador)"
    );
    foreach ($destinatarios as $destinatario) {
        $insertar->execute([
            ":usuario" => (int) $destinatario,
            ":tipo" => $tipo,
            ":registro" => $registroId,
            ":mensaje" => $mensaje,
            ":creador" => $creadoPor,
        ]);
    }
}
