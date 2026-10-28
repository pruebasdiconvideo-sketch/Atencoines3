# Módulo de Cotizaciones Comerciales — Atenciones (MonosyncERP)

Implementación completa del módulo de cotizaciones con numeración correlativa anual, trazabilidad, versionado, comunicaciones y exportación a PDF/Excel, integrado con el sistema existente (tickets, empresas, personas, roles, permisos, auditoría).

## ¿Qué incluye?

### Base de datos (SQL Server)
- **17 tablas nuevas** en `dbo.*` (prefijo `cotizacion_*`):
  - `cotizacion_tipo_catalogo` — 7 tipos: VENTAS, SOPORTE, MANTENIMIENTO, REPARACIÓN, CABLEADO, REPUESTOS, SOPORTE_Y_MANT
  - `cotizacion_empresa_emisora` — empresa que emite (precargada con DICON: Ingeniería de Control Digital S.A.)
  - `cotizacion_zona_pricing` — Lima / Conos / Balnearios
  - `cotizacion_producto_catalogo` — **120+ productos** importados del Excel (SOA, EQA, FVA, ATS, STE, ACE, RCA, FOT)
  - `cotizacion_producto_precio_zona` — precios por zona para servicios
  - `cotizacion_forma_pago` — 9 formas de pago con descuentos asociados
  - `cotizacion_garantia` — 7 garantías
  - `cotizacion_plazo_entrega` — 6 plazos
  - `cotizacion_validez` — 7/15/30 días
  - `cotizacion_cuenta_bancaria` — BBVA, BCP, BN y tarjeta (precargadas)
  - `cotizacion_correlativo_anual` — control de numeración (UNIQUE constraint)
  - `cotizacion` — cabecera principal
  - `cotizacion_version` — versionado .1, .2, .3
  - `cotizacion_detalle` — ítems
  - `cotizacion_comunicacion` — historial no sobrescribible
  - `cotizacion_historial_estado` — trazabilidad
  - `cotizacion_documento` — adjuntos (varbinary en BD)
- **13 Stored Procedures** transaccionales:
  - `sp_cotizacion_correlativo_siguiente` (MERGE con UPDLOCK + HOLDLOCK → concurrencia segura)
  - `sp_cotizacion_calcular_totales` (subtotal/IGV/total con descuento global)
  - `sp_cotizacion_create` (crea cotización + detalle + historial + auditoría atómico)
  - `sp_cotizacion_get` (devuelve 6 result sets: cabecera, detalle, historial, comunicaciones, versiones, documentos)
  - `sp_cotizacion_list_paged` (filtros + paginación OFFSET/FETCH)
  - `sp_cotizacion_update_status` (matriz de transiciones de 9 estados)
  - `sp_cotizacion_add_comunicacion`
  - `sp_cotizacion_update` (cabecera + detalle atómico, con opción `forzar_version`)
  - `sp_cotizacion_documento_save` / `sp_cotizacion_documento_get` (adjuntos)
  - `sp_cotizacion_delete_admin` (soft delete)
  - `sp_cotizacion_catalogos_listar` (todos los catálogos en una llamada)
  - `sp_cotizacion_producto_listar`
- **Seed de datos** idempotente: catálogos del Excel importados automáticamente
- **Registro automático** del módulo `COTIZACIONES` en `dbo.modulo_catalogo`
- **Rol `VENTAS`** creado en `dbo.persona_rol` con permisos:
  - ✅ ver, registrar, modificar, exportar, imprimir
  - ❌ eliminar, aprobar (reservados a admin/supervisor)
- **Permisos completos** del rol ADMIN (id_rol = 1) sobre COTIZACIONES
- **Asignación automática** del rol VENTAS a los colaboradores mencionados en el Excel (Angel Sanchez, Juan Olivos, Edgar Vargas, Briyit Valencia, Jony Posito) si ya existen en `dbo.persona`

