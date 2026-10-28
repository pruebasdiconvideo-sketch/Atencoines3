# Mejora del módulo de Cotizaciones — Guía de instalación

## RESUMEN

Este paquete contiene todas las mejoras solicitadas para el módulo de
cotizaciones del sistema MonosyncERP. **No reemplaza el proyecto original**:
se agregan archivos nuevos y se aplican patches sobre los existentes.

## ARQUITECTURA EXISTENTE (diagnóstico)

| Componente | Tecnología | Ubicación |
|------------|-----------|-----------|
| Frontend | HTML + JavaScript (vanilla) + CSS | `assets/js/`, `assets/css/` |
| Backend | PHP 8 + PDO (sqlsrv) | `api/routes/` |
| Base de datos | Microsoft SQL Server 2022 | `[MonosyncERP]` |
| Router API | `api/index.php` (dispatcher por archivo) | — |
| Auth | Sesión PHP + Token Bearer (vía `includes/auth.php`) | — |
| PDF | HTML imprimible (no usa librería externa) | `api/routes/cotizaciones_print.php`, `includes/CotizacionExporter.php` |

**Tablas relevantes existentes** (sin cambios estructurales):
- `empresa` → agregamos columna `codigo_empresa`
- `cotizacion` → ya tiene `codigo`, `version_numero`, `cuentas_adicionales_json`, `fecha_proximo_seguimiento`
- `cotizacion_comunicacion` → agregamos columnas `persona_contactada`, `resultado_comunicacion`, `compromiso_fecha`
- `cotizacion_correlativo_anual` → ya existe (controla correlativos por empresa + tipo + año)
- `cotizacion_cuenta_bancaria` → ya existe (catálogo de cuentas por emisora)
- `cotizacion_empresa_emisora` → ya tiene `logo_base64`
- `cotizacion_zona_pricing` → se conserva en BD (no se elimina) pero se oculta de la UI

## MEJORAS IMPLEMENTADAS

### 1. Generación automática del código `SERV-2026-001.1`
- **Script SQL 02** agrega columna `codigo_empresa` a la tabla `empresa` y crea los SPs `sp_cotizacion_create_v5`, `sp_cotizacion_preview_codigo` y `sp_empresa_set_codigo`.
- **JS** (`cotizaciones_mejoras.js`) parchea el formulario de nueva cotización para mostrar el código generado automáticamente en un banner, sin que el usuario lo escriba.
- **PHP** (`api/routes/cotizaciones_seguimiento.php`) añade el endpoint `GET /cotizaciones/preview_codigo?id_empresa=&id_tipo=` que el JS consulta en tiempo real.
- **PHP** (`api/routes/empresas_codigo.php`) añade los endpoints `PATCH /empresas/{id}/codigo_empresa` y `GET /empresas/{id}/codigo_empresa` para gestionar el código desde el módulo de empresas.

### 2. Seguimiento comercial de cotizaciones
- **Script SQL 03** amplía la tabla `cotizacion_comunicacion` con los campos `persona_contactada`, `resultado_comunicacion` y `compromiso_fecha`. Crea los SPs `sp_cotizacion_list_seguimientos`, `sp_cotizacion_count_seguimientos` y `sp_cotizacion_add_comunicacion_v2`. Crea la vista `vw_cotizaciones_con_seguimiento`.
- **JS** expone `App.showSeguimientoModal(idCot, codigo)` que abre un modal con el contador, el formulario de registro y el historial completo.
- **PHP** añade los endpoints `GET /cotizaciones/{id}/seguimientos`, `GET /cotizaciones/{id}/seguimientos/count` y `POST /cotizaciones/{id}/seguimientos`.

### 3. Ocultar estado de aprobación en PDF/impresión
- **PHP** nuevo archivo `api/routes/cotizaciones_print_v2.php` — versión mejorada del print que NO muestra el badge de estado (BORRADOR, APROBADA, RECHAZADA, etc.) en el documento. El estado sigue visible en la interfaz interna.
- Acceso: `GET /cotizaciones_print_v2/{id}` — el router lo carga automáticamente al coincidir el nombre del archivo con el primer segmento de la URL.

### 4. Configuración del logotipo
- La infraestructura ya existía (`cotizacion_empresa_emisora.logo_base64` + SP `sp_cotizacion_emisora_logo_save`).
- **Script SQL 04** verifica y crea los SPs auxiliares `sp_cotizacion_emisora_logo_get` y `sp_cotizacion_emisora_logo_delete`.
- **JS** nuevo `configuracion_emisoras_logo_v2.js` con UI mejorada (drag & drop, preview en vivo, validación, eliminar y descargar).

