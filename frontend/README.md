# Sistema local GROUP_CEA

Este es el único frontend del sistema. CRM, Pipeline, Asistente CRM y WhatsApp local usan la sesión, la API PHP y la base de datos `sistema_tesis`.

## Puesta en marcha

1. Inicia Apache y MySQL desde XAMPP.
2. Desde esta carpeta, ejecuta `npm install` la primera vez y `npm run build`.
3. Abre `http://localhost/sistema_tesis/` e inicia sesión.

El build se genera en `public/app/` y PHP lo sirve dentro del panel existente. No hace falta mantener Vite abierto para el uso normal. El servidor `npm run dev` queda para desarrollo.

## Módulos integrados

- **CRM Comercial:** alta, edición, búsqueda y gestión de clientes.
- **Pipeline Kanban:** oportunidades, etapas y cuestionario comercial por gestión.
- **Asistente CRM:** consulta clientes y oportunidades, mueve oportunidades con confirmación y gestiona documentos cuando el usuario tiene permiso.
- **WhatsApp:** bandeja de demostración local; no es una conexión con WhatsApp Business.
- Los demás módulos operativos y administrativos siguen en PHP y aparecen según los permisos del usuario.

## Comandos

- `npm run dev`: servidor local de desarrollo.
- `npm run build`: compila assets para XAMPP.
- `npm run lint`: revisión estática.
