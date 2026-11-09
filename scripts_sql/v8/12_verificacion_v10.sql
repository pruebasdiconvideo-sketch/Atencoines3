/*============================================================================
 *  SCRIPT 12 — VERIFICACIÓN V10 (Multi-select + Sin Zona Pricing)
 *  ---------------------------------------------------------------------------
 *  Verifica:
 *    1. Que las cotizaciones existentes tengan cuentas_adicionales_json
 *    2. Que las cuentas bancarias adicionales se puedan consultar
 *    3. Lista cotizaciones con sus cuentas seleccionadas
 *==========================================================================*/

USE [MonosyncERP];
GO
SET NOCOUNT ON;
PRINT '======================================================================';
PRINT '  SCRIPT 12 - Verificación V10 (Multi-select + Sin Zona Pricing)';
PRINT '======================================================================';

PRINT '';
PRINT '--- 1. Cotizaciones con cuentas adicionales ---';
SELECT
    c.id_cotizacion,
    c.codigo,
    c.estado,
    c.id_cuenta_bancaria,
    CASE WHEN c.cuentas_adicionales_json IS NULL THEN 'NO'
         ELSE 'SI (' + c.cuentas_adicionales_json + ')' END AS cuentas_adicionales,
    CASE WHEN c.id_zona_pricing IS NULL THEN 'NULL (correcto)'
         ELSE CAST(c.id_zona_pricing AS NVARCHAR(10)) END AS zona_pricing
FROM dbo.cotizacion c
ORDER BY c.id_cotizacion DESC;

PRINT '';
PRINT '--- 2. Tipos activos (deben ser solo SERV y VENT) ---';
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
WHERE activo = 1
ORDER BY id_tipo;

PRINT '';
PRINT '--- 3. Cuentas bancarias activas por emisora ---';
SELECT
    em.id_emisora,
    em.razon_social,
    COUNT(c.id_cuenta) AS total_cuentas,
    SUM(CASE WHEN c.activo = 1 THEN 1 ELSE 0 END) AS cuentas_activas
FROM dbo.cotizacion_empresa_emisora em
LEFT JOIN dbo.cotizacion_cuenta_bancaria c ON em.id_emisora = c.id_emisora
WHERE em.activo = 1
GROUP BY em.id_emisora, em.razon_social
ORDER BY em.razon_social;

PRINT '';
PRINT '--- 4. Logos cargados ---';
SELECT
    em.id_emisora,
    em.razon_social,
    CASE WHEN em.logo_base64 IS NULL THEN 'SIN LOGO'
         ELSE 'CON LOGO (' + CAST(LEN(em.logo_base64) AS NVARCHAR(10)) + ' chars)' END AS estado_logo
FROM dbo.cotizacion_empresa_emisora em
ORDER BY em.razon_social;

PRINT '';
PRINT '======================================================================';
GO
