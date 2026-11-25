<?php
$paginaActual = basename($_SERVER["PHP_SELF"]);
$queryString = $_SERVER["QUERY_STRING"] ?? "";

$enlaceActivo = static function (string $pagina, ?string $tab = null) use ($paginaActual, $queryString): string {
    if ($paginaActual !== $pagina) {
        return "";
    }
    if ($tab !== null && strpos($queryString, "tab={$tab}") === false) {
        return "";
    }
    return " active";
};

require_once __DIR__ . "/../config/seguridad.php";

// Identificar y asegurar la categoría del usuario actual
$rolCodigo = strtolower($_SESSION["rol"] ?? "");
$rolNombre = $_SESSION["rol_nombre"] ?? "";

if ((empty($rolNombre) || $rolNombre === "Usuario") && isset($conexion, $_SESSION["id_usuario"])) {
    try {
        $stmtR = $conexion->prepare(
            "SELECT r.nombre, r.codigo FROM usuarios u 
             LEFT JOIN roles r ON r.id_rol = u.id_rol 
             WHERE u.id_usuario = :id LIMIT 1"
        );
        $stmtR->execute([":id" => (int) $_SESSION["id_usuario"]]);
        $rRow = $stmtR->fetch();
        if ($rRow) {
            $rolNombre = $rRow["nombre"] ?: "Usuario";
            $rolCodigo = strtolower($rRow["codigo"] ?: "usuario");
            $_SESSION["rol_nombre"] = $rolNombre;
            $_SESSION["rol"] = $rolCodigo;
        }
    } catch (Throwable $e) {
    }
}

// Identificación estricta de bloque por categoría de usuario
$esAdmin = ($rolCodigo === "administrador");
$esComercial = in_array($rolCodigo, ["ventas", "vendedor", "comercial"]);
$esAcademico = in_array($rolCodigo, ["asesor_academico", "coordinador_academico", "academico", "asesor"]);
$esLegal = in_array($rolCodigo, ["legal", "abogado"]);
$esFinanzas = in_array($rolCodigo, ["contabilidad", "finanzas"]);
$esOperativo = in_array($rolCodigo, ["trabajador", "operaciones", "personal_operativo"]);

// Permisos complementarios solo si no coincide un bloque directo
$puedeGerencial = $esAdmin || (isset($conexion) && usuarioTienePermiso($conexion, "dashboard.gerencial"));
$puedeCRM = $esComercial;
$puedeTesis = $esAcademico;
$puedeAsesor = $esAcademico;
$puedeLegal = $esLegal;
$puedeFinanzas = $esFinanzas;
$puedeDocumentos = $esAcademico;
$puedeExpedientes = isset($conexion) && usuarioTieneAlgunPermiso(
    $conexion,
    ["expedientes.gestionar", "legal.gestionar", "tesis.gestionar", "documentos.gestionar"]
);
$puedeContratos = $esFinanzas || $esLegal;
$puedeHorarios = $esAcademico;
$puedeInventario = $esOperativo;
$puedePsicologia = $esOperativo;
$puedeFormacion = $esOperativo;
$puedeServicios = $esAdmin;
$puedeEmpresa = $esAdmin;

// Insignia de categoría profesional
$badgeClaseRol = "badge-role-worker";
$dotColor = "#94a3b8";
if ($esAdmin) {
    $badgeClaseRol = "badge-role-admin";
    $dotColor = "#a855f7";
} elseif ($esAcademico) {
    $badgeClaseRol = "badge-role-academic";
    $dotColor = "#3b82f6";
} elseif ($esComercial) {
    $badgeClaseRol = "badge-role-sales";
    $dotColor = "#10b981";
} elseif ($esLegal) {
    $badgeClaseRol = "badge-role-legal";
    $dotColor = "#f59e0b";
} elseif ($esFinanzas) {
    $badgeClaseRol = "badge-role-finance";
    $dotColor = "#0ea5e9";
}

