<?php

function inicializarCronogramaAcademico(PDO $conexion, int $idTesis, bool $tieneAsesor = false): void
{
    $etapas = [
        ["crm_proyecto", "Proyecto académico", 1, "En proceso", 7],
        ["asignacion_asesor", "Asignación de asesor", 2, $tieneAsesor ? "En proceso" : "Pendiente de información", 14],
        ["cronograma", "Cronograma de trabajo", 3, "Pendiente", 21],
        ["entregables", "Entregables", 4, "Pendiente", 35],
        ["revision", "Revisión académica", 5, "Pendiente", 49],
        ["observaciones", "Observaciones", 6, "Pendiente", 56],
        ["correcciones", "Correcciones", 7, "Pendiente", 70],
        ["entrega_final", "Entrega final", 8, "Pendiente", 84],
    ];
    $insertar = $conexion->prepare(
        "INSERT IGNORE INTO coordinacion_hitos (id_tesis, codigo, titulo, orden, fecha_objetivo, estado)
         VALUES (:tesis, :codigo, :titulo, :orden, DATE_ADD(CURDATE(), INTERVAL :dias DAY), :estado)"
    );
    foreach ($etapas as [$codigo, $titulo, $orden, $estado, $dias]) {
        $insertar->execute([
            ":tesis" => $idTesis,
            ":codigo" => $codigo,
            ":titulo" => $titulo,
            ":orden" => $orden,
            ":dias" => $dias,
            ":estado" => $estado,
        ]);
    }
}