### 5. Mostrar 3 cuentas bancarias automáticamente
- **PHP** endpoint `GET /cotizaciones/{id}/cuentas_bancarias` devuelve todas las cuentas activas de la emisora.
- **JS** parchea el formulario: oculta el combo box de "Cuenta bancaria principal" y muestra una lista visual con las 3 cuentas activas, que se incluirán automáticamente en el PDF.
- **PDF** el nuevo `cotizaciones_print_v2.php` muestra todas las cuentas activas (hasta 3) en una sección "Cuentas bancarias" sin necesidad de seleccionarlas.

### 6. Eliminar opción "Zona Pricing"
- La tabla `cotizacion_zona_pricing` **se conserva** en la BD (para histórico).
- **JS** oculta el campo `id_zona_pricing` del formulario de cotización.
- **JS** elimina del menú lateral cualquier enlace que apunte a `zona-pricing` o `ZonaPricing`.
- El campo `id_zona_pricing` se mantiene en la tabla `cotizacion` para preservar las cotizaciones históricas, pero las nuevas se crean sin ese valor.

### 7. Buscador de rubros
- **PHP** `api/routes/rubros.php` (versión mejorada) — soporta `?query=`, `?page=`, `?per_page=` y `?id=all`.
- **JS** parchea la vista `App.views.rubros` para añadir:
  - Input de búsqueda con debounce de 300 ms.
  - Botones "Buscar" y "Limpiar".
  - Mensaje cuando no hay resultados.
  - Conserva la paginación existente.
  - Compatible con las acciones de registrar/editar/eliminar.

## PASOS DE INSTALACIÓN

### PASO 1 — Copiar archivos al proyecto

Copie los archivos de este paquete a las rutas correspondientes del proyecto existente (manteniendo la estructura):

```
api/routes/rubros.php                          (REEMPLAZA el existente)
api/routes/cotizaciones_seguimiento.php       (NUEVO)
api/routes/cotizaciones_print_v2.php          (NUEVO)
api/routes/empresas_codigo.php                (NUEVO)
api/_patch_router_includes.php                (DOCUMENTACIÓN)
assets/js/cotizaciones_mejoras.js             (NUEVO)
assets/js/configuracion_emisoras_logo_v2.js   (NUEVO)
assets/css/cotizaciones_mejoras.css           (NUEVO)
scripts_sql/*.sql                             (6 scripts + README.md)
```

### PASO 2 — Aplicar patches al router (api/index.php)

Edite `api/index.php` y busque la línea:

```php
require $routeFile;
```

Añada DESPUÉS de esa línea:

```php
// ===== PATCH: cotizaciones_mejoras =====
$__patchSeg = __DIR__ . "/routes/{$resource}_seguimiento.php";
$__patchEmp = __DIR__ . "/routes/{$resource}_codigo.php";
if (is_file($__patchSeg)) require $__patchSeg;
if (is_file($__patchEmp)) require $__patchEmp;
```

### PASO 3 — Incluir el JS y CSS en el layout principal

Edite `index.php` (el del raíz del proyecto, no el de la api) y en la sección `<head>` o antes de `</body>`, agregue:

```html
<link rel="stylesheet" href="/assets/css/cotizaciones_mejoras.css">
<script src="/assets/js/cotizaciones_mejoras.js"></script>
<script src="/assets/js/configuracion_emisoras_logo_v2.js"></script>
```

Estos deben cargarse **DESPUÉS** de `cotizaciones_v2.js` y `views.js`.

### PASO 4 — Ejecutar los scripts SQL

1. Haga backup de la BD: `BACKUP DATABASE [MonosyncERP] TO DISK = '...'`
2. Ejecute en orden (con SSMS o sqlcmd):
   - `01_preparacion_validacion.sql`
   - `02_generacion_codigos.sql`
   - `03_seguimiento_comercial.sql`
   - `04_configuracion_logo.sql`
   - `05_validaciones_indices.sql`
   - `06_verificacion_posterior.sql`

### PASO 5 — Asignar códigos a las empresas

Para cada empresa que vaya a emitir cotizaciones, asigne un `codigo_empresa`:

```sql
USE [MonosyncERP];
EXEC dbo.sp_empresa_set_codigo
     @id_empresa     = 1,
     @codigo_empresa = 'SERV',
     @id_usuario     = 1;
```

O desde la UI (una vez integrados los endpoints), use el módulo de Empresas → Editar → campo "Código identificador".

### PASO 6 — Verificar

