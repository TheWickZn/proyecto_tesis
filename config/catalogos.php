<?php

/**
 * Helper para gestión dinámica y escalable de catálogos en base de datos.
 * Evita opciones hardcodeadas en el código PHP/HTML.
 */

function obtenerCatalogo(PDO $conexion, string $tipo, bool $soloActivos = true): array
{
    try {
        $sql = "SELECT id_catalogo, tipo, codigo, nombre, color, orden, activo, es_sistema
                FROM catalogos
                WHERE tipo = :tipo" . ($soloActivos ? " AND activo = 1" : "") . "
                ORDER BY orden ASC, nombre ASC";
        $stmt = $conexion->prepare($sql);
        $stmt->execute([":tipo" => $tipo]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function asegurarOpcionEnCatalogo(PDO $conexion, string $tipo, string $nombre, ?string $color = null): string
{
    $nombre = trim($nombre);
    if ($nombre === "") {
        return "";
    }

    try {
        // Buscar si ya existe por nombre exacto (case-insensitive) o código
        $stmt = $conexion->prepare(
            "SELECT nombre FROM catalogos WHERE tipo = :tipo AND LOWER(nombre) = LOWER(:nombre) LIMIT 1"
        );
        $stmt->execute([":tipo" => $tipo, ":nombre" => $nombre]);
        $existente = $stmt->fetchColumn();
        if ($existente !== false) {
            return (string) $existente;
        }

        // Generar código limpio
        $codigoBase = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre), '_'));
        if ($codigoBase === "") {
            $codigoBase = "opcion_" . bin2hex(random_bytes(4));
        }

        $codigo = $codigoBase;
        $sufijo = 1;
        while (true) {
            $stmtCheck = $conexion->prepare(
                "SELECT 1 FROM catalogos WHERE tipo = :tipo AND codigo = :codigo LIMIT 1"
            );
            $stmtCheck->execute([":tipo" => $tipo, ":codigo" => $codigo]);
            if (!$stmtCheck->fetchColumn()) {
                break;
            }
            $sufijo++;
            $codigo = "{$codigoBase}_{$sufijo}";
        }

        // Obtener orden siguiente
        $stmtOrden = $conexion->prepare("SELECT COALESCE(MAX(orden), 0) + 1 FROM catalogos WHERE tipo = :tipo");
        $stmtOrden->execute([":tipo" => $tipo]);
        $siguienteOrden = (int) $stmtOrden->fetchColumn();

        $stmtInsert = $conexion->prepare(
            "INSERT INTO catalogos (tipo, codigo, nombre, color, orden, activo, es_sistema)
             VALUES (:tipo, :codigo, :nombre, :color, :orden, 1, 0)"
        );
        $stmtInsert->execute([
            ":tipo" => $tipo,
            ":codigo" => $codigo,
            ":nombre" => $nombre,
            ":color" => $color,
            ":orden" => $siguienteOrden,
        ]);

        return $nombre;
    } catch (Throwable $e) {
        return $nombre;
    }
}

