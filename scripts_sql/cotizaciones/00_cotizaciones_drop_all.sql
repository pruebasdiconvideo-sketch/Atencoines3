/* ============================================================================
 *  00_cotizaciones_drop_all.sql
 *  ----------------------------------------------------------------------------
 *  Script de LIMPIEZA TOTAL del módulo de Cotizaciones.
 *
 *  Elimina en orden seguro (respetando FKs):
 *    1. Todos los Stored Procedures sp_cotizacion_*
 *    2. Todas las tablas cotizacion_* (en orden inverso de FKs)
 *    3. El registro del módulo COTIZACIONES en modulo_catalogo
 *    4. El rol VENTAS en persona_rol (opcional, comentado por defecto)
 *
 *  USO:
 *    - Ejecuta este script ANTES de volver a correr los scripts 01-05
 *    - Es IDEMPOTENTE: si los objetos no existen, los omite sin error
 *
 *  NOTA: NO elimina los datos existentes de empresas, personas, tickets, etc.
 *        Solo elimina objetos del módulo de cotizaciones.
 *
 *  ADVERTENCIA: Si tienes cotizaciones creadas, este script las ELIMINA TODAS.
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

PRINT N'====================================================================';
PRINT N'  LIMPIEZA DEL MÓDULO DE COTIZACIONES';
PRINT N'====================================================================';

/* ============================================================================
 * 1) ELIMINAR TODOS LOS STORED PROCEDURES
 *    Orden: indiferente (los SPs no dependen entre sí para ser eliminados)
 * ============================================================================
 */
PRINT N'Eliminando Stored Procedures...';