### Backend (PHP REST API)
- **`api/routes/cotizaciones.php`** — Nuevo endpoint con 16 sub-rutas:
  - `GET    /cotizaciones` — Listado paginado con filtros
  - `POST   /cotizaciones` — Crear cotización con detalle JSON
  - `GET    /cotizaciones/catalogos` — Todos los catálogos en 1 llamada
  - `GET    /cotizaciones/productos` — Catálogo de productos con búsqueda
  - `GET    /cotizaciones/{id}` — Detalle completo (6 result sets)
  - `PATCH  /cotizaciones/{id}` — Actualizar cabecera + detalle
  - `PATCH  /cotizaciones/{id}/status` — Cambiar estado (valida transiciones)
  - `POST   /cotizaciones/{id}/comunicaciones` — Agregar comunicación
  - `POST   /cotizaciones/{id}/versiones` — Crear nueva versión (.2, .3)
  - `GET    /cotizaciones/{id}/export/pdf` — PDF (réplica del Excel)
  - `GET    /cotizaciones/{id}/export/xlsx` — Excel con PhpSpreadsheet
  - `GET    /cotizaciones/{id}/export/csv` — CSV
  - `POST   /cotizaciones/{id}/files` — Subir documento adjunto
  - `GET    /cotizaciones/{id}/files` — Listar documentos
  - `GET    /cotizaciones/{id}/files/{fileId}` — Descargar documento
  - `DELETE /cotizaciones/{id}` — Soft delete (admin)
  - `POST   /cotizaciones/export/job` — Encolar exportación masiva
- **`includes/CotizacionExporter.php`** — Helper con 3 formatos:
  - **PDF**: HTML/CSS pixel-perfect del Excel original + auto `window.print()`. El navegador convierte a PDF con "Guardar como PDF".
  - **XLSX**: PhpSpreadsheet (ya en composer.json). 4 hojas (Cotización, Historial, Comunicaciones, Versiones).
  - **CSV**: con BOM UTF-8, plano.
- **`modules/exportacion/worker.php`** extendido para soportar módulo `COTIZACIONES` en exportación masiva asíncrona.

### Frontend (SPA)
- **`index.php`** — Nuevo ítem de menú "Comercial → Cotizaciones" con submenu:
  - Listado de Cotizaciones
  - Catálogo de Productos
- **`assets/js/app.js`** — Mapeo de permisos `COTIZACIONES:ver` para las rutas `cotizaciones` y `cotizaciones-productos` en `applyPermissionsToMenu()` y `canAccessRoute()`.
- **`assets/js/views.js`** — Nuevas vistas `App.views.cotizaciones` y `App.views.cotizaciones_productos` con:
  - Listado con filtros (estado, año, fechas, búsqueda, empresa)
  - Detalle completo con 6 secciones (cabecera, items, condiciones, historial, comunicaciones, versiones, documentos)
  - Cambio de estado con validación de permisos (aprobar/rechazar/cerrar requieren `aprobar`)
  - Modal para registrar comunicaciones (medio, tipo, descripción, respuesta, próximo seguimiento)
  - Formulario completo con autocompletar de empresa, vendedor, productos del catálogo
  - Tabla de ítems con cálculo automático de subtotales, IGV y total
  - Soporte para seleccionar zona de pricing (aplica precio según zona a servicios)
  - Upload de documentos adjuntos
  - Exportación a PDF y Excel con un click
  - Vista de catálogo de productos (solo lectura para el vendedor)

### Pruebas
- **`tests/cotizaciones_test.php`** — Script ejecutable con 14 escenarios:
  1. Primera cotización de una empresa (correlativo 0001)
  2. Segunda cotización de la misma empresa (0002)
  3. Primera cotización de otra empresa (0001)
  4. Primera cotización en un nuevo año (0001)
  5. Consulta de cotizaciones por año
  6. Concurrencia: 3 correlativos consecutivos sin duplicidad
  7. Múltiples comunicaciones (no se sobrescriben)
  8. Diferentes usuarios y fechas en comunicaciones
  9. Aprobación y cancelación con fechas y responsables
  10. Validación de permisos por rol
  11. Prevención de transiciones no autorizadas
  12. Conservación del historial tras cambios de estado
  13. Errores de BD y reversión de transacciones
  14. Búsqueda y filtrado de cotizaciones

## Formato del código de cotización

`COT-EMP000001-2026-0001`