// Iconos vectoriales SVG profesionales (reemplaza emojis para acabado de alta gama)
function navIcon(string $tipo): string {
    $map = [
        'home' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'crm' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'target' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="6" r="1"/><circle cx="12" cy="12" r="2"/></svg>',
        'pipeline' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="5" height="16" rx="1"/></svg>',
        'users' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'academic' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
        'docs' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'records' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>',
        'calendar' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'legal' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>',
        'shield' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'finance' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        'payments' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        'inventory' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'psychology' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
        'training' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
        'user_mgmt' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg>',
        'company' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="9" y1="22" x2="9" y2="18"/><line x1="15" y1="22" x2="15" y2="18"/><line x1="9" y1="6" x2="9" y2="6.01"/><line x1="15" y1="6" x2="15" y2="6.01"/><line x1="9" y1="10" x2="9" y2="10.01"/><line x1="15" y1="10" x2="15" y2="10.01"/></svg>',
        'services' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
    ];
    return $map[$tipo] ?? '';
}

// Notificaciones comerciales
$puedeRecibirNotificaciones = ($puedeCRM || $puedeTesis || $puedeAsesor || $puedeGerencial || $esAdmin)
    && isset($conexion) && $conexion instanceof PDO;
$notificacionesSinLeer = 0;
$ultimaNotificacion = 0;
if ($puedeRecibirNotificaciones) {
    try {
        $stmtNotif = $conexion->prepare(
            "SELECT COALESCE(SUM(leida_en IS NULL), 0) AS sin_leer, COALESCE(MAX(id_notificacion), 0) AS ultima
             FROM notificaciones WHERE usuario_id = :usuario"
        );
        $stmtNotif->execute([":usuario" => (int) $_SESSION["id_usuario"]]);
        $resNotif = $stmtNotif->fetch();
        $notificacionesSinLeer = (int) ($resNotif["sin_leer"] ?? 0);
        $ultimaNotificacion = (int) ($resNotif["ultima"] ?? 0);
    } catch (Throwable $e) {
    }
}
?>

