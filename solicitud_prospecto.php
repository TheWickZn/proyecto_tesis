<?php
session_start();

require_once __DIR__ . "/config/conexion.php";

$fuentes = [
    "facebook" => "Facebook",
    "tiktok" => "TikTok",
    "whatsapp" => "WhatsApp",
    "instagram" => "Instagram",
    "google" => "Google",
    "referido" => "Referido",
    "otro" => "Otro",
];
$fuenteInicial = strtolower(trim((string) ($_GET["fuente"] ?? "")));
if (!isset($fuentes[$fuenteInicial])) {
    $fuenteInicial = "";
}

if (empty($_SESSION["token_solicitud_prospecto"])) {
    $_SESSION["token_solicitud_prospecto"] = bin2hex(random_bytes(32));
}

$campos = [
    "nombre_completo" => "",
    "telefono" => "",
    "correo" => "",
    "dni" => "",
    "fecha_nacimiento" => "",
    "universidad" => "",
    "carrera" => "",
    "servicio" => "",
    "fuente" => $fuentes[$fuenteInicial] ?? "",
];
$errores = [];
$servicios = [];
$campanaPublica = null;
$enviado = isset($_GET["enviado"]);

if ($conexion instanceof PDO) {
    $servicios = $conexion->query(
        "SELECT nombre FROM servicios WHERE estado = 'Activo' ORDER BY nombre"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $_SESSION["campana_solicitud_prospecto"] = (int) ($_GET["campana"] ?? 0);
    }
    $idCampanaSesion = (int) ($_SESSION["campana_solicitud_prospecto"] ?? 0);
    if ($idCampanaSesion > 0) {
        $stmtCampana = $conexion->prepare(
            "SELECT id_campana, nombre, canal FROM campanas_marketing
             WHERE id_campana = :id AND estado = 'Activa'
               AND fecha_inicio <= CURDATE() AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
             LIMIT 1"
        );
        $stmtCampana->execute([":id" => $idCampanaSesion]);
        $campanaPublica = $stmtCampana->fetch() ?: null;
        if (!$campanaPublica) {
            $_SESSION["campana_solicitud_prospecto"] = 0;
        } else {
            $campos["fuente"] = $campanaPublica["canal"];
        }
    }
} else {
    $errores[] = "El formulario no está disponible en este momento. Intenta nuevamente más tarde.";
    http_response_code(503);
}

