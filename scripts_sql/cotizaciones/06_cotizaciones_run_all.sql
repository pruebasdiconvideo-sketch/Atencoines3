/* ============================================================================
 *  06_cotizaciones_run_all.sql
 *  ----------------------------------------------------------------------------
 *  Script MAESTRO de instalación del módulo de Cotizaciones.
 *
 *  IMPORTANTE: Este script NO ejecuta los otros scripts automáticamente.
 *  La directiva `:r` es sintaxis de sqlcmd (línea de comandos), no de SSMS.
 *  En SSMS da error "Incorrect syntax near ':'".
 *
 *  PARA INSTALAR EL MÓDULO, ejecuta los scripts en orden manualmente:
 *
 *    1. 00_cotizaciones_drop_all.sql           ← LIMPIA todo (solo si reinstalas)
 *    2. 01_cotizaciones_tablas.sql              ← crea 17 tablas + índices + FKs
 *    3. 02_cotizaciones_seed_catalogos.sql      ← catálogos del Excel
 *    4. 03_cotizaciones_seed_productos.sql      ← 160 productos del Excel
 *    5. 04_cotizaciones_seed_modulo_permisos.sql ← módulo + rol + permisos
 *    6. 05_cotizaciones_sps_parte1.sql           ← SPs (correlativo, create, totales)
 *    7. 05_cotizaciones_sps_parte2.sql           ← SPs (get, list, status, etc.)
 *
 *  ALTERNATIVA vía sqlcmd (desde CMD):
 *    sqlcmd -S localhost -E -d MonosyncERP -i "01_cotizaciones_tablas.sql"
 *    sqlcmd -S localhost -E -d MonosyncERP -i "02_cotizaciones_seed_catalogos.sql"
 *    ... (uno por uno)
 *
 *  Este script solo muestra el estado actual y los pasos a seguir.
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

PRINT N'====================================================================';
PRINT N'  MÓDULO DE COTIZACIONES — Instalador MAESTRO';
PRINT N'  Base de datos: ' + DB_NAME();
PRINT N'  Fecha: ' + CONVERT(NVARCHAR(30), SYSDATETIME(), 120);
PRINT N'====================================================================';
PRINT N'';
PRINT N'ESTADO ACTUAL:';

DECLARE @tablas_cot INT, @sps_cot INT, @productos INT, @tipos INT, @formas_pago INT,
        @garantias INT, @plazos INT, @validezes INT, @cuentas INT, @zonas INT,
        @emisoras INT;

SELECT @tablas_cot = COUNT(*) FROM sys.tables t
WHERE t.name LIKE 'cotizacion[_]%' AND SCHEMA_NAME(t.schema_id) = 'dbo';

SELECT @sps_cot = COUNT(*) FROM sys.objects o
WHERE o.type = 'P' AND (o.name LIKE 'sp_cotizacion[_]%' OR o.name = 'sp_cotizacion_correlativo_siguiente');

SELECT @productos = COUNT(*) FROM dbo.cotizacion_producto_catalogo;
SELECT @tipos = COUNT(*) FROM dbo.cotizacion_tipo_catalogo;
SELECT @formas_pago = COUNT(*) FROM dbo.cotizacion_forma_pago;
SELECT @garantias = COUNT(*) FROM dbo.cotizacion_garantia;
SELECT @plazos = COUNT(*) FROM dbo.cotizacion_plazo_entrega;
SELECT @validezes = COUNT(*) FROM dbo.cotizacion_validez;
SELECT @cuentas = COUNT(*) FROM dbo.cotizacion_cuenta_bancaria;
SELECT @zonas = COUNT(*) FROM dbo.cotizacion_zona_pricing;
SELECT @emisoras = COUNT(*) FROM dbo.cotizacion_empresa_emisora;

PRINT N'  Tablas cotizacion_* creadas: ' + CAST(@tablas_cot AS NVARCHAR(10)) + N'/17';
PRINT N'  Stored Procedures: ' + CAST(@sps_cot AS NVARCHAR(10)) + N'/13';
PRINT N'  Productos en catálogo: ' + CAST(@productos AS NVARCHAR(10));
PRINT N'  Tipos de cotización: ' + CAST(@tipos AS NVARCHAR(10)) + N'/7';
PRINT N'  Formas de pago: ' + CAST(@formas_pago AS NVARCHAR(10)) + N'/9';
PRINT N'  Garantías: ' + CAST(@garantias AS NVARCHAR(10)) + N'/7';
PRINT N'  Plazos de entrega: ' + CAST(@plazos AS NVARCHAR(10)) + N'/6';
PRINT N'  Valideces: ' + CAST(@validezes AS NVARCHAR(10)) + N'/3';
PRINT N'  Cuentas bancarias: ' + CAST(@cuentas AS NVARCHAR(10)) + N'/4';
PRINT N'  Zonas de pricing: ' + CAST(@zonas AS NVARCHAR(10)) + N'/3';
PRINT N'  Empresas emisoras: ' + CAST(@emisoras AS NVARCHAR(10)) + N'/1';
PRINT N'';

IF @tablas_cot = 17 AND @sps_cot = 13
BEGIN
    PRINT N'  ✅ MÓDULO COMPLETAMENTE INSTALADO Y FUNCIONAL';
END
ELSE IF @tablas_cot > 0 OR @sps_cot > 0
BEGIN
    PRINT N'  ⚠️ INSTALACIÓN INCOMPLETA — faltan objetos';
    PRINT N'';
    PRINT N'  PASOS PARA COMPLETAR/REINSTALAR:';
    PRINT N'  1. Ejecuta 00_cotizaciones_drop_all.sql para limpiar todo';
    PRINT N'  2. Ejecuta en orden: 01, 02, 03, 04, 05a, 05b';
END
ELSE
BEGIN
    PRINT N'  📦 MÓDULO NO INSTALADO AÚN';
    PRINT N'';
    PRINT N'  PASOS PARA INSTALAR:';
    PRINT N'  1. Ejecuta 01_cotizaciones_tablas.sql';
    PRINT N'  2. Ejecuta 02_cotizaciones_seed_catalogos.sql';
    PRINT N'  3. Ejecuta 03_cotizaciones_seed_productos.sql';
    PRINT N'  4. Ejecuta 04_cotizaciones_seed_modulo_permisos.sql';
    PRINT N'  5. Ejecuta 05_cotizaciones_sps_parte1.sql';
    PRINT N'  6. Ejecuta 05_cotizaciones_sps_parte2.sql';
END

PRINT N'====================================================================';
GO
