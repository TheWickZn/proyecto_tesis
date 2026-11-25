<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
if ($conexion) {
    exigirPermiso($conexion, "empresa.gestionar");
}

$mensaje = "";
$error = "";
$perfil = [];
$areas = [];
$cargos = [];
$trabajadores = [];
$usuarios = [];
$trabajadorEditar = null;
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";
            if ($accion === "guardar_perfil") {
                $datos = [
                    ":razon_social" => trim($_POST["razon_social"] ?? ""),
                    ":nombre_comercial" => trim($_POST["nombre_comercial"] ?? "") ?: null,
                    ":ruc" => trim($_POST["ruc"] ?? ""),
                    ":direccion" => trim($_POST["direccion"] ?? "") ?: null,
                    ":telefono" => trim($_POST["telefono"] ?? "") ?: null,
                    ":correo" => trim($_POST["correo"] ?? "") ?: null,
                    ":representante_legal" => trim($_POST["representante_legal"] ?? "") ?: null,
                    ":actualizado_por" => (int) $_SESSION["id_usuario"],
                ];
                if ($datos[":razon_social"] === "" || !preg_match('/^[0-9]{11}$/', $datos[":ruc"])) {
                    throw new RuntimeException("La razón social es obligatoria y el RUC debe tener 11 dígitos.");
                }
                if ($datos[":telefono"] !== null && !preg_match('/^9[0-9]{8}$/', $datos[":telefono"])) {
                    throw new RuntimeException("El celular debe tener 9 dígitos y comenzar con 9.");
                }
                if ($datos[":correo"] !== null && !filter_var($datos[":correo"], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException("Ingresa un correo empresarial válido.");
                }
                $conexion->prepare(
                    "INSERT INTO empresa_perfil
                     (id_perfil, razon_social, nombre_comercial, ruc, direccion, telefono, correo, representante_legal, actualizado_por)
                     VALUES (1, :razon_social, :nombre_comercial, :ruc, :direccion, :telefono, :correo, :representante_legal, :actualizado_por)
                     ON DUPLICATE KEY UPDATE razon_social = VALUES(razon_social), nombre_comercial = VALUES(nombre_comercial),
                     ruc = VALUES(ruc), direccion = VALUES(direccion), telefono = VALUES(telefono), correo = VALUES(correo),
                     representante_legal = VALUES(representante_legal), actualizado_por = VALUES(actualizado_por)"
                )->execute($datos);
                $mensaje = "Perfil empresarial guardado correctamente.";
            } elseif ($accion === "guardar_area") {
                $nombre = trim($_POST["nombre_area"] ?? "");
                if ($nombre === "") {
                    throw new RuntimeException("El nombre del área es obligatorio.");
                }
                $conexion->prepare(
                    "INSERT INTO empresa_areas (nombre, descripcion) VALUES (:nombre, :descripcion)"
                )->execute([
                    ":nombre" => $nombre,
                    ":descripcion" => trim($_POST["descripcion_area"] ?? "") ?: null,
                ]);
                $mensaje = "Área creada correctamente.";
            } elseif ($accion === "estado_area") {
                $idArea = (int) ($_POST["id_area"] ?? 0);
                $estado = (int) ($_POST["estado"] ?? -1);
                if ($idArea <= 0 || !in_array($estado, [0, 1], true)) {
                    throw new RuntimeException("Selecciona un área y estado válidos.");
                }
                $conexion->prepare("UPDATE empresa_areas SET estado = :estado WHERE id_area = :id")
                    ->execute([":estado" => $estado, ":id" => $idArea]);
                $mensaje = "Estado del área actualizado.";
            } elseif ($accion === "guardar_cargo") {
                $idArea = (int) ($_POST["id_area_cargo"] ?? 0);
                $nombre = trim($_POST["nombre_cargo"] ?? "");
                if ($idArea <= 0 || $nombre === "") {
                    throw new RuntimeException("Selecciona un área activa e indica el nombre del cargo.");
                }
                $stmtArea = $conexion->prepare("SELECT 1 FROM empresa_areas WHERE id_area = :id AND estado = 1");
                $stmtArea->execute([":id" => $idArea]);
                if (!$stmtArea->fetchColumn()) {
                    throw new RuntimeException("El área seleccionada no existe o está inactiva.");
                }
                $conexion->prepare(
                    "INSERT INTO empresa_cargos (id_area, nombre, descripcion) VALUES (:area, :nombre, :descripcion)"
                )->execute([
                    ":area" => $idArea,
                    ":nombre" => $nombre,
                    ":descripcion" => trim($_POST["descripcion_cargo"] ?? "") ?: null,
                ]);
                $mensaje = "Cargo creado correctamente.";
            } elseif ($accion === "estado_cargo") {
                $idCargo = (int) ($_POST["id_cargo"] ?? 0);
                $estado = (int) ($_POST["estado"] ?? -1);
                if ($idCargo <= 0 || !in_array($estado, [0, 1], true)) {
                    throw new RuntimeException("Selecciona un cargo y estado válidos.");
                }
                $conexion->prepare("UPDATE empresa_cargos SET estado = :estado WHERE id_cargo = :id")
                    ->execute([":estado" => $estado, ":id" => $idCargo]);
                $mensaje = "Estado del cargo actualizado.";
            } elseif ($accion === "guardar_trabajador") {
                $idTrabajador = (int) ($_POST["id_trabajador"] ?? 0);
                $idArea = (int) ($_POST["id_area_trabajador"] ?? 0);
                $idCargo = (int) ($_POST["id_cargo_trabajador"] ?? 0);
                $usuarioId = (int) ($_POST["usuario_id"] ?? 0) ?: null;
                $nombres = trim($_POST["nombres_trabajador"] ?? "");
                $apellidos = trim($_POST["apellidos_trabajador"] ?? "");
                $documento = trim($_POST["documento_trabajador"] ?? "");
                $telefono = trim($_POST["telefono_trabajador"] ?? "") ?: null;
                $correo = trim($_POST["correo_trabajador"] ?? "") ?: null;
                $fechaIngreso = trim($_POST["fecha_ingreso"] ?? "");
                $fecha = DateTime::createFromFormat("!Y-m-d", $fechaIngreso);
                if ($nombres === "" || $apellidos === "" || !preg_match('/^[0-9]{8}$/', $documento) ||
                    ($telefono !== null && !preg_match('/^9[0-9]{8}$/', $telefono)) ||
                    ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) ||
                    $idArea <= 0 || $idCargo <= 0 || !$fecha || $fecha->format("Y-m-d") !== $fechaIngreso) {
                    throw new RuntimeException("Completa nombres, DNI de 8 dígitos, área, cargo y una fecha de ingreso válida.");
                }
                $stmtCargo = $conexion->prepare(
                    "SELECT 1 FROM empresa_cargos c
                     INNER JOIN empresa_areas a ON a.id_area = c.id_area
                     WHERE c.id_cargo = :cargo AND c.id_area = :area AND c.estado = 1 AND a.estado = 1"
                );
                $stmtCargo->execute([":cargo" => $idCargo, ":area" => $idArea]);
                if (!$stmtCargo->fetchColumn()) {
                    throw new RuntimeException("El cargo debe pertenecer al área activa seleccionada.");
                }
                if ($usuarioId !== null) {
                    $stmtUsuario = $conexion->prepare(
                        "SELECT 1 FROM usuarios u
                         WHERE u.id_usuario = :usuario AND u.estado = 1
                           AND NOT EXISTS (
                               SELECT 1 FROM empresa_trabajadores t
                               WHERE t.usuario_id = u.id_usuario AND t.id_trabajador <> :trabajador
                           )"
                    );
                    $stmtUsuario->execute([":usuario" => $usuarioId, ":trabajador" => $idTrabajador]);
                    if (!$stmtUsuario->fetchColumn()) {
                        throw new RuntimeException("La cuenta seleccionada no existe, está inactiva o ya pertenece a otro trabajador.");
                    }
                }
                $datosTrabajador = [
                    ":nombres" => $nombres,
                    ":apellidos" => $apellidos,
                    ":documento" => $documento,
                    ":telefono" => $telefono,
                    ":correo" => $correo,
                    ":area" => $idArea,
                    ":cargo" => $idCargo,
                    ":usuario" => $usuarioId,
                    ":fecha" => $fechaIngreso,
                ];
                if ($idTrabajador > 0) {
                    $datosTrabajador[":id"] = $idTrabajador;
                    $conexion->prepare(
                        "UPDATE empresa_trabajadores SET nombres = :nombres, apellidos = :apellidos,
                         documento = :documento, telefono = :telefono, correo = :correo, id_area = :area,
                         id_cargo = :cargo, usuario_id = :usuario, fecha_ingreso = :fecha WHERE id_trabajador = :id"
                    )->execute($datosTrabajador);
                    $mensaje = "Datos del trabajador actualizados correctamente.";
                } else {
                    $conexion->prepare(
                        "INSERT INTO empresa_trabajadores
                         (nombres, apellidos, documento, telefono, correo, id_area, id_cargo, usuario_id, fecha_ingreso)
                         VALUES (:nombres, :apellidos, :documento, :telefono, :correo, :area, :cargo, :usuario, :fecha)"
                    )->execute($datosTrabajador);
                    $mensaje = "Trabajador registrado correctamente.";
                }
            } elseif ($accion === "estado_trabajador") {
                $idTrabajador = (int) ($_POST["id_trabajador"] ?? 0);
                $estado = (int) ($_POST["estado"] ?? -1);
                if ($idTrabajador <= 0 || !in_array($estado, [0, 1], true)) {
                    throw new RuntimeException("Selecciona un trabajador y estado válidos.");
                }
                $conexion->prepare("UPDATE empresa_trabajadores SET estado = :estado WHERE id_trabajador = :id")
                    ->execute([":estado" => $estado, ":id" => $idTrabajador]);
                $mensaje = "Estado del trabajador actualizado.";
            }
        }

        if (isset($_GET["editar_trabajador"])) {
            $stmtTrabajador = $conexion->prepare("SELECT * FROM empresa_trabajadores WHERE id_trabajador = :id");
            $stmtTrabajador->execute([":id" => (int) $_GET["editar_trabajador"]]);
            $trabajadorEditar = $stmtTrabajador->fetch() ?: null;
        }
        $perfil = $conexion->query("SELECT * FROM empresa_perfil WHERE id_perfil = 1")->fetch() ?: [];
        $areas = $conexion->query(
            "SELECT a.*, COUNT(c.id_cargo) AS total_cargos
             FROM empresa_areas a LEFT JOIN empresa_cargos c ON c.id_area = a.id_area
             GROUP BY a.id_area ORDER BY a.nombre"
        )->fetchAll();
        $cargos = $conexion->query(
            "SELECT c.*, a.nombre AS area FROM empresa_cargos c
             INNER JOIN empresa_areas a ON a.id_area = c.id_area ORDER BY a.nombre, c.nombre"
        )->fetchAll();
        $usuarios = $conexion->query(
            "SELECT u.id_usuario, u.nombres, u.apellidos FROM usuarios u
             WHERE u.estado = 1 AND NOT EXISTS (
                 SELECT 1 FROM empresa_trabajadores t
                 WHERE t.usuario_id = u.id_usuario AND t.id_trabajador <> " . (int) ($trabajadorEditar["id_trabajador"] ?? 0) . "
             ) ORDER BY u.nombres, u.apellidos"
        )->fetchAll();
        $trabajadores = $conexion->query(
            "SELECT t.*, a.nombre AS area, c.nombre AS cargo,
                    CONCAT_WS(' ', u.nombres, u.apellidos) AS usuario
             FROM empresa_trabajadores t
             INNER JOIN empresa_areas a ON a.id_area = t.id_area
             INNER JOIN empresa_cargos c ON c.id_cargo = t.id_cargo
             LEFT JOIN usuarios u ON u.id_usuario = t.usuario_id
             ORDER BY t.estado DESC, t.apellidos, t.nombres"
        )->fetchAll();
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() :
            "No se pudo guardar la información. Verifica que el RUC no esté registrado en otro perfil.";
    }
}