| Componente | Significado | Ejemplo |
|------------|-------------|---------|
| `COT` | Prefijo del tipo (configurable por tipo) | `COT`, `SERV`, `MAN`, `REP`, `CAB`, `RPT`, `SYM` |
| `EMP000001` | `id_empresa` rellenado a 6 dígitos | `EMP000001` para id=1, `EMP001234` para id=1234 |
| `2026` | Año de creación (4 dígitos) | `2026` |
| `0001` | Correlativo anual por (empresa, tipo) | `0001` para la primera, `0002` para la segunda |

- **Único global** en la tabla `dbo.cotizacion` (UNIQUE constraint)
- **Concurrencia segura**: el SP `sp_cotizacion_correlativo_siguiente` usa MERGE con `UPDLOCK + HOLDLOCK` para bloquear la fila de correlativo mientras la incrementa. Dos usuarios que crean cotizaciones simultáneamente nunca obtienen el mismo número.
- **Independiente por empresa y tipo**: cada combinación (id_empresa, id_tipo, anio) tiene su propia fila en `cotizacion_correlativo_anual`. Reinicia automáticamente cada año para cada empresa.
- **No se modifica al cambiar de año**: las cotizaciones históricas conservan su código original.

## Estados y transiciones

```
BORRADOR → PENDIENTE_ENVIO → ENVIADA → EN_SEGUIMIENTO → EN_NEGOCIACION → APROBADA → CERRADA
                              ↓                ↓             ↓                ↓
                          RECHAZADA        RECHAZADA      RECHAZADA        CANCELADA
                          CANCELADA        CANCELADA      CANCELADA
```

Matriz de transiciones permitidas (validada en `sp_cotizacion_update_status`):

| Estado origen | Estados destino válidos |
|---------------|-------------------------|
| BORRADOR | PENDIENTE_ENVIO, CANCELADA |
| PENDIENTE_ENVIO | ENVIADA, CANCELADA, BORRADOR |
| ENVIADA | EN_SEGUIMIENTO, EN_NEGOCIACION, APROBADA, RECHAZADA, CANCELADA |
| EN_SEGUIMIENTO | EN_NEGOCIACION, APROBADA, RECHAZADA, CANCELADA, ENVIADA |
| EN_NEGOCIACION | APROBADA, RECHAZADA, CANCELADA, EN_SEGUIMIENTO |
| APROBADA | CERRADA, CANCELADA |
| RECHAZADA | CERRADA, PENDIENTE_ENVIO (revive) |
| CANCELADA | PENDIENTE_ENVIO, BORRADOR (revive con motivo) |
| CERRADA | PENDIENTE_ENVIO (reabrir con motivo) |

Cada cambio de estado registra en `cotizacion_historial_estado`:
- estado_anterior, estado_nuevo
- id_usuario, motivo
- tipo_evento (CREACION, ENVIO, APROBACION, RECHAZO, CANCELACION, CIERRE, CAMBIO_ESTADO)
- origen_evento (MANUAL)
- fecha_cambio (SYSDATETIME)

Además, los campos `fecha_aprobacion`, `id_usuario_aprobo`, `fecha_cancelacion`, `id_usuario_cancelo`, `motivo_cancelacion`, etc., se actualizan en la cabecera de la cotización según el estado destino.

## Instalación

### Paso 1: Ejecutar los scripts SQL (en orden)

Conéctate a SQL Server Management Studio sobre la base de datos `MonosyncERP` y ejecuta:

```
scripts_sql/cotizaciones/01_cotizaciones_tablas.sql           ← tablas + índices + FK
scripts_sql/cotizaciones/02_cotizaciones_seed_catalogos.sql   ← catálogos del Excel
scripts_sql/cotizaciones/03_cotizaciones_seed_productos.sql  ← 120+ productos del Excel
scripts_sql/cotizaciones/04_cotizaciones_seed_modulo_permisos.sql ← módulo + rol + permisos
scripts_sql/cotizaciones/05_cotizaciones_sps_parte1.sql      ← SPs (correlativo, create, totales)
scripts_sql/cotizaciones/05_cotizaciones_sps_parte2.sql      ← SPs (get, list, status, etc.)
```

O ejecuta todo de una vez con:

```
scripts_sql/cotizaciones/06_cotizaciones_run_all.sql
```

