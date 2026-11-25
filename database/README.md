# Esquema académico de referencia

`sistema_tesis_academico.sql` conserva un diseño de tablas para una posible ampliación académica. No es una segunda aplicación ni forma parte de la puesta en marcha actual.

El sistema local en funcionamiento usa únicamente la base `sistema_tesis`, conectada en `config/conexion.php`; sus formularios, permisos y migraciones se mantienen dentro de la aplicación PHP. No importes el esquema de referencia para iniciar o probar el sistema actual, porque tiene un modelo distinto y aún no está conectado a sus módulos.

## Cómo se relaciona la información

- Los prospectos avanzan por etapas comerciales y se pueden convertir en clientes. Sus oportunidades registran el servicio de interés, el responsable y los seguimientos.
- Cada cliente puede tener un perfil de estudiante. El programa académico pertenece a una universidad.
- Los contratos vinculan un cliente con un servicio y pueden generar cuotas y pagos.
- Cada proyecto de tesis pertenece a un estudiante y al mismo cliente. Si está asociado a un contrato, la clave foránea compuesta garantiza que sea del cliente correcto.
- Un proyecto puede tener varios autores y asesores, y se organiza en hitos basados en un catálogo de etapas.
- Cada hito contiene entregables; cada entregable conserva versiones y el historial de revisiones.
- Los usuarios internos se asignan a roles y pueden figurar como responsables comerciales, revisores o personal que registra pagos.

La gestión académica operativa se integra en la aplicación actual mediante las migraciones de `config/migraciones.php` y la pantalla `empresa/gestion_academica.php`. La pantalla registra servicios contratados, proyectos ligados a expedientes y clientes, asesores con especialidad y disponibilidad, coordinadores, fechas, estados, entregables y observaciones. No importes este archivo SQL en la base activa ni cambies solo el nombre de la base en la conexión.

## Formularios y permisos de la aplicación actual

Los módulos PHP de `empresa/` usan la base `sistema_tesis`; sus tablas y permisos se preparan mediante las migraciones automáticas de `config/migraciones.php` al conectar la aplicación. Los prospectos con estado **Ganado** se pueden convertir a clientes desde Prospectos; la conversión conserva su vínculo comercial y registra el servicio de interés como servicio activo del cliente. Los ingresos registrados desde Pagos y los movimientos de Contabilidad se guardan en `movimientos_financieros`.

Para habilitar un área a un rol, inicia sesión con una cuenta administradora, abre **Usuarios y permisos** y marca los permisos correspondientes. Finanzas y Psicología permiten separar consulta (`Ver`) de edición (`Gestionar`); las páginas también verifican el permiso en el servidor, y no solo ocultan enlaces. Las cuentas se desactivan en vez de borrarse para conservar referencias y autoría de sus registros.

En **Usuarios y permisos**, el permiso **Ver prospectos de todos los trabajadores** controla el alcance del Embudo de ventas: los roles que lo tengan pueden consultar y asignar prospectos de todo el equipo; los demás solo ven y gestionan los asignados a su usuario. Las nuevas columnas de tipo de documento y de persona se agregan automáticamente mediante las migraciones de la aplicación.

El **Área Empresarial** guarda el perfil de la organización, sus áreas, cargos y trabajadores en `empresa_perfil`, `empresa_areas`, `empresa_cargos` y `empresa_trabajadores`. Un registro de trabajador puede asociarse opcionalmente a una cuenta de acceso ya existente. La gestión de **Documentos** registra metadatos en `documentos_empresa` y almacena los archivos en `storage/documentos`; los adjuntos de casos legales se registran en `documentos_legales` y sus cambios en `historial_casos_legales`. Los archivos permitidos actualmente son PDF, JPG y PNG, con un límite de 8 MB, y se sirven mediante páginas que requieren permiso de acceso. Inicia sesión con una cuenta administradora y concede los permisos `empresa.gestionar` y `documentos.gestionar` a los roles correspondientes desde **Usuarios y permisos**.

La conexión actual apunta a MySQL local de XAMPP (`localhost`, base `sistema_tesis`). Las migraciones se ejecutan automáticamente cuando la aplicación conecta; para que los datos estén disponibles en un sitio publicado habrá que configurar posteriormente el servidor y su propia base MySQL.