$valor = static fn (string $campo): string => $escapar($perfil[$campo] ?? "");
$valorTrabajador = static fn (string $campo): string => $escapar($trabajadorEditar[$campo] ?? "");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área Empresarial | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner"><p class="eyebrow">Administración</p><h1>Área Empresarial</h1><p>Guarda los datos institucionales y organiza las áreas y cargos de la empresa.</p></div>
        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>
        <section class="crud-layout">
            <div class="panel">
                <h3>Perfil de la empresa</h3>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar_perfil">
                    <div class="form-grid">
                        <div class="campo"><label for="razon_social">Razón social *</label><input id="razon_social" name="razon_social" required maxlength="180" value="<?= $valor("razon_social") ?>"></div>
                        <div class="campo"><label for="nombre_comercial">Nombre comercial</label><input id="nombre_comercial" name="nombre_comercial" maxlength="180" value="<?= $valor("nombre_comercial") ?>"></div>
                        <div class="campo"><label for="ruc">RUC *</label><input id="ruc" name="ruc" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" required value="<?= $valor("ruc") ?>"></div>
                        <div class="campo"><label for="representante_legal">Representante legal</label><input id="representante_legal" name="representante_legal" maxlength="180" value="<?= $valor("representante_legal") ?>"></div>
                        <div class="campo"><label for="telefono">Celular</label><input id="telefono" name="telefono" inputmode="numeric" pattern="9[0-9]{8}" maxlength="9" value="<?= $valor("telefono") ?>"></div>
                        <div class="campo"><label for="correo">Correo</label><input id="correo" name="correo" type="email" maxlength="150" value="<?= $valor("correo") ?>"></div>
                    </div>
                    <div class="campo"><label for="direccion">Dirección</label><input id="direccion" name="direccion" maxlength="255" value="<?= $valor("direccion") ?>"></div>
                    <button class="btn-primary form-submit" type="submit">Guardar perfil</button>
                </form>
            </div>
            <div class="panel">
                <h3>Registrar área</h3>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar_area">
                    <div class="campo"><label for="nombre_area">Nombre del área *</label><input id="nombre_area" name="nombre_area" maxlength="100" required></div>
                    <div class="campo"><label for="descripcion_area">Descripción</label><textarea id="descripcion_area" name="descripcion_area" maxlength="255" rows="2"></textarea></div>
                    <button class="btn-secondary" type="submit">Guardar área</button>
                </form>
                <h3>Cargos registrados</h3>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar_cargo">
                    <div class="form-grid">
                        <div class="campo"><label for="id_area_cargo">Área *</label><select id="id_area_cargo" name="id_area_cargo" required><option value="">Seleccionar área</option><?php foreach ($areas as $area): ?><?php if ((int) $area["estado"] === 1): ?><option value="<?= (int) $area["id_area"] ?>"><?= $escapar($area["nombre"]) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                        <div class="campo"><label for="nombre_cargo">Cargo *</label><input id="nombre_cargo" name="nombre_cargo" maxlength="100" required></div>
                    </div>
                    <div class="campo"><label for="descripcion_cargo">Descripción</label><input id="descripcion_cargo" name="descripcion_cargo" maxlength="255"></div>
                    <button class="btn-secondary" type="submit">Guardar cargo</button>
                </form>
            </div>
        </section>
        <section class="crud-layout">
            <div class="panel">
                <div class="panel-heading"><h3><?= $trabajadorEditar ? "Editar trabajador" : "Registrar trabajador" ?></h3><?php if ($trabajadorEditar): ?><a class="text-link" href="empresa.php">Cancelar</a><?php endif; ?></div>
                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar_trabajador"><input type="hidden" name="id_trabajador" value="<?= (int) ($trabajadorEditar["id_trabajador"] ?? 0) ?>">
                    <div class="form-grid">
                        <div class="campo"><label for="nombres_trabajador">Nombres *</label><input id="nombres_trabajador" name="nombres_trabajador" required maxlength="100" value="<?= $valorTrabajador("nombres") ?>"></div>
                        <div class="campo"><label for="apellidos_trabajador">Apellidos *</label><input id="apellidos_trabajador" name="apellidos_trabajador" required maxlength="100" value="<?= $valorTrabajador("apellidos") ?>"></div>
                        <div class="campo"><label for="documento_trabajador">DNI *</label><input id="documento_trabajador" name="documento_trabajador" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required value="<?= $valorTrabajador("documento") ?>"></div>
                        <div class="campo"><label for="telefono_trabajador">Celular</label><input id="telefono_trabajador" name="telefono_trabajador" inputmode="numeric" pattern="9[0-9]{8}" maxlength="9" value="<?= $valorTrabajador("telefono") ?>"></div>
                        <div class="campo"><label for="correo_trabajador">Correo</label><input id="correo_trabajador" name="correo_trabajador" type="email" maxlength="150" value="<?= $valorTrabajador("correo") ?>"></div>
                        <div class="campo"><label for="fecha_ingreso">Fecha de ingreso *</label><input id="fecha_ingreso" name="fecha_ingreso" type="date" required value="<?= $valorTrabajador("fecha_ingreso") ?: date("Y-m-d") ?>"></div>
                        <div class="campo"><label for="id_area_trabajador">Área *</label><select id="id_area_trabajador" name="id_area_trabajador" required><option value="">Seleccionar área</option><?php foreach ($areas as $area): ?><?php if ((int) $area["estado"] === 1): ?><option value="<?= (int) $area["id_area"] ?>" <?= (int) ($trabajadorEditar["id_area"] ?? 0) === (int) $area["id_area"] ? "selected" : "" ?>><?= $escapar($area["nombre"]) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                        <div class="campo"><label for="id_cargo_trabajador">Cargo *</label><select id="id_cargo_trabajador" name="id_cargo_trabajador" required><option value="">Seleccionar cargo</option><?php foreach ($cargos as $cargo): ?><?php if ((int) $cargo["estado"] === 1): ?><option value="<?= (int) $cargo["id_cargo"] ?>" data-area="<?= (int) $cargo["id_area"] ?>" <?= (int) ($trabajadorEditar["id_cargo"] ?? 0) === (int) $cargo["id_cargo"] ? "selected" : "" ?>><?= $escapar($cargo["area"] . " · " . $cargo["nombre"]) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                        <div class="campo"><label for="usuario_id">Cuenta de acceso</label><select id="usuario_id" name="usuario_id"><option value="">Sin cuenta vinculada</option><?php foreach ($usuarios as $usuario): ?><option value="<?= (int) $usuario["id_usuario"] ?>" <?= (int) ($trabajadorEditar["usuario_id"] ?? 0) === (int) $usuario["id_usuario"] ? "selected" : "" ?>><?= $escapar(trim($usuario["nombres"] . " " . $usuario["apellidos"])) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <button class="btn-primary form-submit" type="submit"><?= $trabajadorEditar ? "Guardar cambios" : "Guardar trabajador" ?></button>
                </form>
            </div>
            <div class="panel">
                <h3>Trabajadores registrados</h3>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Trabajador</th><th>Documento / contacto</th><th>Área y cargo</th><th>Ingreso</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
                    <?php foreach ($trabajadores as $trabajador): ?><tr><td><strong><?= $escapar(trim($trabajador["nombres"] . " " . $trabajador["apellidos"])) ?></strong><small><?= $escapar($trabajador["usuario"] ? "Cuenta: " . $trabajador["usuario"] : "Sin cuenta de acceso") ?></small></td><td><?= $escapar($trabajador["documento"]) ?><small><?= $escapar($trabajador["telefono"]) ?><?= $trabajador["correo"] ? " · " . $escapar($trabajador["correo"]) : "" ?></small></td><td><?= $escapar($trabajador["area"]) ?><small><?= $escapar($trabajador["cargo"]) ?></small></td><td><?= $escapar($trabajador["fecha_ingreso"]) ?></td><td><?= (int) $trabajador["estado"] === 1 ? "Activo" : "Inactivo" ?></td><td class="actions"><a class="text-link" href="empresa.php?editar_trabajador=<?= (int) $trabajador["id_trabajador"] ?>">Editar</a><form method="post"><input type="hidden" name="accion" value="estado_trabajador"><input type="hidden" name="id_trabajador" value="<?= (int) $trabajador["id_trabajador"] ?>"><input type="hidden" name="estado" value="<?= (int) $trabajador["estado"] === 1 ? 0 : 1 ?>"><button class="link-button" type="submit"><?= (int) $trabajador["estado"] === 1 ? "Desactivar" : "Reactivar" ?></button></form></td></tr><?php endforeach; ?>
                    <?php if (!$trabajadores): ?><tr><td colspan="6" class="empty-state">Aún no hay trabajadores registrados.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </section>
        <script>
        const areaSelect = document.getElementById("id_area_trabajador");
        const cargoSelect = document.getElementById("id_cargo_trabajador");
        const filtrarCargos = () => {
            for (const option of cargoSelect.options) {
                if (!option.value) continue;
                option.hidden = option.dataset.area !== areaSelect.value;
                if (option.hidden && option.selected) cargoSelect.value = "";
            }
        };
        areaSelect.addEventListener("change", filtrarCargos);
        filtrarCargos();
        </script>
        <section class="module-board">
            <div class="panel"><h3>Áreas de la empresa</h3><div class="table-wrap"><table class="data-table"><thead><tr><th>Área</th><th>Cargos</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($areas as $area): ?><tr><td><strong><?= $escapar($area["nombre"]) ?></strong><small><?= $escapar($area["descripcion"]) ?></small></td><td><?= (int) $area["total_cargos"] ?></td><td><?= (int) $area["estado"] === 1 ? "Activa" : "Inactiva" ?></td><td><form method="post"><input type="hidden" name="accion" value="estado_area"><input type="hidden" name="id_area" value="<?= (int) $area["id_area"] ?>"><input type="hidden" name="estado" value="<?= (int) $area["estado"] === 1 ? 0 : 1 ?>"><button class="link-button" type="submit"><?= (int) $area["estado"] === 1 ? "Desactivar" : "Reactivar" ?></button></form></td></tr><?php endforeach; ?>
                <?php if (!$areas): ?><tr><td colspan="4" class="empty-state">Aún no hay áreas registradas.</td></tr><?php endif; ?>
            </tbody></table></div></div>
            <div class="panel"><h3>Cargos</h3><div class="table-wrap"><table class="data-table"><thead><tr><th>Área</th><th>Cargo</th><th>Descripción</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
                <?php foreach ($cargos as $cargo): ?><tr><td><?= $escapar($cargo["area"]) ?></td><td><?= $escapar($cargo["nombre"]) ?></td><td><?= $escapar($cargo["descripcion"]) ?></td><td><?= (int) $cargo["estado"] === 1 ? "Activo" : "Inactivo" ?></td><td><form method="post"><input type="hidden" name="accion" value="estado_cargo"><input type="hidden" name="id_cargo" value="<?= (int) $cargo["id_cargo"] ?>"><input type="hidden" name="estado" value="<?= (int) $cargo["estado"] === 1 ? 0 : 1 ?>"><button class="link-button" type="submit"><?= (int) $cargo["estado"] === 1 ? "Desactivar" : "Reactivar" ?></button></form></td></tr><?php endforeach; ?>
                <?php if (!$cargos): ?><tr><td colspan="5" class="empty-state">Aún no hay cargos registrados.</td></tr><?php endif; ?>
            </tbody></table></div></div>
        </section>
        <p class="module-note">Las cuentas y permisos de trabajadores se administran por separado en Usuarios y permisos. Este módulo registra la estructura organizacional.</p>
    </main>
</div>
</body>
</html>
