<?php

function registrarComisionPorPago(PDO $conexion, int $idPago, int $idContrato, float $monto, string $fechaPago): void
{
    $stmtResponsable = $conexion->prepare(
        "SELECT c.responsable_id, u.comision_porcentaje
         FROM contratos_academicos c
         LEFT JOIN usuarios u ON u.id_usuario = c.responsable_id
         WHERE c.id_contrato = :contrato
         LIMIT 1"
    );
    $stmtResponsable->execute([":contrato" => $idContrato]);
    $responsable = $stmtResponsable->fetch();
    if (!$responsable) {
        throw new RuntimeException("No se pudo determinar el responsable comercial del contrato.");
    }

    $idUsuario = (int) ($responsable["responsable_id"] ?? 0);
    $porcentaje = (float) ($responsable["comision_porcentaje"] ?? 0);
    if ($idUsuario <= 0 || $porcentaje <= 0) {
        return;
    }

    $montoBaseCentimos = (int) round($monto * 100);
    $porcentajeCentimos = (int) round($porcentaje * 100);
    $comisionCentimos = intdiv($montoBaseCentimos * $porcentajeCentimos + 5000, 10000);
    if ($comisionCentimos <= 0) {
        return;
    }

    $conexion->prepare(
        "INSERT INTO comisiones_ventas
         (id_pago, id_usuario, monto_base, porcentaje, monto_comision, fecha_pago)
         VALUES (:pago, :usuario, :base, :porcentaje, :comision, :fecha)"
    )->execute([
        ":pago" => $idPago,
        ":usuario" => $idUsuario,
        ":base" => number_format($montoBaseCentimos / 100, 2, ".", ""),
        ":porcentaje" => number_format($porcentaje, 2, ".", ""),
        ":comision" => number_format($comisionCentimos / 100, 2, ".", ""),
        ":fecha" => $fechaPago,
    ]);
}