$contarCaracteres = static function (string $valor): int {
    $cantidad = preg_match_all("/./us", $valor, $coincidencias);
    return $cantidad === false ? PHP_INT_MAX : $cantidad;
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach ($campos as $campo => $valor) {
        $campos[$campo] = trim((string) ($_POST[$campo] ?? ""));
    }

    if (!$conexion instanceof PDO) {
        $errores[] = "No se pudo guardar la solicitud porque la base de datos no está disponible.";
    } elseif (!hash_equals(
        (string) $_SESSION["token_solicitud_prospecto"],
        (string) ($_POST["token"] ?? "")
    )) {
        $errores[] = "La sesión del formulario venció. Recarga la página e inténtalo nuevamente.";
    } elseif (trim((string) ($_POST["website"] ?? "")) !== "") {
        $errores[] = "No se pudo validar la solicitud.";
    } else {
        $nombre = $campos["nombre_completo"];
        $telefono = $campos["telefono"];
        $correo = $campos["correo"];
        $dni = $campos["dni"];
        $fechaNacimiento = $campos["fecha_nacimiento"];
        $universidad = $campos["universidad"];
        $carrera = $campos["carrera"];
        $servicio = $campos["servicio"];
        $fuente = $campos["fuente"];
        $idCampana = (int) ($_SESSION["campana_solicitud_prospecto"] ?? 0) ?: null;
        if ($idCampana !== null) {
            if (!$campanaPublica || (int) $campanaPublica["id_campana"] !== $idCampana) {
                $errores[] = "La campaña de captación ya no está activa. Recarga el formulario.";
            } else {
                $fuente = $campanaPublica["canal"];
                $campos["fuente"] = $fuente;
            }
        }

        if ($nombre === "" || $contarCaracteres($nombre) > 100 || preg_match("/\\p{C}/u", $nombre)) {
            $errores[] = "Escribe tu nombre completo (máximo 100 caracteres).";
        }
        if (!preg_match("/^9[0-9]{8}$/D", $telefono)) {
            $errores[] = "El celular debe contener exactamente 9 números y comenzar con 9.";
        }
        if (
            !filter_var($correo, FILTER_VALIDATE_EMAIL)
            || !preg_match("/@(gmail|hotmail)\\.com$/iD", $correo)
            || $contarCaracteres($correo) > 150
        ) {
            $errores[] = "Ingresa un correo válido de Gmail o Hotmail.";
        }
        if (!preg_match("/^[0-9]{8}$/D", $dni)) {
            $errores[] = "El DNI debe contener exactamente 8 números.";
        }

        $fechaNacimientoValida = DateTimeImmutable::createFromFormat("!Y-m-d", $fechaNacimiento);
        $erroresFecha = DateTimeImmutable::getLastErrors();
        if (
            !$fechaNacimientoValida
            || ($erroresFecha !== false && ($erroresFecha["warning_count"] > 0 || $erroresFecha["error_count"] > 0))
            || $fechaNacimientoValida->format("Y-m-d") !== $fechaNacimiento
            || $fechaNacimientoValida > new DateTimeImmutable("today")
        ) {
            $errores[] = "Selecciona una fecha de nacimiento válida que no sea futura.";
        }
        if ($universidad === "" || $contarCaracteres($universidad) > 180) {
            $errores[] = "Indica tu universidad o institución (máximo 180 caracteres).";
        }
        if ($carrera === "" || $contarCaracteres($carrera) > 150) {
            $errores[] = "Indica tu carrera o especialidad (máximo 150 caracteres).";
        }
        if ($servicio === "" || $contarCaracteres($servicio) > 150) {
            $errores[] = "Selecciona o escribe el servicio que necesitas (máximo 150 caracteres).";
        }
        if (!in_array($fuente, array_values($fuentes), true)) {
            $errores[] = "Selecciona el canal por el que llegaste.";
        }
        if (empty($_POST["consentimiento"])) {
            $errores[] = "Debes autorizar el contacto relacionado con tu solicitud.";
        }
    }

    if (!$errores && $conexion instanceof PDO) {
        try {
            $conexion->beginTransaction();

            $vendedores = $conexion->query(
                "SELECT u.id_usuario
                 FROM usuarios u
                 INNER JOIN roles r ON r.id_rol = u.id_rol
                 WHERE u.estado = 1 AND r.codigo IN ('ventas', 'vendedor')
                 ORDER BY u.id_usuario
                 FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);
            $vendedores = array_map("intval", $vendedores);
            if (!$vendedores) {
                throw new RuntimeException("No hay vendedores activos para asignar la solicitud.");
            }

            $marcadores = implode(",", array_fill(0, count($vendedores), "?"));
            $ultimoAsignado = $conexion->prepare(
                "SELECT vendedor_id FROM prospectos
                 WHERE vendedor_id IN ({$marcadores})
                 ORDER BY id_prospecto DESC LIMIT 1"
            );
            $ultimoAsignado->execute($vendedores);
            $ultimoId = $ultimoAsignado->fetchColumn();
            $posicion = $ultimoId === false ? false : array_search((int) $ultimoId, $vendedores, true);
            $vendedorAsignado = $posicion === false
                ? $vendedores[0]
                : $vendedores[($posicion + 1) % count($vendedores)];

            $insertar = $conexion->prepare(
                "INSERT INTO prospectos
                 (nombres, apellidos, telefono, dni, fecha_nacimiento, correo, universidad, carrera,
                  servicio, fuente, id_campana, vendedor_id, estado, proxima_accion, creado_por)
                 VALUES (:nombres, NULL, :telefono, :dni, :fecha_nacimiento, :correo, :universidad, :carrera,
                         :servicio, :fuente, :campana, :vendedor_id, 'Nuevo', NULL, NULL)"
            );
            $insertar->execute([
                ":nombres" => $nombre,
                ":telefono" => $telefono,
                ":dni" => $dni,
                ":fecha_nacimiento" => $fechaNacimiento,
                ":correo" => $correo,
                ":universidad" => $universidad,
                ":carrera" => $carrera,
                ":servicio" => $servicio,
                ":fuente" => $fuente,
                ":campana" => $idCampana,
                ":vendedor_id" => $vendedorAsignado,
            ]);
            $idProspecto = (int) $conexion->lastInsertId();

            $conexion->prepare(
                "INSERT INTO seguimientos_prospectos (id_prospecto, usuario_id, fecha, accion, observaciones)
                 VALUES (:id, NULL, CURDATE(), 'Solicitud recibida por formulario externo', :observaciones)"
            )->execute([
                ":id" => $idProspecto,
                ":observaciones" => "Canal: {$fuente}. Autorización de contacto aceptada.",
            ]);

            $conexion->prepare(
                "INSERT INTO notificaciones (usuario_id, tipo, registro_id, mensaje, creado_por)
                 VALUES (:usuario, 'prospecto', :registro, 'Nuevo prospecto asignado automáticamente', NULL)"
            )->execute([
                ":usuario" => $vendedorAsignado,
                ":registro" => $idProspecto,
            ]);

            $conexion->commit();
            unset($_SESSION["token_solicitud_prospecto"]);
            unset($_SESSION["campana_solicitud_prospecto"]);
            header("Location: solicitud_prospecto.php?enviado=1");
            exit;
        } catch (Throwable $e) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log("Error al registrar solicitud pública de prospecto: " . $e->getMessage());
            $errores[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : "No se pudo guardar la solicitud. Intenta nuevamente más tarde.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $errores) {
    http_response_code($conexion instanceof PDO ? 422 : 503);
}

$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitud de asesoría | GROUP_CEA</title>
    <link rel="stylesheet" href="css/estilos.css">
    <style>
        body.solicitud-publica { min-height: 100vh; padding: 32px 16px; }
        .solicitud-wrap { width: min(760px, 100%); margin: 0 auto; }
        .solicitud-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 20px; box-shadow: 0 18px 48px rgba(15, 27, 61, .1); padding: 30px; }
        .solicitud-card h1 { margin: 0 0 8px; }
        .solicitud-intro { color: #64748b; margin: 0 0 24px; }
        .solicitud-consent { display: flex; align-items: flex-start; gap: 10px; margin: 18px 0; color: #475569; }
        .solicitud-consent input { margin-top: 5px; }
        .solicitud-honeypot { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        .solicitud-errors { border: 1px solid #fecaca; border-radius: 12px; background: #fef2f2; color: #991b1b; padding: 14px 18px; margin-bottom: 20px; }
        .solicitud-errors ul { margin: 6px 0 0; padding-left: 20px; }
        .solicitud-success { border: 1px solid #86efac; border-radius: 12px; background: #f0fdf4; color: #166534; padding: 16px 18px; margin-bottom: 20px; }
        .solicitud-footer { color: #64748b; font-size: .85rem; margin-top: 18px; text-align: center; }
        @media (max-width: 600px) { .solicitud-card { padding: 22px 18px; } }
    </style>
</head>
<body class="auth-body solicitud-publica">
<main class="solicitud-wrap">
    <section class="solicitud-card">
        <p class="eyebrow">GROUP_CEA · Atención académica</p>
        <h1>Cuéntanos qué necesitas</h1>
        <p class="solicitud-intro">Completa tus datos y un asesor comercial se comunicará contigo para orientarte.</p>

        <?php if ($enviado): ?>
            <div class="solicitud-success" role="status">
                Recibimos tu solicitud. Un asesor se pondrá en contacto contigo.
            </div>
        <?php endif; ?>

        <?php if ($errores): ?>
            <div class="solicitud-errors" role="alert">
                <strong>No se pudo enviar la solicitud:</strong>
                <ul><?php foreach ($errores as $error): ?><li><?= $escapar($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="post" class="data-form">
            <input type="hidden" name="token" value="<?= $escapar($_SESSION["token_solicitud_prospecto"]) ?>">
            <div class="solicitud-honeypot" aria-hidden="true">
                <label for="website">No completar</label>
                <input id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="campo">
                <label for="nombre_completo">Nombre completo *</label>
                <input id="nombre_completo" name="nombre_completo" maxlength="100" autocomplete="name" required value="<?= $escapar($campos["nombre_completo"]) ?>">
            </div>

            <div class="form-grid">
                <div class="campo">
                    <label for="telefono">Celular peruano *</label>
                    <input id="telefono" name="telefono" type="tel" inputmode="numeric" pattern="9[0-9]{8}" maxlength="9" minlength="9" autocomplete="tel" placeholder="9XXXXXXXX" required value="<?= $escapar($campos["telefono"]) ?>">
                    <small>9 números; debe empezar con 9.</small>
                </div>
                <div class="campo">
                    <label for="correo">Correo Gmail o Hotmail *</label>
                    <input id="correo" name="correo" type="email" maxlength="150" autocomplete="email" placeholder="nombre@gmail.com" required value="<?= $escapar($campos["correo"]) ?>">
                </div>
                <div class="campo">
                    <label for="dni">DNI peruano *</label>
                    <input id="dni" name="dni" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" minlength="8" autocomplete="off" required value="<?= $escapar($campos["dni"]) ?>">
                    <small>Solo números, exactamente 8 dígitos.</small>
                </div>
                <div class="campo">
                    <label for="fecha_nacimiento">Fecha de nacimiento *</label>
                    <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" max="<?= date("Y-m-d") ?>" required value="<?= $escapar($campos["fecha_nacimiento"]) ?>">
                </div>
                <div class="campo">
                    <label for="universidad">Universidad o institución *</label>
                    <input id="universidad" name="universidad" maxlength="180" required value="<?= $escapar($campos["universidad"]) ?>">
                </div>
                <div class="campo">
                    <label for="carrera">Carrera o especialidad *</label>
                    <input id="carrera" name="carrera" maxlength="150" required value="<?= $escapar($campos["carrera"]) ?>">
                </div>
                <div class="campo">
                    <label for="servicio">Servicio que necesitas *</label>
                    <input id="servicio" name="servicio" list="servicios-disponibles" maxlength="150" required value="<?= $escapar($campos["servicio"]) ?>">
                    <datalist id="servicios-disponibles">
                        <?php foreach ($servicios as $servicio): ?><option value="<?= $escapar($servicio) ?>"></option><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="campo">
                    <?php if ($campanaPublica): ?>
                        <label>Campaña de captación</label>
                        <input value="<?= $escapar($campanaPublica["nombre"] . " · " . $campanaPublica["canal"]) ?>" readonly>
                        <input type="hidden" name="fuente" value="<?= $escapar($campanaPublica["canal"]) ?>">
                    <?php else: ?>
                        <label for="fuente">¿Por dónde nos encontraste? *</label>
                        <select id="fuente" name="fuente" required>
                            <option value="">Selecciona un canal</option>
                            <?php foreach ($fuentes as $codigo => $nombreFuente): ?>
                                <option value="<?= $escapar($nombreFuente) ?>" <?= $campos["fuente"] === $nombreFuente ? "selected" : "" ?>><?= $escapar($nombreFuente) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <label class="solicitud-consent">
                <input type="checkbox" name="consentimiento" value="1" required>
                <span>Autorizo que GROUP_CEA use estos datos para responder esta solicitud y ponerse en contacto conmigo.</span>
            </label>

            <button type="submit" class="btn-primary form-submit">Enviar solicitud</button>
            <p class="solicitud-footer">La solicitud se asignará automáticamente a un asesor.</p>
        </form>
    </section>
</main>
</body>
</html>
