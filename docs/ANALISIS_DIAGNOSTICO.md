# Análisis y diagnóstico de la implementación actual

## 1. ARQUITECTURA ACTUAL

### Stack tecnológico

| Capa | Tecnología | Versión |
|------|-----------|---------|
| Frontend | HTML + JavaScript vanilla + CSS | — |
| Backend | PHP 8+ con PDO (driver sqlsrv) | 8.x |
| Base de datos | Microsoft SQL Server | 2022 (MSSQL17.SQL2025) |
| Servidor web | IIS con FastCGI (Windows) | — |
| Autenticación | Sesión PHP + Bearer Token | — |
| PDF | HTML imprimible (window.print → PDF del navegador) | — |
| Excel | PhpSpreadsheet (composer.json) | — |

### Estructura del proyecto

```
Atencoines3/
├── api/
│   ├── index.php                    Router principal (dispatcher por archivo)
│   └── routes/                      Un archivo PHP por recurso REST
│       ├── auth.php
│       ├── cotizaciones.php         1332 líneas — CRUD principal
│       ├── cotizaciones_print.php   Vista HTML imprimible
│       ├── cotizaciones_emisora.php Gestión de emisoras + logo
│       ├── cotizaciones_catalogos.php
│       ├── cotizaciones_documentos.php
│       ├── cotizaciones_export.php
│       ├── rubros.php               56 líneas — CRUD simple
│       ├── empresas.php
│       └── ...
├── assets/
│   ├── js/
│   │   ├── app.js                   App principal (router SPA)
│   │   ├── views.js                 8272 líneas — todas las vistas
│   │   ├── cotizaciones_v2.js       1310 líneas — vista de cotizaciones
│   │   ├── cotizaciones_view.js     Vista detalle/editar
│   │   ├── configuracion_emisoras_logo.js
│   │   └── ...
│   ├── css/
│   └── img/
├── config/
│   └── database.php                 Conexión PDO a SQL Server
├── includes/
│   ├── auth.php
│   ├── audit.php
│   ├── api_response.php
│   ├── session.php
│   ├── PermisoHelper.php
│   └── CotizacionExporter.php       Generación de PDF/Excel/CSV
├── modules/
│   ├── notificaciones/
│   └── exportacion/
├── scripts_sql/
│   └── cotizaciones/                Scripts SQL del módulo original
└── storage/
```

### Patrón arquitectónico

- **Router**: `api/index.php` detecta el recurso (primer segmento de la URL) y hace `require "routes/{$resource}.php"`. Las variables `$method, $segments, $id, $action, $user` están disponibles en el scope del archivo incluido.
- **Auth**: Sesión PHP (cookie) o token Bearer. `Auth::requirePermission('MODULO', 'accion')` valida permisos.
- **SPs**: Toda la lógica de negocio está en stored procedures de SQL Server. PHP solo hace el binding de parámetros y parseo de result sets.
- **Frontend**: SPA muy simple. `App.views.<recurso>` define cada vista. `App.request(url, opts)` hace fetch a la API.

## 2. DIAGNÓSTICO DE TABLAS Y RELACIONES

### Tablas del módulo de cotizaciones (ya existentes)

```
empresa (1) ─────< (N) cotizacion (1) ─────< (N) cotizacion_detalle
   │                     │
   │                     ├─────< (N) cotizacion_comunicacion    ← SEGUIMIENTO COMERCIAL
   │                     ├─────< (N) cotizacion_historial_estado
   │                     ├─────< (N) cotizacion_documento
   │                     └─────< (N) cotizacion_version
   │
   ├─────< (N) empresa_sucursal
   ├─────< (N) empresa_contacto
   └───────> (1) rubro

cotizacion_empresa_emisora (1) ─────< (N) cotizacion_cuenta_bancaria
   │
   └─────< (N) cotizacion (como id_emisora)

cotizacion_tipo_catalogo (1) ─────< (N) cotizacion_correlativo_anual ─────> (1) empresa
cotizacion_zona_pricing (1) ─────< (N) cotizacion (como id_zona_pricing) [A OCULTAR]
```

### Restricciones UNIQUE existentes (relevantes)

