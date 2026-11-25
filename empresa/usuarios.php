<?php
session_start();
if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";

if ($conexion) {
    exigirPermiso($conexion, "usuarios.gestionar");
}

$mensaje = "";
$error = "";
$usuarioEditar = null;
$usuarios = [];
$roles = [];
$permisos = [];
$asignaciones = [];
$escapar = static fn ($valor): string => htmlspecialchars((string) ($valor ?? ""), ENT_QUOTES, "UTF-8");

// Métricas de usuarios por categoría
$conteoTotal = 0;
$conteoAdmins = 0;
$conteoAcademicos = 0;
$conteoComerciales = 0;
$conteoOtros = 0;

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";

            if ($accion === "guardar_usuario") {
                $idUsuario = (int) ($_POST["id_usuario"] ?? 0);
                $usuario = trim($_POST["usuario"] ?? "");
                $nombres = trim($_POST["nombres"] ?? "");
                $apellidos = trim($_POST["apellidos"] ?? "");
                $rolCodigo = trim($_POST["rol"] ?? "");
                $estado = (int) ($_POST["estado"] ?? 1) === 1 ? 1 : 0;
                $password = $_POST["password"] ?? "";

                $stmtRol = $conexion->prepare("SELECT id_rol FROM roles WHERE codigo = :codigo LIMIT 1");
                $stmtRol->execute([":codigo" => $rolCodigo]);
                $idRol = (int) $stmtRol->fetchColumn();

                if ($usuario === "" || $nombres === "" || $idRol <= 0) {
                    throw new RuntimeException("Completa usuario, nombre y categoría de rol válida.");
                }

                // Restricciones de nombres y apellidos (Solo letras y espacios)
                if (!preg_match('/^[\p{L}]+(?: [\p{L}]+)*$/u', $nombres)) {
                    throw new RuntimeException("El nombre solo puede contener letras y espacios (sin números ni caracteres especiales).");
                }
                if ($apellidos !== "" && !preg_match('/^[\p{L}]+(?: [\p{L}]+)*$/u', $apellidos)) {
                    throw new RuntimeException("Los apellidos solo pueden contener letras y espacios (sin números ni caracteres especiales).");
                }

                if ($idUsuario === 0 && $password === "") {
                    throw new RuntimeException("La contraseña es obligatoria para un nuevo usuario.");
                }
                if ($password !== "" && strlen($password) < 8) {
                    throw new RuntimeException("La contraseña debe tener al menos 8 caracteres.");
                }
                if ($idUsuario === (int) $_SESSION["id_usuario"] && ($estado !== 1 || $rolCodigo !== "administrador")) {
                    throw new RuntimeException("No puedes desactivar tu propia cuenta ni quitarte el rol administrador.");
                }

                if ($idUsuario > 0) {
                    $sql = "UPDATE usuarios SET usuario = :usuario, nombres = :nombres, apellidos = :apellidos,
                            id_rol = :id_rol, estado = :estado";
                    $datos = [
                        ":usuario" => $usuario,
                        ":nombres" => $nombres,
                        ":apellidos" => $apellidos,
                        ":id_rol" => $idRol,
                        ":estado" => $estado,
                        ":id" => $idUsuario
                    ];
                    if ($password !== "") {
                        $sql .= ", password = :password";
                        $datos[":password"] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    $sql .= " WHERE id_usuario = :id";
                    $conexion->prepare($sql)->execute($datos);
                    $mensaje = "Usuario '{$usuario}' actualizado correctamente.";
                } else {
                    $stmtCheck = $conexion->prepare("SELECT 1 FROM usuarios WHERE usuario = :u LIMIT 1");
                    $stmtCheck->execute([":u" => $usuario]);
                    if ($stmtCheck->fetchColumn()) {
                        throw new RuntimeException("El nombre de usuario '{$usuario}' ya está en uso.");
                    }

                    $conexion->prepare(
                        "INSERT INTO usuarios (usuario, id_rol, password, nombres, apellidos, estado)
                         VALUES (:usuario, :id_rol, :password, :nombres, :apellidos, :estado)"
                    )->execute([
                        ":usuario" => $usuario,
                        ":id_rol" => $idRol,
                        ":password" => password_hash($password, PASSWORD_DEFAULT),
                        ":nombres" => $nombres,
                        ":apellidos" => $apellidos,
                        ":estado" => $estado,
                    ]);
                    $mensaje = "Usuario '{$usuario}' creado exitosamente con su categoría.";
                }
            } elseif ($accion === "cambiar_estado") {
                $idUsuario = (int) ($_POST["id_usuario"] ?? 0);
                $nuevoEstado = (int) ($_POST["estado"] ?? -1);
                if ($idUsuario <= 0 || !in_array($nuevoEstado, [0, 1], true)) {
                    throw new RuntimeException("Selecciona una cuenta y un estado válido.");
                }
                if ($idUsuario === (int) $_SESSION["id_usuario"] && $nuevoEstado === 0) {
                    throw new RuntimeException("No puedes desactivar tu propia cuenta.");
                }

                $stmtUsuario = $conexion->prepare(
                    "SELECT r.codigo, u.estado FROM usuarios u
                     INNER JOIN roles r ON r.id_rol = u.id_rol
                     WHERE u.id_usuario = :id"
                );
                $stmtUsuario->execute([":id" => $idUsuario]);
                $usuarioActual = $stmtUsuario->fetch();
                if (!$usuarioActual) {
                    throw new RuntimeException("El usuario seleccionado no existe.");
                }
                if ($usuarioActual["codigo"] === "administrador" && $nuevoEstado === 0) {
                    $totalAdmins = (int) $conexion->query(
                        "SELECT COUNT(*) FROM usuarios u
                         INNER JOIN roles r ON r.id_rol = u.id_rol
                         WHERE r.codigo = 'administrador' AND u.estado = 1"
                    )->fetchColumn();
                    if ($totalAdmins <= 1) {
                        throw new RuntimeException("Debe existir al menos un usuario administrador activo en el sistema.");
                    }
                }
                $stmt = $conexion->prepare("UPDATE usuarios SET estado = :estado WHERE id_usuario = :id");
                $stmt->execute([":estado" => $nuevoEstado, ":id" => $idUsuario]);
                $mensaje = $nuevoEstado === 1 ? "Cuenta reactivada." : "Cuenta desactivada.";
            } elseif ($accion === "guardar_permisos") {
                $idRol = (int) ($_POST["id_rol"] ?? 0);
                $permisosSeleccionados = array_map("intval", $_POST["permisos"] ?? []);
                if ($idRol <= 0) {
                    throw new RuntimeException("Selecciona un rol válido.");
                }
                $stmtRol = $conexion->prepare("SELECT codigo FROM roles WHERE id_rol = :id LIMIT 1");
                $stmtRol->execute([":id" => $idRol]);
                $codigoRol = $stmtRol->fetchColumn();
                if ($codigoRol === "administrador") {
                    throw new RuntimeException("El rol Administrador conserva acceso completo al sistema.");
                }
                $conexion->beginTransaction();
                $conexion->prepare("DELETE FROM rol_permisos WHERE id_rol = :id")->execute([":id" => $idRol]);
                if (!empty($permisosSeleccionados)) {
                    $insertar = $conexion->prepare("INSERT INTO rol_permisos (id_rol, id_permiso) VALUES (:id_rol, :id_permiso)");
                    foreach ($permisosSeleccionados as $idPermiso) {
                        $insertar->execute([":id_rol" => $idRol, ":id_permiso" => $idPermiso]);
                    }
                }
                $conexion->commit();
                $mensaje = "Permisos actualizados para el rol seleccionado.";
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare(
                "SELECT u.*, r.codigo AS rol FROM usuarios u
                 INNER JOIN roles r ON r.id_rol = u.id_rol
                 WHERE u.id_usuario = :id LIMIT 1"
            );
            $stmt->execute([":id" => (int) $_GET["editar"]]);
            $usuarioEditar = $stmt->fetch() ?: null;
        }

        $roles = $conexion->query("SELECT * FROM roles ORDER BY nombre")->fetchAll();
        $permisos = $conexion->query("SELECT * FROM permisos ORDER BY nombre")->fetchAll();
        $asignaciones = $conexion->query("SELECT id_rol, id_permiso FROM rol_permisos")->fetchAll();
        $usuarios = $conexion->query(
            "SELECT u.*, r.nombre AS nombre_rol, r.codigo AS rol FROM usuarios u
             INNER JOIN roles r ON r.id_rol = u.id_rol
             ORDER BY (r.codigo = 'administrador') DESC, r.nombre ASC, u.nombres ASC"
        )->fetchAll();

        // Calcular métricas
        $conteoTotal = count($usuarios);
        foreach ($usuarios as $u) {
            if ((int)$u["estado"] === 1) {
                if ($u["rol"] === "administrador") $conteoAdmins++;
                elseif ($u["rol"] === "asesor_academico") $conteoAcademicos++;
                elseif (in_array($u["rol"], ["ventas", "vendedor"])) $conteoComerciales++;
                else $conteoOtros++;
            }
        }

    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : "Error en el servidor: " . $e->getMessage();
    }
}