1. **Código automático**: entre al módulo de Cotizaciones → Nueva cotización → seleccione empresa y tipo → verá el banner con el código previsualizado.
2. **Seguimiento comercial**: en el listado de cotizaciones, abra una y verá el botón "Seguimiento" → abre el modal con historial y formulario.
3. **PDF sin estado**: abra una cotización y use el botón "Imprimir / PDF v2" → no debe aparecer el estado.
4. **Logo**: en la edición de emisoras, use el botón "Logo" → modal con drag & drop.
5. **3 cuentas bancarias**: en el formulario de cotización, verá la lista de cuentas activas en lugar del combo.
6. **Zona Pricing**: el campo no aparece en el formulario de cotización.
7. **Buscador de rubros**: en el módulo de Rubros, verá el input de búsqueda con resultados en tiempo real.

## PRUEBAS FUNCIONALES (checklist)

| # | Prueba | Cómo verificar |
|---|--------|----------------|
| 1 | Código automático por empresa | Cree 2 cotizaciones para la misma empresa → los códigos siguen el patrón `SERV-2026-001.1`, `SERV-2026-002.1` |
| 2 | Correlativo anual | Cree cotizaciones en años distintos → reinicia en 001 al cambiar de año |
| 3 | Concurrencia | (Requiere 2 usuarios) Crear cotizaciones simultáneamente → no se generan códigos duplicados (restricción UNIQUE en `cotizacion.codigo`) |
| 4 | Seguimiento comercial | Registre 3 seguimientos en una cotización → el contador muestra "3" y el historial se conserva |
| 5 | PDF sin estado | Genere el PDF v2 → no aparece APROBADA/RECHAZADA/etc. |
| 6 | Logo | Suba un logo PNG ≤ 2 MB → aparece en el PDF v2 |
| 7 | 3 cuentas bancarias | Cree una cotización → las 3 cuentas aparecen en el PDF automáticamente |
| 8 | Zona Pricing eliminada | El formulario de cotización no muestra el campo Zona Pricing |
| 9 | Buscador de rubros | Busque por nombre → solo aparecen los que coinciden; el botón "Limpiar" restaura |
| 10 | Regresión | Crear, editar, eliminar, exportar cotizaciones y tickets sigue funcionando |

## ROLLBACK

Si algo sale mal:

1. **Restaurar BD** desde el backup del Paso 4.
2. **Eliminar archivos nuevos**:
   - `api/routes/cotizaciones_seguimiento.php`
   - `api/routes/cotizaciones_print_v2.php`
   - `api/routes/empresas_codigo.php`
   - `assets/js/cotizaciones_mejoras.js`
   - `assets/js/configuracion_emisoras_logo_v2.js`
   - `assets/css/cotizaciones_mejoras.css`
3. **Restaurar `api/routes/rubros.php`** desde el repo original.
4. **Quitar el patch** del router en `api/index.php`.
5. **Quitar las etiquetas `<link>` y `<script>`** del layout principal.

## PROBLEMAS CONOCIDOS

- Si una empresa no tiene `codigo_empresa` asignado, el banner de preview mostrará una advertencia. Asigne el código antes de crear cotizaciones para esa empresa.
- Las cotizaciones históricas conservan su código original (formato `PREFIJO-EMPnnnnnn-AAAA-nnnn`). Solo las nuevas usan el formato `SERV-2026-001.1`.
- El SP `sp_cotizacion_create_v4` (versión anterior) sigue existiendo y funcional — el backend PHP lo detecta y usa el v5 si está disponible, si no, hace fallback al v4. Para activar el nuevo formato en TODAS las nuevas cotizaciones, asegúrese de que el script 02 se ejecutó correctamente.

## ARCHIVOS MODIFICADOS/CREADOS — RESUMEN

| Archivo | Acción | Líneas aprox. |
|---------|--------|---------------|
| `scripts_sql/01_preparacion_validacion.sql` | Crear | ~180 |
| `scripts_sql/02_generacion_codigos.sql` | Crear | ~280 |
| `scripts_sql/03_seguimiento_comercial.sql` | Crear | ~220 |
| `scripts_sql/04_configuracion_logo.sql` | Crear | ~140 |
| `scripts_sql/05_validaciones_indices.sql` | Crear | ~120 |
| `scripts_sql/06_verificacion_posterior.sql` | Crear | ~160 |
| `api/routes/rubros.php` | Reemplazar | ~155 |
| `api/routes/cotizaciones_seguimiento.php` | Crear | ~225 |
| `api/routes/cotizaciones_print_v2.php` | Crear | ~290 |
| `api/routes/empresas_codigo.php` | Crear | ~95 |
| `assets/js/cotizaciones_mejoras.js` | Crear | ~310 |
| `assets/js/configuracion_emisoras_logo_v2.js` | Crear | ~190 |
| `assets/css/cotizaciones_mejoras.css` | Crear | ~165 |

**Total**: 13 archivos nuevos/modificados, ~2.700 líneas de código.
