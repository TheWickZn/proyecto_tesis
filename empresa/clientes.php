<?php
session_start();

if (!isset($_SESSION["id_usuario"])) {
    header("Location: ../auth/login.php");
    exit;
}

require_once "../config/conexion.php";
require_once "../config/seguridad.php";
require_once "../config/notificaciones.php";
require_once "../config/catalogos.php";

if ($conexion) {
    exigirPermiso($conexion, "crm.gestionar");
}

$mensaje = "";
$error = "";
$clienteEditar = null;
$tiposDocCatalogo = [];

if (!$conexion) {
    $error = "No se pudo conectar con la base de datos.";
} else {
    try {
        $tiposDocCatalogo = obtenerCatalogo($conexion, "tipo_documento_identidad");

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $accion = $_POST["accion"] ?? "";

            if ($accion === "guardar") {
                $idCliente = (int) ($_POST["id_cliente"] ?? 0);
                $tipoDocumento = trim($_POST["tipo_documento"] ?? "DNI");
                $documento = trim($_POST["documento"] ?? "");
                $telefonoRaw = trim($_POST["telefono"] ?? "");
                $telefonoLimpio = preg_replace('/\s+/', '', $telefonoRaw) ?: null;

                $nombres = trim($_POST["nombres"] ?? "");
                $apellidos = trim($_POST["apellidos"] ?? "") ?: null;
                $correo = trim($_POST["correo"] ?? "") ?: null;
                $empresa = trim($_POST["empresa"] ?? "") ?: null;
                $fechaNacimiento = trim($_POST["fecha_nacimiento"] ?? "") ?: null;
                $universidad = trim($_POST["universidad"] ?? "") ?: null;
                $carrera = trim($_POST["carrera"] ?? "") ?: null;
                $estado = trim($_POST["estado"] ?? "Activo");
                $observaciones = trim($_POST["observaciones"] ?? "") ?: null;
                if ($fechaNacimiento !== null) {
                    $fechaValidada = DateTimeImmutable::createFromFormat("!Y-m-d", $fechaNacimiento);
                    $erroresFecha = DateTimeImmutable::getLastErrors();
                    if (
                        !$fechaValidada
                        || ($erroresFecha !== false && ($erroresFecha["warning_count"] > 0 || $erroresFecha["error_count"] > 0))
                        || $fechaValidada->format("Y-m-d") !== $fechaNacimiento
                        || $fechaValidada > new DateTimeImmutable("today")
                    ) {
                        throw new RuntimeException("La fecha de nacimiento debe ser válida y no puede ser futura.");
                    }
                }

                // 1. RESTRICCIÓN: Nombres solo letras y espacios
                if ($nombres === "" || !preg_match('/^[\p{L}]+(?: [\p{L}]+)*$/u', $nombres)) {
                    throw new RuntimeException("El nombre solo puede contener letras y espacios (sin números ni caracteres especiales).");
                }
                // 2. RESTRICCIÓN: Apellidos solo letras y espacios
                if ($apellidos !== null && !preg_match('/^[\p{L}]+(?: [\p{L}]+)*$/u', $apellidos)) {
                    throw new RuntimeException("Los apellidos solo pueden contener letras y espacios (sin números ni caracteres especiales).");
                }

                // 3. RESTRICCIÓN: Documento solo números
                $longitudDocumento = ($tipoDocumento === "DNI") ? 8 : (($tipoDocumento === "RUC") ? 11 : 0);
                if ($longitudDocumento > 0) {
                    if (!preg_match('/^[0-9]{' . $longitudDocumento . '}$/', $documento)) {
                        throw new RuntimeException("Para {$tipoDocumento} se requieren exactamente {$longitudDocumento} dígitos numéricos.");
                    }
                } else {
                    if (!preg_match('/^[0-9]{6,15}$/', $documento)) {
                        throw new RuntimeException("El número de documento debe contener solo dígitos.");
                    }
                }

                // 4. RESTRICCIÓN: Teléfono celular de 9 dígitos que comience con 9
                if ($telefonoLimpio !== null && !preg_match('/^9[0-9]{8}$/', $telefonoLimpio)) {
                    throw new RuntimeException("El celular debe tener 9 dígitos y comenzar obligatoriamente con el número 9.");
                }

                $datos = [
                    ":nombres" => $nombres,
                    ":apellidos" => $apellidos,
                    ":tipo_documento" => $tipoDocumento,
                    ":documento" => $documento,
                    ":telefono" => $telefonoLimpio,
                    ":correo" => $correo,
                    ":empresa" => $empresa,
                    ":fecha_nacimiento" => $fechaNacimiento,
                    ":universidad" => $universidad,
                    ":carrera" => $carrera,
                    ":estado" => $estado,
                    ":observaciones" => $observaciones,
                ];

                if ($idCliente > 0) {
                    $sql = "UPDATE clientes SET nombres = :nombres, apellidos = :apellidos, tipo_documento = :tipo_documento, documento = :documento,
                            telefono = :telefono, correo = :correo, empresa = :empresa, fecha_nacimiento = :fecha_nacimiento,
                            universidad = :universidad, carrera = :carrera, estado = :estado,
                            observaciones = :observaciones WHERE id_cliente = :id_cliente";
                    $datos[":id_cliente"] = $idCliente;
                    $conexion->prepare($sql)->execute($datos);
                    $mensaje = "Cliente actualizado correctamente.";
                } else {
                    $sql = "INSERT INTO clientes (nombres, apellidos, tipo_documento, documento, telefono, correo, empresa, fecha_nacimiento, universidad, carrera, estado, observaciones)
                            VALUES (:nombres, :apellidos, :tipo_documento, :documento, :telefono, :correo, :empresa, :fecha_nacimiento, :universidad, :carrera, :estado, :observaciones)";
                    $conexion->beginTransaction();
                    $conexion->prepare($sql)->execute($datos);
                    notificarNuevoRegistroComercial(
                        $conexion,
                        "cliente",
                        (int) $conexion->lastInsertId(),
                        trim($datos[":nombres"] . " " . ($datos[":apellidos"] ?? "")),
                        (int) $_SESSION["id_usuario"]
                    );
                    $conexion->commit();
                    $mensaje = "Cliente registrado correctamente.";
                }
            } elseif ($accion === "eliminar") {
                $idCliente = (int) ($_POST["id_cliente"] ?? 0);
                if ($idCliente > 0) {
                    $stmt = $conexion->prepare("DELETE FROM clientes WHERE id_cliente = :id_cliente");
                    $stmt->execute([":id_cliente" => $idCliente]);
                    $mensaje = "Cliente eliminado correctamente.";
                }
            }
        }

        if (isset($_GET["editar"])) {
            $stmt = $conexion->prepare("SELECT * FROM clientes WHERE id_cliente = :id_cliente");
            $stmt->execute([":id_cliente" => (int) $_GET["editar"]]);
            $clienteEditar = $stmt->fetch() ?: null;
        }

        $busqueda = trim($_GET["buscar"] ?? "");
        if ($busqueda !== "") {
            $stmt = $conexion->prepare("SELECT * FROM clientes
                WHERE CONCAT_WS(' ', nombres, apellidos, empresa, documento) LIKE :buscar
                ORDER BY id_cliente DESC");
            $stmt->execute([":buscar" => "%{$busqueda}%"]);
        } else {
            $stmt = $conexion->query("SELECT * FROM clientes ORDER BY id_cliente DESC");
        }
        $clientes = $stmt->fetchAll();
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : "Error en el servidor: " . $e->getMessage();
        $clientes = $clientes ?? [];
    }
}