| Tabla | Campo(s) | Tipo |
|-------|----------|------|
| `cotizacion` | `codigo` | UNIQUE NONCLUSTERED (garantiza códigos únicos) |
| `cotizacion_correlativo_anual` | `(id_empresa, id_tipo, anio)` | UNIQUE NONCLUSTERED (controla correlativo por empresa+tipo+año) |
| `empresa` | `ruc` | UNIQUE NONCLUSTERED |
| `empresa` | `correo` | UNIQUE NONCLUSTERED |
| `cotizacion_empresa_emisora` | `ruc` | UNIQUE NONCLUSTERED |

### SPs clave existentes

| SP | Función | Estado |
|----|---------|--------|
| `sp_cotizacion_create` (v1) | Crear cotización | Existe — formato código antiguo |
| `sp_cotizacion_create_v2` | + cuentas adicionales JSON | Existe |
| `sp_cotizacion_create_v3` | + flag `codigo_por_empresa` | Existe |
| `sp_cotizacion_create_v4` | + fix FK en correlativo | Existe — versión actual |
| `sp_cotizacion_correlativo_siguiente` | Genera correlativo atómico con UPDLOCK | Existe |
| `sp_cotizacion_add_comunicacion` | Agregar comunicación | Existe — no tiene todos los campos nuevos |
| `sp_cotizacion_get` | Detalle completo (6 result sets) | Existe |
| `sp_cotizacion_list_paged` | Listado paginado con filtros | Existe |
| `sp_cotizacion_emisora_logo_save` | Guardar logo base64 | Existe |
| `sp_cotizacion_calcular_totales` | Recalcular subtotal/IGV/total | Existe |

## 3. PROBLEMAS DETECTADOS

### 3.1. Formato del código de cotización actual
- **Actual**: `PREFIJO-EMP000001-2026-0001` (ej: `COT-EMP000001-2026-0001`)
- **Solicitado**: `SERV-2026-001.1` (SERV = código de empresa, .1 = versión)
- **Problema**: El formato actual usa el `prefijo_codigo` del **tipo** de cotización (no de la empresa). No existe un campo `codigo_empresa` en la tabla `empresa`.
- **Solución**: Agregar columna `codigo_empresa` a `empresa` + nuevo SP `sp_cotizacion_create_v5` que genera el formato solicitado.

### 3.2. Seguimiento comercial
- La tabla `cotizacion_comunicacion` existe pero no tiene campos específicos para:
  - Persona contactada
  - Resultado normalizado (Pendiente, Aceptada, Rechazada, etc.)
  - Fecha de compromiso
- **Solución**: Agregar 3 columnas + crear SPs de listado/contador.

### 3.3. Estado de aprobación en PDF
- El archivo `cotizaciones_print.php` muestra un badge con el estado (`APROBADA`, `RECHAZADA`, etc.) en el documento generado.
- **Solución**: Crear `cotizaciones_print_v2.php` que NO muestra el estado. El estado sigue visible en la UI interna.

### 3.4. Logo de la empresa emisora
- La infraestructura YA existe (`logo_base64` en `cotizacion_empresa_emisora`, SP `sp_cotizacion_emisora_logo_save`, JS `configuracion_emisoras_logo.js`).
- **Solución**: Mejorar la UI (drag & drop, preview en vivo, validación). El PDF v2 ya incluye el logo automáticamente.

### 3.5. Cuentas bancarias
- La BD ya soporta múltiples cuentas por emisora (`cotizacion_cuenta_bancaria` con campo `orden`).
- El formulario actual usa un combo box para seleccionar UNA cuenta principal + un JSON de "cuentas adicionales".
- **Solicitado**: Las 3 cuentas deben aparecer automáticamente, sin combo.
- **Solución**: Parchear el JS para mostrar la lista visualmente + enviar las 3 cuentas automáticamente. El PDF v2 ya las muestra todas.

### 3.6. Zona Pricing
- La tabla `cotizacion_zona_pricing` existe. El campo `id_zona_pricing` está en `cotizacion` y `cotizacion_detalle`.
- El JS actual muestra un combo de Zona en el formulario.
- **Solución**: Ocultar el combo del formulario + eliminar el enlace del menú. NO se eliminan tablas/columnas (conserva histórico).