> **Nota**: `:r` (sqlcmd) requiere que abras `06_cotizaciones_run_all.sql` con SQL Server Management Studio y ejecutes desde ahí, no con `sqlcmd` standalone. Si usas `sqlcmd`, ejecuta los 6 scripts en orden manualmente.

Los scripts son **idempotentes**: si ya están creados los objetos, los omiten.

### Paso 2: Subir los archivos PHP

Mantén la estructura de carpetas del ZIP. Archivos a subir al servidor web:

| Archivo del ZIP | Ruta destino en el servidor | Acción |
|-----------------|------------------------------|--------|
| `api/routes/cotizaciones.php` | `api/routes/cotizaciones.php` | **NUEVO** |
| `includes/CotizacionExporter.php` | `includes/CotizacionExporter.php` | **NUEVO** |
| `index.php` | `index.php` | **REEMPLAZAR** (solo se agregó el menú) |
| `assets/js/app.js` | `assets/js/app.js` | **REEMPLAZAR** (solo se agregó el mapeo COTIZACIONES) |
| `assets/js/views.js` | `assets/js/views.js` | **REEMPLAZAR** (se agregaron ~1000 líneas al final) |
| `modules/exportacion/worker.php` | `modules/exportacion/worker.php` | **REEMPLAZAR** (se extendió para COTIZACIONES) |
| `tests/cotizaciones_test.php` | `tests/cotizaciones_test.php` | **NUEVO (temporal)** |

> **No subas los `.sql` al servidor web**: se ejecutan en SQL Server.

### Paso 3: Probar el módulo

1. Inicia sesión en la app con un usuario admin o con rol VENTAS.
2. En el menú lateral, debería aparecer el grupo "Comercial" con "Cotizaciones" y "Catálogo de Productos".
3. Haz clic en "Cotizaciones" → verás el listado (vacío si no has creado ninguna).
4. Haz clic en "Nueva Cotización" → completa el formulario:
   - Selecciona una empresa cliente (autocompleta)
   - Selecciona tipo (Ventas, Soporte, etc.)
   - Selecciona emisora (DICON por defecto)
   - Agrega ítems buscando en el catálogo de productos (precarga los del Excel)
   - Configura condiciones comerciales (forma de pago, garantía, plazo, validez, cuenta)
   - Guarda
5. En el detalle de la cotización:
   - Cambia el estado (Pendiente de envío → Enviada → Aprobada)
   - Registra comunicaciones
   - Sube documentos adjuntos
   - Exporta a PDF o Excel con un click

### Paso 4: Probar las pruebas automáticas

1. Sube `tests/cotizaciones_test.php` a la carpeta `tests/` del servidor.
2. Abre en el navegador: `https://tu-dominio/tests/cotizaciones_test.php`
3. Verás el resultado de los 14 escenarios con OK/FAIL.
4. **⚠️ BORRA el archivo después de probar** (expone datos internos).

## Reglas de seguridad implementadas

### Numeración segura bajo concurrencia

```sql
MERGE dbo.cotizacion_correlativo_anual WITH (UPDLOCK, HOLDLOCK) AS tgt
USING (SELECT @id_empresa, @id_tipo, @anio) AS src
    ON (tgt.id_empresa = src.id_empresa AND tgt.id_tipo = src.id_tipo AND tgt.anio = src.anio)
WHEN MATCHED THEN UPDATE SET tgt.ultimo_correlativo = tgt.ultimo_correlativo + 1
WHEN NOT MATCHED THEN INSERT (...) VALUES (..., 1, ...)
OUTPUT inserted.ultimo_correlativo INTO @out_table;
```

- `UPDLOCK` bloquea la fila para actualización mientras dure la transacción.
- `HOLDLOCK` mantiene el bloqueo con serializable semantics.
- `UNIQUE (id_empresa, id_tipo, anio)` como red de seguridad final.
- **Resultado**: dos usuarios que crean cotizaciones simultáneamente NUNCA obtienen el mismo correlativo.

### Trazabilidad completa

