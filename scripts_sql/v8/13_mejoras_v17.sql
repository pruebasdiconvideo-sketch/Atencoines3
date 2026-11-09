/*============================================================================
 *  SCRIPT 13 — MEJORAS V17
 *  ---------------------------------------------------------------------------
 *  1. SP sp_cotizacion_cuenta_bancaria_delete_v2: soft-delete (desactiva
 *     en vez de bloquear). Permite "eliminar" cuentas que ya están en
 *     cotizaciones, simplemente las marca como activo=0.
 *  2. SP sp_auditoria_listar_por_usuario: lista acciones de un usuario
 *     específico con filtros de fecha y módulo.
 *  3. Vista vw_auditoria_usuario: vista para reporte de acciones por usuario.
 *==========================================================================*/

USE [MonosyncERP];
GO
SET NOCOUNT ON;
PRINT '======================================================================';
PRINT '  SCRIPT 13 - MEJORAS V17';
PRINT '======================================================================';

/*------------------------------------------------------------------------
 * 1. SP para eliminar cuenta bancaria (soft-delete)
 *----------------------------------------------------------------------*/
PRINT '';
PRINT '  -> Creando sp_cotizacion_cuenta_bancaria_delete_v2 (soft-delete)...';
GO

CREATE OR ALTER PROCEDURE dbo.sp_cotizacion_cuenta_bancaria_delete_v2
    @id_cuenta INT
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_cuenta_bancaria WHERE id_cuenta = @id_cuenta)
        THROW 51117, N'Cuenta bancaria no encontrada.', 1;

    -- Soft-delete: marcar como inactiva en vez de eliminar fisicamente
    UPDATE dbo.cotizacion_cuenta_bancaria
    SET activo = 0
    WHERE id_cuenta = @id_cuenta;

    SELECT 1 AS success, @id_cuenta AS id_cuenta,
           N'Cuenta desactivada correctamente. Las cotizaciones existentes conservan sus datos.' AS mensaje;
END;
GO

/*------------------------------------------------------------------------
 * 2. SP para listar auditoría por usuario
 *----------------------------------------------------------------------*/
PRINT '  -> Creando sp_auditoria_listar_por_usuario...';
GO

CREATE OR ALTER PROCEDURE dbo.sp_auditoria_listar_por_usuario
    @id_usuario      INT = NULL,
    @correo_usuario  NVARCHAR(100) = NULL,
    @modulo          NVARCHAR(50) = NULL,
    @fecha_desde     DATETIME = NULL,
    @fecha_hasta     DATETIME = NULL,
    @limite          INT = 500
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (@limite)
        a.id_auditoria_accion,
        a.id_usuario,
        a.correo_usuario,
        a.modulo,
        a.accion,
        a.descripcion,
        a.tabla_afectada,
        a.pk_registro,
        a.ip_address,
        a.hostname,
        a.datos_adicionales,
        a.resultado,
        CONVERT(VARCHAR(19), a.fecha_hora, 120) AS fecha_hora,
        p.nombres + ' ' + p.apellido_paterno AS nombre_completo
    FROM dbo.auditoria_accion a WITH (NOLOCK)
    LEFT JOIN dbo.persona p WITH (NOLOCK) ON a.id_usuario = p.id_persona
    WHERE (@id_usuario IS NULL OR a.id_usuario = @id_usuario)
      AND (@correo_usuario IS NULL OR a.correo_usuario LIKE '%' + @correo_usuario + '%')
      AND (@modulo IS NULL OR a.modulo = @modulo)
      AND (@fecha_desde IS NULL OR a.fecha_hora >= @fecha_desde)
      AND (@fecha_hasta IS NULL OR a.fecha_hora <= @fecha_hasta)
    ORDER BY a.fecha_hora DESC;
END;
GO

/*------------------------------------------------------------------------
 * 3. Vista para reporte de auditoría por usuario
 *----------------------------------------------------------------------*/
PRINT '  -> Creando vista vw_auditoria_usuario...';
GO

IF OBJECT_ID('dbo.vw_auditoria_usuario','V') IS NOT NULL
    DROP VIEW dbo.vw_auditoria_usuario;
GO

CREATE VIEW dbo.vw_auditoria_usuario AS
SELECT
    a.id_auditoria_accion,
    a.id_usuario,
    a.correo_usuario,
    p.nombres + ' ' + p.apellido_paterno AS nombre_completo,
    p.numero_documento,
    a.modulo,
    a.accion,
    a.descripcion,
    a.tabla_afectada,
    a.pk_registro,
    a.ip_address,
    a.hostname,
    a.resultado,
    a.fecha_hora,
    CONVERT(VARCHAR(10), a.fecha_hora, 23) AS fecha,
    CONVERT(VARCHAR(8), a.fecha_hora, 108) AS hora
FROM dbo.auditoria_accion a WITH (NOLOCK)
LEFT JOIN dbo.persona p WITH (NOLOCK) ON a.id_usuario = p.id_persona;
GO

/*------------------------------------------------------------------------
 * 4. Verificación
 *----------------------------------------------------------------------*/
PRINT '';
PRINT '--- Verificación ---';
IF OBJECT_ID('dbo.sp_cotizacion_cuenta_bancaria_delete_v2','P') IS NOT NULL
    PRINT '  [OK] sp_cotizacion_cuenta_bancaria_delete_v2 creado';
IF OBJECT_ID('dbo.sp_auditoria_listar_por_usuario','P') IS NOT NULL
    PRINT '  [OK] sp_auditoria_listar_por_usuario creado';
IF OBJECT_ID('dbo.vw_auditoria_usuario','V') IS NOT NULL
    PRINT '  [OK] vw_auditoria_usuario creada';

PRINT '';
PRINT '  Fecha fin: ' + CONVERT(VARCHAR(19), SYSDATETIME(), 120);
PRINT '======================================================================';
GO