### 3.7. Buscador de rubros
- La vista `App.views.rubros` en `views.js` lista todos los rubros sin filtro.
- El SP `sp_rubro_buscar` ya soporta un parámetro `@query`.
- El endpoint `GET /rubros?query=` ya lo pasa al SP.
- **Solución**: Parchear la vista para añadir input de búsqueda con debounce + botones Buscar/Limpiar + mensaje de "sin resultados".

## 4. ARCHIVOS A MODIFICAR

| Archivo | Tipo de cambio |
|---------|---------------|
| `api/index.php` | Añadir 4 líneas (cargar patches de rutas nuevas) |
| `index.php` (raíz) | Añadir 3 tags `<link>`/`<script>` en el layout |

## 5. ARCHIVOS A CREAR

| Archivo | Función |
|---------|---------|
| `scripts_sql/01_preparacion_validacion.sql` | Verificación previa |
| `scripts_sql/02_generacion_codigos.sql` | Columna `codigo_empresa` + SP v5 |
| `scripts_sql/03_seguimiento_comercial.sql` | Ampliar `cotizacion_comunicacion` + SPs |
| `scripts_sql/04_configuracion_logo.sql` | Verificar/crear SPs de logo |
| `scripts_sql/05_validaciones_indices.sql` | Índices + CHECKs |
| `scripts_sql/06_verificacion_posterior.sql` | Verificación final |
| `api/routes/cotizaciones_seguimiento.php` | Endpoints de seguimiento + preview código |
| `api/routes/cotizaciones_print_v2.php` | PDF v2 (sin estado + 3 cuentas) |
| `api/routes/empresas_codigo.php` | Endpoints de `codigo_empresa` |
| `assets/js/cotizaciones_mejoras.js` | Patches de UI (buscador rubros, form cotización, modal seguimiento) |
| `assets/js/configuracion_emisoras_logo_v2.js` | UI mejorada de logo |
| `assets/css/cotizaciones_mejoras.css` | Estilos complementarios |

## 6. ARCHIVOS A REEMPLAZAR

| Archivo | Razón |
|---------|-------|
| `api/routes/rubros.php` | Añadir soporte de búsqueda con paginación en servidor |

## 7. ARCHIVOS QUE NO SE MODIFICAN

- `api/routes/cotizaciones.php` — se mantienen los endpoints existentes. Los nuevos endpoints viven en `cotizaciones_seguimiento.php` y se cargan vía el patch del router.
- `api/routes/cotizaciones_emisora.php` — ya tiene la lógica de logo. Se complementa con JS mejorado.
- `includes/CotizacionExporter.php` — se conserva para exportación XLSX/CSV. El PDF v2 es un archivo aparte.
- Todos los scripts SQL originales en `scripts_sql/cotizaciones/` — se conservan intactos.

## 8. MATRIZ DE TRAZABILIDAD

| Requerimiento | Script SQL | PHP | JS | CSS |
|---------------|------------|-----|----|----|
| 3.1 Código automático SERV-2026-001.1 | 02 | `cotizaciones_seguimiento.php` (preview), `empresas_codigo.php` | `cotizaciones_mejoras.js` | — |
| 3.2 Seguimiento comercial | 03 | `cotizaciones_seguimiento.php` | `cotizaciones_mejoras.js` (modal) | `cotizaciones_mejoras.css` |
| 3.3 Ocultar estado en PDF | — | `cotizaciones_print_v2.php` | — | — |
| 3.4 Configuración de logo | 04 | (ya existe) | `configuracion_emisoras_logo_v2.js` | — |
| 3.5 3 cuentas bancarias | — | `cotizaciones_seguimiento.php` (endpoint), `cotizaciones_print_v2.php` (PDF) | `cotizaciones_mejoras.js` (form patch) | — |
| 3.6 Eliminar Zona Pricing | — | — | `cotizaciones_mejoras.js` (hide + remove menu) | `cotizaciones_mejoras.css` (!important) |
| 3.7 Buscador de rubros | — | `rubros.php` (reemplazo) | `cotizaciones_mejoras.js` (view patch) | `cotizaciones_mejoras.css` |
| 4.x Scripts SQL | 01-06 | — | — | — |
| 7.x Seguridad | CHECKs en 05 | Validación en `empresas_codigo.php` | Validación en `configuracion_emisoras_logo_v2.js` | — |
| 8.x Pruebas | 06 (verificación) | — | — | — |