- **Historial no sobrescribible**: cada cambio de estado y cada comunicación generan un nuevo registro. Nunca se hace `UPDATE` sobre `cotizacion_historial_estado` o `cotizacion_comunicacion`.
- **Auditoría general**: cada operación llama a `sp_auditoria_accion_registrar` (tabla `auditoria_accion` con IP, hostname, user-agent).
- **Fechas y responsables**: cada estado tiene su propia fecha y usuario (fecha_aprobacion + id_usuario_aprobo, fecha_cancelacion + id_usuario_cancelo, etc.). No se mezclan.

### Permisos por rol

- El módulo `COTIZACIONES` se registra en `dbo.modulo_catalogo`.
- `Auth::requirePermission('COTIZACIONES', 'ver')` valida en cada endpoint.
- El rol `VENTAS` puede crear, editar, exportar, imprimir pero **no eliminar ni aprobar** (esos requieren rol `ADMIN` o un rol con permiso `aprobar`).
- **El usuario que crea/modifica/aprueba se toma de `$_SESSION['user']['id_persona']`**, no del cuerpo JSON. El usuario no puede atribuir operaciones a otros.

### Validaciones de integridad

- Validación de existencia de empresa, contacto, emisora, tipo, sucursal, vendedor (THROW si no existen).
- Validación de transiciones de estado (matriz estricta).
- Validación de que la sucursal pertenece a la empresa.
- Transacciones en cada SP crítico (`BEGIN TRAN` / `COMMIT` / `ROLLBACK`).
- `THROW` con código específico para cada error (51xxx).

### Versionado

- Las cotizaciones en estado BORRADOR o PENDIENTE_ENVIO se editan directamente.
- Las cotizaciones en estados superiores (ENVIADA, APROBADA, etc.) requieren `forzar_version = 1` para editar. Esto crea:
  1. Un snapshot en `cotizacion_version` con el estado actual (cabecera + detalle en JSON).
  2. Incrementa `version_numero` en la cabecera.
- Cada versión tiene su código visible: `COT-EMP000001-2026-0001.2`, `.3`, etc.

## Exportación a PDF y Excel

### PDF (réplica del Excel)

El PDF se genera como HTML completo con CSS que replica el formato del Excel:
- Cabecera con razón social + RUC de la emisora
- Código y fecha de la cotización
- Título del documento (según tipo)
- Datos del cliente (Señores, Atención, Cargo, RUC)
- Tabla de ítems con columnas Ítem / Código / Descripción / Cant. / P.U. / Desc% / P.T.
- Subtotal / IGV (18%) / Total
- Condiciones comerciales (Garantía, Forma de Pago, Cuenta Corriente, Plazo, Validez, Facturación, Detracción)
- Pie con el vendedor (nombre, cargo, email)
- Auto `window.print()` al abrir → el usuario hace "Guardar como PDF" desde el navegador.

**Ventajas de este enfoque**:
- No requiere instalar TCPDF/FPDF/mPDF (cero dependencias nuevas).
- El resultado es pixel-perfect con el Excel original.
- Funciona en todos los navegadores modernos.
- Si en el futuro quieres PDF server-side, basta con `composer require mpdf/mpdf` y cambiar `outputPdf()` para usar `$mpdf->WriteHTML($html)`.

### Excel (.xlsx)

- Usa PhpSpreadsheet (ya en `composer.json`).
- 4 hojas: Cotización (réplica del Excel), Historial, Comunicaciones, Versiones.
- Fallback automático a CSV si PhpSpreadsheet no está cargado.

### CSV

- UTF-8 con BOM (compatible con Excel).
- Incluye cabecera + items + totales + condiciones + historial + comunicaciones.

## Catálogo de productos importados del Excel

El script `03_cotizaciones_seed_productos.sql` importa todos los productos del archivo Excel "Cotizacion Ventas V9.53 - V15.xlsx":

