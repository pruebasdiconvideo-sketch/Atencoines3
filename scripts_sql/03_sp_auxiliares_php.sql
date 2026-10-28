/* ============================================================================
 *  Parche SQL - SP auxiliares referenciados en el codigo PHP v2.0
 *  Fecha: 2026-10-17
 * ========================================================================== */
USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

/* SP: obtener rol principal de una persona */
IF OBJECT_ID('dbo.sp_persona_rol_get', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_persona_rol_get AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_persona_rol_get]
    @id_persona INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP 1 pra.id_rol, pr.nombre AS rol_nombre
    FROM dbo.persona_rol_asignacion pra WITH (NOLOCK)
    INNER JOIN dbo.persona_rol pr ON pra.id_rol = pr.id_rol
    WHERE pra.id_persona = @id_persona
    ORDER BY pra.fecha_asignacion DESC;
END
GO

/* SP: obtener usuario actual (datos de sesion) */
IF OBJECT_ID('dbo.sp_usuario_actual', 'P') IS NULL
BEGIN
    EXEC('CREATE PROCEDURE dbo.sp_usuario_actual AS BEGIN SELECT 1 AS dummy; END');
END
GO
ALTER PROCEDURE [dbo].[sp_usuario_actual]
    @id_persona INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT p.id_persona, p.correo, p.nombres, p.apellido_paterno,
           pra.id_rol, pr.nombre AS rol,
           DATEDIFF(MINUTE, 0, GETUTCDATE()) AS utc_now_minute
    FROM dbo.persona p WITH (NOLOCK)
    LEFT JOIN dbo.persona_rol_asignacion pra WITH (NOLOCK) ON p.id_persona = pra.id_persona
    LEFT JOIN dbo.persona_rol pr WITH (NOLOCK) ON pra.id_rol = pr.id_rol
    WHERE p.id_persona = @id_persona AND p.activo = 1;
END
GO

PRINT 'Parche SP auxiliares completado.';
GO