IF OBJECT_ID(N'dbo.sp_cotizacion_correlativo_siguiente','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_correlativo_siguiente;
IF OBJECT_ID(N'dbo.sp_cotizacion_calcular_totales','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_calcular_totales;
IF OBJECT_ID(N'dbo.sp_cotizacion_create','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_create;
IF OBJECT_ID(N'dbo.sp_cotizacion_get','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_get;
IF OBJECT_ID(N'dbo.sp_cotizacion_list_paged','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_list_paged;
IF OBJECT_ID(N'dbo.sp_cotizacion_update_status','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_update_status;
IF OBJECT_ID(N'dbo.sp_cotizacion_add_comunicacion','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_add_comunicacion;
IF OBJECT_ID(N'dbo.sp_cotizacion_update','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_update;
IF OBJECT_ID(N'dbo.sp_cotizacion_documento_save','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_documento_save;
IF OBJECT_ID(N'dbo.sp_cotizacion_documento_get','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_documento_get;
IF OBJECT_ID(N'dbo.sp_cotizacion_delete_admin','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_delete_admin;
IF OBJECT_ID(N'dbo.sp_cotizacion_catalogos_listar','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_catalogos_listar;
IF OBJECT_ID(N'dbo.sp_cotizacion_producto_listar','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_producto_listar;

PRINT N'[OK] SPs eliminados (si existían)';
GO

/* ============================================================================
 * 2) ELIMINAR ÍNDICES (antes de eliminar tablas para evitar warnings)
 * ============================================================================
 */
PRINT N'Eliminando índices...';

IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_empresa_estado' AND OBJECT_ID('dbo.cotizacion') = object_id)
    DROP INDEX IX_cotizacion_empresa_estado ON dbo.cotizacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_estado_fecha' AND OBJECT_ID('dbo.cotizacion') = object_id)
    DROP INDEX IX_cotizacion_estado_fecha ON dbo.cotizacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_tipo_anio' AND OBJECT_ID('dbo.cotizacion') = object_id)
    DROP INDEX IX_cotizacion_tipo_anio ON dbo.cotizacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_vendedor' AND OBJECT_ID('dbo.cotizacion') = object_id)
    DROP INDEX IX_cotizacion_vendedor ON dbo.cotizacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_creador' AND OBJECT_ID('dbo.cotizacion') = object_id)
    DROP INDEX IX_cotizacion_creador ON dbo.cotizacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_proximo_seguimiento' AND OBJECT_ID('dbo.cotizacion') = object_id)
    DROP INDEX IX_cotizacion_proximo_seguimiento ON dbo.cotizacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_codigo_like' AND OBJECT_ID('dbo.cotizacion') = object_id)
    DROP INDEX IX_cotizacion_codigo_like ON dbo.cotizacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cotizacion_eliminado' AND OBJECT_ID('dbo.cotizacion') = object_id)
    DROP INDEX IX_cotizacion_eliminado ON dbo.cotizacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_historial_cot_fecha' AND OBJECT_ID('dbo.cotizacion_historial_estado') = object_id)
    DROP INDEX IX_cot_historial_cot_fecha ON dbo.cotizacion_historial_estado;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_comunicacion_cot_fecha' AND OBJECT_ID('dbo.cotizacion_comunicacion') = object_id)
    DROP INDEX IX_cot_comunicacion_cot_fecha ON dbo.cotizacion_comunicacion;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_detalle_cot_orden' AND OBJECT_ID('dbo.cotizacion_detalle') = object_id)
    DROP INDEX IX_cot_detalle_cot_orden ON dbo.cotizacion_detalle;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_version_cot_fecha' AND OBJECT_ID('dbo.cotizacion_version') = object_id)
    DROP INDEX IX_cot_version_cot_fecha ON dbo.cotizacion_version;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_cot_doc_cot' AND OBJECT_ID('dbo.cotizacion_documento') = object_id)
    DROP INDEX IX_cot_doc_cot ON dbo.cotizacion_documento;

PRINT N'[OK] Índices eliminados (si existían)';
GO

/* ============================================================================
 * 3) ELIMINAR TABLAS EN ORDEN INVERSO DE FKs
 *    (hijas primero, padres después)
 * ============================================================================
 */
PRINT N'Eliminando tablas (orden inverso de FKs)...';

-- Tablas hijas (con FKs a otras tablas cotizacion_*)
IF OBJECT_ID(N'dbo.cotizacion_documento','U') IS NOT NULL DROP TABLE dbo.cotizacion_documento;
IF OBJECT_ID(N'dbo.cotizacion_historial_estado','U') IS NOT NULL DROP TABLE dbo.cotizacion_historial_estado;
IF OBJECT_ID(N'dbo.cotizacion_comunicacion','U') IS NOT NULL DROP TABLE dbo.cotizacion_comunicacion;
IF OBJECT_ID(N'dbo.cotizacion_version','U') IS NOT NULL DROP TABLE dbo.cotizacion_version;
IF OBJECT_ID(N'dbo.cotizacion_detalle','U') IS NOT NULL DROP TABLE dbo.cotizacion_detalle;
IF OBJECT_ID(N'dbo.cotizacion','U') IS NOT NULL DROP TABLE dbo.cotizacion;

-- Tablas puente y catálogos
IF OBJECT_ID(N'dbo.cotizacion_correlativo_anual','U') IS NOT NULL DROP TABLE dbo.cotizacion_correlativo_anual;
IF OBJECT_ID(N'dbo.cotizacion_cuenta_bancaria','U') IS NOT NULL DROP TABLE dbo.cotizacion_cuenta_bancaria;
IF OBJECT_ID(N'dbo.cotizacion_validez','U') IS NOT NULL DROP TABLE dbo.cotizacion_validez;
IF OBJECT_ID(N'dbo.cotizacion_plazo_entrega','U') IS NOT NULL DROP TABLE dbo.cotizacion_plazo_entrega;
IF OBJECT_ID(N'dbo.cotizacion_garantia','U') IS NOT NULL DROP TABLE dbo.cotizacion_garantia;
IF OBJECT_ID(N'dbo.cotizacion_forma_pago','U') IS NOT NULL DROP TABLE dbo.cotizacion_forma_pago;
IF OBJECT_ID(N'dbo.cotizacion_producto_precio_zona','U') IS NOT NULL DROP TABLE dbo.cotizacion_producto_precio_zona;
IF OBJECT_ID(N'dbo.cotizacion_producto_catalogo','U') IS NOT NULL DROP TABLE dbo.cotizacion_producto_catalogo;
IF OBJECT_ID(N'dbo.cotizacion_zona_pricing','U') IS NOT NULL DROP TABLE dbo.cotizacion_zona_pricing;
IF OBJECT_ID(N'dbo.cotizacion_empresa_emisora','U') IS NOT NULL DROP TABLE dbo.cotizacion_empresa_emisora;
IF OBJECT_ID(N'dbo.cotizacion_tipo_catalogo','U') IS NOT NULL DROP TABLE dbo.cotizacion_tipo_catalogo;

PRINT N'[OK] Tablas eliminadas (si existían)';
GO

/* ============================================================================
 * 4) ELIMINAR PERMISOS DEL ROL SOBRE COTIZACIONES
 *    (el módulo se registrará de nuevo al correr 04_*.sql)
 * ============================================================================
 */
PRINT N'Eliminando permisos del módulo COTIZACIONES...';

DELETE FROM dbo.role_permissions WHERE modulo = 'COTIZACIONES';
PRINT N'[OK] Permisos eliminados';
GO

/* ============================================================================
 * 5) ELIMINAR EL MÓDULO COTIZACIONES de modulo_catalogo
 * ============================================================================
 */
PRINT N'Eliminando módulo COTIZACIONES de modulo_catalogo...';

DELETE FROM dbo.modulo_catalogo WHERE codigo = 'COTIZACIONES';
PRINT N'[OK] Módulo eliminado de modulo_catalogo';
GO

/* ============================================================================
 * 6) ELIMINAR EL ROL VENTAS (OPCIONAL — comentado por defecto)
 *    Si lo descomentas, también se quitará la asignación del rol a personas.
 *    Por defecto NO lo eliminamos para no perder la configuración hecha.
 * ============================================================================
 */
/*
PRINT N'Eliminando rol VENTAS...';
DELETE FROM dbo.persona_rol_asignacion WHERE id_rol = (SELECT id_rol FROM dbo.persona_rol WHERE nombre = 'VENTAS');
DELETE FROM dbo.persona_rol WHERE nombre = 'VENTAS';
PRINT N'[OK] Rol VENTAS eliminado';
GO
*/

PRINT N'';
PRINT N'====================================================================';
PRINT N'  ✅ LIMPIEZA COMPLETADA';
PRINT N'====================================================================';
PRINT N'';
PRINT N'Ahora ejecuta los scripts en orden:';
PRINT N'  1. scripts_sql/cotizaciones/01_cotizaciones_tablas.sql';
PRINT N'  2. scripts_sql/cotizaciones/02_cotizaciones_seed_catalogos.sql';
PRINT N'  3. scripts_sql/cotizaciones/03_cotizaciones_seed_productos.sql';
PRINT N'  4. scripts_sql/cotizaciones/04_cotizaciones_seed_modulo_permisos.sql';
PRINT N'  5. scripts_sql/cotizaciones/05_cotizaciones_sps_parte1.sql';
PRINT N'  6. scripts_sql/cotizaciones/05_cotizaciones_sps_parte2.sql';
PRINT N'';
PRINT N'  (no uses 06_cotizaciones_run_all.sql porque usa :r que no funciona en SSMS)';
PRINT N'====================================================================';
GO
