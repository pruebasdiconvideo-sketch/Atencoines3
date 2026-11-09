/*============================================================================
 *  SCRIPT 10 (CORREGIDO) — FIX: Solo 2 tipos de cotización activos
 *  ---------------------------------------------------------------------------
 *  BUG CORREGIDO en V5:
 *    La V5 tenía una sentencia CTE+UPDATE rota que intentaba hacer
 *    `UPDATE duplicados SET activo = 0` pero la CTE solo exponía `id_tipo`
 *    y `rn`, no `activo`. Esto causaba:
 *       Msg 207, Level 16, State 1, Line 53
 *       Invalid column name 'activo'.
 *
 *  Esta versión elimina esa sentencia rota y usa directamente el JOIN
 *  (que sí funciona).
 *
 *  RESULTADO ESPERADO:
 *    Quedan solo 2 tipos activos: SERV (Soporte) y VENT (Ventas).
 *==========================================================================*/

USE [MonosyncERP];
GO
SET NOCOUNT ON;
PRINT '======================================================================';
PRINT '  SCRIPT 10 (CORREGIDO) — Solo 2 tipos de cotización activos';
PRINT '======================================================================';

-- 1. Mostrar tipos actuales
PRINT '';
PRINT '--- Tipos ANTES:';
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
ORDER BY activo DESC, orden, id_tipo;

-- 2. Desactivar todos los tipos cuyo código Y prefijo NO sean SERV o VENT
UPDATE dbo.cotizacion_tipo_catalogo
SET activo = 0
WHERE codigo NOT IN ('SERV','VENT')
  AND prefijo_codigo NOT IN ('SERV','VENT');

PRINT '';
PRINT '  Después de paso 2 (desactivar los que no son SERV/VENT):';
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
WHERE activo = 1
ORDER BY id_tipo;

-- 3. Asegurar que SERV y VENT estén activos
UPDATE dbo.cotizacion_tipo_catalogo
SET activo = 1, codigo_por_empresa = 1
WHERE codigo IN ('SERV','VENT')
   OR prefijo_codigo IN ('SERV','VENT');

PRINT '';
PRINT '  Después de paso 3 (activar SERV y VENT):';
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
WHERE activo = 1
ORDER BY id_tipo;

-- 4. Desactivar duplicados: para cada prefijo_codigo ('SERV' y 'VENT'),
--    mantener solo el de MENOR id_tipo, desactivar los demás.
--    Esto se hace con un JOIN (no CTE+UPDATE porque la CTE no expone 'activo').
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

PRINT '';
PRINT '  Después de paso 4 (desactivar duplicados, mantener menor ID):';
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
WHERE activo = 1
ORDER BY id_tipo;

-- 5. Re-asegurar que el de menor ID para SERV y para VENT esté activo
UPDATE dbo.cotizacion_tipo_catalogo
SET activo = 1
WHERE id_tipo IN (
    SELECT MIN(id_tipo) FROM dbo.cotizacion_tipo_catalogo WHERE prefijo_codigo = 'SERV'
)
OR id_tipo IN (
    SELECT MIN(id_tipo) FROM dbo.cotizacion_tipo_catalogo WHERE prefijo_codigo = 'VENT'
);

-- 6. Resultado final
PRINT '';
PRINT '--- Tipos DESPUÉS (deben ser exactamente 2: SERV y VENT):';
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
ORDER BY activo DESC, orden, id_tipo;

PRINT '';
DECLARE @activos INT = (SELECT COUNT(*) FROM dbo.cotizacion_tipo_catalogo WHERE activo = 1);
PRINT '  Total de tipos activos: ' + CAST(@activos AS NVARCHAR(10));
IF @activos = 2
    PRINT '  ✓ OK — Exactamente 2 tipos activos.';
ELSE
    PRINT '  ⚠ ADVERTENCIA — Hay ' + CAST(@activos AS NVARCHAR(10)) + ' tipos activos. Revise manualmente.';

PRINT '';
PRINT '======================================================================';
GO
