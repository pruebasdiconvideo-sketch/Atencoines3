/* ============================================================================
 *  11_cotizaciones_fix_sp_list_paged.sql
 *  ----------------------------------------------------------------------------
 *  Recrea sp_cotizacion_list_paged con los JOINs corregidos (id_plazo, id_cuenta,
 *  id_producto en lugar de id_plazo_entrega, id_cuenta_bancaria, id_producto_catalogo).
 *
 *  Este SP es el que muestra el LISTADO de cotizaciones. Si tiene un bug de
 *  "Invalid column name", el listado devuelve 500 y no se ven las cotizaciones.
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.sp_cotizacion_list_paged','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_list_paged;
GO

CREATE PROCEDURE dbo.sp_cotizacion_list_paged
    @estado              NVARCHAR(20)  = NULL,
    @id_empresa         INT           = NULL,
    @id_tipo             INT           = NULL,
    @id_usuario_creador  INT           = NULL,
    @id_vendedor         INT           = NULL,
    @fecha_desde         DATE          = NULL,
    @fecha_hasta         DATE          = NULL,
    @search_term         NVARCHAR(255) = NULL,
    @incluir_eliminados  BIT           = 0,
    @anio                INT           = NULL,
    @page                INT           = 1,
    @per_page            INT           = 50
AS
BEGIN
    SET NOCOUNT ON;

    IF @page IS NULL OR @page < 1 SET @page = 1;
    IF @per_page IS NULL OR @per_page < 1 OR @per_page > 200 SET @per_page = 50;
    DECLARE @offset INT = (@page - 1) * @per_page;

    -- Result set 1: filas de la página actual
    SELECT
        c.id_cotizacion,
        c.codigo,
        c.version_numero,
        c.estado,
        CONVERT(VARCHAR(10), c.fecha_creacion, 23) AS fecha_creacion,
        c.fecha_envio,
        c.fecha_aprobacion,
        c.fecha_cancelacion,
        c.fecha_cierre,
        c.fecha_proximo_seguimiento,
        c.titulo_documento,
        c.total,
        c.moneda,
        c.id_empresa,
        c.id_tipo,
        c.id_vendedor,
        c.id_usuario_creador,
        e.razon_social AS empresa_razon_social,
        e.nombre_comercial AS empresa_nombre_comercial,
        e.ruc AS empresa_ruc,
        tc.codigo AS tipo_codigo,
        tc.nombre AS tipo_nombre,
        p.nombres + N' ' + p.apellido_paterno AS vendedor_nombre,
        pc.nombres + N' ' + pc.apellido_paterno AS creador_nombre,
        (SELECT COUNT(*) FROM dbo.cotizacion_comunicacion cc WHERE cc.id_cotizacion = c.id_cotizacion) AS total_comunicaciones
    FROM dbo.cotizacion c
    INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
    INNER JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
    LEFT  JOIN dbo.persona p ON c.id_vendedor = p.id_persona
    LEFT  JOIN dbo.persona pc ON c.id_usuario_creador = pc.id_persona
    WHERE 1=1
      AND (@incluir_eliminados = 1 OR ISNULL(c.eliminado, 0) = 0)
      AND (@estado IS NULL OR c.estado = @estado)
      AND (@id_empresa IS NULL OR c.id_empresa = @id_empresa)
      AND (@id_tipo IS NULL OR c.id_tipo = @id_tipo)
      AND (@id_usuario_creador IS NULL OR c.id_usuario_creador = @id_usuario_creador)
      AND (@id_vendedor IS NULL OR c.id_vendedor = @id_vendedor)
      AND (@fecha_desde IS NULL OR CAST(c.fecha_creacion AS DATE) >= @fecha_desde)
      AND (@fecha_hasta IS NULL OR CAST(c.fecha_creacion AS DATE) <= @fecha_hasta)
      AND (@anio IS NULL OR YEAR(c.fecha_creacion) = @anio)
      AND (@search_term IS NULL
           OR c.codigo LIKE '%' + @search_term + '%'
           OR c.motivo LIKE '%' + @search_term + '%'
           OR c.titulo_documento LIKE '%' + @search_term + '%'
           OR e.razon_social LIKE '%' + @search_term + '%'
           OR e.nombre_comercial LIKE '%' + @search_term + '%'
           OR e.ruc LIKE '%' + @search_term + '%')
    ORDER BY c.id_cotizacion DESC
    OFFSET @offset ROWS FETCH NEXT @per_page ROWS ONLY;

    -- Result set 2: total de filas
    SELECT COUNT(*) AS total
    FROM dbo.cotizacion c
    INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
    WHERE 1=1
      AND (@incluir_eliminados = 1 OR ISNULL(c.eliminado, 0) = 0)
      AND (@estado IS NULL OR c.estado = @estado)
      AND (@id_empresa IS NULL OR c.id_empresa = @id_empresa)
      AND (@id_tipo IS NULL OR c.id_tipo = @id_tipo)
      AND (@id_usuario_creador IS NULL OR c.id_usuario_creador = @id_usuario_creador)
      AND (@id_vendedor IS NULL OR c.id_vendedor = @id_vendedor)
      AND (@fecha_desde IS NULL OR CAST(c.fecha_creacion AS DATE) >= @fecha_desde)
      AND (@fecha_hasta IS NULL OR CAST(c.fecha_creacion AS DATE) <= @fecha_hasta)
      AND (@anio IS NULL OR YEAR(c.fecha_creacion) = @anio)
      AND (@search_term IS NULL
           OR c.codigo LIKE '%' + @search_term + '%'
           OR c.motivo LIKE '%' + @search_term + '%'
           OR c.titulo_documento LIKE '%' + @search_term + '%'
           OR e.razon_social LIKE '%' + @search_term + '%'
           OR e.nombre_comercial LIKE '%' + @search_term + '%'
           OR e.ruc LIKE '%' + @search_term + '%');
END;
GO

PRINT N'[OK] sp_cotizacion_list_paged recreado con JOINs corregidos';
GO

-- Verificar
DECLARE @count INT;
SELECT @count = COUNT(*) FROM dbo.cotizacion;
PRINT N'[INFO] Cotizaciones en la base de datos: ' + CAST(@count AS NVARCHAR(10));
GO
