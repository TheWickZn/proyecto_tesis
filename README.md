# GROUP_CEA — Sistema local

Aplicación local integrada para operar desde una sola entrada: PHP sirve el panel y los módulos administrativos; el frontend comercial compilado se monta dentro del mismo panel y comparte sesión, API y base de datos.

## Iniciar el sistema

1. Inicia Apache y MySQL en XAMPP.
2. Verifica la conexión local en `config/conexion.php` y que la base activa sea `sistema_tesis`.
3. Si cambias el frontend, ejecuta `npm install` y `npm run build` desde `frontend/`.
4. Abre `http://localhost/sistema_tesis/` e inicia sesión.

El uso normal no requiere otro servidor web ni dejar Vite ejecutándose. CRM, Pipeline, Asistente CRM y WhatsApp local se muestran dentro del panel según los permisos; las demás áreas siguen integradas en la navegación PHP.

## Despliegue público

GitHub guarda el código, pero GitHub Pages no ejecuta esta aplicación PHP ni su base MySQL. Para una demo gratuita, InfinityFree anuncia soporte para PHP, MySQL y SSL. Crea allí una base vacía, copia `config/hosting.local.php.example` como `config/hosting.local.php` en el servidor y completa los valores que proporciona el panel. Las migraciones crean las tablas y la cuenta inicial si configuras `APP_ADMIN_USERNAME` y `APP_ADMIN_PASSWORD`.

Sube los archivos por FTP o el gestor del hosting; un `push` a GitHub no actualiza InfinityFree automáticamente. Tras editar, compila React con `npm run build` en `frontend/` si corresponde, vuelve a subir los archivos modificados y recarga el sitio.

No subas ni importes respaldos SQL, claves, archivos privados ni datos personales reales. Los respaldos locales están excluidos por `.gitignore`. Usa solo datos ficticios en esta demo. Después del primer arranque, elimina `APP_ADMIN_USERNAME` y `APP_ADMIN_PASSWORD` del archivo local del hosting; la contraseña se almacena como hash en MySQL. Protege igualmente las credenciales de la base y no las compartas públicamente.

El proyecto también conserva un `Dockerfile` para proveedores como Railway que ejecutan contenedores y variables de entorno. Para documentos subidos, configura almacenamiento persistente en esos proveedores; el acceso web directo a `storage/` está bloqueado.

## Pagos y coordinación académica

- Al convertir un prospecto ganado, el CRM genera el cliente, su expediente, el proyecto académico y el cronograma inicial de ocho etapas; si hay asesores activos, asigna el de menor carga de proyectos. En **Gestión Académica**, las tarjetas se abren para consultar el expediente y actualizar fechas, estados y observaciones.
- **Contratos, cuotas y saldos** permite registrar precio total, adelanto, vencimientos y pagos. El saldo se calcula con pagos registrados y los vencimientos se clasifican como al día, próximos o vencidos. Cada pago contractual también se refleja en Contabilidad.
- Los contratos admiten una fecha de fin opcional. **Contratos, cuotas y saldos** muestra un aviso cuando un contrato vigente vence hoy o dentro de los próximos 7 días; los contratos ya registrados sin fecha deben actualizarse para recibir avisos.
- Los contratos nuevos quedan pendientes de la firma del cliente hasta registrar su firma y fecha. El módulo **Contratos, cuotas y saldos** alerta sobre los contratos pendientes; los contratos existentes quedan como “Sin seguimiento” para evitar avisos incorrectos y se pueden marcar manualmente.
- En **Gestión de Documentos**, contratos, adendas, comprobantes y otros archivos pueden vincularse a clientes, proyectos y contratos; los documentos contractuales quedan limitados a los permisos de contratos, finanzas y legal. También se puede llevar un checklist manual de documentos requeridos por cliente o contrato, con fecha límite y estado pendiente/completado/cancelado.
- El rol **Asesor académico** accede a **Mis tareas y entregas**, con solo sus proyectos asignados. Puede subir versiones para revisión; coordinación recibe una notificación, aprueba u observa la entrega y el asesor recibe el resultado. Los archivos se guardan en almacenamiento privado y se descargan mediante una ruta autorizada.
- El rol **Coordinador Académico** puede revisar entregas y gestionar proyectos. El acceso del asesor ya no incluye el tablero global ni el repositorio general de documentos.
- **Dashboard Gerencial** muestra ventas confirmadas/pagadas del mes, clientes nuevos, prospectos, proyectos activos, saldos por cobrar, conversión, ingresos, cobranza vencida, carga académica y productividad.
- En el dashboard se registran campañas, inversión y canal. Los prospectos creados con el enlace de campaña quedan asociados para calcular leads, CPL y clientes generados.
- Las comisiones se calculan por cada pago contractual recibido, con el porcentaje configurado para el vendedor responsable del contrato. La tasa aplicada queda congelada en el registro; liquidar una comisión crea el egreso correspondiente. Los pagos anteriores a la función no se recalculan.
- Los contratos admiten un vendedor responsable; los contratos sin asignar aparecen como alerta porque no generan comisiones. El gerente puede configurar tasas por vendedor y asignar responsables, incluso para contratos existentes (solo para pagos futuros).
- El esquema nuevo se aplica automáticamente mediante migraciones al abrir el sistema.

## Formulario público de prospectos

El formulario separado del panel está disponible en `http://localhost/sistema_tesis/solicitud_prospecto.php`. Cada solicitud se guarda en el CRM, se asigna por turnos entre vendedores activos y genera una notificación para el vendedor asignado.

Para registrar el canal automáticamente, se puede compartir el enlace con `?fuente=facebook`, `?fuente=tiktok`, `?fuente=whatsapp`, `?fuente=instagram`, `?fuente=google`, `?fuente=referido` o `?fuente=otro`. Para medir una campaña concreta, comparte el enlace generado desde **Dashboard Gerencial** (`?campana=ID`). El CRM registra como nuevos los prospectos recibidos; el vendedor programa su siguiente seguimiento desde el módulo de prospectos.

## Organización

- `auth/`, `config/`, `api/`, `empresa/`: autenticación, permisos, API y módulos del sistema.
- `frontend/`: único código fuente de la interfaz React.
- `public/app/`: archivos compilados que sirve PHP; se regeneran con `npm run build`.
- `database/sistema_tesis_academico.sql`: esquema de referencia para una posible ampliación académica; no es la base de datos activa y no se debe importar para iniciar el sistema actual.

Consulta [frontend/README.md](./frontend/README.md) para desarrollo y pruebas locales.
