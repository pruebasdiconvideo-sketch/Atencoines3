# VERSIÓN 6 — Fix del bug en script SQL 10

## BUG CORREGIDO

En la V5, el script `10_fix_tipos_cotizacion.sql` tenía una sentencia **CTE + UPDATE** rota:

```sql
-- SENTENCIA ROTA (V5):
;WITH duplicados AS (
    SELECT id_tipo,
           ROW_NUMBER() OVER (PARTITION BY prefijo_codigo ORDER BY id_tipo) AS rn
    FROM dbo.cotizacion_tipo_catalogo
    WHERE activo = 1
      AND prefijo_codigo IN ('SERV','VENT')
)
UPDATE duplicados
SET activo = 0   -- ← ERROR: la CTE no expone 'activo'
;
```

La CTE solo exponía `id_tipo` y `rn`, no `activo`. Esto causaba:
```
Msg 207, Level 16, State 1, Line 53
Invalid column name 'activo'.
```

Y abortaba el resto del script.

## FIX V6

El script corregido elimina esa sentencia rota y usa directamente el JOIN (que sí funciona):

```sql
-- SENTENCIA CORREGIDA (V6):
UPDATE t
SET t.activo = 0
FROM dbo.cotizacion_tipo_catalogo t
INNER JOIN (
    SELECT id_tipo,
           ROW_NUMBER() OVER (PARTITION BY prefijo_codigo ORDER BY id_tipo) AS rn
    FROM dbo.cotizacion_tipo_catalogo
    WHERE prefijo_codigo IN ('SERV','VENT')
      AND activo = 1
) x ON t.id_tipo = x.id_tipo
WHERE x.rn > 1;
```

Esta sentencia sí funciona porque actualiza la tabla real `t` (no la CTE) y el JOIN expone la columna `id_tipo` para identificar las filas a desactivar.

## ARCHIVOS DE LA V6

```
entrega_v6/
├── README.md                          Esta guía
├── test_v3.php                        Sin cambios
├── nueva_cotizacion_v3.php            Sin cambios
├── config_logo_v3.php                 Sin cambios
├── seguimiento_comercial_v3.php       Sin cambios
├── asignar_codigo_v3.php              Sin cambios
└── scripts_sql/
    └── 10_fix_tipos_cotizacion.sql    CORREGIDO (eliminada sentencia CTE rota)
```

## INSTALACIÓN

### PASO 1 — Ejecutar el SQL corregido con `sa`

```sql
USE [MonosyncERP];
-- Ejecutar el archivo: scripts_sql/10_fix_tipos_cotizacion.sql
```

Esta vez **no debe dar error**. Al final debe imprimir:

```
  Total de tipos activos: 2
  ✓ OK — Exactamente 2 tipos activos.
```

### PASO 2 — Verificar manualmente

```sql
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
WHERE activo = 1
ORDER BY id_tipo;
```

Debe devolver exactamente 2 filas:
- Una con `prefijo_codigo = 'SERV'` (probablemente ID=8)
- Una con `prefijo_codigo = 'VENT'` (probablemente ID=9)

### PASO 3 — Verificar el formulario de nueva cotización

Acceda a `http://localhost:8036/nueva_cotizacion_v3.php` (después de iniciar sesión).

En el combo "Tipo de cotización" solo deben aparecer **2 opciones**:
- Soporte (SERV)
- Ventas (VENT)

### PASO 4 — Si las páginas PHP ya funcionaban en V5

No necesita reemplazarlas — son idénticas. Solo necesita volver a ejecutar el script SQL corregido.

## SI EL ERROR PERSISTE

Si después de ejecutar el script V6 sigue viendo más de 2 tipos activos, ejecute manualmente:

```sql
-- Ver qué hay
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
ORDER BY activo DESC, id_tipo;

-- Desactivar todos excepto los 2 que queremos (reemplace los IDs)
UPDATE dbo.cotizacion_tipo_catalogo
SET activo = 0
WHERE id_tipo NOT IN (8, 9);  -- 8=SERV, 9=VENT (ajuste según su BD)

-- Verificar
SELECT id_tipo, codigo, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
WHERE activo = 1;
-- Debe devolver solo 2 filas
```

## RESUMEN DE VERSIONES

| Versión | Problema corregido |
|---------|--------------------|
| V1-V3 | Patches JS que no se aplicaban por la SPA |
| V4 | Páginas PHP independientes, pero no compartían sesión |
| V5 | Fix de autenticación (`startAppSession()`) + script SQL 10 (con bug) |
| **V6** | **Fix del bug en script SQL 10** (sentencia CTE+UPDATE rota) |
