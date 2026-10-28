/* =====================================================================
 * FIX 03 — Vista jerárquica de divisiones geográficas
 *
 * Crea una vista que devuelve las divisiones con su ruta completa:
 *   País → Departamento → Provincia → Distrito
 *
 * Permite filtrar por nivel y por padre en una sola query, evitando
 * las múltiples consultas recursivas actuales.
 * ===================================================================== */

USE [MonosyncERP];
GO

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.vw_divisiones_jerarquicas', N'V') IS NOT NULL
    DROP VIEW dbo.vw_divisiones_jerarquicas;
GO

CREATE VIEW [dbo].[vw_divisiones_jerarquicas] AS
WITH recursivo AS (
    SELECT
        d.id_division,
        d.nombre,
        d.id_tipo_division,
        d.id_division_padre,
        d.id_pais,
        CAST(d.nombre AS NVARCHAR(500)) AS ruta,
        1 AS nivel,
        d.nombre AS nombre_pais,
        NULL AS nombre_departamento,
        NULL AS nombre_provincia,
        NULL AS nombre_distrito
    FROM dbo.division_geografica d
    WHERE d.id_division_padre IS NULL

    UNION ALL

    SELECT
        d.id_division,
        d.nombre,
        d.id_tipo_division,
        d.id_division_padre,
        d.id_pais,
        CAST(r.ruta + N' > ' + d.nombre AS NVARCHAR(500)),
        r.nivel + 1,
        CASE WHEN d.id_tipo_division = 1 THEN d.nombre ELSE r.nombre_pais END,
        CASE WHEN d.id_tipo_division = 2 THEN d.nombre ELSE r.nombre_departamento END,
        CASE WHEN d.id_tipo_division = 3 THEN d.nombre ELSE r.nombre_provincia END,
        CASE WHEN d.id_tipo_division = 4 THEN d.nombre ELSE r.nombre_distrito END
    FROM dbo.division_geografica d
    INNER JOIN recursivo r ON d.id_division_padre = r.id_division
)
SELECT
    r.id_division,
    r.nombre,
    r.id_tipo_division,
    r.id_division_padre,
    r.id_pais,
    p.nombre AS pais_nombre,
    r.nombre_pais,
    r.nombre_departamento,
    r.nombre_provincia,
    r.nombre_distrito,
    r.ruta,
    r.nivel,
    td.nombre AS tipo_division_nombre
FROM recursivo r
LEFT JOIN dbo.pais p ON r.id_pais = p.id_pais
LEFT JOIN dbo.tipo_division td ON r.id_tipo_division = td.id_tipo_division;
GO

/* SP optimizado para listar divisiones con filtros en cascada */
IF OBJECT_ID(N'dbo.sp_divisiones_filtrar', N'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_divisiones_filtrar;
GO

CREATE PROCEDURE [dbo].[sp_divisiones_filtrar]
    @id_pais          INT = NULL,
    @id_departamento  INT = NULL,
    @id_provincia     INT = NULL,
    @id_distrito      INT = NULL,
    @nivel            INT = NULL,  -- 1=pais, 2=departamento, 3=provincia, 4=distrito
    @id_padre         INT = NULL,
    @busqueda         NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    /* Si se especifica id_padre, devolver hijas directas */
    IF @id_padre IS NOT NULL
    BEGIN
        SELECT d.id_division, d.nombre, d.id_tipo_division,
               d.id_division_padre, d.id_pais,
               td.nombre AS tipo_division_nombre,
               p.nombre AS pais_nombre
        FROM dbo.division_geografica d
        LEFT JOIN dbo.tipo_division td ON d.id_tipo_division = td.id_tipo_division
        LEFT JOIN dbo.pais p ON d.id_pais = p.id_pais
        WHERE d.id_division_padre = @id_padre
        ORDER BY d.nombre;
        RETURN;
    END;

    /* Si se especifica un distrito concreto, devolverlo con su ruta */
    IF @id_distrito IS NOT NULL
    BEGIN
        SELECT * FROM dbo.vw_divisiones_jerarquicas
        WHERE id_division = @id_distrito;
        RETURN;
    END;

    /* Filtros por jerarquía usando la vista recursiva */
    SELECT
        id_division,
        nombre,
        id_tipo_division,
        id_division_padre,
        id_pais,
        pais_nombre,
        nombre_pais,
        nombre_departamento,
        nombre_provincia,
        nombre_distrito,
        ruta,
        nivel,
        tipo_division_nombre
    FROM dbo.vw_divisiones_jerarquicas
    WHERE (@id_pais IS NULL OR id_pais = @id_pais)
      AND (@nivel IS NULL OR nivel = @nivel)
      AND (@id_departamento IS NULL
           OR nombre_departamento IS NOT NULL
              AND id_division = @id_departamento)
      AND (@busqueda IS NULL OR nombre LIKE N'%' + @busqueda + N'%')
    ORDER BY ruta;
END
GO

PRINT N'FIX 03 OK: vw_divisiones_jerarquicas + sp_divisiones_filtrar';
GO