$valor = static function (string $campo) use ($clienteEditar): string {
    return htmlspecialchars((string) ($clienteEditar[$campo] ?? ""), ENT_QUOTES, "UTF-8");
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes | GROUP_CEA</title>
    <link rel="stylesheet" href="../css/estilos.css?v=<?= (int) filemtime(__DIR__ . "/../css/estilos.css") ?>">
</head>
<body class="dashboard-body">
    <div class="module-shell">
        <?php require "_navegacion.php"; ?>

        <main class="module-content">
            <div class="module-banner">
                <p class="eyebrow">Ficha integral del cliente</p>
                <h1>Clientes</h1>
                <p>Administra los datos personales, académicos, de contacto y observaciones de clientes y tesistas.</p>
                <a class="btn-secondary btn-sm" href="gestion_academica.php">Servicios y gestión académica</a>
            </div>

            <?php if ($mensaje !== ""): ?><div class="notice success"><?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
            <?php if ($error !== ""): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>

            <section class="crud-layout">
                <!-- Formulario de Cliente -->
                <div class="panel">
                    <div class="panel-heading">
                        <h3><?= $clienteEditar ? "Editar cliente" : "Nuevo cliente" ?></h3>
                        <?php if ($clienteEditar): ?><a class="text-link" href="clientes.php">Cancelar</a><?php endif; ?>
                    </div>
                    <form method="post" class="data-form">
                        <input type="hidden" name="accion" value="guardar">
                        <input type="hidden" name="id_cliente" value="<?= (int) ($clienteEditar["id_cliente"] ?? 0) ?>">
                        <div class="form-grid">
                            <!-- Nombres: Solo letras y espacios -->
                            <div class="campo">
                                <label for="nombres">Nombres *</label>
                                <input id="nombres" name="nombres" pattern="[\p{L}]+(?: [\p{L}]+)*" maxlength="100" title="Solo mayúsculas, minúsculas y espacios." required value="<?= $valor("nombres") ?>" placeholder="Ej.: María Elena">
                            </div>

                            <!-- Apellidos: Solo letras y espacios -->
                            <div class="campo">
                                <label for="apellidos">Apellidos</label>
                                <input id="apellidos" name="apellidos" pattern="[\p{L}]+(?: [\p{L}]+)*" maxlength="100" title="Solo mayúsculas, minúsculas y espacios." value="<?= $valor("apellidos") ?>" placeholder="Ej.: Salazar Rojas">
                            </div>

                            <!-- Tipo de Documento dinámico desde catálogo -->
                            <div class="campo">
                                <label for="tipo_documento">Tipo de documento *</label>
                                <select id="tipo_documento" name="tipo_documento" required>
                                    <option value="DNI" <?= ($clienteEditar["tipo_documento"] ?? "DNI") === "DNI" ? "selected" : "" ?>>DNI (8 dígitos)</option>
                                    <option value="RUC" <?= ($clienteEditar["tipo_documento"] ?? "") === "RUC" ? "selected" : "" ?>>RUC (11 dígitos)</option>
                                    <option value="CE" <?= ($clienteEditar["tipo_documento"] ?? "") === "CE" ? "selected" : "" ?>>Carné de Extranjería (CE)</option>
                                    <option value="Pasaporte" <?= ($clienteEditar["tipo_documento"] ?? "") === "Pasaporte" ? "selected" : "" ?>>Pasaporte</option>
                                </select>
                            </div>

                            <!-- Documento: Solo números -->
                            <div class="campo">
                                <label for="documento">Número de documento *</label>
                                <input id="documento" name="documento" type="text" inputmode="numeric" required value="<?= $valor("documento") ?>" placeholder="Solo números">
                            </div>

                            <!-- Celular: 9 dígitos empezando en 9, formato visual -->
                            <div class="campo">
                                <label for="telefono">Celular (9 dígitos)</label>
                                <input id="telefono" name="telefono" type="tel" inputmode="numeric" maxlength="11" placeholder="9XX XXX XXX" value="<?= $valor("telefono") ?>" title="Debe tener 9 dígitos y comenzar con 9.">
                            </div>

                            <div class="campo">
                                <label for="correo">Correo electrónico</label>
                                <input type="email" id="correo" name="correo" value="<?= $valor("correo") ?>" placeholder="ejemplo@correo.com">
                            </div>

                            <div class="campo">
                                <label for="empresa">Empresa / Institución</label>
                                <input id="empresa" name="empresa" value="<?= $valor("empresa") ?>" placeholder="Universidad o Empresa">
                            </div>

                            <div class="campo">
                                <label for="fecha_nacimiento">Fecha de nacimiento</label>
                                <input id="fecha_nacimiento" name="fecha_nacimiento" type="date" max="<?= date("Y-m-d") ?>" value="<?= $valor("fecha_nacimiento") ?>">
                            </div>
                            <div class="campo">
                                <label for="universidad">Universidad</label>
                                <input id="universidad" name="universidad" maxlength="180" value="<?= $valor("universidad") ?>">
                            </div>
                            <div class="campo">
                                <label for="carrera">Carrera / programa académico</label>
                                <input id="carrera" name="carrera" maxlength="150" value="<?= $valor("carrera") ?>">
                            </div>

                            <div class="campo">
                                <label for="estado">Estado</label>
                                <select id="estado" name="estado">
                                    <option <?= $valor("estado") === "Activo" || !$clienteEditar ? "selected" : "" ?>>Activo</option>
                                    <option <?= $valor("estado") === "Inactivo" ? "selected" : "" ?>>Inactivo</option>
                                </select>
                            </div>
                        </div>

                        <div class="campo">
                            <label for="observaciones">Observaciones / Notas</label>
                            <textarea id="observaciones" name="observaciones" rows="3" placeholder="Detalles de facturación, trámites, etc."><?= $valor("observaciones") ?></textarea>
                        </div>

                        <button type="submit" class="btn-primary form-submit">
                            <?= $clienteEditar ? "Actualizar Cliente" : "Guardar Cliente" ?>
                        </button>
                    </form>
                </div>

                <!-- Tabla de Clientes -->
                <div class="panel">
                    <div class="panel-heading">
                        <h3>Clientes registrados (<?= count($clientes) ?>)</h3>
                        <form method="get" class="search-form">
                            <input name="buscar" placeholder="Buscar por nombre, DNI..." value="<?= htmlspecialchars($_GET["buscar"] ?? "", ENT_QUOTES, "UTF-8") ?>">
                            <button type="submit" class="btn-secondary btn-sm">Buscar</button>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Cliente / Titular</th>
                                    <th>Contacto</th>
                                    <th>Institución / Empresa</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($clientes ?? [] as $cliente): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars(trim($cliente["nombres"] . " " . ($cliente["apellidos"] ?? "")), ENT_QUOTES, "UTF-8") ?></strong>
                                        <small><?= htmlspecialchars(trim(($cliente["tipo_documento"] ?? "DOC") . ": " . ($cliente["documento"] ?? "-")), ENT_QUOTES, "UTF-8") ?></small>
                                        <small><?= htmlspecialchars(trim(($cliente["universidad"] ?? "") . (($cliente["carrera"] ?? "") !== "" ? " · " . $cliente["carrera"] : "")), ENT_QUOTES, "UTF-8") ?></small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($cliente["telefono"] ?? "", ENT_QUOTES, "UTF-8") ?>
                                        <small><?= htmlspecialchars($cliente["correo"] ?? "", ENT_QUOTES, "UTF-8") ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($cliente["empresa"] ?? "-", ENT_QUOTES, "UTF-8") ?></td>
                                    <td><span class="status-badge"><?= htmlspecialchars($cliente["estado"], ENT_QUOTES, "UTF-8") ?></span></td>
                                    <td class="actions">
                                        <a class="btn-action btn-action-primary" href="clientes.php?editar=<?= (int) $cliente["id_cliente"] ?>" title="Editar">Editar</a>
                                        <a class="btn-action btn-action-primary" href="gestion_academica.php?cliente=<?= (int) $cliente["id_cliente"] ?>" title="Servicios y proyectos">Gestión</a>
                                        <form method="post" onsubmit="return confirm('¿Eliminar este cliente?');" style="display:inline;">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id_cliente" value="<?= (int) $cliente["id_cliente"] ?>">
                                            <button class="btn-action btn-action-danger" type="submit" title="Eliminar">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($clientes)): ?>
                                <tr><td colspan="5" class="empty-state">No hay clientes registrados.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", () => {
        const tipoDoc = document.getElementById("tipo_documento");
        const docInput = document.getElementById("documento");

        const ajustarLongitudDoc = () => {
            if (!tipoDoc || !docInput) return;
            const tipo = tipoDoc.value;
            if (tipo === "DNI") {
                docInput.maxLength = 8;
                docInput.placeholder = "8 dígitos (ej.: 12345678)";
            } else if (tipo === "RUC") {
                docInput.maxLength = 11;
                docInput.placeholder = "11 dígitos (ej.: 20123456789)";
            } else {
                docInput.maxLength = 15;
                docInput.placeholder = "Solo números";
            }
        };

        if (tipoDoc) {
            tipoDoc.addEventListener("change", ajustarLongitudDoc);
            ajustarLongitudDoc();
        }
    });
    </script>
</body>
</html>
