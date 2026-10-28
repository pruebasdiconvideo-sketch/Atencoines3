# FIX DEFINITIVO — Error `nvarchar(max) → varbinary(max)` + Permisos

Esta versión **v2** corrige definitivamente el error que seguías viendo.
La versión anterior usaba `PDO::PARAM_LOB` que tiene un bug en
`pdo_sqlsrv 5.11/5.12 con PHP 8.x` y manda el binario como
`NVARCHAR(MAX)` incluso a través del SP.

**Solución v2:** mandar el archivo como **base64 (string NVARCHAR)**
al SP `sp_ticket_documento_save`. El SP lo decodifica dentro de SQL
Server usando `CONVERT(XML, ...).value('xs:base64Binary(...)')`. Como
**ningún parámetro desde PHP es VARBINARY**, el driver nunca necesita
hacer conversión implícita y el error desaparece.

---

## Estructura del paquete (lista para subir)

```
Atenciones-fixed/
├── README.md                              ← este archivo
├── scripts_sql/
│   ├── 01_fix_upload_varbinary.sql        ← ejecutar en SQL Server
│   ├── 01b_verificar_sp.sql               ← ejecutar para verificar (opcional)
│   ├── 02_sp_usuario_permisos.sql         ← ejecutar en SQL Server
│   └── 03_seed_modulos.sql                ← ejecutar en SQL Server
├── includes/
│   └── PermisoHelper.php                  ← archivo nuevo
├── api/routes/
│   └── tickets.php                        ← reemplaza al original (fix v2)
├── assets/js/
│   └── app.js                             ← reemplaza al original (fix permisos)
└── test_upload_fix.php                    ← BORRAR después de probar
```

---

## PASO 1 — Ejecutar los scripts SQL (¡IMPORTANTE: EN ORDEN!)

Conéctate a SQL Server Management Studio sobre `MonosyncERP` y ejecuta:

```
1. scripts_sql/01_fix_upload_varbinary.sql   ← crea el SP
2. scripts_sql/01b_verificar_sp.sql          ← verifica que todo esté OK
3. scripts_sql/02_sp_usuario_permisos.sql    ← crea SPs de permisos
4. scripts_sql/03_seed_modulos.sql           ← puebla catálogo de módulos
```

El script `01b_verificar_sp.sql` es nuevo y te dirá exactamente si el SP
está bien instalado y si la decodificación base64 funciona. **Si dice
algún [FAIL], no sigas** — resuelve eso primero.

### Verificación rápida (manual)

```sql
-- Deben aparecer 9 módulos:
SELECT codigo, nombre FROM dbo.modulo_catalogo ORDER BY orden;

-- Deben existir los 3 SPs:
SELECT name FROM sys.objects
WHERE name IN (
    'sp_ticket_documento_save',
    'sp_ticket_documento_get',
    'sp_usuario_permisos_get'
);

-- Ver parámetros del SP de subida:
SELECT name, TYPE_NAME(system_type_id) AS tipo
FROM sys.parameters
WHERE object_id = OBJECT_ID(N'dbo.sp_ticket_documento_save')
ORDER BY parameter_id;
```

---

## PASO 2 — Subir los archivos al servidor

Sube manteniendo la estructura de carpetas:

| Archivo local (este paquete)      | Ruta destino en el servidor                | Acción         |
|-----------------------------------|--------------------------------------------|----------------|
| `includes/PermisoHelper.php`      | `includes/PermisoHelper.php`               | **NUEVO**      |
| `api/routes/tickets.php`          | `api/routes/tickets.php`                   | **REEMPLAZAR** |
| `assets/js/app.js`                | `assets/js/app.js`                         | **REEMPLAZAR** |
| `test_upload_fix.php`             | `test_upload_fix.php`                      | **TEMPORAL**   |

> **No subas los `.sql` al servidor web** — se ejecutan en SQL Server,
> no se necesitan en el hosting PHP.

---

## PASO 3 — Registrar PermisoHelper en `api/index.php`

Abrir `api/index.php` y agregar la línea marcada con `// <-- agregar`
justo después de los otros `require_once` de `includes/`:

```php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/PermisoHelper.php';  // <-- agregar
require_once __DIR__ . '/../includes/audit.php';
```

---

## PASO 4 — Probar el fix con `test_upload_fix.php`

1. Sube `test_upload_fix.php` a la raíz del proyecto (junto a `index.php`).
2. Ábrelo en el navegador: `https://tu-dominio/test_upload_fix.php`
3. Verás 5 pruebas. **Todas deben decir `[OK]`**.

