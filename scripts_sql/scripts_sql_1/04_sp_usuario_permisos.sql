/* =====================================================================
 * FIX 02 — SPs y funciones para permisos por usuario
 *
 * Crea:
 *   - dbo.sp_usuario_permisos_get   : matriz completa para el frontend
 *   - dbo.fn_usuario_tiene_permiso  : chequeo puntual para backend
 *   - dbo.vw_usuario_permisos       : vista consolidada para reportes
 *
 * Compatible con el esquema existente (role_permissions, modulo_catalogo,
 * persona_rol_asignacion). No duplica el endpoint /auth/permissions sino
 * que le da una base de datos sólida para escalar.
 * ===================================================================== */

USE [MonosyncERP];
GO

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

/* ------------------------------------------------------------------ */
/* SP: sp_usuario_permisos_get                                        */
/*   @id_persona INT                                                  */
/*   Result set 1: usuario + rol                                      */
/*   Result set 2: permisos por módulo (una fila por módulo activo)   */
/* ------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.sp_usuario_permisos_get', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_usuario_permisos_get;
GO

CREATE PROCEDURE [dbo].[sp_usuario_permisos_get]
    @id_persona INT
AS
BEGIN
    SET NOCOUNT ON;

    /* Result set 1: datos del usuario + su rol principal */
    SELECT TOP 1
        p.id_persona,
        p.correo,
        p.nombres,
        p.apellido_paterno,
        p.activo,
        p.bloqueado,
        pra.id_rol,
        pr.nombre AS rol_nombre
    FROM dbo.persona p
    LEFT JOIN dbo.persona_rol_asignacion pra ON p.id_persona = pra.id_persona
    LEFT JOIN dbo.persona_rol pr             ON pra.id_rol = pr.id_rol
    WHERE p.id_persona = @id_persona
      AND p.activo = 1
    ORDER BY pra.fecha_asignacion DESC;

    /* Result set 2: permisos por módulo.
     * LEFT JOIN contra modulo_catalogo para que SIEMPRE aparezcan todos
     * los módulos activos, incluso si el rol no tiene fila en
     * role_permissions (en cuyo caso todos los flags quedan en 0). */
    SELECT
        mc.id_modulo,
        mc.codigo        AS modulo_codigo,
        mc.nombre        AS modulo_nombre,
        mc.descripcion   AS modulo_descripcion,
        mc.orden         AS modulo_orden,
        ISNULL(rp.ver,        0) AS puede_ver,
        ISNULL(rp.registrar,  0) AS puede_registrar,
        ISNULL(rp.modificar,  0) AS puede_modificar,
        ISNULL(rp.eliminar,   0) AS puede_eliminar,
        ISNULL(rp.aprobar,    0) AS puede_aprobar,
        ISNULL(rp.exportar,   0) AS puede_exportar,
        ISNULL(rp.imprimir,   0) AS puede_imprimir
    FROM dbo.modulo_catalogo mc
    LEFT JOIN dbo.role_permissions rp
           ON rp.modulo = mc.codigo
          AND rp.id_rol = (
              SELECT TOP 1 pra.id_rol
              FROM dbo.persona_rol_asignacion pra
              WHERE pra.id_persona = @id_persona
              ORDER BY pra.fecha_asignacion DESC
          )
    WHERE mc.activo = 1
    ORDER BY mc.orden, mc.codigo;
END
GO

/* ------------------------------------------------------------------ */
/* FN: fn_usuario_tiene_permiso                                       */
/*   @id_persona INT, @modulo NVARCHAR(50), @accion NVARCHAR(20)      */
/*   Retorna BIT. Admin (rol id 1) tiene bypass total.                */
/*   Útil para validaciones puntuales en SPs o en endpoints REST.     */
/* ------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.fn_usuario_tiene_permiso', N'FN') IS NOT NULL
    DROP FUNCTION dbo.fn_usuario_tiene_permiso;
GO

CREATE FUNCTION [dbo].[fn_usuario_tiene_permiso]
    (@id_persona INT, @modulo NVARCHAR(50), @accion NVARCHAR(20))
RETURNS BIT
AS
BEGIN
    DECLARE @ok BIT = 0;

    /* Admin global (rol id 1 por convención) tiene acceso total */
    IF EXISTS (SELECT 1 FROM dbo.persona_rol_asignacion pra
               WHERE pra.id_persona = @id_persona
                 AND pra.id_rol = 1)
        RETURN 1;

    SELECT @ok = CASE @accion
        WHEN 'ver'       THEN rp.ver
        WHEN 'registrar' THEN rp.registrar
        WHEN 'modificar' THEN rp.modificar
        WHEN 'eliminar'  THEN rp.eliminar
        WHEN 'aprobar'   THEN rp.aprobar
        WHEN 'exportar'  THEN rp.exportar
        WHEN 'imprimir'  THEN rp.imprimir
        ELSE 0 END
    FROM dbo.role_permissions rp
    WHERE rp.id_rol = (
        SELECT TOP 1 pra.id_rol
        FROM dbo.persona_rol_asignacion pra
        WHERE pra.id_persona = @id_persona
        ORDER BY pra.fecha_asignacion DESC
    )
    AND rp.modulo = @modulo;

    RETURN ISNULL(@ok, 0);
END
GO

/* ------------------------------------------------------------------ */
/* Vista: vw_usuario_permisos (consolidado para reportes)             */
/* ------------------------------------------------------------------ */
IF OBJECT_ID(N'dbo.vw_usuario_permisos', N'V') IS NOT NULL
    DROP VIEW dbo.vw_usuario_permisos;
GO

CREATE VIEW dbo.vw_usuario_permisos AS
SELECT
    p.id_persona,
    p.correo,
    p.nombres + N' ' + p.apellido_paterno AS usuario_nombre,
    pra.id_rol,
    pr.nombre AS rol_nombre,
    mc.codigo AS modulo_codigo,
    mc.nombre AS modulo_nombre,
    ISNULL(rp.ver,       0) AS puede_ver,
    ISNULL(rp.registrar, 0) AS puede_registrar,
    ISNULL(rp.modificar, 0) AS puede_modificar,
    ISNULL(rp.eliminar,  0) AS puede_eliminar,
    ISNULL(rp.aprobar,   0) AS puede_aprobar,
    ISNULL(rp.exportar,  0) AS puede_exportar,
    ISNULL(rp.imprimir,  0) AS puede_imprimir
FROM dbo.persona p
LEFT JOIN dbo.persona_rol_asignacion pra ON p.id_persona = pra.id_persona
LEFT JOIN dbo.persona_rol pr             ON pra.id_rol = pr.id_rol
CROSS JOIN dbo.modulo_catalogo mc
LEFT JOIN dbo.role_permissions rp
       ON rp.id_rol = pra.id_rol
      AND rp.modulo = mc.codigo
WHERE p.activo = 1
  AND mc.activo = 1;
GO

PRINT N'FIX 02 OK: sp_usuario_permisos_get, fn_usuario_tiene_permiso, vw_usuario_permisos';
GO