$valor = static function (string $campo) use ($usuarioEditar): string {
    return htmlspecialchars((string) ($usuarioEditar[$campo] ?? ""), ENT_QUOTES, "UTF-8");
};

$permisosPorRol = [];
foreach ($asignaciones as $asignacion) {
    $permisosPorRol[(int) $asignacion["id_rol"]][] = (int) $asignacion["id_permiso"];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios y Categorías | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
</head>
<body class="dashboard-body">
<div class="module-shell">
    <?php require "_navegacion.php"; ?>
    <main class="module-content">
        <div class="module-banner">
            <p class="eyebrow">Administración del Sistema</p>
            <h1>Usuarios y Categorías de Acceso</h1>
            <p>Control de cuentas de trabajadores, asignación de categorías por área y administración de permisos de acceso.</p>
        </div>

        <?php if ($mensaje !== ""): ?><div class="notice success"><?= $escapar($mensaje) ?></div><?php endif; ?>
        <?php if ($error !== ""): ?><div class="notice error"><?= $escapar($error) ?></div><?php endif; ?>

        <!-- Tarjetas de Métricas de Categorías de Usuarios -->
        <section class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon blue">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= $conteoTotal ?></span>
                    <span class="kpi-label">Total Cuentas</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon purple">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= $conteoAdmins ?></span>
                    <span class="kpi-label">Administradores</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon blue">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= $conteoAcademicos ?></span>
                    <span class="kpi-label">Asesores Académicos</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon green">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= $conteoComerciales ?></span>
                    <span class="kpi-label">Asesores Comerciales</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon amber">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
                </div>
                <div class="kpi-data">
                    <span class="kpi-value"><?= $conteoOtros ?></span>
                    <span class="kpi-label">Legal y Operativos</span>
                </div>
            </div>
        </section>

        <section class="crud-layout" style="grid-template-columns: 1.15fr 1.85fr; gap: 24px;">
            <!-- Formulario de Creación / Edición -->
            <div class="panel">
                <div class="panel-heading">
                    <h3><?= $usuarioEditar ? "Editar Usuario" : "Crear Nuevo Usuario" ?></h3>
                    <?php if ($usuarioEditar): ?><a class="text-link" href="usuarios.php">Cancelar</a><?php endif; ?>
                </div>

                <form method="post" class="data-form">
                    <input type="hidden" name="accion" value="guardar_usuario">
                    <input type="hidden" name="id_usuario" value="<?= (int) ($usuarioEditar["id_usuario"] ?? 0) ?>">

                    <div class="campo">
                        <label for="usuario">Nombre de Usuario (Login) *</label>
                        <input id="usuario" name="usuario" required autocomplete="username" value="<?= $valor("usuario") ?>" placeholder="Ej.: c.mendoza">
                    </div>

                    <div class="form-grid">
                        <div class="campo">
                            <label for="nombres">Nombres *</label>
                            <input id="nombres" name="nombres" pattern="[\p{L}]+(?: [\p{L}]+)*" required value="<?= $valor("nombres") ?>" placeholder="Ej.: Carlos">
                        </div>

                        <div class="campo">
                            <label for="apellidos">Apellidos</label>
                            <input id="apellidos" name="apellidos" pattern="[\p{L}]+(?: [\p{L}]+)*" value="<?= $valor("apellidos") ?>" placeholder="Ej.: Mendoza">
                        </div>
                    </div>

                    <div class="campo">
                        <label for="password">Contraseña <?= $usuarioEditar ? "(dejar en blanco para conservar)" : "*" ?></label>
                        <input type="password" id="password" name="password" <?= $usuarioEditar ? "" : "required" ?> minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres">
                    </div>

                    <div class="form-grid">
                        <!-- Categoría de Usuario con descripciones claras -->
                        <div class="campo">
                            <label for="rol">Categoría / Rol en el Sistema *</label>
                            <select id="rol" name="rol" required>
                                <optgroup label="Administración General">
                                    <option value="administrador" <?= ($usuarioEditar["rol"] ?? "") === "administrador" ? "selected" : "" ?>>
                                        Administrador (Acceso total)
                                    </option>
                                </optgroup>
                                <optgroup label="Personal por Área / Especialidad">
                                    <option value="asesor_academico" <?= ($usuarioEditar["rol"] ?? "") === "asesor_academico" ? "selected" : "" ?>>
                                        Asesor Académico (Tesis y Expedientes)
                                    </option>
                                    <option value="ventas" <?= in_array($usuarioEditar["rol"] ?? "", ["ventas", "vendedor"]) ? "selected" : "" ?>>
                                        Asesor Comercial (Ventas y Clientes)
                                    </option>
                                    <option value="legal" <?= ($usuarioEditar["rol"] ?? "") === "legal" ? "selected" : "" ?>>
                                        Asesor Legal (Contratos y Convenios)
                                    </option>
                                    <option value="contabilidad" <?= ($usuarioEditar["rol"] ?? "") === "contabilidad" ? "selected" : "" ?>>
                                        Finanzas y Contabilidad
                                    </option>
                                    <option value="coordinador_academico" <?= ($usuarioEditar["rol"] ?? "") === "coordinador_academico" ? "selected" : "" ?>>
                                        Coordinador Académico
                                    </option>
                                    <option value="gerente" <?= ($usuarioEditar["rol"] ?? "") === "gerente" ? "selected" : "" ?>>
                                        Gerencia
                                    </option>
                                    <option value="trabajador" <?= ($usuarioEditar["rol"] ?? "") === "trabajador" ? "selected" : "" ?>>
                                        Personal Operativo
                                    </option>
                                </optgroup>
                            </select>
                        </div>

                        <div class="campo">
                            <label for="estado">Estado de la Cuenta</label>
                            <select id="estado" name="estado">
                                <option value="1" <?= (int) ($usuarioEditar["estado"] ?? 1) === 1 ? "selected" : "" ?>>Activa</option>
                                <option value="0" <?= isset($usuarioEditar["estado"]) && (int) $usuarioEditar["estado"] === 0 ? "selected" : "" ?>>Inactiva / Suspendida</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary form-submit" style="width:100%; margin-top:10px;">
                        <?= $usuarioEditar ? "Actualizar Usuario" : "Guardar Nuevo Usuario" ?>
                    </button>
                </form>
            </div>

            <!-- Tabla Dividida de Cuentas: Administradores y Trabajadores -->
            <div class="panel">
                <div class="panel-heading">
                    <h3>Cuentas y Personal Registrado (<?= count($usuarios) ?>)</h3>
                    <span style="font-size:0.8rem; color:#64748b;">Dividido por Administradores y Trabajadores</span>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Usuario / Titular</th>
                                <th>Categoría / Rol</th>
                                <th>Tipo de Acceso</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <?php
                                $esAdminUser = ($u["rol"] === "administrador");
                                $badgeClass = "badge-role-worker";
                                $dotColor = "#94a3b8";
                                $tipoAcceso = "Bloque Trabajador";

                                if ($esAdminUser) {
                                    $badgeClass = "badge-role-admin";
                                    $dotColor = "#a855f7";
                                    $tipoAcceso = "Bloque Administrador (Total)";
                                } elseif ($u["rol"] === "asesor_academico") {
                                    $badgeClass = "badge-role-academic";
                                    $dotColor = "#3b82f6";
                                    $tipoAcceso = "Bloque Académico / Tesis";
                                } elseif (in_array($u["rol"], ["ventas", "vendedor"])) {
                                    $badgeClass = "badge-role-sales";
                                    $dotColor = "#10b981";
                                    $tipoAcceso = "Bloque Comercial / Ventas";
                                } elseif ($u["rol"] === "legal") {
                                    $badgeClass = "badge-role-legal";
                                    $dotColor = "#f59e0b";
                                    $tipoAcceso = "Bloque Legal / Contratos";
                                } elseif ($u["rol"] === "contabilidad") {
                                    $badgeClass = "badge-role-finance";
                                    $dotColor = "#0ea5e9";
                                    $tipoAcceso = "Bloque Financiero";
                                }
                                ?>
                                <tr style="<?= $esAdminUser ? 'background: rgba(124, 58, 237, 0.02);' : '' ?>">
                                    <td>
                                        <strong><?= $escapar(trim($u["nombres"] . " " . ($u["apellidos"] ?? ""))) ?></strong>
                                        <small style="color:#64748b;">@<?= $escapar($u["usuario"]) ?></small>
                                    </td>
                                    <td>
                                        <span class="user-role-tag <?= $badgeClass ?>">
                                            <span class="user-role-dot" style="background-color: <?= $dotColor ?>;"></span>
                                            <?= $escapar($u["nombre_rol"] ?: $u["rol"]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small style="font-weight:600; color:<?= $esAdminUser ? '#7c3aed' : '#475569' ?>;">
                                            <?= $tipoAcceso ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="status-badge" style="background:<?= (int)$u["estado"] === 1 ? '#dcfce7; color:#15803d;' : '#fee2e2; color:#dc2626;' ?>">
                                            <?= (int) $u["estado"] === 1 ? "Activo" : "Inactivo" ?>
                                        </span>
                                    </td>
                                    <td class="actions">
                                        <a class="btn-action btn-action-primary" href="usuarios.php?editar=<?= (int) $u["id_usuario"] ?>">
                                            Editar
                                        </a>

                                        <form method="post" onsubmit="return confirm('¿<?= (int) $u["estado"] === 1 ? "Desactivar" : "Reactivar" ?> este usuario?');" style="display:inline;">
                                            <input type="hidden" name="accion" value="cambiar_estado">
                                            <input type="hidden" name="id_usuario" value="<?= (int) $u["id_usuario"] ?>">
                                            <input type="hidden" name="estado" value="<?= (int) $u["estado"] === 1 ? 0 : 1 ?>">
                                            <button class="btn-action <?= (int) $u["estado"] === 1 ? "btn-action-danger" : "btn-action-primary" ?>" type="submit">
                                                <?= (int) $u["estado"] === 1 ? "Desactivar" : "Activar" ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Matriz de Permisos por Rol -->
        <section class="panel" style="margin-top: 24px;">
            <div class="panel-heading">
                <h3>Permisos Asignados a cada Categoría / Rol</h3>
                <span class="module-note">Configura las vistas y acciones a las que cada categoría de trabajador tiene acceso.</span>
            </div>
            
            <div class="permissions-toolbar">
                <label for="buscar-permiso">Buscar permiso por nombre o módulo</label>
                <input id="buscar-permiso" type="search" placeholder="Ej.: tesis, crm, contratos, documentos" autocomplete="off">
            </div>

            <div class="permission-roles" id="permission-roles">
                <?php foreach ($roles as $rol): ?>
                    <?php 
                    $permisosRol = $permisosPorRol[(int) $rol["id_rol"]] ?? []; 
                    $cantidadPermisosRol = count($permisosRol);
                    $isAdminRole = ($rol["codigo"] === "administrador");
                    ?>
                    <details class="permission-role" data-role-search="<?= $escapar($rol["nombre"]) ?>" data-admin="<?= $isAdminRole ? "true" : "false" ?>">
                        <summary>
                            <span>
                                <strong><?= $isAdminRole ? "👑 " : "💼 " ?><?= $escapar($rol["nombre"]) ?></strong>
                            </span>
                            <?php if ($isAdminRole): ?>
                                <span class="permission-count" style="background:#ede9fe; color:#6d28d9;">Acceso total al sistema</span>
                            <?php else: ?>
                                <span class="permission-count"><?= $cantidadPermisosRol ?> <?= $cantidadPermisosRol === 1 ? "permiso" : "permisos" ?></span>
                            <?php endif; ?>
                        </summary>

                        <?php if (!$isAdminRole): ?>
                            <form method="post" class="permission-role-form" style="padding:14px;">
                                <input type="hidden" name="accion" value="guardar_permisos">
                                <input type="hidden" name="id_rol" value="<?= (int) $rol["id_rol"] ?>">
                                <div class="permission-grid">
                                    <?php foreach ($permisos as $permiso): ?>
                                        <label class="permission-option" data-permission-search="<?= $escapar($permiso["nombre"] . " " . $permiso["codigo"]) ?>">
                                            <input type="checkbox" name="permisos[]" value="<?= (int) $permiso["id_permiso"] ?>" <?= in_array((int) $permiso["id_permiso"], $permisosRol, true) ? "checked" : "" ?>>
                                            <span><?= $escapar($permiso["nombre"]) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" class="btn-secondary" style="margin-top:12px;">Guardar Permisos de este Rol</button>
                            </form>
                        <?php else: ?>
                            <p class="permission-admin-note" style="padding:14px; margin:0; color:#6d28d9; font-weight:600;">
                                El Administrador conserva automáticamente todos los permisos y acceso a todos los módulos.
                            </p>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const buscarPermiso = document.getElementById("buscar-permiso");
    const permissionRoles = document.getElementById("permission-roles");
    if (buscarPermiso && permissionRoles) {
        buscarPermiso.addEventListener("input", (e) => {
            const q = e.target.value.toLowerCase().trim();
            const options = permissionRoles.querySelectorAll(".permission-option");
            options.forEach(opt => {
                const text = (opt.dataset.permissionSearch || "").toLowerCase();
                opt.style.display = (q === "" || text.includes(q)) ? "flex" : "none";
            });
        });
    }
});
</script>
</body>
</html>
