CREATE DATABASE IF NOT EXISTS sistema_tesis_academico
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sistema_tesis_academico;

CREATE TABLE IF NOT EXISTS roles (
    id_rol INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(60) NOT NULL,
    codigo VARCHAR(60) NOT NULL,
    PRIMARY KEY (id_rol),
    UNIQUE KEY uq_roles_nombre (nombre),
    UNIQUE KEY uq_roles_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_rol INT UNSIGNED NOT NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    correo VARCHAR(180) NOT NULL,
    telefono VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    estado ENUM('Activo', 'Inactivo', 'Suspendido') NOT NULL DEFAULT 'Activo',
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_usuario),
    UNIQUE KEY uq_usuarios_correo (correo),
    KEY idx_usuarios_rol_estado (id_rol, estado),
    CONSTRAINT fk_usuarios_rol FOREIGN KEY (id_rol)
        REFERENCES roles (id_rol) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clientes (
    id_cliente INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_cliente ENUM('Persona', 'Empresa') NOT NULL DEFAULT 'Persona',
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NULL,
    razon_social VARCHAR(180) NULL,
    tipo_documento VARCHAR(30) NULL,
    numero_documento VARCHAR(40) NULL,
    correo VARCHAR(180) NULL,
    telefono VARCHAR(30) NULL,
    direccion VARCHAR(255) NULL,
    estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',
    observaciones TEXT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_cliente),
    UNIQUE KEY uq_clientes_documento (tipo_documento, numero_documento),
    KEY idx_clientes_nombre (apellidos, nombres),
    KEY idx_clientes_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS universidades (
    id_universidad INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(180) NOT NULL,
    abreviatura VARCHAR(40) NULL,
    pais VARCHAR(100) NOT NULL DEFAULT 'Perú',
    estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',
    PRIMARY KEY (id_universidad),
    UNIQUE KEY uq_universidades_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programas_academicos (
    id_programa INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_universidad INT UNSIGNED NOT NULL,
    nombre VARCHAR(180) NOT NULL,
    facultad VARCHAR(180) NULL,
    grado VARCHAR(80) NOT NULL DEFAULT '',
    estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',
    PRIMARY KEY (id_programa),
    UNIQUE KEY uq_programa_universidad_nombre_grado (id_universidad, nombre, grado),
    CONSTRAINT fk_programas_universidad FOREIGN KEY (id_universidad)
        REFERENCES universidades (id_universidad) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estudiantes (
    id_estudiante INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente INT UNSIGNED NOT NULL,
    id_programa INT UNSIGNED NULL,
    codigo_universitario VARCHAR(60) NULL,
    ciclo VARCHAR(30) NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_estudiante),
    UNIQUE KEY uq_estudiantes_cliente (id_cliente),
    UNIQUE KEY uq_estudiante_programa_codigo (id_programa, codigo_universitario),
    UNIQUE KEY uq_estudiante_cliente (id_estudiante, id_cliente),
    CONSTRAINT fk_estudiantes_cliente FOREIGN KEY (id_cliente)
        REFERENCES clientes (id_cliente) ON DELETE CASCADE,
    CONSTRAINT fk_estudiantes_programa FOREIGN KEY (id_programa)
        REFERENCES programas_academicos (id_programa) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servicios (
    id_servicio INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    precio_referencial DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',
    PRIMARY KEY (id_servicio),
    UNIQUE KEY uq_servicios_nombre (nombre),
    CONSTRAINT chk_servicios_precio CHECK (precio_referencial >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospectos (
    id_prospecto INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NULL,
    correo VARCHAR(180) NULL,
    telefono VARCHAR(30) NULL,
    universidad_interes VARCHAR(180) NULL,
    carrera_interes VARCHAR(180) NULL,
    fuente VARCHAR(100) NULL,
    estado ENUM('Nuevo', 'Contactado', 'Calificado', 'Convertido', 'Descartado') NOT NULL DEFAULT 'Nuevo',
    responsable_id INT UNSIGNED NULL,
    convertido_a_cliente_id INT UNSIGNED NULL,
    observaciones TEXT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_prospecto),
    UNIQUE KEY uq_prospecto_cliente_convertido (convertido_a_cliente_id),
    KEY idx_prospectos_estado_responsable (estado, responsable_id),
    CONSTRAINT fk_prospectos_responsable FOREIGN KEY (responsable_id)
        REFERENCES usuarios (id_usuario) ON DELETE SET NULL,
    CONSTRAINT fk_prospectos_cliente FOREIGN KEY (convertido_a_cliente_id)
        REFERENCES clientes (id_cliente) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etapas_comerciales (
    id_etapa_comercial INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(40) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL,
    resultado ENUM('Abierta', 'Ganada', 'Perdida') NOT NULL DEFAULT 'Abierta',
    PRIMARY KEY (id_etapa_comercial),
    UNIQUE KEY uq_etapas_comerciales_codigo (codigo),
    UNIQUE KEY uq_etapas_comerciales_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oportunidades (
    id_oportunidad INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_prospecto INT UNSIGNED NULL,
    id_cliente INT UNSIGNED NULL,
    id_servicio INT UNSIGNED NULL,
    id_etapa_comercial INT UNSIGNED NOT NULL,
    responsable_id INT UNSIGNED NULL,
    titulo VARCHAR(180) NOT NULL,
    monto_estimado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fecha_proxima_accion DATE NULL,
    observaciones TEXT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_oportunidad),
    KEY idx_oportunidades_etapa_fecha (id_etapa_comercial, fecha_registro),
    KEY idx_oportunidades_responsable (responsable_id),
    CONSTRAINT chk_oportunidad_un_solo_contacto CHECK (
        (id_prospecto IS NOT NULL AND id_cliente IS NULL) OR
        (id_prospecto IS NULL AND id_cliente IS NOT NULL)
    ),
    CONSTRAINT chk_oportunidad_monto CHECK (monto_estimado >= 0),
    CONSTRAINT fk_oportunidades_prospecto FOREIGN KEY (id_prospecto)
        REFERENCES prospectos (id_prospecto) ON DELETE RESTRICT,
    CONSTRAINT fk_oportunidades_cliente FOREIGN KEY (id_cliente)
        REFERENCES clientes (id_cliente) ON DELETE RESTRICT,
    CONSTRAINT fk_oportunidades_servicio FOREIGN KEY (id_servicio)
        REFERENCES servicios (id_servicio) ON DELETE SET NULL,
    CONSTRAINT fk_oportunidades_etapa FOREIGN KEY (id_etapa_comercial)
        REFERENCES etapas_comerciales (id_etapa_comercial) ON DELETE RESTRICT,
    CONSTRAINT fk_oportunidades_responsable FOREIGN KEY (responsable_id)
        REFERENCES usuarios (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seguimientos_comerciales (
    id_seguimiento INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_oportunidad INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NULL,
    tipo ENUM('Llamada', 'Correo', 'Reunión', 'Mensaje', 'Otro') NOT NULL DEFAULT 'Otro',
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resumen TEXT NOT NULL,
    proxima_accion DATE NULL,
    PRIMARY KEY (id_seguimiento),
    KEY idx_seguimientos_oportunidad_fecha (id_oportunidad, fecha),
    CONSTRAINT fk_seguimientos_oportunidad FOREIGN KEY (id_oportunidad)
        REFERENCES oportunidades (id_oportunidad) ON DELETE CASCADE,
    CONSTRAINT fk_seguimientos_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contratos (
    id_contrato INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente INT UNSIGNED NOT NULL,
    id_servicio INT UNSIGNED NOT NULL,
    id_oportunidad INT UNSIGNED NULL,
    responsable_id INT UNSIGNED NULL,
    numero_contrato VARCHAR(60) NOT NULL,
    fecha_firma DATE NULL,
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    monto_total DECIMAL(12,2) NOT NULL,
    estado ENUM('Borrador', 'Vigente', 'Completado', 'Cancelado') NOT NULL DEFAULT 'Borrador',
    observaciones TEXT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_contrato),
    UNIQUE KEY uq_contratos_numero (numero_contrato),
    UNIQUE KEY uq_contrato_cliente (id_contrato, id_cliente),
    KEY idx_contratos_cliente_estado (id_cliente, estado),
    CONSTRAINT chk_contrato_monto CHECK (monto_total >= 0),
    CONSTRAINT chk_contrato_fechas CHECK (
        fecha_fin IS NULL OR fecha_inicio IS NULL OR fecha_fin >= fecha_inicio
    ),
    CONSTRAINT fk_contratos_cliente FOREIGN KEY (id_cliente)
        REFERENCES clientes (id_cliente) ON DELETE RESTRICT,
    CONSTRAINT fk_contratos_servicio FOREIGN KEY (id_servicio)
        REFERENCES servicios (id_servicio) ON DELETE RESTRICT,
    CONSTRAINT fk_contratos_oportunidad FOREIGN KEY (id_oportunidad)
        REFERENCES oportunidades (id_oportunidad) ON DELETE SET NULL,
    CONSTRAINT fk_contratos_responsable FOREIGN KEY (responsable_id)
        REFERENCES usuarios (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proyectos_tesis (
    id_tesis INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cliente INT UNSIGNED NOT NULL,
    id_estudiante INT UNSIGNED NOT NULL,
    id_contrato INT UNSIGNED NULL,
    titulo VARCHAR(255) NOT NULL,
    linea_investigacion VARCHAR(180) NULL,
    tipo_investigacion VARCHAR(100) NULL,
    problema_investigacion TEXT NULL,
    objetivos TEXT NULL,
    metodologia TEXT NULL,
    estado ENUM('Propuesta', 'Planificación', 'En desarrollo', 'En revisión', 'Listo para sustentar', 'Sustentado', 'Cerrado', 'Cancelado') NOT NULL DEFAULT 'Propuesta',
    fecha_inicio DATE NULL,
    fecha_entrega_estimada DATE NULL,
    fecha_sustentacion DATE NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_tesis),
    UNIQUE KEY uq_tesis_contrato (id_contrato),
    KEY idx_tesis_cliente_estado (id_cliente, estado),
    KEY idx_tesis_estudiante (id_estudiante),
    CONSTRAINT chk_tesis_fechas CHECK (
        fecha_entrega_estimada IS NULL OR fecha_inicio IS NULL OR fecha_entrega_estimada >= fecha_inicio
    ),
    CONSTRAINT fk_tesis_cliente FOREIGN KEY (id_cliente)
        REFERENCES clientes (id_cliente) ON DELETE RESTRICT,
    CONSTRAINT fk_tesis_estudiante_cliente FOREIGN KEY (id_estudiante, id_cliente)
        REFERENCES estudiantes (id_estudiante, id_cliente) ON DELETE RESTRICT,
    CONSTRAINT fk_tesis_contrato_cliente FOREIGN KEY (id_contrato, id_cliente)
        REFERENCES contratos (id_contrato, id_cliente) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autores_tesis (
    id_tesis INT UNSIGNED NOT NULL,
    id_estudiante INT UNSIGNED NOT NULL,
    rol_autor ENUM('Principal', 'Coautor') NOT NULL DEFAULT 'Coautor',
    PRIMARY KEY (id_tesis, id_estudiante),
    CONSTRAINT fk_autores_tesis FOREIGN KEY (id_tesis)
        REFERENCES proyectos_tesis (id_tesis) ON DELETE CASCADE,
    CONSTRAINT fk_autores_estudiante FOREIGN KEY (id_estudiante)
        REFERENCES estudiantes (id_estudiante) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asesores (
    id_asesor INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario INT UNSIGNED NULL,
    nombres VARCHAR(100) NOT NULL,
    apellidos VARCHAR(100) NOT NULL,
    correo VARCHAR(180) NULL,
    especialidad VARCHAR(180) NULL,
    grado_academico VARCHAR(100) NULL,
    estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',
    PRIMARY KEY (id_asesor),
    UNIQUE KEY uq_asesor_usuario (id_usuario),
    CONSTRAINT fk_asesores_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tesis_asesores (
    id_tesis INT UNSIGNED NOT NULL,
    id_asesor INT UNSIGNED NOT NULL,
    tipo_asesor ENUM('Principal', 'Metodológico', 'Temático', 'Estadístico') NOT NULL DEFAULT 'Principal',
    fecha_asignacion DATE NOT NULL,
    fecha_fin DATE NULL,
    observaciones TEXT NULL,
    PRIMARY KEY (id_tesis, id_asesor, fecha_asignacion),
    KEY idx_tesis_asesores_asesor (id_asesor, fecha_fin),
    CONSTRAINT chk_asignacion_asesor_fechas CHECK (
        fecha_fin IS NULL OR fecha_fin >= fecha_asignacion
    ),
    CONSTRAINT fk_tesis_asesores_tesis FOREIGN KEY (id_tesis)
        REFERENCES proyectos_tesis (id_tesis) ON DELETE CASCADE,
    CONSTRAINT fk_tesis_asesores_asesor FOREIGN KEY (id_asesor)
        REFERENCES asesores (id_asesor) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS etapas_tesis (
    id_etapa_tesis INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo VARCHAR(50) NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL,
    descripcion VARCHAR(255) NULL,
    PRIMARY KEY (id_etapa_tesis),
    UNIQUE KEY uq_etapas_tesis_codigo (codigo),
    UNIQUE KEY uq_etapas_tesis_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hitos_tesis (
    id_hito INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_tesis INT UNSIGNED NOT NULL,
    id_etapa_tesis INT UNSIGNED NOT NULL,
    responsable_id INT UNSIGNED NULL,
    fecha_objetivo DATE NULL,
    fecha_completado DATE NULL,
    estado ENUM('Pendiente', 'En proceso', 'En revisión', 'Completado', 'Atrasado', 'Omitido') NOT NULL DEFAULT 'Pendiente',
    observaciones TEXT NULL,
    PRIMARY KEY (id_hito),
    UNIQUE KEY uq_hito_tesis_etapa (id_tesis, id_etapa_tesis),
    KEY idx_hitos_responsable_estado (responsable_id, estado),
    CONSTRAINT fk_hitos_tesis FOREIGN KEY (id_tesis)
        REFERENCES proyectos_tesis (id_tesis) ON DELETE CASCADE,
    CONSTRAINT fk_hitos_etapa FOREIGN KEY (id_etapa_tesis)
        REFERENCES etapas_tesis (id_etapa_tesis) ON DELETE RESTRICT,
    CONSTRAINT fk_hitos_responsable FOREIGN KEY (responsable_id)
        REFERENCES usuarios (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entregables_tesis (
    id_entregable INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_hito INT UNSIGNED NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    descripcion TEXT NULL,
    obligatorio TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id_entregable),
    KEY idx_entregables_hito (id_hito),
    CONSTRAINT fk_entregables_hito FOREIGN KEY (id_hito)
        REFERENCES hitos_tesis (id_hito) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS versiones_entregable (
    id_version INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_entregable INT UNSIGNED NOT NULL,
    numero_version SMALLINT UNSIGNED NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    ruta_archivo VARCHAR(500) NOT NULL,
    comentario_estudiante TEXT NULL,
    estado ENUM('En revisión', 'Observado', 'Aprobado') NOT NULL DEFAULT 'En revisión',
    enviado_por INT UNSIGNED NULL,
    fecha_envio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_revision DATETIME NULL,
    PRIMARY KEY (id_version),
    UNIQUE KEY uq_entregable_version (id_entregable, numero_version),
    KEY idx_versiones_estado_fecha (estado, fecha_envio),
    CONSTRAINT fk_versiones_entregable FOREIGN KEY (id_entregable)
        REFERENCES entregables_tesis (id_entregable) ON DELETE CASCADE,
    CONSTRAINT fk_versiones_usuario FOREIGN KEY (enviado_por)
        REFERENCES usuarios (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revisiones_entregable (
    id_revision INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_version INT UNSIGNED NOT NULL,
    id_asesor INT UNSIGNED NULL,
    resultado ENUM('Observado', 'Aprobado') NOT NULL,
    comentarios TEXT NOT NULL,
    fecha_revision DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_revision),
    KEY idx_revisiones_version_fecha (id_version, fecha_revision),
    CONSTRAINT fk_revisiones_version FOREIGN KEY (id_version)
        REFERENCES versiones_entregable (id_version) ON DELETE CASCADE,
    CONSTRAINT fk_revisiones_asesor FOREIGN KEY (id_asesor)
        REFERENCES asesores (id_asesor) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cuotas_contrato (
    id_cuota INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_contrato INT UNSIGNED NOT NULL,
    numero_cuota SMALLINT UNSIGNED NOT NULL,
    concepto VARCHAR(150) NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    estado ENUM('Pendiente', 'Parcial', 'Pagada', 'Vencida', 'Anulada') NOT NULL DEFAULT 'Pendiente',
    PRIMARY KEY (id_cuota),
    UNIQUE KEY uq_contrato_numero_cuota (id_contrato, numero_cuota),
    KEY idx_cuotas_estado_vencimiento (estado, fecha_vencimiento),
    CONSTRAINT chk_cuota_monto CHECK (monto >= 0),
    CONSTRAINT fk_cuotas_contrato FOREIGN KEY (id_contrato)
        REFERENCES contratos (id_contrato) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagos (
    id_pago INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_cuota INT UNSIGNED NOT NULL,
    registrado_por INT UNSIGNED NULL,
    numero_comprobante VARCHAR(80) NULL,
    fecha_pago DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    monto DECIMAL(12,2) NOT NULL,
    metodo_pago ENUM('Efectivo', 'Transferencia', 'Tarjeta', 'Billetera digital', 'Otro') NOT NULL,
    referencia VARCHAR(120) NULL,
    estado ENUM('Registrado', 'Anulado', 'Reembolsado') NOT NULL DEFAULT 'Registrado',
    observaciones TEXT NULL,
    PRIMARY KEY (id_pago),
    UNIQUE KEY uq_pagos_comprobante (numero_comprobante),
    KEY idx_pagos_cuota_fecha (id_cuota, fecha_pago),
    CONSTRAINT chk_pago_monto CHECK (monto > 0),
    CONSTRAINT fk_pagos_cuota FOREIGN KEY (id_cuota)
        REFERENCES cuotas_contrato (id_cuota) ON DELETE RESTRICT,
    CONSTRAINT fk_pagos_usuario FOREIGN KEY (registrado_por)
        REFERENCES usuarios (id_usuario) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (nombre, codigo) VALUES
    ('Administrador', 'administrador'),
    ('Comercial', 'comercial'),
    ('Asesor académico', 'asesor_academico'),
    ('Finanzas', 'finanzas');

INSERT IGNORE INTO etapas_comerciales (codigo, nombre, orden, resultado) VALUES
    ('nuevo', 'Nuevo', 1, 'Abierta'),
    ('contactado', 'Contactado', 2, 'Abierta'),
    ('diagnostico', 'Diagnóstico académico', 3, 'Abierta'),
    ('propuesta', 'Propuesta enviada', 4, 'Abierta'),
    ('negociacion', 'Negociación', 5, 'Abierta'),
    ('ganada', 'Ganada', 6, 'Ganada'),
    ('perdida', 'Perdida', 7, 'Perdida');

INSERT IGNORE INTO etapas_tesis (codigo, nombre, orden, descripcion) VALUES
    ('propuesta', 'Propuesta', 1, 'Definición y aprobación del tema de investigación.'),
    ('plan_tesis', 'Plan de tesis', 2, 'Elaboración y aprobación del plan de investigación.'),
    ('marco_teorico', 'Marco teórico', 3, 'Desarrollo de antecedentes y bases teóricas.'),
    ('metodologia', 'Metodología', 4, 'Diseño y validación metodológica.'),
    ('trabajo_campo', 'Trabajo de campo', 5, 'Recolección y procesamiento de datos.'),
    ('resultados', 'Resultados y discusión', 6, 'Análisis e interpretación de resultados.'),
    ('revision_final', 'Revisión final', 7, 'Correcciones y preparación de sustentación.'),
    ('sustentacion', 'Sustentación', 8, 'Preparación y registro de la defensa académica.');

INSERT IGNORE INTO servicios (nombre, descripcion, precio_referencial) VALUES
    ('Asesoría metodológica', 'Acompañamiento en el diseño y validación metodológica.', 0.00),
    ('Asesoría integral de tesis', 'Acompañamiento académico por etapas del proyecto.', 0.00),
    ('Análisis estadístico', 'Orientación en procesamiento y análisis de datos.', 0.00),
    ('Revisión académica', 'Revisión de estructura, redacción y formato académico.', 0.00),
    ('Preparación para sustentación', 'Orientación para presentación y defensa del trabajo.', 0.00);
