<?php
session_start();

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/seguridad.php';
require_once __DIR__ . '/../config/notificaciones.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!$conexion) {
    http_response_code(500);
    echo json_encode(['error' => 'No hay conexión a la base de datos.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function jsonResponse($payload, $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$verificarAccesoOportunidad = static function (int $idOportunidad) use ($conexion): void {
    $sql = 'SELECT 1 FROM oportunidades WHERE id_oportunidad = :id';
    $parametros = [':id' => $idOportunidad];
    if (!usuarioTienePermiso($conexion, 'crm.ver_todos_prospectos')) {
        $sql .= ' AND responsable_id = :usuario';
        $parametros[':usuario'] = (int) $_SESSION['id_usuario'];
    }

    $stmt = $conexion->prepare($sql);
    $stmt->execute($parametros);
    if (!$stmt->fetchColumn()) {
        jsonResponse(['error' => 'La oportunidad no existe o no está asignada a tu usuario.'], 404);
    }
};

if (in_array($action, ['clientes', 'etapas', 'oportunidades'], true)
    && !usuarioTienePermiso($conexion, 'crm.gestionar')) {
    jsonResponse(['error' => 'No tienes permisos para acceder al módulo comercial.'], 403);
}

$archivoDocumentoGuardado = null;

if ($action === 'documentos' && !usuarioTienePermiso($conexion, 'documentos.gestionar')) {
    jsonResponse(['error' => 'No tienes permiso para gestionar documentos.'], 403);
}

try {
    if ($method === 'GET' && $action === 'documentos') {
        $busqueda = trim((string) ($_GET['q'] ?? ''));
        $termino = '%' . $busqueda . '%';
        $stmt = $conexion->prepare(
            "SELECT d.id_documento, d.titulo, d.nombre_original, d.categoria, d.fecha_registro,
                    CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente
             FROM documentos_empresa d
             LEFT JOIN clientes c ON c.id_cliente = d.id_cliente
             WHERE d.estado = 1
               AND (d.titulo LIKE :titulo OR CONCAT_WS(' ', c.nombres, c.apellidos, c.empresa) LIKE :cliente)
             ORDER BY d.fecha_registro DESC, d.id_documento DESC
             LIMIT 20"
        );
        $stmt->execute([':titulo' => $termino, ':cliente' => $termino]);
        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $action === 'documentos') {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $categoria = trim((string) ($_POST['categoria'] ?? 'Otro'));
        $idCliente = (int) ($_POST['id_cliente'] ?? 0);
        $archivo = $_FILES['archivo'] ?? null;
        $categoriasPermitidas = ['Contrato', 'Propuesta', 'Informe', 'Reporte', 'Certificado', 'Otro'];
        $mimeExtensiones = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        if ($titulo === '' || !preg_match('//u', $titulo)
            || preg_match_all('/./us', $titulo, $tituloCaracteres) > 180) {
            jsonResponse(['error' => 'Escribe un título de documento válido (máximo 180 caracteres).'], 422);
        }
        if (!in_array($categoria, $categoriasPermitidas, true)) {
            jsonResponse(['error' => 'Selecciona una categoría válida.'], 422);
        }
        if ($idCliente <= 0) {
            jsonResponse(['error' => 'Selecciona el cliente al que pertenece el documento.'], 422);
        }
        $stmtCliente = $conexion->prepare(
            "SELECT 1 FROM clientes WHERE id_cliente = :id AND estado = 'Activo'"
        );
        $stmtCliente->execute([':id' => $idCliente]);
        if (!$stmtCliente->fetchColumn()) {
            jsonResponse(['error' => 'El cliente seleccionado no existe o está inactivo.'], 422);
        }
        if (!$archivo || !isset($archivo['error'], $archivo['tmp_name'], $archivo['size'], $archivo['name'])) {
            jsonResponse(['error' => 'Selecciona un archivo para subir.'], 422);
        }
        if ((int) $archivo['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'No se pudo recibir el archivo. Verifica su tamaño y vuelve a intentarlo.'], 422);
        }
        if ((int) $archivo['size'] <= 0 || (int) $archivo['size'] > 8 * 1024 * 1024) {
            jsonResponse(['error' => 'El archivo debe pesar como máximo 8 MB.'], 422);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($archivo['tmp_name']);
        if (!isset($mimeExtensiones[$mime])) {
            jsonResponse(['error' => 'Formato no permitido. Usa PDF, JPG o PNG.'], 422);
        }

        $nombreOriginal = basename(str_replace('\\', '/', (string) $archivo['name']));
        if ($nombreOriginal === '' || strlen($nombreOriginal) > 255) {
            jsonResponse(['error' => 'El nombre original del archivo no es válido.'], 422);
        }
        $nombreAlmacenado = bin2hex(random_bytes(32)) . '.' . $mimeExtensiones[$mime];
        $directorio = __DIR__ . '/../storage/documentos';
        if (!is_dir($directorio) && !mkdir($directorio, 0700, true) && !is_dir($directorio)) {
            jsonResponse(['error' => 'No se pudo preparar el almacenamiento privado de documentos.'], 500);
        }
        $archivoDocumentoGuardado = $directorio . DIRECTORY_SEPARATOR . $nombreAlmacenado;
        if (!move_uploaded_file($archivo['tmp_name'], $archivoDocumentoGuardado)) {
            $archivoDocumentoGuardado = null;
            jsonResponse(['error' => 'No se pudo almacenar el archivo subido.'], 500);
        }

        $conexion->prepare(
            "INSERT INTO documentos_empresa
             (nombre_original, titulo, nombre_almacenado, mime_type, tamano_bytes, categoria, descripcion, id_cliente, subido_por)
             VALUES (:original, :titulo, :almacenado, :mime, :tamano, :categoria, NULL, :cliente, :usuario)"
        )->execute([
            ':original' => $nombreOriginal,
            ':titulo' => $titulo,
            ':almacenado' => $nombreAlmacenado,
            ':mime' => $mime,
            ':tamano' => (int) $archivo['size'],
            ':categoria' => $categoria,
            ':cliente' => $idCliente,
            ':usuario' => (int) $_SESSION['id_usuario'],
        ]);
        $idDocumento = (int) $conexion->lastInsertId();
        $archivoDocumentoGuardado = null;

        jsonResponse([
            'success' => true,
            'data' => [
                'id_documento' => $idDocumento,
                'titulo' => $titulo,
                'cliente' => '',
            ],
        ], 201);
    }

    if ($method === 'GET' && $action === 'clientes') {
        $stmt = $conexion->query("SELECT * FROM clientes ORDER BY id_cliente DESC");
        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'etapas') {
        $stmt = $conexion->query("SELECT * FROM etapas_pipeline ORDER BY orden, id_etapa");
        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'oportunidades') {
        $filtroResponsable = '';
        $parametrosOportunidades = [];
        if (!usuarioTienePermiso($conexion, 'crm.ver_todos_prospectos')) {
            $filtroResponsable = 'WHERE o.responsable_id = :usuario';
            $parametrosOportunidades[':usuario'] = (int) $_SESSION['id_usuario'];
        }
        $stmt = $conexion->prepare(
            "SELECT o.*, CONCAT_WS(' ', c.nombres, c.apellidos) AS cliente,
                    e.nombre AS etapa, e.orden,
                    CONCAT_WS(' ', u.nombres, u.apellidos) AS responsable
             FROM oportunidades o
             INNER JOIN clientes c ON c.id_cliente = o.id_cliente
             LEFT JOIN etapas_pipeline e ON e.id_etapa = o.id_etapa
             LEFT JOIN usuarios u ON u.id_usuario = o.responsable_id
             {$filtroResponsable}
             ORDER BY e.orden, o.id_oportunidad DESC"
        );
        $stmt->execute($parametrosOportunidades);
        jsonResponse(['data' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $action === 'oportunidades') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $tipoCliente = trim((string) ($payload['tipo_cliente'] ?? ''));
        $cursoInteres = trim((string) ($payload['curso_interes'] ?? ''));
        $servicioInteres = trim((string) ($payload['servicio_interes'] ?? ''));
        $areaCliente = trim((string) ($payload['area_cliente'] ?? ''));
        $modalidad = trim((string) ($payload['modalidad'] ?? ''));
        $cantidadParticipantes = trim((string) ($payload['cantidad_participantes'] ?? ''));
        $horarioPreferido = trim((string) ($payload['horario_preferido'] ?? ''));
        $objetivoCliente = trim((string) ($payload['objetivo_cliente'] ?? ''));
        $participantes = null;
        if ($cantidadParticipantes !== '') {
            $participantes = filter_var($cantidadParticipantes, FILTER_VALIDATE_INT);
            if ($participantes === false || $participantes < 1 || $participantes > 65535) {
                jsonResponse(['error' => 'La cantidad de participantes debe ser un número entero entre 1 y 65535.'], 422);
            }
        }
        $datos = [
            ':id_cliente' => (int) ($payload['id_cliente'] ?? 0),
            ':id_etapa' => (int) ($payload['id_etapa'] ?? 0) ?: null,
            ':responsable_id' => (int) ($_SESSION['id_usuario'] ?? 0) ?: null,
            ':titulo' => trim((string) ($payload['titulo'] ?? '')),
            ':monto_estimado' => max(0, (float) ($payload['monto_estimado'] ?? 0)),
            ':fecha_proxima_accion' => isset($payload['fecha_proxima_accion']) && $payload['fecha_proxima_accion'] !== '' ? $payload['fecha_proxima_accion'] : null,
            ':observaciones' => trim((string) ($payload['observaciones'] ?? '')) ?: null,
            ':tipo_cliente' => $tipoCliente,
            ':curso_interes' => $cursoInteres,
            ':servicio_interes' => $servicioInteres,
            ':area_cliente' => $areaCliente,
            ':modalidad' => $modalidad !== '' ? $modalidad : null,
            ':cantidad_participantes' => $participantes,
            ':horario_preferido' => $horarioPreferido !== '' ? $horarioPreferido : null,
            ':objetivo_cliente' => $objetivoCliente !== '' ? $objetivoCliente : null,
        ];

        if ($datos[':id_cliente'] <= 0 || $datos[':titulo'] === '') {
            jsonResponse(['error' => 'El cliente y el nombre de la gestión son obligatorios.'], 422);
        }
        if (!in_array($tipoCliente, ['Persona natural', 'Empresa'], true)
            || $cursoInteres === '' || $servicioInteres === '' || $areaCliente === '') {
            jsonResponse(['error' => 'Completa el tipo de cliente, curso, servicio y área del cuestionario.'], 422);
        }
        if (!in_array($modalidad, ['', 'Presencial', 'Virtual', 'Híbrida'], true)) {
            jsonResponse(['error' => 'Selecciona una modalidad válida.'], 422);
        }

        $sql = "INSERT INTO oportunidades (
                    id_cliente, id_etapa, responsable_id, titulo, monto_estimado,
                    fecha_proxima_accion, observaciones, tipo_cliente, curso_interes,
                    servicio_interes, area_cliente, modalidad, cantidad_participantes,
                    horario_preferido, objetivo_cliente
                ) VALUES (
                    :id_cliente, :id_etapa, :responsable_id, :titulo, :monto_estimado,
                    :fecha_proxima_accion, :observaciones, :tipo_cliente, :curso_interes,
                    :servicio_interes, :area_cliente, :modalidad, :cantidad_participantes,
                    :horario_preferido, :objetivo_cliente
                )";
        $conexion->prepare($sql)->execute($datos);
        jsonResponse(['success' => true, 'message' => 'Gestión comercial y cuestionario guardados correctamente.']);
    }

    if ($method === 'POST' && $action === 'clientes') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $idCliente = (int) ($payload['id'] ?? $payload['id_cliente'] ?? 0);
        $nombres = trim((string) ($payload['nombres'] ?? ''));
        $apellidos = trim((string) ($payload['apellidos'] ?? ''));
        $tipoDocumento = trim((string) ($payload['tipo_documento'] ?? ''));
        $documento = trim((string) ($payload['documento'] ?? ''));
        $telefono = trim((string) ($payload['telefono'] ?? ''));

        if ($nombres === '' || !preg_match('/^[\p{L}]+(?: +[\p{L}]+)*$/u', $nombres)) {
            jsonResponse(['error' => 'El nombre solo puede contener letras y espacios.'], 422);
        }
        if ($apellidos !== '' && !preg_match('/^[\p{L}]+(?: +[\p{L}]+)*$/u', $apellidos)) {
            jsonResponse(['error' => 'Los apellidos solo pueden contener letras y espacios.'], 422);
        }
        $longitudDocumento = $tipoDocumento === 'DNI' ? 8 : ($tipoDocumento === 'RUC' ? 11 : 0);
        if ($longitudDocumento === 0 || !preg_match('/^[0-9]{' . $longitudDocumento . '}$/', $documento)) {
            jsonResponse(['error' => 'Selecciona DNI (8 dígitos) o RUC (11 dígitos) e ingresa su número completo.'], 422);
        }
        if ($telefono !== '' && !preg_match('/^9[0-9]{8}$/', $telefono)) {
            jsonResponse(['error' => 'El teléfono debe tener 9 dígitos y comenzar con 9.'], 422);
        }

        $datos = [
            ':nombres' => $nombres,
            ':apellidos' => $apellidos ?: null,
            ':tipo_documento' => $tipoDocumento,
            ':documento' => $documento,
            ':telefono' => $telefono ?: null,
            ':correo' => trim((string) ($payload['correo'] ?? '')) ?: null,
            ':empresa' => trim((string) ($payload['empresa'] ?? '')) ?: null,
            ':estado' => trim((string) ($payload['estado'] ?? 'Activo')) ?: 'Activo',
            ':observaciones' => trim((string) ($payload['observaciones'] ?? '')) ?: null,
        ];

        if ($idCliente > 0) {
            $datos[':id_cliente'] = $idCliente;
            $sql = "UPDATE clientes SET nombres = :nombres, apellidos = :apellidos, tipo_documento = :tipo_documento, documento = :documento,
                    telefono = :telefono, correo = :correo, empresa = :empresa, estado = :estado,
                    observaciones = :observaciones WHERE id_cliente = :id_cliente";
            $conexion->prepare($sql)->execute($datos);
            jsonResponse(['success' => true, 'message' => 'Cliente actualizado correctamente.']);
        }

        $sql = "INSERT INTO clientes (nombres, apellidos, tipo_documento, documento, telefono, correo, empresa, estado, observaciones)
                VALUES (:nombres, :apellidos, :tipo_documento, :documento, :telefono, :correo, :empresa, :estado, :observaciones)";
        $conexion->beginTransaction();
        $conexion->prepare($sql)->execute($datos);
        notificarNuevoRegistroComercial(
            $conexion,
            'cliente',
            (int) $conexion->lastInsertId(),
            trim($datos[':nombres'] . ' ' . ($datos[':apellidos'] ?? '')),
            (int) $_SESSION['id_usuario']
        );
        $conexion->commit();
        jsonResponse(['success' => true, 'message' => 'Cliente registrado correctamente.']);
    }

    if ($method === 'DELETE' && $action === 'clientes') {
        $idCliente = (int) ($_GET['id'] ?? 0);
        if ($idCliente <= 0) {
            jsonResponse(['error' => 'Falta el identificador del cliente.'], 422);
        }

        $conexion->prepare('DELETE FROM clientes WHERE id_cliente = :id_cliente')->execute([':id_cliente' => $idCliente]);
        jsonResponse(['success' => true, 'message' => 'Cliente eliminado correctamente.']);
    }

    if ($method === 'PATCH' && $action === 'oportunidades') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $idOportunidad = (int) ($payload['id'] ?? $payload['id_oportunidad'] ?? 0);
        if ($idOportunidad <= 0) {
            jsonResponse(['error' => 'Falta el identificador de la oportunidad.'], 422);
        }
        $verificarAccesoOportunidad($idOportunidad);

        if (array_key_exists('titulo', $payload)) {
            $tipoCliente = trim((string) ($payload['tipo_cliente'] ?? ''));
            $cursoInteres = trim((string) ($payload['curso_interes'] ?? ''));
            $servicioInteres = trim((string) ($payload['servicio_interes'] ?? ''));
            $areaCliente = trim((string) ($payload['area_cliente'] ?? ''));
            $modalidad = trim((string) ($payload['modalidad'] ?? ''));
            $cantidadTexto = trim((string) ($payload['cantidad_participantes'] ?? ''));
            $participantes = $cantidadTexto === '' ? null : filter_var($cantidadTexto, FILTER_VALIDATE_INT);
            if ((int) ($payload['id_cliente'] ?? 0) <= 0 || (int) ($payload['id_etapa'] ?? 0) <= 0
                || trim((string) $payload['titulo']) === ''
                || !in_array($tipoCliente, ['Persona natural', 'Empresa'], true)
                || $cursoInteres === '' || $servicioInteres === '' || $areaCliente === ''
                || !in_array($modalidad, ['', 'Presencial', 'Virtual', 'Híbrida'], true)
                || ($cantidadTexto !== '' && ($participantes === false || $participantes < 1 || $participantes > 65535))) {
                jsonResponse(['error' => 'Completa los datos obligatorios de la gestión y selecciona valores válidos.'], 422);
            }

            $datos = [
                ':id' => $idOportunidad,
                ':id_cliente' => (int) $payload['id_cliente'],
                ':id_etapa' => (int) $payload['id_etapa'],
                ':titulo' => trim((string) $payload['titulo']),
                ':monto_estimado' => max(0, (float) ($payload['monto_estimado'] ?? 0)),
                ':fecha_proxima_accion' => trim((string) ($payload['fecha_proxima_accion'] ?? '')) ?: null,
                ':observaciones' => trim((string) ($payload['observaciones'] ?? '')) ?: null,
                ':tipo_cliente' => $tipoCliente,
                ':curso_interes' => $cursoInteres,
                ':servicio_interes' => $servicioInteres,
                ':area_cliente' => $areaCliente,
                ':modalidad' => $modalidad !== '' ? $modalidad : null,
                ':cantidad_participantes' => $participantes,
                ':horario_preferido' => trim((string) ($payload['horario_preferido'] ?? '')) ?: null,
                ':objetivo_cliente' => trim((string) ($payload['objetivo_cliente'] ?? '')) ?: null,
            ];
            $stmt = $conexion->prepare(
                'UPDATE oportunidades SET id_cliente = :id_cliente, id_etapa = :id_etapa, titulo = :titulo,
                 monto_estimado = :monto_estimado, fecha_proxima_accion = :fecha_proxima_accion,
                 observaciones = :observaciones, tipo_cliente = :tipo_cliente, curso_interes = :curso_interes,
                 servicio_interes = :servicio_interes, area_cliente = :area_cliente, modalidad = :modalidad,
                 cantidad_participantes = :cantidad_participantes, horario_preferido = :horario_preferido,
                 objetivo_cliente = :objetivo_cliente WHERE id_oportunidad = :id'
            );
            $stmt->execute($datos);
            if ($stmt->rowCount() === 0) {
                $existe = $conexion->prepare('SELECT 1 FROM oportunidades WHERE id_oportunidad = :id');
                $existe->execute([':id' => $idOportunidad]);
                if (!$existe->fetchColumn()) {
                    jsonResponse(['error' => 'La oportunidad seleccionada no existe.'], 404);
                }
            }
            jsonResponse(['success' => true, 'message' => 'Gestión comercial actualizada correctamente.']);
        }

        $idEtapa = (int) ($payload['id_etapa'] ?? 0);
        if ($idEtapa <= 0) {
            jsonResponse(['error' => 'Faltan datos para actualizar la etapa.'], 422);
        }

        $stmt = $conexion->prepare('UPDATE oportunidades SET id_etapa = :id_etapa WHERE id_oportunidad = :id_oportunidad');
        $stmt->execute([':id_etapa' => $idEtapa, ':id_oportunidad' => $idOportunidad]);
        if ($stmt->rowCount() === 0) {
            $existe = $conexion->prepare('SELECT 1 FROM oportunidades WHERE id_oportunidad = :id');
            $existe->execute([':id' => $idOportunidad]);
            if (!$existe->fetchColumn()) {
                jsonResponse(['error' => 'La oportunidad seleccionada no existe.'], 404);
            }
        }

        jsonResponse(['success' => true, 'message' => 'Etapa actualizada correctamente.']);
    }

    if ($method === 'DELETE' && $action === 'oportunidades') {
        $idOportunidad = (int) ($_GET['id'] ?? 0);
        if ($idOportunidad <= 0) {
            jsonResponse(['error' => 'Falta el identificador de la oportunidad.'], 422);
        }

        $verificarAccesoOportunidad($idOportunidad);

        $conexion->prepare('DELETE FROM oportunidades WHERE id_oportunidad = :id_oportunidad')->execute([
            ':id_oportunidad' => $idOportunidad,
        ]);
        jsonResponse(['success' => true, 'message' => 'Oportunidad eliminada correctamente.']);
    }

    jsonResponse(['error' => 'Acción no soportada.'], 400);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    if ($archivoDocumentoGuardado !== null && is_file($archivoDocumentoGuardado)
        && !unlink($archivoDocumentoGuardado)) {
        jsonResponse(['error' => 'No se pudo completar la operación y el archivo requiere revisión manual.'], 500);
    }
    jsonResponse(['error' => $e->getMessage()], 500);
}