<aside class="sidebar">
    <!-- Logotipo Corporativo -->
    <div class="brand-panel">
        <div class="brand-mark">
            <span>CEA</span>
        </div>
        <div class="brand-info">
            <h2>GROUP_CEA</h2>
            <span>Gestión Documental & Tesis</span>
        </div>
    </div>

    <!-- Navegación Dividida por Bloques de Trabajo -->
    <nav class="sidebar-nav">
        <!-- 1. BLOQUE PRINCIPAL -->
        <div class="nav-block">
            <span class="nav-section-label">
                <span>Principal</span>
            </span>
            <a class="side-link<?= $enlaceActivo("dashboard.php") ?>" href="dashboard.php" data-dashboard-link>
                <?= navIcon('home') ?>
                <span>Inicio</span>
            </a>
            <?php if ($puedeGerencial): ?>
            <a class="side-link<?= $enlaceActivo("dashboard_gerencial.php") ?>" href="dashboard_gerencial.php">
                <?= navIcon('finance') ?>
                <span>Dashboard Gerencial</span>
            </a>
            <?php endif; ?>
        </div>

        <!-- 2. BLOQUE: ÁREA COMERCIAL Y VENTAS -->
        <?php if ($puedeCRM): ?>
        <div class="nav-block">
            <span class="nav-section-label">
                <span>Comercial y Ventas</span>
                <span class="nav-section-pill commercial">Comercial</span>
            </span>
            <a class="side-link<?= $enlaceActivo("crm_comercial.php") ?>" href="crm_comercial.php">
                <?= navIcon('crm') ?>
                <span>Contactos y seguimiento</span>
            </a>
            <a class="side-link<?= $enlaceActivo("prospectos.php") ?>" href="prospectos.php">
                <?= navIcon('target') ?>
                <span>Prospectos</span>
            </a>
            <a class="side-link<?= $enlaceActivo("pipeline.php", "ventas") ?>" href="pipeline.php?tab=ventas">
                <?= navIcon('pipeline') ?>
                <span>Tablero de Ventas Comercial</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- 3. BLOQUE: ÁREA ACADÉMICA Y OPERACIÓN TESIS -->
        <?php if ($puedeTesis || $puedeDocumentos || $puedeAsesor): ?>
        <div class="nav-block">
            <span class="nav-section-label">
                <span>Académico y Tesis</span>
                <span class="nav-section-pill academic">Tesis</span>
            </span>
            <?php if ($puedeTesis): ?>
            <a class="side-link<?= $enlaceActivo("pipeline.php", "tesis") ?>" href="pipeline.php?tab=tesis">
                <?= navIcon('pipeline') ?>
                <span>Tablero de tesis</span>
            </a>
            <a class="side-link<?= $enlaceActivo("gestion_academica.php") ?>" href="gestion_academica.php">
                <?= navIcon('academic') ?>
                <span>Gestión Académica</span>
            </a>
            <?php endif; ?>
            <?php if ($puedeDocumentos): ?>
            <a class="side-link<?= $enlaceActivo("documentos.php") ?>" href="documentos.php">
                <?= navIcon('docs') ?>
                <span>Repositorio Documental</span>
            </a>
            <?php endif; ?>
            <?php if ($puedeExpedientes): ?>
            <a class="side-link<?= $enlaceActivo("expedientes_gestion.php") ?>" href="expedientes_gestion.php">
                <?= navIcon('records') ?>
                <span>Expedientes Académicos</span>
            </a>
            <?php endif; ?>
            <?php if ($puedeHorarios): ?>
            <a class="side-link<?= $enlaceActivo("horarios.php") ?>" href="horarios.php">
                <?= navIcon('calendar') ?>
                <span>Horarios y Asesorías</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 4. BLOQUE: ÁREA LEGAL Y CONTRATOS -->
        <?php if ($puedeLegal): ?>
        <div class="nav-block">
            <span class="nav-section-label">
                <span>Gestión Legal</span>
                <span class="nav-section-pill legal">Legal</span>
            </span>
            <a class="side-link<?= $enlaceActivo("pipeline.php", "legal") ?>" href="pipeline.php?tab=legal">
                <?= navIcon('pipeline') ?>
                <span>Tablero de gestión legal contratos/convenios</span>
            </a>
            <a class="side-link<?= $enlaceActivo("legal.php") ?>" href="legal.php">
                <?= navIcon('shield') ?>
                <span>Área Legal y Casos</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- 5. BLOQUE: GESTIÓN FINANCIERA -->
        <?php if ($puedeFinanzas): ?>
        <div class="nav-block">
            <span class="nav-section-label">
                <span>Finanzas y Pagos</span>
                <span class="nav-section-pill finance">Finanzas</span>
            </span>
            <a class="side-link<?= $enlaceActivo("contabilidad.php") ?>" href="contabilidad.php">
                <?= navIcon('finance') ?>
                <span>Contabilidad</span>
            </a>
            <a class="side-link<?= $enlaceActivo("pagos.php") ?>" href="pagos.php">
                <?= navIcon('payments') ?>
                <span>Pagos y Comprobantes</span>
            </a>
            <a class="side-link<?= $enlaceActivo("contratos.php") ?>" href="contratos.php">
                <?= navIcon('payments') ?>
                <span>Contratos, cuotas y saldos</span>
            </a>
        </div>
        <?php endif; ?>

        <!-- 6. BLOQUE: OPERACIONES Y SOPORTE INTERNO -->
        <?php if ($puedeInventario || $puedePsicologia || $puedeFormacion): ?>
        <div class="nav-block">
            <span class="nav-section-label">
                <span>Operaciones</span>
                <span class="nav-section-pill ops">Apoyo</span>
            </span>
            <?php if ($puedeInventario): ?>
            <a class="side-link<?= $enlaceActivo("inventario.php") ?>" href="inventario.php">
                <?= navIcon('inventory') ?>
                <span>Inventario y Recursos</span>
            </a>
            <?php endif; ?>
            <?php if ($puedePsicologia): ?>
            <a class="side-link<?= $enlaceActivo("psicologia.php") ?>" href="psicologia.php">
                <?= navIcon('psychology') ?>
                <span>Psicología y Soporte</span>
            </a>
            <?php endif; ?>
            <?php if ($puedeFormacion): ?>
            <a class="side-link<?= $enlaceActivo("formacion_marketing.php") ?>" href="formacion_marketing.php">
                <?= navIcon('training') ?>
                <span>Formación Interna</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 7. BLOQUE EXCLUSIVO: ADMINISTRACIÓN GENERAL -->
        <?php if ($esAdmin): ?>
        <div class="nav-block admin-nav-block">
            <span class="nav-section-label admin-section-label">
                <span>Administración</span>
                <span class="nav-section-pill admin">Solo Admin</span>
            </span>
            <a class="side-link admin-side-link<?= $enlaceActivo("usuarios.php") ?>" href="usuarios.php">
                <?= navIcon('user_mgmt') ?>
                <span>Usuarios y Categorías</span>
            </a>
            <?php if ($puedeEmpresa): ?>
            <a class="side-link admin-side-link<?= $enlaceActivo("empresa.php") ?>" href="empresa.php">
                <?= navIcon('company') ?>
                <span>Área Empresarial</span>
            </a>
            <?php endif; ?>
            <?php if ($puedeServicios): ?>
            <a class="side-link admin-side-link<?= $enlaceActivo("servicios_gestion.php") ?>" href="servicios_gestion.php">
                <?= navIcon('services') ?>
                <span>Catálogo de Servicios</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </nav>

    <!-- Perfil del Usuario en el Pie del Sidebar -->
    <div class="sidebar-footer">
        <div class="user-profile-badge">
            <div class="user-avatar-circle">
                <?= strtoupper(substr($_SESSION["nombres"] ?? $_SESSION["usuario"] ?? "U", 0, 1)) ?>
            </div>
            <div class="user-meta-info">
                <span class="user-name-label">
                    <?= htmlspecialchars($_SESSION["nombres"] ?? $_SESSION["usuario"] ?? "Usuario", ENT_QUOTES, "UTF-8") ?>
                </span>
                <span class="user-role-tag <?= $badgeClaseRol ?>">
                    <span class="user-role-dot" style="background-color: <?= $dotColor ?>;"></span>
                    <?= htmlspecialchars($rolNombre ?: "Usuario", ENT_QUOTES, "UTF-8") ?>
                </span>
            </div>
        </div>
        <a href="../auth/logout.php" class="logout-btn" title="Cerrar sesión" aria-label="Cerrar sesión">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
        </a>
    </div>