Si alguna dice `[FAIL]`:
- **PRUEBA 1 falla**: no ejecutaste `01_fix_upload_varbinary.sql`.
- **PRUEBA 2 falla**: tu tabla `ticket_documento` no tiene la columna
  `contenido`. Ejecuta primero el script del repo `scripts_sql/15_*.sql`
  que añade esa columna.
- **PRUEBA 3 falla**: tu SQL Server no soporta XML xs:base64Binary
  (requiere SQL Server 2016+).
- **PRUEBA 4 falla**: hay un problema con la llamada al SP desde PHP.
  El mensaje mostrará el error exacto.

**Después de probar: BORRA `test_upload_fix.php`** del servidor
(expone datos internos).

---

## PASO 5 — Probar en la app real

1. Inicia sesión en la app.
2. Abre un ticket existente.
3. Sube un PDF o imagen pequeña (1-2 MB para empezar).
4. Debe aparecer en la lista de documentos sin error.

Si sigue fallando, **copia el mensaje exacto del error** y pásalo por
aquí. Pero con el fix v2 (base64) el error `nvarchar → varbinary` es
imposible porque ningún parámetro PHP va como binario.

---

## ¿Qué cambió respecto a la versión anterior?

### v1 (no funcionó)
- PHP mandaba el binario con `PDO::PARAM_LOB` al SP.
- El driver `pdo_sqlsrv` tenía un bug y lo enviaba como `NVARCHAR(MAX)`.
- SQL Server seguía rechazando con el mismo error.

### v2 (esta versión — definitivo)
- PHP hace `base64_encode($binario)` → string NVARCHAR(MAX).
- El SP recibe `@base64_contenido NVARCHAR(MAX)` — tipo que coincide.
- Dentro del SP: `CONVERT(XML, '<x>' + @base64 + '</x>').value('xs:base64Binary(/x[1])', 'VARBINARY(MAX)')`
- **Ningún parámetro PHP es VARBINARY**, así que el bug del driver no aplica.

---

## Fix permisos (sin cambios respecto a v1)

`assets/js/app.js` incluye:
- `applyPermissionsToDynamic(root)` — oculta elementos con
  `data-requires-permission="MODULO:accion"`.
- `startPermissionObserver()` — MutationObserver que reaplica permisos
  automáticamente cuando se renderizan nuevas vistas.
- `permissionAttrs(modulo, accion)` — helper para template strings.
- Atajos: `App.canView/canCreate/canEdit/canDelete/canApprove/canExport/canPrint`.

Para ocultar botones en `views.js`, márcalos:

```html
<button class="btn btn-primary"
        data-requires-permission="TICKETS:registrar">
    Nuevo Ticket
</button>

<button class="btn btn-danger"
        data-requires-permission="TICKETS:eliminar">
    Eliminar
</button>
```

O usando helpers en template strings:

```js
container.innerHTML = `
  <button ${App.permissionAttrs('TICKETS','registrar')}>Nuevo</button>
  ${App.canDelete('TICKETS') ? `<button onclick="eliminar()">Eliminar</button>` : ''}
`;
```

---

## Resumen técnico del fix de subida

```
ANTES (roto):
   PHP: $binario = file_get_contents(...)
   PHP: $stmt->bindValue(9, $binario, PDO::PARAM_LOB)
   Driver pdo_sqlsrv: envía como NVARCHAR(MAX)  ← BUG
   SQL Server: INSERT INTO ticket_documento (contenido) VALUES (?)
   SQL Server: rechaza "nvarchar(max) → varbinary(max) not allowed"

DESPUÉS (fix v2):
   PHP: $binario = file_get_contents(...)
   PHP: $base64 = base64_encode($binario)        ← string NVARCHAR
   PHP: $stmt->bindValue(9, $base64, PDO::PARAM_STR)
   Driver pdo_sqlsrv: envía como NVARCHAR(MAX)   ← sin problemas
   SQL Server: EXEC sp_ticket_documento_save @base64_contenido = ?
   SP: CONVERT(XML, '<x>'+@base64+'</x>').value('xs:base64Binary(/x[1])', 'VARBINARY(MAX)')
   SP: INSERT INTO ticket_documento (contenido) VALUES (@contenido_final)
   → OK, sin conversión implícita
```

---

## Si necesitas ayuda

Si después de aplicar todo sigues viendo el error:
1. Verifica con `test_upload_fix.php` — debe dar todo `[OK]`.
2. Verifica que subiste `tickets.php` a la ruta correcta
   (`api/routes/tickets.php`) y reemplazó al anterior.
3. Borra caché del navegador (Ctrl+Shift+R) por si el JS viejo sigue
   activo.
4. Copia el mensaje exacto del error y pásalo.
