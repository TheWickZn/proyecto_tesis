<?php

function ejecutarMigraciones(PDO $conexion): void
{
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS migraciones (
            id_migracion INT UNSIGNED NOT NULL AUTO_INCREMENT,
            version VARCHAR(50) NOT NULL,
            ejecutada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_migracion),
            UNIQUE KEY uq_migraciones_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS usuarios (
            id_usuario INT NOT NULL AUTO_INCREMENT,
            usuario VARCHAR(80) NOT NULL,
            password VARCHAR(255) NOT NULL,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NOT NULL DEFAULT '',
            rol VARCHAR(50) NOT NULL DEFAULT 'Ventas',
            estado TINYINT(1) NOT NULL DEFAULT 1,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_usuario),
            UNIQUE KEY uq_usuarios_usuario (usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    if ((int) $conexion->query("SELECT COUNT(*) FROM usuarios")->fetchColumn() === 0) {
        $configuracionHosting = $GLOBALS["configuracionHosting"] ?? [];
        $usuarioAdministrador = trim((string) (getenv("APP_ADMIN_USERNAME") ?: ($configuracionHosting["APP_ADMIN_USERNAME"] ?? "")));
        $passwordAdministrador = (string) (getenv("APP_ADMIN_PASSWORD") ?: ($configuracionHosting["APP_ADMIN_PASSWORD"] ?? ""));
    }

    if (
        isset($usuarioAdministrador, $passwordAdministrador)
        && $usuarioAdministrador !== ""
        && $passwordAdministrador !== ""
    ) {
        $crearAdministrador = $conexion->prepare(
            "INSERT INTO usuarios (usuario, password, nombres, rol, estado)
             VALUES (:usuario, :password, 'Administrador', 'Administrador', 1)"
        );
        $crearAdministrador->execute([
            ":usuario" => $usuarioAdministrador,
            ":password" => password_hash($passwordAdministrador, PASSWORD_DEFAULT),
        ]);
    } elseif (isset($usuarioAdministrador, $passwordAdministrador)) {
        error_log("Configure APP_ADMIN_USERNAME and APP_ADMIN_PASSWORD to create the initial administrator.");
    }

    $version = "001_esquema_empresarial";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);

    if ($stmt->fetchColumn()) {
        ejecutarMigracionProspectos($conexion);
        ejecutarMigracionRelaciones($conexion);
        ejecutarMigracionTesis($conexion);
        ejecutarMigracionCuestionarioComercial($conexion);
        ejecutarMigracionPermisosAreas($conexion);
        ejecutarMigracionDatosFormularios($conexion);
        ejecutarMigracionPermisoProspectosCompletos($conexion);
        ejecutarMigracionAreasEmpresaYLegal($conexion);
        ejecutarMigracionTituloDocumentos($conexion);
        ejecutarMigracionBloquesTrabajador($conexion);
        ejecutarMigracionNotificaciones($conexion);
        ejecutarMigracionCatalogosYSistemaDocumentos($conexion);
        ejecutarMigracionCrmInteraccionesYKanban($conexion);
        ejecutarMigracionRolesCategoriasYTrabajadores($conexion);
        ejecutarMigracionUsuariosDemoPorCategoria($conexion);
        ejecutarMigracionCuentaTrabajadorLegada($conexion);
        ejecutarMigracionFormularioPublicoProspectos($conexion);
        ejecutarMigracionGestionClientesYProyectos($conexion);
        return;
    }

    $tablas = [
        "CREATE TABLE IF NOT EXISTS roles (
            id_rol INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(50) NOT NULL,
            codigo VARCHAR(50) NOT NULL,
            PRIMARY KEY (id_rol),
            UNIQUE KEY uq_roles_nombre (nombre),
            UNIQUE KEY uq_roles_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS permisos (
            id_permiso INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(100) NOT NULL,
            codigo VARCHAR(100) NOT NULL,
            PRIMARY KEY (id_permiso),
            UNIQUE KEY uq_permisos_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS rol_permisos (
            id_rol INT UNSIGNED NOT NULL,
            id_permiso INT UNSIGNED NOT NULL,
            PRIMARY KEY (id_rol, id_permiso),
            CONSTRAINT fk_rol_permisos_rol FOREIGN KEY (id_rol) REFERENCES roles (id_rol) ON DELETE CASCADE,
            CONSTRAINT fk_rol_permisos_permiso FOREIGN KEY (id_permiso) REFERENCES permisos (id_permiso) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS clientes (
            id_cliente INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NULL,
            documento VARCHAR(30) NULL,
            telefono VARCHAR(30) NULL,
            correo VARCHAR(150) NULL,
            empresa VARCHAR(150) NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
            observaciones TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_cliente),
            KEY idx_clientes_nombre (apellidos, nombres)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS servicios (
            id_servicio INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(150) NOT NULL,
            descripcion TEXT NULL,
            precio DECIMAL(12,2) NOT NULL DEFAULT 0,
            estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_servicio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS etapas_pipeline (
            id_etapa INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(80) NOT NULL,
            orden INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id_etapa),
            UNIQUE KEY uq_etapas_pipeline_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS oportunidades (
            id_oportunidad INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_cliente INT UNSIGNED NOT NULL,
            id_etapa INT UNSIGNED NULL,
            responsable_id INT NULL,
            titulo VARCHAR(150) NOT NULL,
            monto_estimado DECIMAL(12,2) NOT NULL DEFAULT 0,
            fecha_proxima_accion DATE NULL,
            observaciones TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_oportunidad),
            KEY idx_oportunidades_cliente (id_cliente),
            CONSTRAINT fk_oportunidades_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente),
            CONSTRAINT fk_oportunidades_etapa FOREIGN KEY (id_etapa) REFERENCES etapas_pipeline (id_etapa) ON DELETE SET NULL,
            CONSTRAINT fk_oportunidades_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS ventas (
            id_venta INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_cliente INT UNSIGNED NOT NULL,
            id_servicio INT UNSIGNED NULL,
            id_oportunidad INT UNSIGNED NULL,
            responsable_id INT NULL,
            monto DECIMAL(12,2) NOT NULL DEFAULT 0,
            fecha DATE NOT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
            observaciones TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_venta),
            KEY idx_ventas_fecha (fecha),
            CONSTRAINT fk_ventas_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente),
            CONSTRAINT fk_ventas_servicio FOREIGN KEY (id_servicio) REFERENCES servicios (id_servicio) ON DELETE SET NULL,
            CONSTRAINT fk_ventas_oportunidad FOREIGN KEY (id_oportunidad) REFERENCES oportunidades (id_oportunidad) ON DELETE SET NULL,
            CONSTRAINT fk_ventas_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS expedientes (
            id_expediente INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_cliente INT UNSIGNED NOT NULL,
            numero_expediente VARCHAR(80) NOT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Abierto',
            descripcion TEXT NULL,
            observaciones TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_expediente),
            UNIQUE KEY uq_expedientes_numero (numero_expediente),
            CONSTRAINT fk_expedientes_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS recibos_expedientes (
            id_recibo INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_expediente INT UNSIGNED NOT NULL,
            numero_recibo VARCHAR(80) NOT NULL,
            fecha DATE NOT NULL,
            monto DECIMAL(12,2) NOT NULL DEFAULT 0,
            estado VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
            observaciones TEXT NULL,
            PRIMARY KEY (id_recibo),
            KEY idx_recibos_expediente (id_expediente),
            CONSTRAINT fk_recibos_expediente FOREIGN KEY (id_expediente) REFERENCES expedientes (id_expediente) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS casos_legales (
            id_caso INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_cliente INT UNSIGNED NOT NULL,
            numero_expediente VARCHAR(80) NULL,
            tipo_proceso VARCHAR(120) NOT NULL,
            responsable_id INT NULL,
            fecha_inicio DATE NOT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
            descripcion TEXT NULL,
            observaciones TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_caso),
            CONSTRAINT fk_casos_legales_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente),
            CONSTRAINT fk_casos_legales_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS horarios (
            id_horario INT UNSIGNED NOT NULL AUTO_INCREMENT,
            trabajador_id INT NOT NULL,
            actividad VARCHAR(150) NOT NULL,
            area VARCHAR(100) NOT NULL,
            fecha DATE NOT NULL,
            hora_inicio TIME NOT NULL,
            hora_finalizacion TIME NOT NULL,
            observaciones TEXT NULL,
            PRIMARY KEY (id_horario),
            KEY idx_horarios_filtro (trabajador_id, area, fecha),
            CONSTRAINT fk_horarios_trabajador FOREIGN KEY (trabajador_id) REFERENCES usuarios (id_usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS recursos (
            id_recurso INT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo VARCHAR(60) NOT NULL,
            nombre VARCHAR(150) NOT NULL,
            categoria VARCHAR(100) NOT NULL,
            descripcion TEXT NULL,
            cantidad INT NOT NULL DEFAULT 0,
            estado VARCHAR(40) NOT NULL DEFAULT 'Disponible',
            ubicacion VARCHAR(150) NULL,
            responsable_id INT NULL,
            fecha_registro DATE NOT NULL,
            observaciones TEXT NULL,
            PRIMARY KEY (id_recurso),
            UNIQUE KEY uq_recursos_codigo (codigo),
            CONSTRAINT fk_recursos_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS asignaciones_recursos (
            id_asignacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
            trabajador_id INT NOT NULL,
            recurso_id INT UNSIGNED NOT NULL,
            fecha_asignacion DATE NOT NULL,
            cantidad INT NOT NULL DEFAULT 1,
            estado_entrega VARCHAR(40) NOT NULL DEFAULT 'Entregado',
            fecha_devolucion DATE NULL,
            observaciones TEXT NULL,
            PRIMARY KEY (id_asignacion),
            CONSTRAINT fk_asignaciones_trabajador FOREIGN KEY (trabajador_id) REFERENCES usuarios (id_usuario),
            CONSTRAINT fk_asignaciones_recurso FOREIGN KEY (recurso_id) REFERENCES recursos (id_recurso)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS reconteos (
            id_reconteo INT UNSIGNED NOT NULL AUTO_INCREMENT,
            recurso_id INT UNSIGNED NOT NULL,
            fecha DATE NOT NULL,
            responsable_id INT NOT NULL,
            cantidad_registrada INT NOT NULL DEFAULT 0,
            cantidad_encontrada INT NOT NULL DEFAULT 0,
            diferencia INT AS (cantidad_encontrada - cantidad_registrada) STORED,
            observaciones TEXT NULL,
            PRIMARY KEY (id_reconteo),
            CONSTRAINT fk_reconteos_recurso FOREIGN KEY (recurso_id) REFERENCES recursos (id_recurso),
            CONSTRAINT fk_reconteos_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS compras_almacen (
            id_compra INT UNSIGNED NOT NULL AUTO_INCREMENT,
            material VARCHAR(150) NOT NULL,
            cantidad_actual INT NOT NULL DEFAULT 0,
            cantidad_minima INT NOT NULL DEFAULT 0,
            cantidad_comprar INT NOT NULL DEFAULT 0,
            responsable_id INT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Pendiente',
            observaciones TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_compra),
            CONSTRAINT fk_compras_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS seguimientos_psicologia (
            id_seguimiento INT UNSIGNED NOT NULL AUTO_INCREMENT,
            persona VARCHAR(150) NOT NULL,
            fecha_atencion DATE NOT NULL,
            profesional_id INT NULL,
            tipo_seguimiento VARCHAR(100) NOT NULL,
            estado VARCHAR(40) NOT NULL DEFAULT 'Pendiente',
            observaciones_generales TEXT NULL,
            proxima_fecha DATE NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_seguimiento),
            CONSTRAINT fk_psicologia_profesional FOREIGN KEY (profesional_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS formaciones_marketing (
            id_formacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(150) NOT NULL,
            descripcion TEXT NULL,
            responsable_id INT NULL,
            fecha DATE NOT NULL,
            duracion_minutos INT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Programada',
            observaciones TEXT NULL,
            PRIMARY KEY (id_formacion),
            CONSTRAINT fk_formaciones_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS formacion_participantes (
            id_formacion INT UNSIGNED NOT NULL,
            trabajador_id INT NOT NULL,
            PRIMARY KEY (id_formacion, trabajador_id),
            CONSTRAINT fk_participantes_formacion FOREIGN KEY (id_formacion) REFERENCES formaciones_marketing (id_formacion) ON DELETE CASCADE,
            CONSTRAINT fk_participantes_trabajador FOREIGN KEY (trabajador_id) REFERENCES usuarios (id_usuario) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS movimientos_financieros (
            id_movimiento INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo VARCHAR(20) NOT NULL,
            categoria VARCHAR(100) NOT NULL,
            concepto VARCHAR(180) NOT NULL,
            persona_proveedor VARCHAR(150) NULL,
            tipo_persona VARCHAR(20) NULL,
            monto DECIMAL(12,2) NOT NULL DEFAULT 0,
            fecha DATE NOT NULL,
            metodo_pago VARCHAR(40) NOT NULL,
            responsable_id INT NULL,
            numero_comprobante VARCHAR(100) NULL,
            observaciones TEXT NULL,
            PRIMARY KEY (id_movimiento),
            KEY idx_movimientos_tipo_fecha (tipo, fecha),
            CONSTRAINT fk_movimientos_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS comprobantes (
            id_comprobante INT UNSIGNED NOT NULL AUTO_INCREMENT,
            movimiento_id INT UNSIGNED NOT NULL,
            nombre_archivo VARCHAR(255) NOT NULL,
            ruta_archivo VARCHAR(500) NOT NULL,
            tipo_mime VARCHAR(100) NULL,
            estado_validacion VARCHAR(40) NOT NULL DEFAULT 'Pendiente de validación',
            revisado_por INT NULL,
            revisado_en DATETIME NULL,
            observaciones TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_comprobante),
            CONSTRAINT fk_comprobantes_movimiento FOREIGN KEY (movimiento_id) REFERENCES movimientos_financieros (id_movimiento) ON DELETE CASCADE,
            CONSTRAINT fk_comprobantes_revisor FOREIGN KEY (revisado_por) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    try {
        $conexion->beginTransaction();

        foreach ($tablas as $sql) {
            $conexion->exec($sql);
        }

        $roles = [
            ["Administrador", "administrador"],
            ["Ventas", "ventas"],
            ["Vendedor", "vendedor"],
            ["Legal", "legal"],
            ["Contabilidad", "contabilidad"],
            ["Recursos Humanos", "recursos_humanos"],
            ["Psicología", "psicologia"],
            ["Empresa", "empresa"],
        ];
        $insertarRol = $conexion->prepare("INSERT IGNORE INTO roles (nombre, codigo) VALUES (:nombre, :codigo)");
        foreach ($roles as $rol) {
            $insertarRol->execute([":nombre" => $rol[0], ":codigo" => $rol[1]]);
        }

        $permisos = [
            ["Ver dashboard", "dashboard.ver"],
            ["Gestionar CRM", "crm.gestionar"],
            ["Gestionar ventas", "ventas.gestionar"],
            ["Gestionar horarios", "horarios.gestionar"],
            ["Gestionar inventario", "inventario.gestionar"],
            ["Gestionar legal", "legal.gestionar"],
            ["Ver psicología", "psicologia.ver"],
            ["Gestionar psicología", "psicologia.gestionar"],
            ["Gestionar formación", "formacion.gestionar"],
            ["Ver finanzas", "finanzas.ver"],
            ["Gestionar finanzas", "finanzas.gestionar"],
            ["Gestionar usuarios", "usuarios.gestionar"],
        ];
        $insertarPermiso = $conexion->prepare("INSERT IGNORE INTO permisos (nombre, codigo) VALUES (:nombre, :codigo)");
        foreach ($permisos as $permiso) {
            $insertarPermiso->execute([":nombre" => $permiso[0], ":codigo" => $permiso[1]]);
        }

        $conexion->exec(
            "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
             SELECT r.id_rol, p.id_permiso
             FROM roles r CROSS JOIN permisos p
             WHERE r.codigo = 'administrador'"
        );

        $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
        $registrar->execute([":version" => $version]);
        $conexion->commit();
        ejecutarMigracionProspectos($conexion);
        ejecutarMigracionRelaciones($conexion);
        ejecutarMigracionTesis($conexion);
        ejecutarMigracionCuestionarioComercial($conexion);
        ejecutarMigracionPermisosAreas($conexion);
        ejecutarMigracionDatosFormularios($conexion);
        ejecutarMigracionPermisoProspectosCompletos($conexion);
        ejecutarMigracionAreasEmpresaYLegal($conexion);
        ejecutarMigracionTituloDocumentos($conexion);
        ejecutarMigracionBloquesTrabajador($conexion);
        ejecutarMigracionNotificaciones($conexion);
        ejecutarMigracionCatalogosYSistemaDocumentos($conexion);
        ejecutarMigracionCrmInteraccionesYKanban($conexion);
        ejecutarMigracionRolesCategoriasYTrabajadores($conexion);
        ejecutarMigracionUsuariosDemoPorCategoria($conexion);
        ejecutarMigracionCuentaTrabajadorLegada($conexion);
        ejecutarMigracionFormularioPublicoProspectos($conexion);
        ejecutarMigracionGestionClientesYProyectos($conexion);
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        throw $e;
    }
}

function ejecutarMigracionProspectos(PDO $conexion): void
{
    $version = "002_prospectos_y_seguimientos";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS prospectos (
            id_prospecto INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NULL,
            telefono VARCHAR(30) NULL,
            correo VARCHAR(150) NULL,
            universidad VARCHAR(180) NULL,
            carrera VARCHAR(150) NULL,
            servicio VARCHAR(150) NULL,
            fuente VARCHAR(120) NULL,
            vendedor_id INT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Nuevo',
            observaciones TEXT NULL,
            proxima_accion DATE NULL,
            creado_por INT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_prospecto),
            KEY idx_prospectos_estado (estado),
            KEY idx_prospectos_vendedor (vendedor_id),
            KEY idx_prospectos_proxima_accion (proxima_accion),
            CONSTRAINT fk_prospectos_vendedor FOREIGN KEY (vendedor_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL,
            CONSTRAINT fk_prospectos_creador FOREIGN KEY (creado_por) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS seguimientos_prospectos (
            id_seguimiento INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_prospecto INT UNSIGNED NOT NULL,
            usuario_id INT NULL,
            fecha DATE NOT NULL,
            accion VARCHAR(180) NOT NULL,
            observaciones TEXT NULL,
            proxima_accion DATE NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_seguimiento),
            KEY idx_seguimientos_prospecto_fecha (id_prospecto, fecha),
            CONSTRAINT fk_seguimientos_prospecto FOREIGN KEY (id_prospecto) REFERENCES prospectos (id_prospecto) ON DELETE CASCADE,
            CONSTRAINT fk_seguimientos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $conexion->exec("INSERT IGNORE INTO roles (nombre, codigo) VALUES ('Vendedor', 'vendedor')");
    $conexion->exec("INSERT IGNORE INTO permisos (nombre, codigo) VALUES ('Gestionar CRM y prospectos', 'crm.gestionar')");
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo IN ('ventas', 'vendedor') AND p.codigo = 'crm.gestionar'"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionRelaciones(PDO $conexion): void
{
    $version = "003_relaciones_foraneas";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $columnaExiste = static function (string $tabla, string $columna) use ($conexion): bool {
        $stmt = $conexion->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :tabla AND column_name = :columna LIMIT 1"
        );
        $stmt->execute([":tabla" => $tabla, ":columna" => $columna]);
        return (bool) $stmt->fetchColumn();
    };
    $claveForaneaExiste = static function (string $tabla, string $nombre) use ($conexion): bool {
        $stmt = $conexion->prepare(
            "SELECT 1 FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND table_name = :tabla
               AND constraint_name = :nombre AND constraint_type = 'FOREIGN KEY' LIMIT 1"
        );
        $stmt->execute([":tabla" => $tabla, ":nombre" => $nombre]);
        return (bool) $stmt->fetchColumn();
    };

    if (!$columnaExiste("usuarios", "id_rol")) {
        $conexion->exec("ALTER TABLE usuarios ADD COLUMN id_rol INT UNSIGNED NULL AFTER id_usuario");
    }
    if ($columnaExiste("usuarios", "rol")) {
        $conexion->exec(
            "UPDATE usuarios u
             INNER JOIN roles r ON LOWER(r.codigo) = LOWER(u.rol) OR LOWER(r.nombre) = LOWER(u.rol)
             SET u.id_rol = r.id_rol
             WHERE u.id_rol IS NULL"
        );
    }
    $usuariosSinRol = (int) $conexion->query("SELECT COUNT(*) FROM usuarios WHERE id_rol IS NULL")->fetchColumn();
    if ($usuariosSinRol > 0) {
        throw new RuntimeException("No se puede relacionar usuarios: hay {$usuariosSinRol} cuenta(s) con rol sin correspondencia.");
    }
    $conexion->exec("ALTER TABLE usuarios MODIFY id_rol INT UNSIGNED NOT NULL");
    if (!$claveForaneaExiste("usuarios", "fk_usuarios_rol")) {
        $conexion->exec(
            "ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_rol
             FOREIGN KEY (id_rol) REFERENCES roles (id_rol)"
        );
    }
    if ($columnaExiste("usuarios", "rol")) {
        $conexion->exec("ALTER TABLE usuarios DROP COLUMN rol");
    }

    if (!$columnaExiste("casos_legales", "id_expediente")) {
        $conexion->exec("ALTER TABLE casos_legales ADD COLUMN id_expediente INT UNSIGNED NULL AFTER id_cliente");
    }
    if (!$columnaExiste("casos_legales", "numero_expediente_legacy")) {
        $conexion->exec("ALTER TABLE casos_legales ADD COLUMN numero_expediente_legacy VARCHAR(80) NULL AFTER id_expediente");
    }
    if ($columnaExiste("casos_legales", "numero_expediente")) {
        $conexion->exec(
            "UPDATE casos_legales c
             INNER JOIN expedientes e ON e.numero_expediente = c.numero_expediente
             SET c.id_expediente = e.id_expediente
             WHERE c.id_expediente IS NULL"
        );
        $conexion->exec(
            "UPDATE casos_legales
             SET numero_expediente_legacy = numero_expediente
             WHERE numero_expediente IS NOT NULL AND id_expediente IS NULL
               AND numero_expediente_legacy IS NULL"
        );
        $conexion->exec("ALTER TABLE casos_legales DROP COLUMN numero_expediente");
    }
    if (!$claveForaneaExiste("casos_legales", "fk_casos_legales_expediente")) {
        $conexion->exec(
            "ALTER TABLE casos_legales ADD CONSTRAINT fk_casos_legales_expediente
             FOREIGN KEY (id_expediente) REFERENCES expedientes (id_expediente) ON DELETE SET NULL"
        );
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionTesis(PDO $conexion): void
{
    $version = "004_gestion_academica_tesis";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $tablas = [
        "CREATE TABLE IF NOT EXISTS universidades (
            id_universidad INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(180) NOT NULL,
            abreviatura VARCHAR(40) NULL,
            pais VARCHAR(100) NOT NULL DEFAULT 'Perú',
            estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_universidad),
            UNIQUE KEY uq_universidades_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS programas_academicos (
            id_programa INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_universidad INT UNSIGNED NOT NULL,
            nombre VARCHAR(180) NOT NULL,
            facultad VARCHAR(180) NULL,
            grado VARCHAR(60) NOT NULL DEFAULT '',
            estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_programa),
            UNIQUE KEY uq_programa_universidad_nombre (id_universidad, nombre, grado),
            CONSTRAINT fk_programa_universidad FOREIGN KEY (id_universidad)
                REFERENCES universidades (id_universidad) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS etapas_tesis (
            id_etapa_tesis INT UNSIGNED NOT NULL AUTO_INCREMENT,
            codigo VARCHAR(50) NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            orden INT NOT NULL,
            descripcion VARCHAR(255) NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
            PRIMARY KEY (id_etapa_tesis),
            UNIQUE KEY uq_etapas_tesis_codigo (codigo),
            UNIQUE KEY uq_etapas_tesis_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS proyectos_tesis (
            id_tesis INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_expediente INT UNSIGNED NOT NULL,
            id_programa INT UNSIGNED NULL,
            id_servicio INT UNSIGNED NULL,
            titulo VARCHAR(255) NOT NULL,
            linea_investigacion VARCHAR(180) NULL,
            tipo_investigacion VARCHAR(100) NULL,
            problema_investigacion TEXT NULL,
            objetivos TEXT NULL,
            metodologia TEXT NULL,
            estado VARCHAR(40) NOT NULL DEFAULT 'Propuesta',
            fecha_inicio DATE NULL,
            fecha_entrega_estimada DATE NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_tesis),
            UNIQUE KEY uq_tesis_expediente (id_expediente),
            KEY idx_tesis_programa_estado (id_programa, estado),
            KEY idx_tesis_fecha_entrega (fecha_entrega_estimada),
            CONSTRAINT fk_tesis_expediente FOREIGN KEY (id_expediente)
                REFERENCES expedientes (id_expediente) ON DELETE CASCADE,
            CONSTRAINT fk_tesis_programa FOREIGN KEY (id_programa)
                REFERENCES programas_academicos (id_programa) ON DELETE SET NULL,
            CONSTRAINT fk_tesis_servicio FOREIGN KEY (id_servicio)
                REFERENCES servicios (id_servicio) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS tesis_asesores (
            id_asignacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_tesis INT UNSIGNED NOT NULL,
            id_usuario INT NULL,
            tipo_asesor VARCHAR(40) NOT NULL DEFAULT 'Principal',
            fecha_asignacion DATE NOT NULL,
            fecha_fin DATE NULL,
            observaciones TEXT NULL,
            PRIMARY KEY (id_asignacion),
            KEY idx_tesis_asesor_vigencia (id_tesis, fecha_fin),
            KEY idx_asesores_usuario (id_usuario),
            CONSTRAINT fk_tesis_asesor_proyecto FOREIGN KEY (id_tesis)
                REFERENCES proyectos_tesis (id_tesis) ON DELETE CASCADE,
            CONSTRAINT fk_tesis_asesor_usuario FOREIGN KEY (id_usuario)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS hitos_tesis (
            id_hito INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_tesis INT UNSIGNED NOT NULL,
            id_etapa_tesis INT UNSIGNED NOT NULL,
            responsable_id INT NULL,
            fecha_objetivo DATE NULL,
            fecha_completado DATE NULL,
            estado VARCHAR(40) NOT NULL DEFAULT 'Pendiente',
            observaciones TEXT NULL,
            PRIMARY KEY (id_hito),
            UNIQUE KEY uq_hito_tesis_etapa (id_tesis, id_etapa_tesis),
            KEY idx_hitos_responsable_estado (responsable_id, estado),
            CONSTRAINT fk_hito_tesis_proyecto FOREIGN KEY (id_tesis)
                REFERENCES proyectos_tesis (id_tesis) ON DELETE CASCADE,
            CONSTRAINT fk_hito_tesis_etapa FOREIGN KEY (id_etapa_tesis)
                REFERENCES etapas_tesis (id_etapa_tesis) ON DELETE RESTRICT,
            CONSTRAINT fk_hito_tesis_responsable FOREIGN KEY (responsable_id)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS entregables_tesis (
            id_entregable INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_hito INT UNSIGNED NOT NULL,
            titulo VARCHAR(180) NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            ruta_archivo VARCHAR(500) NULL,
            estado VARCHAR(40) NOT NULL DEFAULT 'En revisión',
            comentarios_asesor TEXT NULL,
            enviado_por INT NULL,
            revisado_por INT NULL,
            fecha_envio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_revision DATETIME NULL,
            PRIMARY KEY (id_entregable),
            UNIQUE KEY uq_entregable_hito_version (id_hito, version),
            KEY idx_entregables_estado_fecha (estado, fecha_envio),
            CONSTRAINT fk_entregable_hito FOREIGN KEY (id_hito)
                REFERENCES hitos_tesis (id_hito) ON DELETE CASCADE,
            CONSTRAINT fk_entregable_emisor FOREIGN KEY (enviado_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL,
            CONSTRAINT fk_entregable_revisor FOREIGN KEY (revisado_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    foreach ($tablas as $sql) {
        $conexion->exec($sql);
    }

    $etapas = [
        ["propuesta", "Propuesta", 1, "Definición y aprobación del tema de investigación."],
        ["plan_tesis", "Plan de tesis", 2, "Elaboración y aprobación del plan de investigación."],
        ["marco_teorico", "Marco teórico", 3, "Desarrollo de antecedentes y bases teóricas."],
        ["metodologia", "Metodología", 4, "Diseño y validación metodológica."],
        ["trabajo_campo", "Trabajo de campo", 5, "Recolección y procesamiento de datos."],
        ["resultados", "Resultados y discusión", 6, "Análisis, interpretación y discusión de resultados."],
        ["revision_final", "Revisión final", 7, "Correcciones, formato y preparación de sustentación."],
        ["sustentacion", "Sustentación", 8, "Preparación y registro de la defensa académica."],
    ];
    $insertarEtapa = $conexion->prepare(
        "INSERT IGNORE INTO etapas_tesis (codigo, nombre, orden, descripcion)
         VALUES (:codigo, :nombre, :orden, :descripcion)"
    );
    foreach ($etapas as $etapa) {
        $insertarEtapa->execute([
            ":codigo" => $etapa[0],
            ":nombre" => $etapa[1],
            ":orden" => $etapa[2],
            ":descripcion" => $etapa[3],
        ]);
    }

    $conexion->exec("INSERT IGNORE INTO roles (nombre, codigo) VALUES ('Asesor Académico', 'asesor_academico')");
    $conexion->exec("INSERT IGNORE INTO permisos (nombre, codigo) VALUES ('Gestionar proyectos de tesis', 'tesis.gestionar')");
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'administrador' AND p.codigo = 'tesis.gestionar'"
    );
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'asesor_academico' AND p.codigo = 'tesis.gestionar'"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionCuestionarioComercial(PDO $conexion): void
{
    $version = "005_cuestionario_gestion_comercial";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $columnas = [
        "tipo_cliente" => "VARCHAR(30) NULL",
        "curso_interes" => "VARCHAR(180) NULL",
        "servicio_interes" => "VARCHAR(500) NULL",
        "area_cliente" => "VARCHAR(150) NULL",
        "modalidad" => "VARCHAR(30) NULL",
        "cantidad_participantes" => "SMALLINT UNSIGNED NULL",
        "horario_preferido" => "VARCHAR(180) NULL",
        "objetivo_cliente" => "TEXT NULL",
    ];
    $consultaColumna = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'oportunidades'
           AND column_name = :columna LIMIT 1"
    );

    foreach ($columnas as $nombre => $tipo) {
        $consultaColumna->execute([":columna" => $nombre]);
        if (!$consultaColumna->fetchColumn()) {
            $conexion->exec("ALTER TABLE oportunidades ADD COLUMN {$nombre} {$tipo}");
        }
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionPermisosAreas(PDO $conexion): void
{
    $version = "006_permisos_por_area";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $permisos = [
        ["Gestionar documentos", "documentos.gestionar"],
        ["Acceder a comunicaciones", "comunicaciones.ver"],
    ];
    $insertarPermiso = $conexion->prepare("INSERT IGNORE INTO permisos (nombre, codigo) VALUES (:nombre, :codigo)");
    foreach ($permisos as $permiso) {
        $insertarPermiso->execute([":nombre" => $permiso[0], ":codigo" => $permiso[1]]);
    }

    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'administrador'
           AND p.codigo IN ('documentos.gestionar', 'comunicaciones.ver')"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionDatosFormularios(PDO $conexion): void
{
    $version = "007_datos_formularios";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $columnas = [
        "clientes" => ["tipo_documento" => "VARCHAR(10) NULL"],
        "movimientos_financieros" => ["tipo_persona" => "VARCHAR(20) NULL"],
    ];
    $consultaColumna = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :tabla
           AND column_name = :columna LIMIT 1"
    );
    foreach ($columnas as $tabla => $campos) {
        foreach ($campos as $nombre => $tipo) {
            $consultaColumna->execute([":tabla" => $tabla, ":columna" => $nombre]);
            if (!$consultaColumna->fetchColumn()) {
                $conexion->exec("ALTER TABLE {$tabla} ADD COLUMN {$nombre} {$tipo}");
            }
        }
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionPermisoProspectosCompletos(PDO $conexion): void
{
    $version = "008_permiso_prospectos_completos";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $conexion->exec(
        "INSERT IGNORE INTO permisos (nombre, codigo)
         VALUES ('Ver prospectos de todos los trabajadores', 'crm.ver_todos_prospectos')"
    );
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'administrador' AND p.codigo = 'crm.ver_todos_prospectos'"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionAreasEmpresaYLegal(PDO $conexion): void
{
    $version = "010_area_empresarial_personal";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $tablas = [
        "CREATE TABLE IF NOT EXISTS empresa_perfil (
            id_perfil TINYINT UNSIGNED NOT NULL,
            razon_social VARCHAR(180) NOT NULL,
            nombre_comercial VARCHAR(180) NULL,
            ruc CHAR(11) NOT NULL,
            direccion VARCHAR(255) NULL,
            telefono VARCHAR(20) NULL,
            correo VARCHAR(150) NULL,
            representante_legal VARCHAR(180) NULL,
            actualizado_por INT NULL,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_perfil),
            UNIQUE KEY uq_empresa_perfil_ruc (ruc),
            CONSTRAINT fk_empresa_perfil_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS empresa_areas (
            id_area INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(100) NOT NULL,
            descripcion VARCHAR(255) NULL,
            estado TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id_area),
            UNIQUE KEY uq_empresa_areas_nombre (nombre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS empresa_cargos (
            id_cargo INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_area INT UNSIGNED NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            descripcion VARCHAR(255) NULL,
            estado TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id_cargo),
            UNIQUE KEY uq_empresa_cargo_area_nombre (id_area, nombre),
            CONSTRAINT fk_empresa_cargos_area FOREIGN KEY (id_area) REFERENCES empresa_areas (id_area) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS empresa_trabajadores (
            id_trabajador INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NOT NULL,
            documento CHAR(8) NOT NULL,
            telefono CHAR(9) NULL,
            correo VARCHAR(150) NULL,
            id_area INT UNSIGNED NOT NULL,
            id_cargo INT UNSIGNED NOT NULL,
            usuario_id INT NULL,
            fecha_ingreso DATE NOT NULL,
            estado TINYINT(1) NOT NULL DEFAULT 1,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_trabajador),
            UNIQUE KEY uq_empresa_trabajador_documento (documento),
            UNIQUE KEY uq_empresa_trabajador_usuario (usuario_id),
            KEY idx_empresa_trabajador_area_cargo (id_area, id_cargo),
            CONSTRAINT fk_empresa_trabajador_area FOREIGN KEY (id_area) REFERENCES empresa_areas (id_area) ON DELETE RESTRICT,
            CONSTRAINT fk_empresa_trabajador_cargo FOREIGN KEY (id_cargo) REFERENCES empresa_cargos (id_cargo) ON DELETE RESTRICT,
            CONSTRAINT fk_empresa_trabajador_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS documentos_legales (
            id_documento INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_caso INT UNSIGNED NULL,
            id_expediente INT UNSIGNED NULL,
            nombre_original VARCHAR(255) NOT NULL,
            nombre_almacenado CHAR(64) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            tamano_bytes INT UNSIGNED NOT NULL,
            subido_por INT NOT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_documento),
            UNIQUE KEY uq_documentos_legales_archivo (nombre_almacenado),
            KEY idx_documentos_legales_caso (id_caso),
            KEY idx_documentos_legales_expediente (id_expediente),
            CONSTRAINT fk_documentos_legales_caso FOREIGN KEY (id_caso) REFERENCES casos_legales (id_caso) ON DELETE RESTRICT,
            CONSTRAINT fk_documentos_legales_expediente FOREIGN KEY (id_expediente) REFERENCES expedientes (id_expediente) ON DELETE RESTRICT,
            CONSTRAINT fk_documentos_legales_usuario FOREIGN KEY (subido_por) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS documentos_empresa (
            id_documento INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre_original VARCHAR(255) NOT NULL,
            nombre_almacenado CHAR(64) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            tamano_bytes INT UNSIGNED NOT NULL,
            categoria VARCHAR(100) NOT NULL,
            descripcion VARCHAR(500) NULL,
            id_cliente INT UNSIGNED NULL,
            subido_por INT NOT NULL,
            estado TINYINT(1) NOT NULL DEFAULT 1,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_documento),
            UNIQUE KEY uq_documentos_empresa_archivo (nombre_almacenado),
            KEY idx_documentos_empresa_fecha (fecha_registro),
            CONSTRAINT fk_documentos_empresa_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente) ON DELETE RESTRICT,
            CONSTRAINT fk_documentos_empresa_usuario FOREIGN KEY (subido_por) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        "CREATE TABLE IF NOT EXISTS historial_casos_legales (
            id_historial INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_caso INT UNSIGNED NOT NULL,
            usuario_id INT NULL,
            accion VARCHAR(40) NOT NULL,
            detalle TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_historial),
            KEY idx_historial_casos_fecha (id_caso, fecha_registro),
            CONSTRAINT fk_historial_casos_caso FOREIGN KEY (id_caso) REFERENCES casos_legales (id_caso) ON DELETE RESTRICT,
            CONSTRAINT fk_historial_casos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];
    foreach ($tablas as $sql) {
        $conexion->exec($sql);
    }

    $insertarPermiso = $conexion->prepare(
        "INSERT IGNORE INTO permisos (nombre, codigo) VALUES (:nombre, :codigo)"
    );
    $insertarPermiso->execute([":nombre" => "Gestionar perfil empresarial", ":codigo" => "empresa.gestionar"]);
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'administrador' AND p.codigo = 'empresa.gestionar'"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionTituloDocumentos(PDO $conexion): void
{
    $version = "011_titulo_documentos_empresa";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $stmt = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'documentos_empresa'
           AND column_name = 'titulo'
         LIMIT 1"
    );
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $conexion->exec(
            "ALTER TABLE documentos_empresa
             ADD COLUMN titulo VARCHAR(180) NOT NULL DEFAULT '' AFTER nombre_original"
        );
    }

    $conexion->exec(
        "UPDATE documentos_empresa SET titulo = LEFT(nombre_original, 180) WHERE titulo = ''"
    );
    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionBloquesTrabajador(PDO $conexion): void
{
    $version = "012_bloques_trabajador";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $conexion->exec(
        "INSERT IGNORE INTO permisos (nombre, codigo)
         VALUES ('Gestionar catálogo de servicios', 'servicios.gestionar')"
    );
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'administrador' AND p.codigo = 'servicios.gestionar'"
    );
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'vendedor' AND p.codigo = 'dashboard.ver'"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionNotificaciones(PDO $conexion): void
{
    $version = "013_notificaciones_comerciales";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS notificaciones (
            id_notificacion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            usuario_id INT NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            registro_id INT UNSIGNED NOT NULL,
            mensaje VARCHAR(255) NOT NULL,
            creado_por INT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            leida_en DATETIME NULL,
            PRIMARY KEY (id_notificacion),
            KEY idx_notificaciones_usuario_fecha (usuario_id, fecha_registro),
            KEY idx_notificaciones_usuario_lectura (usuario_id, leida_en),
            CONSTRAINT fk_notificaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id_usuario) ON DELETE CASCADE,
            CONSTRAINT fk_notificaciones_creador FOREIGN KEY (creado_por) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionCatalogosYSistemaDocumentos(PDO $conexion): void
{
    $version = "014_catalogos_sistema_documentos";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS catalogos (
            id_catalogo INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo VARCHAR(50) NOT NULL,
            codigo VARCHAR(60) NOT NULL,
            nombre VARCHAR(120) NOT NULL,
            color VARCHAR(30) NULL,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            es_sistema TINYINT(1) NOT NULL DEFAULT 0,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_catalogo),
            UNIQUE KEY uq_catalogos_tipo_codigo (tipo, codigo),
            KEY idx_catalogos_tipo_activo (tipo, activo, orden)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $catalogosIniciales = [
        ['categoria_documento', 'tesis', 'Tesis de Grado / Posgrado', '#1d4ed8', 1, 1],
        ['categoria_documento', 'proyecto_tesis', 'Proyecto / Plan de Tesis', '#2563eb', 2, 1],
        ['categoria_documento', 'articulo', 'Art�culo Cient�fico', '#0284c7', 3, 1],
        ['categoria_documento', 'informe', 'Informe de Asesor�a / Avance', '#059669', 4, 1],
        ['categoria_documento', 'contrato', 'Contrato de Servicios', '#d97706', 5, 1],
        ['categoria_documento', 'propuesta', 'Propuesta T�cnico-Econ�mica', '#7c3aed', 6, 1],
        ['categoria_documento', 'acta', 'Acta de Sustentaci�n / Evaluaci�n', '#0d9488', 7, 1],
        ['categoria_documento', 'certificado', 'Certificado / Constancia', '#16a34a', 8, 1],
        ['categoria_documento', 'expediente', 'Expediente Documental', '#4f46e5', 9, 1],
        ['categoria_documento', 'otro', 'Otro Documento', '#6b7280', 10, 1],

        ['estado_documento', 'borrador', 'Borrador', '#64748b', 1, 1],
        ['estado_documento', 'en_revision', 'En revisi�n', '#f59e0b', 2, 1],
        ['estado_documento', 'observado', 'Observado', '#ef4444', 3, 1],
        ['estado_documento', 'aprobado', 'Aprobado', '#10b981', 4, 1],
        ['estado_documento', 'vigente', 'Vigente', '#3b82f6', 5, 1],
        ['estado_documento', 'archivado', 'Archivado', '#94a3b8', 6, 1],

        ['fuente_prospecto', 'recomendacion', 'Recomendaci�n / Referido', '#10b981', 1, 1],
        ['fuente_prospecto', 'sitio_web', 'Sitio Web Oficial', '#3b82f6', 2, 1],
        ['fuente_prospecto', 'whatsapp', 'WhatsApp', '#22c55e', 3, 1],
        ['fuente_prospecto', 'redes_sociales', 'Redes Sociales (FB / IG / TT)', '#8b5cf6', 4, 1],
        ['fuente_prospecto', 'universidad', 'Convenio / Campus Universitario', '#f59e0b', 5, 1],
        ['fuente_prospecto', 'evento_taller', 'Seminario / Taller / Conferencia', '#06b6d4', 6, 1],
        ['fuente_prospecto', 'publicidad_digital', 'Publicidad Digital / Ads', '#ec4899', 7, 1],
        ['fuente_prospecto', 'directo', 'Atenci�n Presencial / Directa', '#64748b', 8, 1],

        ['tipo_documento_identidad', 'dni', 'DNI (8 d�gitos)', null, 1, 1],
        ['tipo_documento_identidad', 'ruc', 'RUC (11 d�gitos)', null, 2, 1],
        ['tipo_documento_identidad', 'ce', 'Carn� de Extranjer�a', null, 3, 1],
        ['tipo_documento_identidad', 'pasaporte', 'Pasaporte', null, 4, 1],

        ['estado_prospecto', 'nuevo', 'Nuevo', '#3b82f6', 1, 1],
        ['estado_prospecto', 'contactado', 'Contactado', '#06b6d4', 2, 1],
        ['estado_prospecto', 'interesado', 'Interesado', '#8b5cf6', 3, 1],
        ['estado_prospecto', 'en_evaluacion', 'En Evaluaci�n', '#f59e0b', 4, 1],
        ['estado_prospecto', 'cotizado', 'Cotizado', '#d97706', 5, 1],
        ['estado_prospecto', 'negociacion', 'Negociaci�n', '#6366f1', 6, 1],
        ['estado_prospecto', 'ganado', 'Ganado', '#10b981', 7, 1],
        ['estado_prospecto', 'perdido', 'Perdido', '#ef4444', 8, 1],
    ];

    $stmtInsert = $conexion->prepare(
        "INSERT IGNORE INTO catalogos (tipo, codigo, nombre, color, orden, activo, es_sistema)
         VALUES (:tipo, :codigo, :nombre, :color, :orden, 1, :es_sistema)"
    );
    foreach ($catalogosIniciales as $cat) {
        $stmtInsert->execute([
            ':tipo' => $cat[0],
            ':codigo' => $cat[1],
            ':nombre' => $cat[2],
            ':color' => $cat[3],
            ':orden' => $cat[4],
            ':es_sistema' => $cat[5],
        ]);
    }

    $columnasNuevas = [
        'estado_documento' => "VARCHAR(40) NOT NULL DEFAULT 'Vigente'",
        'version' => "VARCHAR(20) NOT NULL DEFAULT '1.0'",
        'fecha_documento' => "DATE NULL",
        'numero_expediente' => "VARCHAR(80) NULL",
    ];

    $consultaColumna = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'documentos_empresa'
           AND column_name = :columna LIMIT 1"
    );

    foreach ($columnasNuevas as $nombre => $definicion) {
        $consultaColumna->execute([':columna' => $nombre]);
        if (!$consultaColumna->fetchColumn()) {
            $conexion->exec("ALTER TABLE documentos_empresa ADD COLUMN {$nombre} {$definicion}");
        }
    }

    try {
        $conexion->exec("UPDATE documentos_empresa SET estado_documento = 'Archivado' WHERE estado = 0 AND estado_documento = 'Vigente'");
    } catch (Throwable $e) {
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionCrmInteraccionesYKanban(PDO $conexion): void
{
    $version = "015_crm_interacciones_y_kanban";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    // 1. Agregar columna profesion a tabla clientes si no existe
    $stmtCol = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'clientes' AND column_name = 'profesion' LIMIT 1"
    );
    $stmtCol->execute();
    if (!$stmtCol->fetchColumn()) {
        $conexion->exec("ALTER TABLE clientes ADD COLUMN profesion VARCHAR(150) NULL AFTER empresa");
    }

    // 2. Crear tabla crm_interacciones para registrar el historial completo
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS crm_interacciones (
            id_interaccion INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_cliente INT UNSIGNED NOT NULL,
            usuario_id INT NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            detalle TEXT NOT NULL,
            fecha_interaccion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            proxima_accion DATE NULL,
            resultado VARCHAR(100) NULL,
            PRIMARY KEY (id_interaccion),
            KEY idx_interacciones_cliente (id_cliente),
            KEY idx_interacciones_fecha (fecha_interaccion),
            CONSTRAINT fk_interacciones_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente) ON DELETE CASCADE,
            CONSTRAINT fk_interacciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id_usuario) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // 3. Crear tabla kanban_tarjetas para los 3 tableros especificados
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS kanban_tarjetas (
            id_tarjeta INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tablero VARCHAR(30) NOT NULL,
            columna VARCHAR(100) NOT NULL,
            titulo VARCHAR(180) NOT NULL,
            id_cliente INT UNSIGNED NULL,
            responsable_id INT NULL,
            monto DECIMAL(12,2) NOT NULL DEFAULT 0,
            fecha_limite DATE NULL,
            etiquetas VARCHAR(200) NULL,
            descripcion TEXT NULL,
            orden INT NOT NULL DEFAULT 0,
            estado TINYINT(1) NOT NULL DEFAULT 1,
            creado_por INT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_tarjeta),
            KEY idx_kanban_tablero_columna (tablero, columna),
            CONSTRAINT fk_kanban_cliente FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente) ON DELETE SET NULL,
            CONSTRAINT fk_kanban_responsable FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Sembrar tarjetas de demostraci�n si la tabla kanban_tarjetas est� vac�a
    $totalTarjetas = (int) $conexion->query("SELECT COUNT(*) FROM kanban_tarjetas")->fetchColumn();
    if ($totalTarjetas === 0) {
        $tarjetasIniciales = [
            // Tablero 1: Ventas (Comercial)
            // Columnas: Lead interesado ? Primer contacto hecho ? Cotizaci�n enviada ? Matr�cula confirmada (Ganado)
            ['ventas', 'Lead interesado', 'Carlos Mendoza - Asesor�a Tesis Derecho', 1500.00, 'Tesis Maestr�a', 'Interesado en asesor�a metodol�gica para posgrado.'],
            ['ventas', 'Lead interesado', 'Luc�a Fern�ndez - Tesis en Administraci�n', 1200.00, 'Pregrado', 'Solicit� informaci�n sobre desarrollo de cap�tulos.'],
            ['ventas', 'Primer contacto hecho', 'Jorge Ram�rez - Explicaci�n de paquetes', 1800.00, 'Tesis Doctoral', 'Se llam� y se explic� la metodolog�a de trabajo.'],
            ['ventas', 'Cotizaci�n enviada', 'Dra. Patricia Silva - Propuesta enviada', 2200.00, 'Posgrado Medicina', 'Cotizaci�n enviada por WhatsApp y correo electr�nico.'],
            ['ventas', 'Matr�cula confirmada (Ganado)', 'Ing. Marco Valdivia - Matr�cula Confirmada', 2500.00, 'Ingenier�a Civil', 'Pago inicial realizado y matriculado en el programa.'],

            // Tablero 2: Operaci�n / Tesis (Acad�mico)
            // Columnas: Alumno asignado a asesor ? Elecci�n de tema ? Desarrollo de Cap. 1 y 2 ? Revisi�n de Turnitin ? Simulaci�n de sustentaci�n ? Tesis aprobada
            ['tesis', 'Alumno asignado a asesor', 'Lic. Andrea Torres - Asesor Dr. G�mez', 0, 'Asignaci�n', 'Reuni�n inicial de bienvenida programada.'],
            ['tesis', 'Elecci�n de tema', 'Manuel Estrada - Matriz de consistencia', 0, 'Tema y Plan', 'Definiendo variables y formulaci�n del problema.'],
            ['tesis', 'Desarrollo de Cap. 1 y 2', 'Valeria Castro - Marco te�rico y dise�o', 0, 'En Redacci�n', 'Avance de antecedentes y marco te�rico al 80%.'],
            ['tesis', 'Revisi�n de Turnitin', 'Roberto Morales - Reporte de originalidad', 0, 'Turnitin 11%', 'Documento analizado, 11% de similitud aceptable.'],
            ['tesis', 'Simulaci�n de sustentaci�n', 'Clara Vega - Diapositivas y ensayo jurado', 0, 'Sustentaci�n', 'Simulaci�n con jurado evaluador fijada para esta semana.'],
            ['tesis', 'Tesis aprobada', 'C�sar Hurtado - Aprobado con Menci�n', 0, 'Graduado', 'Sustentaci�n exitosa con dictamen favorable.'],

            // Tablero 3: Gesti�n Legal (Contratos / Convenios)
            // Columnas: Solicitud recibida ? Redacci�n de contrato ? En revisi�n por el cliente ? Firmado y archivado
            ['legal', 'Solicitud recibida', 'Convenio Marco - Universidad Privada', 0, 'Convenio', 'Solicitud institucional de descuento por convenio.'],
            ['legal', 'Redacci�n de contrato', 'Contrato Asesor�a Integral - Caso Valdivia', 0, 'Contrato Servicio', 'Redacci�n de cl�usulas de confidencialidad y entregables.'],
            ['legal', 'En revisi�n por el cliente', 'Adenda de Asesor�a Especializada - Dra. Silva', 0, 'Adenda', 'Cliente revisando borrador de t�rminos contractuales.'],
            ['legal', 'Firmado y archivado', 'Contrato de Confidencialidad y Asesor�a 2026', 0, 'Firmado', 'Firmado digitalmente y archivado en repositorio de documentos.']
        ];

        $stmtInsert = $conexion->prepare(
            "INSERT INTO kanban_tarjetas (tablero, columna, titulo, monto, etiquetas, descripcion, creado_por)
             VALUES (:tablero, :columna, :titulo, :monto, :etiquetas, :descripcion, 1)"
        );
        foreach ($tarjetasIniciales as $t) {
            $stmtInsert->execute([
                ':tablero' => $t[0],
                ':columna' => $t[1],
                ':titulo' => $t[2],
                ':monto' => $t[3],
                ':etiquetas' => $t[4],
                ':descripcion' => $t[5],
            ]);
        }
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionRolesCategoriasYTrabajadores(PDO $conexion): void
{
    $version = "016_roles_categorias_y_permisos_trabajadores";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $rolesOficiales = [
        ["Administrador", "administrador"],
        ["Asesor Acad�mico / Tesis", "asesor_academico"],
        ["Asesor Comercial / Ventas", "ventas"],
        ["Asesor Legal", "legal"],
        ["Finanzas y Contabilidad", "contabilidad"],
        ["Personal Operativo", "trabajador"],
    ];

    $stmtRol = $conexion->prepare(
        "INSERT INTO roles (nombre, codigo) VALUES (:nombre, :codigo)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)"
    );
    foreach ($rolesOficiales as $r) {
        $stmtRol->execute([":nombre" => $r[0], ":codigo" => $r[1]]);
    }

    $permisosPorRol = [
        "administrador" => [
            "dashboard.ver", "crm.gestionar", "ventas.gestionar", "horarios.gestionar",
            "inventario.gestionar", "legal.gestionar", "finanzas.ver", "finanzas.gestionar",
            "documentos.gestionar", "usuarios.gestionar", "empresa.gestionar", "servicios.gestionar",
            "crm.ver_todos_prospectos", "tesis.gestionar", "comunicaciones.ver"
        ],
        "asesor_academico" => [
            "dashboard.ver", "tesis.gestionar", "documentos.gestionar", "horarios.gestionar"
        ],
        "ventas" => [
            "dashboard.ver", "crm.gestionar", "ventas.gestionar"
        ],
        "legal" => [
            "dashboard.ver", "legal.gestionar", "documentos.gestionar"
        ],
        "contabilidad" => [
            "dashboard.ver", "finanzas.ver", "finanzas.gestionar"
        ],
        "trabajador" => [
            "dashboard.ver", "horarios.gestionar", "inventario.gestionar"
        ],
    ];

    $stmtAsignar = $conexion->prepare(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = :rol_codigo AND p.codigo = :permiso_codigo"
    );

    foreach ($permisosPorRol as $codigoRol => $codigosPermisos) {
        foreach ($codigosPermisos as $permiso) {
            $stmtAsignar->execute([
                ":rol_codigo" => $codigoRol,
                ":permiso_codigo" => $permiso
            ]);
        }
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionUsuariosDemoPorCategoria(PDO $conexion): void
{
    $version = "017_usuarios_demo_por_categoria";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionCuentaTrabajadorLegada(PDO $conexion): void
{
    $version = "018_actualizar_cuenta_trabajador_legada";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $idRolTrabajador = $conexion->query(
        "SELECT id_rol FROM roles WHERE codigo = 'trabajador' LIMIT 1"
    )->fetchColumn();
    if (!$idRolTrabajador) {
        throw new RuntimeException("No se encontró el rol trabajador para actualizar la cuenta heredada.");
    }

    $actualizar = $conexion->prepare(
        "UPDATE usuarios
         SET id_rol = :id_rol
         WHERE usuario = 'trabajador'"
    );
    $actualizar->execute([
        ":id_rol" => (int) $idRolTrabajador,
    ]);

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionFormularioPublicoProspectos(PDO $conexion): void
{
    $version = "019_formulario_publico_prospectos";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $columnaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'prospectos' AND column_name = :columna LIMIT 1"
    );
    foreach ([
        "dni" => "ALTER TABLE prospectos ADD COLUMN dni VARCHAR(8) NULL AFTER telefono",
        "fecha_nacimiento" => "ALTER TABLE prospectos ADD COLUMN fecha_nacimiento DATE NULL AFTER dni",
    ] as $columna => $sql) {
        $columnaExiste->execute([":columna" => $columna]);
        if (!$columnaExiste->fetchColumn()) {
            $conexion->exec($sql);
        }
    }

    $fuentes = [
        ["Facebook", "facebook", "#1877f2", 9],
        ["TikTok", "tiktok", "#111827", 10],
        ["Instagram", "instagram", "#c13584", 11],
    ];
    $insertarFuente = $conexion->prepare(
        "INSERT IGNORE INTO catalogos (tipo, codigo, nombre, color, orden, activo, es_sistema)
         VALUES ('fuente_prospecto', :codigo, :nombre, :color, :orden, 1, 1)"
    );
    foreach ($fuentes as [$nombre, $codigo, $color, $orden]) {
        $insertarFuente->execute([
            ":codigo" => $codigo,
            ":nombre" => $nombre,
            ":color" => $color,
            ":orden" => $orden,
        ]);
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}

function ejecutarMigracionGestionClientesYProyectos(PDO $conexion): void
{
    $version = "020_gestion_clientes_y_proyectos";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        ejecutarMigracionPagosContratosCoordinacion($conexion);
        return;
    }

    $columnaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :tabla AND column_name = :columna LIMIT 1"
    );
    $columnas = [
        "clientes" => [
            "fecha_nacimiento" => "DATE NULL",
            "universidad" => "VARCHAR(180) NULL",
            "carrera" => "VARCHAR(150) NULL",
        ],
        "prospectos" => [
            "id_cliente" => "INT UNSIGNED NULL",
        ],
        "proyectos_tesis" => [
            "id_servicio_cliente" => "INT UNSIGNED NULL",
            "coordinador_id" => "INT NULL",
            "entregables" => "TEXT NULL",
            "observaciones" => "TEXT NULL",
        ],
    ];
    foreach ($columnas as $tabla => $campos) {
        foreach ($campos as $nombre => $tipo) {
            $columnaExiste->execute([":tabla" => $tabla, ":columna" => $nombre]);
            if (!$columnaExiste->fetchColumn()) {
                $conexion->exec("ALTER TABLE {$tabla} ADD COLUMN {$nombre} {$tipo}");
            }
        }
    }

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS servicios_clientes (
            id_servicio_cliente INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_cliente INT UNSIGNED NOT NULL,
            nombre_servicio VARCHAR(180) NOT NULL,
            descripcion TEXT NULL,
            monto DECIMAL(12,2) NULL,
            fecha_inicio DATE NULL,
            fecha_fin DATE NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
            observaciones TEXT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_servicio_cliente),
            KEY idx_servicios_clientes_cliente (id_cliente, estado),
            CONSTRAINT fk_servicios_clientes_cliente FOREIGN KEY (id_cliente)
                REFERENCES clientes (id_cliente) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS asesores_academicos (
            id_asesor INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_usuario INT NULL,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NOT NULL,
            correo VARCHAR(180) NULL,
            especialidad VARCHAR(180) NULL,
            disponibilidad TEXT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Activo',
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_asesor),
            UNIQUE KEY uq_asesores_academicos_usuario (id_usuario),
            CONSTRAINT fk_asesores_academicos_usuario FOREIGN KEY (id_usuario)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS proyectos_asesores (
            id_asignacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_tesis INT UNSIGNED NOT NULL,
            id_asesor INT UNSIGNED NOT NULL,
            fecha_asignacion DATE NOT NULL,
            fecha_fin DATE NULL,
            observaciones TEXT NULL,
            PRIMARY KEY (id_asignacion),
            KEY idx_proyectos_asesores_proyecto (id_tesis, fecha_fin),
            KEY idx_proyectos_asesores_asesor (id_asesor, fecha_fin),
            CONSTRAINT fk_proyectos_asesores_tesis FOREIGN KEY (id_tesis)
                REFERENCES proyectos_tesis (id_tesis) ON DELETE CASCADE,
            CONSTRAINT fk_proyectos_asesores_asesor FOREIGN KEY (id_asesor)
                REFERENCES asesores_academicos (id_asesor) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $foraneaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.key_column_usage
         WHERE constraint_schema = DATABASE() AND table_name = :tabla AND constraint_name = :nombre LIMIT 1"
    );
    $foraneas = [
        ["prospectos", "fk_prospectos_cliente_convertido", "FOREIGN KEY (id_cliente) REFERENCES clientes (id_cliente) ON DELETE SET NULL"],
        ["proyectos_tesis", "fk_tesis_servicio_cliente", "FOREIGN KEY (id_servicio_cliente) REFERENCES servicios_clientes (id_servicio_cliente) ON DELETE SET NULL"],
        ["proyectos_tesis", "fk_tesis_coordinador", "FOREIGN KEY (coordinador_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL"],
    ];
    foreach ($foraneas as [$tabla, $nombre, $definicion]) {
        $foraneaExiste->execute([":tabla" => $tabla, ":nombre" => $nombre]);
        if (!$foraneaExiste->fetchColumn()) {
            $conexion->exec("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} {$definicion}");
        }
    }

    $conexion->exec("INSERT IGNORE INTO permisos (nombre, codigo) VALUES ('Gestionar proyectos académicos', 'tesis.gestionar')");
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'administrador' AND p.codigo = 'tesis.gestionar'"
    );
    $conexion->exec(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'asesor_academico' AND p.codigo = 'tesis.gestionar'"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
    ejecutarMigracionPagosContratosCoordinacion($conexion);
}

function ejecutarMigracionPagosContratosCoordinacion(PDO $conexion): void
{
    $version = "021_pagos_contratos_coordinacion";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        ejecutarMigracionAsesoresGerenciaMarketing($conexion);
        return;
    }

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS contratos_academicos (
            id_contrato INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_cliente INT UNSIGNED NOT NULL,
            id_tesis INT UNSIGNED NULL,
            numero_contrato VARCHAR(80) NOT NULL,
            titulo VARCHAR(180) NOT NULL,
            monto_total DECIMAL(12,2) NOT NULL,
            adelanto DECIMAL(12,2) NOT NULL DEFAULT 0,
            fecha_contrato DATE NOT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Vigente',
            observaciones TEXT NULL,
            creado_por INT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_contrato),
            UNIQUE KEY uq_contratos_academicos_numero (numero_contrato),
            KEY idx_contratos_academicos_cliente (id_cliente, estado),
            CONSTRAINT chk_contratos_academicos_montos CHECK (monto_total >= 0 AND adelanto >= 0 AND adelanto <= monto_total),
            CONSTRAINT fk_contratos_academicos_cliente FOREIGN KEY (id_cliente)
                REFERENCES clientes (id_cliente) ON DELETE RESTRICT,
            CONSTRAINT fk_contratos_academicos_tesis FOREIGN KEY (id_tesis)
                REFERENCES proyectos_tesis (id_tesis) ON DELETE SET NULL,
            CONSTRAINT fk_contratos_academicos_usuario FOREIGN KEY (creado_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS cuotas_contrato_academico (
            id_cuota INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_contrato INT UNSIGNED NOT NULL,
            numero_cuota SMALLINT UNSIGNED NOT NULL,
            concepto VARCHAR(150) NOT NULL,
            fecha_vencimiento DATE NOT NULL,
            monto DECIMAL(12,2) NOT NULL,
            PRIMARY KEY (id_cuota),
            UNIQUE KEY uq_cuotas_contrato_academico_numero (id_contrato, numero_cuota),
            KEY idx_cuotas_contrato_academico_fecha (fecha_vencimiento),
            CONSTRAINT chk_cuotas_contrato_academico_monto CHECK (monto > 0),
            CONSTRAINT fk_cuotas_contrato_academico_contrato FOREIGN KEY (id_contrato)
                REFERENCES contratos_academicos (id_contrato) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS pagos_contrato_academico (
            id_pago INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_contrato INT UNSIGNED NOT NULL,
            id_cuota INT UNSIGNED NULL,
            registrado_por INT NULL,
            numero_comprobante VARCHAR(100) NULL,
            fecha_pago DATE NOT NULL,
            monto DECIMAL(12,2) NOT NULL,
            metodo_pago VARCHAR(40) NOT NULL,
            observaciones TEXT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'Registrado',
            id_movimiento INT UNSIGNED NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_pago),
            UNIQUE KEY uq_pago_contrato_comprobante (numero_comprobante),
            KEY idx_pago_contrato_fecha (id_contrato, fecha_pago),
            CONSTRAINT chk_pago_contrato_monto CHECK (monto > 0),
            CONSTRAINT fk_pago_contrato FOREIGN KEY (id_contrato)
                REFERENCES contratos_academicos (id_contrato) ON DELETE RESTRICT,
            CONSTRAINT fk_pago_cuota FOREIGN KEY (id_cuota)
                REFERENCES cuotas_contrato_academico (id_cuota) ON DELETE SET NULL,
            CONSTRAINT fk_pago_usuario FOREIGN KEY (registrado_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL,
            CONSTRAINT fk_pago_movimiento FOREIGN KEY (id_movimiento)
                REFERENCES movimientos_financieros (id_movimiento) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS coordinacion_hitos (
            id_hito INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_tesis INT UNSIGNED NOT NULL,
            codigo VARCHAR(50) NOT NULL,
            titulo VARCHAR(120) NOT NULL,
            orden SMALLINT UNSIGNED NOT NULL,
            fecha_objetivo DATE NULL,
            estado VARCHAR(40) NOT NULL DEFAULT 'Pendiente',
            observaciones TEXT NULL,
            actualizado_por INT NULL,
            fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id_hito),
            UNIQUE KEY uq_coordinacion_hito (id_tesis, codigo),
            KEY idx_coordinacion_hito_fecha (fecha_objetivo, estado),
            CONSTRAINT fk_coordinacion_hito_tesis FOREIGN KEY (id_tesis)
                REFERENCES proyectos_tesis (id_tesis) ON DELETE CASCADE,
            CONSTRAINT fk_coordinacion_hito_usuario FOREIGN KEY (actualizado_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $columnaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :tabla AND column_name = :columna LIMIT 1"
    );
    foreach ([
        "documentos_empresa" => [
            "id_tesis" => "INT UNSIGNED NULL",
            "id_contrato_academico" => "INT UNSIGNED NULL",
        ],
    ] as $tabla => $columnas) {
        foreach ($columnas as $nombre => $tipo) {
            $columnaExiste->execute([":tabla" => $tabla, ":columna" => $nombre]);
            if (!$columnaExiste->fetchColumn()) {
                $conexion->exec("ALTER TABLE {$tabla} ADD COLUMN {$nombre} {$tipo}");
            }
        }
    }

    $foraneaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.key_column_usage
         WHERE constraint_schema = DATABASE() AND table_name = :tabla AND constraint_name = :nombre LIMIT 1"
    );
    foreach ([
        ["documentos_empresa", "fk_documentos_empresa_tesis", "FOREIGN KEY (id_tesis) REFERENCES proyectos_tesis (id_tesis) ON DELETE SET NULL"],
        ["documentos_empresa", "fk_documentos_empresa_contrato_academico", "FOREIGN KEY (id_contrato_academico) REFERENCES contratos_academicos (id_contrato) ON DELETE SET NULL"],
    ] as [$tabla, $nombre, $definicion]) {
        $foraneaExiste->execute([":tabla" => $tabla, ":nombre" => $nombre]);
        if (!$foraneaExiste->fetchColumn()) {
            $conexion->exec("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} {$definicion}");
        }
    }

    $conexion->exec(
        "INSERT IGNORE INTO permisos (nombre, codigo) VALUES
         ('Ver contratos académicos', 'contratos.ver'),
         ('Gestionar contratos académicos y pagos', 'contratos.gestionar')"
    );
    foreach ([
        "administrador" => ["contratos.ver", "contratos.gestionar"],
        "legal" => ["contratos.ver", "contratos.gestionar"],
        "contabilidad" => ["contratos.ver", "contratos.gestionar"],
    ] as $rol => $permisos) {
        foreach ($permisos as $permiso) {
            $stmtPermiso = $conexion->prepare(
                "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
                 SELECT r.id_rol, p.id_permiso FROM roles r CROSS JOIN permisos p
                 WHERE r.codigo = :rol AND p.codigo = :permiso"
            );
            $stmtPermiso->execute([":rol" => $rol, ":permiso" => $permiso]);
        }
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
    ejecutarMigracionAsesoresGerenciaMarketing($conexion);
}

function ejecutarMigracionAsesoresGerenciaMarketing(PDO $conexion): void
{
    $version = "022_asesores_gerencia_marketing";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        ejecutarMigracionComisionesPorAsesor($conexion);
        return;
    }

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS campanas_marketing (
            id_campana INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(150) NOT NULL,
            canal VARCHAR(80) NOT NULL,
            inversion DECIMAL(12,2) NOT NULL DEFAULT 0,
            fecha_inicio DATE NOT NULL,
            fecha_fin DATE NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'Activa',
            creado_por INT NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_campana),
            KEY idx_campanas_fechas (fecha_inicio, fecha_fin, estado),
            CONSTRAINT chk_campanas_inversion CHECK (inversion >= 0),
            CONSTRAINT chk_campanas_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio),
            CONSTRAINT fk_campanas_usuario FOREIGN KEY (creado_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS entregas_asesores (
            id_entrega INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_tesis INT UNSIGNED NOT NULL,
            id_hito INT UNSIGNED NULL,
            id_asesor INT UNSIGNED NOT NULL,
            version SMALLINT UNSIGNED NOT NULL,
            titulo VARCHAR(180) NOT NULL,
            nombre_original VARCHAR(255) NOT NULL,
            nombre_almacenado VARCHAR(80) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            tamano_bytes INT UNSIGNED NOT NULL,
            comentario_asesor TEXT NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'En revisión',
            observaciones_coordinador TEXT NULL,
            revisado_por INT NULL,
            fecha_revision DATETIME NULL,
            fecha_envio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_entrega),
            UNIQUE KEY uq_entrega_hito_version (id_hito, version),
            KEY idx_entregas_tesis_fecha (id_tesis, fecha_envio),
            KEY idx_entregas_asesor_estado (id_asesor, estado),
            CONSTRAINT fk_entregas_tesis FOREIGN KEY (id_tesis)
                REFERENCES proyectos_tesis (id_tesis) ON DELETE CASCADE,
            CONSTRAINT fk_entregas_hito FOREIGN KEY (id_hito)
                REFERENCES coordinacion_hitos (id_hito) ON DELETE SET NULL,
            CONSTRAINT fk_entregas_asesor FOREIGN KEY (id_asesor)
                REFERENCES asesores_academicos (id_asesor) ON DELETE RESTRICT,
            CONSTRAINT fk_entregas_revisor FOREIGN KEY (revisado_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $columnaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :tabla AND column_name = :columna LIMIT 1"
    );
    $columnaExiste->execute([":tabla" => "prospectos", ":columna" => "id_campana"]);
    if (!$columnaExiste->fetchColumn()) {
        $conexion->exec("ALTER TABLE prospectos ADD COLUMN id_campana INT UNSIGNED NULL AFTER fuente");
    }

    $foraneaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.key_column_usage
         WHERE constraint_schema = DATABASE() AND table_name = :tabla AND constraint_name = :nombre LIMIT 1"
    );
    $foraneaExiste->execute([":tabla" => "prospectos", ":nombre" => "fk_prospectos_campana"]);
    if (!$foraneaExiste->fetchColumn()) {
        $conexion->exec(
            "ALTER TABLE prospectos ADD CONSTRAINT fk_prospectos_campana
             FOREIGN KEY (id_campana) REFERENCES campanas_marketing (id_campana) ON DELETE SET NULL"
        );
    }

    $conexion->exec(
        "INSERT INTO roles (nombre, codigo) VALUES
         ('Coordinador Académico', 'coordinador_academico'),
         ('Gerencia', 'gerente')
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)"
    );
    $conexion->exec(
        "INSERT IGNORE INTO permisos (nombre, codigo) VALUES
         ('Gestionar entregas como asesor académico', 'asesor.trabajo'),
         ('Ver dashboard gerencial', 'dashboard.gerencial')"
    );

    $conexion->exec(
        "DELETE rp FROM rol_permisos rp
         INNER JOIN roles r ON r.id_rol = rp.id_rol
         INNER JOIN permisos p ON p.id_permiso = rp.id_permiso
         WHERE r.codigo = 'asesor_academico'
           AND p.codigo IN ('tesis.gestionar', 'documentos.gestionar')"
    );
    foreach ([
        "administrador" => ["dashboard.gerencial"],
        "asesor_academico" => ["asesor.trabajo"],
        "coordinador_academico" => ["tesis.gestionar", "documentos.gestionar"],
        "gerente" => ["dashboard.gerencial", "contratos.ver"],
    ] as $rol => $permisos) {
        foreach ($permisos as $permiso) {
            $stmtPermiso = $conexion->prepare(
                "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
                 SELECT r.id_rol, p.id_permiso FROM roles r CROSS JOIN permisos p
                 WHERE r.codigo = :rol AND p.codigo = :permiso"
            );
            $stmtPermiso->execute([":rol" => $rol, ":permiso" => $permiso]);
        }
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
    ejecutarMigracionComisionesPorAsesor($conexion);
}

function ejecutarMigracionComisionesPorAsesor(PDO $conexion): void
{
    $version = "023_comisiones_por_pago_y_vendedor";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        ejecutarMigracionVencimientosYDocumentosPendientes($conexion);
        return;
    }

    $columnaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :tabla AND column_name = :columna LIMIT 1"
    );
    foreach ([
        ["usuarios", "comision_porcentaje", "DECIMAL(5,2) NOT NULL DEFAULT 0"],
        ["contratos_academicos", "responsable_id", "INT NULL"],
    ] as [$tabla, $columna, $tipo]) {
        $columnaExiste->execute([":tabla" => $tabla, ":columna" => $columna]);
        if (!$columnaExiste->fetchColumn()) {
            $conexion->exec("ALTER TABLE {$tabla} ADD COLUMN {$columna} {$tipo}");
        }
    }

    $foraneaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.key_column_usage
         WHERE constraint_schema = DATABASE() AND table_name = :tabla AND constraint_name = :nombre LIMIT 1"
    );
    $foraneaExiste->execute([":tabla" => "contratos_academicos", ":nombre" => "fk_contrato_responsable"]);
    if (!$foraneaExiste->fetchColumn()) {
        $conexion->exec(
            "ALTER TABLE contratos_academicos ADD CONSTRAINT fk_contrato_responsable
             FOREIGN KEY (responsable_id) REFERENCES usuarios (id_usuario) ON DELETE SET NULL"
        );
    }

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS comisiones_ventas (
            id_comision INT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_pago INT UNSIGNED NOT NULL,
            id_usuario INT NOT NULL,
            monto_base DECIMAL(12,2) NOT NULL,
            porcentaje DECIMAL(5,2) NOT NULL,
            monto_comision DECIMAL(12,2) NOT NULL,
            fecha_pago DATE NOT NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
            id_movimiento INT UNSIGNED NULL,
            liquidada_por INT NULL,
            fecha_liquidacion DATETIME NULL,
            fecha_calculo TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_comision),
            UNIQUE KEY uq_comision_pago (id_pago),
            KEY idx_comisiones_usuario_fecha (id_usuario, fecha_pago),
            KEY idx_comisiones_estado_fecha (estado, fecha_liquidacion),
            CONSTRAINT chk_comisiones_montos CHECK (
                monto_base >= 0 AND porcentaje >= 0 AND porcentaje <= 100 AND monto_comision >= 0
            ),
            CONSTRAINT fk_comision_pago FOREIGN KEY (id_pago)
                REFERENCES pagos_contrato_academico (id_pago) ON DELETE CASCADE,
            CONSTRAINT fk_comision_usuario FOREIGN KEY (id_usuario)
                REFERENCES usuarios (id_usuario) ON DELETE RESTRICT,
            CONSTRAINT fk_comision_movimiento FOREIGN KEY (id_movimiento)
                REFERENCES movimientos_financieros (id_movimiento) ON DELETE RESTRICT,
            CONSTRAINT fk_comision_liquidador FOREIGN KEY (liquidada_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
    ejecutarMigracionVencimientosYDocumentosPendientes($conexion);
}

function ejecutarMigracionVencimientosYDocumentosPendientes(PDO $conexion): void
{
    $version = "024_vencimientos_y_documentos_pendientes";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        ejecutarMigracionFirmaClienteContratos($conexion);
        return;
    }

    $columnaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'contratos_academicos'
           AND column_name = 'fecha_fin' LIMIT 1"
    );
    $columnaExiste->execute();
    if (!$columnaExiste->fetchColumn()) {
        $conexion->exec("ALTER TABLE contratos_academicos ADD COLUMN fecha_fin DATE NULL AFTER fecha_contrato");
    }

    $indiceExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'contratos_academicos'
           AND index_name = 'idx_contratos_fecha_fin_estado' LIMIT 1"
    );
    $indiceExiste->execute();
    if (!$indiceExiste->fetchColumn()) {
        $conexion->exec(
            "CREATE INDEX idx_contratos_fecha_fin_estado
             ON contratos_academicos (estado, fecha_fin)"
        );
    }

    $conexion->exec(
        "CREATE TABLE IF NOT EXISTS documentos_pendientes (
            id_pendiente INT UNSIGNED NOT NULL AUTO_INCREMENT,
            titulo VARCHAR(180) NOT NULL,
            detalle VARCHAR(500) NULL,
            id_cliente INT UNSIGNED NOT NULL,
            id_contrato INT UNSIGNED NULL,
            fecha_limite DATE NULL,
            estado VARCHAR(20) NOT NULL DEFAULT 'Pendiente',
            creado_por INT NULL,
            resuelto_por INT NULL,
            fecha_resolucion DATETIME NULL,
            fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_pendiente),
            KEY idx_documentos_pendientes_cliente_estado (id_cliente, estado),
            KEY idx_documentos_pendientes_contrato (id_contrato),
            KEY idx_documentos_pendientes_fecha (estado, fecha_limite),
            CONSTRAINT fk_documento_pendiente_cliente FOREIGN KEY (id_cliente)
                REFERENCES clientes (id_cliente) ON DELETE RESTRICT,
            CONSTRAINT fk_documento_pendiente_contrato FOREIGN KEY (id_contrato)
                REFERENCES contratos_academicos (id_contrato) ON DELETE SET NULL,
            CONSTRAINT fk_documento_pendiente_creador FOREIGN KEY (creado_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL,
            CONSTRAINT fk_documento_pendiente_resolutor FOREIGN KEY (resuelto_por)
                REFERENCES usuarios (id_usuario) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
    ejecutarMigracionFirmaClienteContratos($conexion);
}

function ejecutarMigracionFirmaClienteContratos(PDO $conexion): void
{
    $version = "025_firma_cliente_contratos";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        ejecutarMigracionPermisoExpedientesAcademicos($conexion);
        return;
    }

    $columnaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'contratos_academicos'
           AND column_name = :columna LIMIT 1"
    );
    foreach ([
        ["estado_firma_cliente", "VARCHAR(20) NULL DEFAULT NULL AFTER fecha_fin"],
        ["fecha_firma_cliente", "DATE NULL AFTER estado_firma_cliente"],
        ["firma_cliente_registrada_por", "INT NULL AFTER fecha_firma_cliente"],
    ] as [$columna, $definicion]) {
        $columnaExiste->execute([":columna" => $columna]);
        if (!$columnaExiste->fetchColumn()) {
            $conexion->exec("ALTER TABLE contratos_academicos ADD COLUMN {$columna} {$definicion}");
        }
    }

    $indiceExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'contratos_academicos'
           AND index_name = 'idx_contratos_estado_firma_cliente' LIMIT 1"
    );
    $indiceExiste->execute();
    if (!$indiceExiste->fetchColumn()) {
        $conexion->exec(
            "CREATE INDEX idx_contratos_estado_firma_cliente
             ON contratos_academicos (estado, estado_firma_cliente)"
        );
    }

    $foraneaExiste = $conexion->prepare(
        "SELECT 1 FROM information_schema.key_column_usage
         WHERE constraint_schema = DATABASE() AND table_name = 'contratos_academicos'
           AND constraint_name = 'fk_contrato_firma_cliente_usuario' LIMIT 1"
    );
    $foraneaExiste->execute();
    if (!$foraneaExiste->fetchColumn()) {
        $conexion->exec(
            "ALTER TABLE contratos_academicos
             ADD CONSTRAINT fk_contrato_firma_cliente_usuario
             FOREIGN KEY (firma_cliente_registrada_por)
             REFERENCES usuarios (id_usuario) ON DELETE SET NULL"
        );
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
    ejecutarMigracionPermisoExpedientesAcademicos($conexion);
}

function ejecutarMigracionPermisoExpedientesAcademicos(PDO $conexion): void
{
    $version = "026_permiso_expedientes_academicos";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        ejecutarMigracionPermisoGestionTesisAsesor($conexion);
        return;
    }

    $conexion->exec(
        "INSERT IGNORE INTO permisos (nombre, codigo)
         VALUES ('Gestionar expedientes académicos', 'expedientes.gestionar')"
    );
    foreach (["coordinador_academico", "legal"] as $rol) {
        $stmtAsignar = $conexion->prepare(
            "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
             SELECT r.id_rol, p.id_permiso
             FROM roles r CROSS JOIN permisos p
             WHERE r.codigo = :rol AND p.codigo = 'expedientes.gestionar'"
        );
        $stmtAsignar->execute([":rol" => $rol]);
    }

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
    ejecutarMigracionPermisoGestionTesisAsesor($conexion);
}

function ejecutarMigracionPermisoGestionTesisAsesor(PDO $conexion): void
{
    $version = "027_permiso_gestion_tesis_asesor";
    $stmt = $conexion->prepare("SELECT 1 FROM migraciones WHERE version = :version LIMIT 1");
    $stmt->execute([":version" => $version]);
    if ($stmt->fetchColumn()) {
        return;
    }

    $stmtAsignar = $conexion->prepare(
        "INSERT IGNORE INTO rol_permisos (id_rol, id_permiso)
         SELECT r.id_rol, p.id_permiso
         FROM roles r CROSS JOIN permisos p
         WHERE r.codigo = 'asesor_academico' AND p.codigo = 'tesis.gestionar'"
    );
    $stmtAsignar->execute();

    $registrar = $conexion->prepare("INSERT INTO migraciones (version) VALUES (:version)");
    $registrar->execute([":version" => $version]);
}