</aside>

<!-- Scripts globales -->
<script src="../js/validaciones.js?v=<?= (int) filemtime(__DIR__ . "/../js/validaciones.js") ?>" defer></script>
<script src="../js/asistente_ia.js?v=<?= (int) filemtime(__DIR__ . "/../js/asistente_ia.js") ?>" defer></script>

<!-- Botón de Notificaciones (Solo la campanita) -->
<?php if ($puedeRecibirNotificaciones): ?>
<section class="notification-center" data-notification-center
    data-endpoint="../api/notificaciones.php"
    data-last-id="<?= $ultimaNotificacion ?>">
    <button class="notification-toggle" type="button" data-notification-toggle
        aria-expanded="false" aria-controls="notification-popover"
        aria-label="Notificaciones<?= $notificacionesSinLeer > 0 ? ": {$notificacionesSinLeer} sin leer" : "" ?>"
        title="Notificaciones del sistema">
        <svg class="notification-bell" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
            <path d="M10 21h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path>
        </svg>
        <span class="notification-badge" data-notification-count<?= $notificacionesSinLeer === 0 ? " hidden" : "" ?>><?= $notificacionesSinLeer > 99 ? "99+" : $notificacionesSinLeer ?></span>
    </button>
    <div class="notification-popover" id="notification-popover" data-notification-popover hidden>
        <div class="notification-heading">
            <strong>Novedades del Sistema</strong>
            <button class="notification-mark-all" type="button" data-notification-mark-all<?= $notificacionesSinLeer === 0 ? " disabled" : "" ?>>Marcar leídas</button>
        </div>
        <p class="notification-error" data-notification-error role="status" hidden></p>
        <div class="notification-list" data-notification-list aria-live="polite">
            <p class="notification-empty">Cargando notificaciones...</p>
        </div>
    </div>
</section>
<script src="../js/notificaciones.js?v=<?= (int) filemtime(__DIR__ . "/../js/notificaciones.js") ?>" defer></script>
<?php endif; ?>
