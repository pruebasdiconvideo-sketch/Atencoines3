/*============================================================================
 *  SCRIPT 11 — VERIFICACIÓN DE LOGOS Y CUENTAS BANCARIAS
 *  ---------------------------------------------------------------------------
 *  Verifica:
 *    1. Que las emisoras tengan logo cargado
 *    2. Que las emisoras tengan cuentas bancarias activas
 *    3. Muestra el estado actual de cada emisora
 *==========================================================================*/

USE [MonosyncERP];
GO
SET NOCOUNT ON;
PRINT '======================================================================';
PRINT '  SCRIPT 11 - Verificación de logos y cuentas bancarias';
PRINT '======================================================================';

PRINT '';
PRINT '--- 1. Estado de logos por emisora ---';
SELECT
    em.id_emisora,
    em.razon_social,
    em.ruc,
    CASE WHEN em.logo_base64 IS NULL THEN 'NO TIENE LOGO'
         ELSE 'TIENE LOGO (' + CAST(LEN(em.logo_base64) AS NVARCHAR(10)) + ' chars)' END AS estado_logo,
    CONVERT(VARCHAR(19), em.fecha_modificacion, 120) AS fecha_modificacion
FROM dbo.cotizacion_empresa_emisora em
ORDER BY em.es_principal DESC, em.razon_social;

PRINT '';
PRINT '--- 2. Cuentas bancarias activas por emisora ---';
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
PRINT '--- 3. Detalle de cuentas bancarias activas ---';
SELECT
    c.id_cuenta,
    em.razon_social,
    c.banco,
    c.numero_cuenta,
    c.cci,
    c.moneda,
    c.titular,
    c.orden
FROM dbo.cotizacion_cuenta_bancaria c
INNER JOIN dbo.cotizacion_empresa_emisora em ON c.id_emisora = em.id_emisora
WHERE c.activo = 1
ORDER BY em.razon_social, c.orden, c.id_cuenta;

PRINT '';
PRINT '--- 4. Últimas 10 cotizaciones con su logo y cuentas ---';
SELECT TOP 10
    c.id_cotizacion,
    c.codigo,
    c.estado,
    em.razon_social AS emisora,
    CASE WHEN em.logo_base64 IS NULL THEN 'sin logo' ELSE 'con logo' END AS tiene_logo,
    ISNULL(cnt.total_cuentas, 0) AS total_cuentas_emisora,
    ISNULL(cnt.cuentas_activas, 0) AS cuentas_activas,
    CASE WHEN c.cuentas_adicionales_json IS NULL THEN 'solo principal'
         ELSE 'multi-cuenta' END AS modo_cuentas
FROM dbo.cotizacion c
INNER JOIN dbo.cotizacion_empresa_emisora em ON c.id_emisora = em.id_emisora
OUTER APPLY (
    SELECT COUNT(*) AS total_cuentas,
           SUM(CASE WHEN cc.activo = 1 THEN 1 ELSE 0 END) AS cuentas_activas
    FROM dbo.cotizacion_cuenta_bancaria cc
    WHERE cc.id_emisora = em.id_emisora
) cnt
ORDER BY c.id_cotizacion DESC;

PRINT '';
PRINT '======================================================================';
GO