| Tipo | Cantidad aprox. | Ejemplos |
|------|-----------------|----------|
| SOFTWARE | 23 | SOA-0003 (Software Estándar), SOA-0142 (Empresarial), SOA-0301 (Modulo Web), SOA-0201 (App) |
| SERVICIO_SOFTWARE | 23 | SSO-0001R (Instalación remota), ATS-001L (Instalación BD) |
| HARDWARE | 27 | EQA-0006 (Reloj EH300), EQA-0019 (Lector Proximidad), NFD-0001 (VIRDI) |
| FACIAL | 7 | FVA-0002 (UBio-X Pro2), FHA-0002 (HF-R2) |
| SERVICIO_HARDWARE | 27 | STE-001D (Diagnóstico), STE-011D (Mant. Preventivo Plus) |
| ACCESORIO | 12 | ACE-0001 (Adaptador), ACE-0009 (Cerradura) |
| REPUESTO | 21 | RCA-0016 (Lector RFID), RCA-0023 (Lector Huella) |
| FOTOCHECK | 17 | FOT-0001 (PVC ByN), FOT-0005 (PVC Full Color Total) |
| INSTALACION | 1 | SEQ-0001L |
| DESARROLLO | 1 | DES-1234 |
| **Total** | **~160** | |

Los servicios (`SERVICIO_SOFTWARE`, `SERVICIO_HARDWARE`, `INSTALACION`) tienen `aplica_precio_zona = 1` y precios diferenciados por Lima/Conos/Balnearios en `cotizacion_producto_precio_zona`.

Los productos físicos tienen `aplica_precio_zona = 0` (precio único).

## Próximos pasos sugeridos

1. **Asignar rol VENTAS a tus vendedores** desde la UI de Seguridad (Roles y Permisos).
2. **Verificar catálogo de productos**: entra a `/cotizaciones-productos` y verifica que los precios y descripciones coinciden con tu Excel. Si necesitas ajustar precios, edita los registros en `cotizacion_producto_catalogo`.
3. **Crear cotizaciones de prueba** con cada tipo (VENTAS, SOPORTE, MANTENIMIENTO) para validar que el PDF y Excel se generan correctamente.
4. **Configurar el worker de exportación** si quieres exportaciones masivas: el `worker.php` está extendido para soportar `COTIZACIONES` en el job asíncrono.
5. **Personalizar el logo**: si quieres que el PDF incluya el logo de DICON, sube el logo como base64 y actualiza `logo_base64` en `cotizacion_empresa_emisora` para el registro con RUC 20468246954.
6. **Eliminar `tests/cotizaciones_test.php`** después de probar (expone datos internos).

## Archivos del módulo

```
Atenciones/
├── scripts_sql/cotizaciones/
│   ├── 01_cotizaciones_tablas.sql           ← 17 tablas + índices + FK
│   ├── 02_cotizaciones_seed_catalogos.sql    ← catálogos del Excel
│   ├── 03_cotizaciones_seed_productos.sql    ← 160 productos del Excel
│   ├── 04_cotizaciones_seed_modulo_permisos.sql ← módulo + rol + permisos
│   ├── 05_cotizaciones_sps_parte1.sql        ← SPs (correlativo, create, totales)
│   ├── 05_cotizaciones_sps_parte2.sql        ← SPs (get, list, status, etc.)
│   └── 06_cotizaciones_run_all.sql           ← script maestro (ejecuta todo)
├── api/routes/cotizaciones.php               ← API REST (16 endpoints)
├── includes/CotizacionExporter.php           ← PDF/XLSX/CSV helper
├── modules/exportacion/worker.php            ← extendido para COTIZACIONES
├── index.php                                 ← menú agregado
├── assets/js/app.js                          ← mapeo de permisos COTIZACIONES
├── assets/js/views.js                        ← App.views.cotizaciones + App.views.cotizaciones_productos
├── tests/cotizaciones_test.php               ← 14 escenarios de prueba
└── README_COTIZACIONES.md                    ← este archivo
```

## Compatibilidad

- ✅ **PHP 8.1+** con extensiones PDO_SQLSRV, mbstring
- ✅ **SQL Server 2016+** (requisito para JSON y HASHBYTES)
- ✅ **PhpSpreadsheet 2.0+** (ya en `composer.json`)
- ✅ No añade dependencias nuevas al `composer.json`
- ✅ No elimina ninguna tabla, columna o dato existente
- ✅ Los scripts son **idempotentes** (puedes ejecutarlos múltiples veces)
- ✅ Respeta todas las convenciones del proyecto existente (formato de nombres, FKs, indices, SPs)
