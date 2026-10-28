/* ============================================================================
 *  05_cotizaciones_sps_parte2.sql
 *  ----------------------------------------------------------------------------
 *  Stored Procedures del módulo de cotizaciones — PARTE 2
 *
 *  Contiene:
 *    - sp_cotizacion_get (cabecera + detalle + historial + comunicaciones +
 *      versiones + documentos + cliente + vendedor + emisora)
 *    - sp_cotizacion_list_paged (listado con filtros paginado)
 *    - sp_cotizacion_update_status (cambio de estado con matriz de transiciones)
 *    - sp_cotizacion_aprobar / rechazar / cancelar / cerrar (atajos)
 *    - sp_cotizacion_add_comunicacion (registrar comunicación)
 *    - sp_cotizacion_add_detalle / update_detalle / remove_detalle
 *    - sp_cotizacion_update (cabecera + detalle atómico)
 *    - sp_cotizacion_create_version (versionado .2, .3, ...)
 *    - sp_cotizacion_documento_save / get (adjuntos)
 *    - sp_cotizacion_delete_admin (soft delete)
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* ============================================================================
 * 1) sp_cotizacion_get
 *
 *    Devuelve TODA la información de una cotización en 6 result sets:
 *      1) Cabecera con joins (empresa, contacto, tipo, emisora, vendedor,
 *         condiciones, etc.)
 *      2) Detalle con joins (producto catálogo)
 *      3) Historial de estados con usuario
 *      4) Comunicaciones con usuario
 *      5) Versiones
 *      6) Documentos
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_get','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_get;
GO
CREATE PROCEDURE dbo.sp_cotizacion_get
    @id_cotizacion INT
AS
BEGIN
    SET NOCOUNT ON;

    -- Result set 1: Cabecera
    SELECT
        c.id_cotizacion,
        c.codigo,
        c.version_numero,
        c.estado,
        c.fecha_creacion,
        c.fecha_actualizacion,
        c.fecha_envio,
        c.fecha_aprobacion,
        c.fecha_rechazo,
        c.fecha_cancelacion,
        c.fecha_cierre,
        c.fecha_ultima_comunicacion,
        c.fecha_proximo_seguimiento,
        c.titulo_documento,
        c.motivo,
        c.observaciones,
        c.motivo_rechazo,
        c.motivo_cancelacion,
        c.motivo_cierre,
        c.snapshot_forma_pago,
        c.snapshot_garantia,
        c.snapshot_plazo_entrega,
        c.snapshot_validez,
        c.snapshot_cuenta_bancaria,
        c.subtotal,
        c.descuento_global_pct,
        c.descuento_global_monto,
        c.base_imponible,
        c.igv_pct,
        c.igv_monto,
        c.total,
        c.moneda,
        c.origen,
        c.eliminado,
        -- Joins
        e.razon_social            AS empresa_razon_social,
        e.nombre_comercial        AS empresa_nombre_comercial,
        e.ruc                     AS empresa_ruc,
        e.correo                  AS empresa_correo,
        e.telefono                AS empresa_telefono,
        ec.cargo                  AS contacto_cargo,
        ec.telefono_empresa       AS contacto_telefono,
        ec.correo_empresa         AS contacto_correo,
        pp.nombres                AS contacto_nombres,
        pp.apellido_paterno       AS contacto_apellidos,
        s.nombre                  AS sucursal_nombre,
        tc.codigo                 AS tipo_codigo,
        tc.nombre                 AS tipo_nombre,
        tc.titulo_documento       AS tipo_titulo_default,
        em.razon_social           AS emisora_razon_social,
        em.ruc                    AS emisora_ruc,
        em.direccion              AS emisora_direccion,
        em.telefono               AS emisora_telefono,
        em.correo                 AS emisora_correo,
        em.logo_base64            AS emisora_logo,
        fp.descripcion            AS forma_pago_descripcion,
        fp.descuento_pct          AS forma_pago_descuento_pct,
        ga.descripcion            AS garantia_descripcion,
        pl.descripcion            AS plazo_entrega_descripcion,
        va.descripcion            AS validez_descripcion,
        va.dias                   AS validez_dias,
        cb.banco                  AS cuenta_banco,
        cb.numero_cuenta          AS cuenta_numero,
        cb.cci                    AS cuenta_cci,
        zp.codigo                 AS zona_codigo,
        zp.nombre                 AS zona_nombre,
        uc.nombres + N' ' + uc.apellido_paterno AS usuario_creador_nombre,
        uc.correo                 AS usuario_creador_correo,
        uv.nombres + N' ' + uv.apellido_paterno AS vendedor_nombre,
        uv.correo                 AS vendedor_correo
    FROM dbo.cotizacion c
    INNER JOIN dbo.empresa e          ON c.id_empresa = e.id_empresa
    LEFT  JOIN dbo.empresa_contacto ec ON c.id_empresa_contacto = ec.id_empresa_contacto
    LEFT  JOIN dbo.persona pp          ON ec.id_persona = pp.id_persona
    LEFT  JOIN dbo.empresa_sucursal s  ON c.id_sucursal = s.id_sucursal
    INNER JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
    INNER JOIN dbo.cotizacion_empresa_emisora em ON c.id_emisora = em.id_emisora
    LEFT  JOIN dbo.cotizacion_forma_pago fp ON c.id_forma_pago = fp.id_forma_pago
    LEFT  JOIN dbo.cotizacion_garantia ga ON c.id_garantia = ga.id_garantia
    LEFT  JOIN dbo.cotizacion_plazo_entrega pl ON c.id_plazo_entrega = pl.id_plazo
    LEFT  JOIN dbo.cotizacion_validez va ON c.id_validez = va.id_validez
    LEFT  JOIN dbo.cotizacion_cuenta_bancaria cb ON c.id_cuenta_bancaria = cb.id_cuenta
    LEFT  JOIN dbo.cotizacion_zona_pricing zp ON c.id_zona_pricing = zp.id_zona
    LEFT  JOIN dbo.persona uc          ON c.id_usuario_creador = uc.id_persona
    LEFT  JOIN dbo.persona uv          ON c.id_vendedor = uv.id_persona
    WHERE c.id_cotizacion = @id_cotizacion;

    -- Result set 2: Detalle
    SELECT
        d.id_detalle,
        d.orden,
        d.id_producto_catalogo,
        d.codigo,
        d.descripcion,
        d.cantidad,
        d.unidad_medida,
        d.precio_unitario,
        d.descuento_pct,
        d.descuento_monto,
        d.subtotal,
        d.afecto_igv,
        d.id_zona_pricing,
        p.id_tipo_producto,
        p.aplica_precio_zona,
        zp.codigo AS zona_codigo,
        zp.nombre AS zona_nombre
    FROM dbo.cotizacion_detalle d
    LEFT JOIN dbo.cotizacion_producto_catalogo p ON d.id_producto_catalogo = p.id_producto
    LEFT JOIN dbo.cotizacion_zona_pricing zp     ON d.id_zona_pricing = zp.id_zona
    WHERE d.id_cotizacion = @id_cotizacion
    ORDER BY d.orden;

    -- Result set 3: Historial de estados
    SELECT
        h.id_historial,
        h.estado_anterior,
        h.estado_nuevo,
        h.motivo,
        h.tipo_evento,
        h.origen_evento,
        h.datos_adicionales,
        h.fecha_cambio,
        p.nombres + N' ' + p.apellido_paterno AS usuario_nombre,
        p.correo AS usuario_correo
    FROM dbo.cotizacion_historial_estado h
    LEFT JOIN dbo.persona p ON h.id_usuario = p.id_persona
    WHERE h.id_cotizacion = @id_cotizacion
    ORDER BY h.fecha_cambio DESC;

    -- Result set 4: Comunicaciones
    SELECT
        co.id_comunicacion,
        co.fecha_registro,
        co.fecha_comunicacion,
        co.medio,
        co.tipo_comunicacion,
        co.descripcion,
        co.respuesta_cliente,
        co.resultado_compromiso,
        co.fecha_proximo_seguimiento,
        co.observaciones,
        p.nombres + N' ' + p.apellido_paterno AS usuario_nombre,
        p.correo AS usuario_correo
    FROM dbo.cotizacion_comunicacion co
    LEFT JOIN dbo.persona p ON co.id_usuario = p.id_persona
    WHERE co.id_cotizacion = @id_cotizacion
    ORDER BY co.fecha_registro DESC;

    -- Result set 5: Versiones
    SELECT
        v.id_version,
        v.version_numero,
        v.codigo_versionado,
        v.motivo,
        v.fecha_version,
        p.nombres + N' ' + p.apellido_paterno AS usuario_nombre,
        p.correo AS usuario_correo
    FROM dbo.cotizacion_version v
    LEFT JOIN dbo.persona p ON v.id_usuario = p.id_persona
    WHERE v.id_cotizacion = @id_cotizacion
    ORDER BY v.version_numero DESC;

    -- Result set 6: Documentos
    SELECT
        d.id_documento,
        d.nombre_original,
        d.archivo_key,
        d.tipo_mime,
        d.tamano_bytes,
        d.tipo_documento,
        d.fecha_registro,
        d.id_comunicacion,
        p.nombres + N' ' + p.apellido_paterno AS usuario_nombre
    FROM dbo.cotizacion_documento d
    LEFT JOIN dbo.persona p ON d.id_usuario = p.id_persona
    WHERE d.id_cotizacion = @id_cotizacion
    ORDER BY d.fecha_registro DESC;
END;
GO

/* ============================================================================
 * 2) sp_cotizacion_list_paged
 *
 *    Listado de cotizaciones con filtros y paginación (OFFSET/FETCH).
 *    Devuelve 2 result sets: filas de la página + total.
 * ============================================================================
 */
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

    DECLARE @sql NVARCHAR(MAX) = N'
        SELECT c.id_cotizacion,
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
               p.nombres + N'' '' + p.apellido_paterno AS vendedor_nombre,
               pc.nombres + N'' '' + pc.apellido_paterno AS creador_nombre,
               (SELECT COUNT(*) FROM dbo.cotizacion_comunicacion cc WHERE cc.id_cotizacion = c.id_cotizacion) AS total_comunicaciones
        FROM dbo.cotizacion c
        INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
        INNER JOIN dbo.cotizacion_tipo_catalogo tc ON c.id_tipo = tc.id_tipo
        LEFT  JOIN dbo.persona p ON c.id_vendedor = p.id_persona
        LEFT  JOIN dbo.persona pc ON c.id_usuario_creador = pc.id_persona
        WHERE 1=1';

    IF @incluir_eliminados = 0
        SET @sql += N' AND c.eliminado = 0';
    IF @estado IS NOT NULL
        SET @sql += N' AND c.estado = @estado';
    IF @id_empresa IS NOT NULL
        SET @sql += N' AND c.id_empresa = @id_empresa';
    IF @id_tipo IS NOT NULL
        SET @sql += N' AND c.id_tipo = @id_tipo';
    IF @id_usuario_creador IS NOT NULL
        SET @sql += N' AND c.id_usuario_creador = @id_usuario_creador';
    IF @id_vendedor IS NOT NULL
        SET @sql += N' AND c.id_vendedor = @id_vendedor';
    IF @fecha_desde IS NOT NULL
        SET @sql += N' AND CAST(c.fecha_creacion AS DATE) >= @fecha_desde';
    IF @fecha_hasta IS NOT NULL
        SET @sql += N' AND CAST(c.fecha_creacion AS DATE) <= @fecha_hasta';
    IF @anio IS NOT NULL
        SET @sql += N' AND YEAR(c.fecha_creacion) = @anio';
    IF @search_term IS NOT NULL
        SET @sql += N' AND (c.codigo LIKE ''%'' + @search_term + ''%''
                         OR c.motivo LIKE ''%'' + @search_term + ''%''
                         OR e.razon_social LIKE ''%'' + @search_term + ''%''
                         OR e.nombre_comercial LIKE ''%'' + @search_term + ''%''
                         OR e.ruc LIKE ''%'' + @search_term + ''%'')';

    SET @sql += N' ORDER BY c.id_cotizacion DESC
                  OFFSET @offset ROWS FETCH NEXT @per_page ROWS ONLY;';

    DECLARE @params NVARCHAR(MAX) = N'
        @estado NVARCHAR(20), @id_empresa INT, @id_tipo INT,
        @id_usuario_creador INT, @id_vendedor INT,
        @fecha_desde DATE, @fecha_hasta DATE,
        @search_term NVARCHAR(255), @anio INT,
        @offset INT, @per_page INT';

    EXEC sp_executesql @sql, @params,
        @estado=@estado, @id_empresa=@id_empresa, @id_tipo=@id_tipo,
        @id_usuario_creador=@id_usuario_creador, @id_vendedor=@id_vendedor,
        @fecha_desde=@fecha_desde, @fecha_hasta=@fecha_hasta,
        @search_term=@search_term, @anio=@anio,
        @offset=@offset, @per_page=@per_page;

    -- Result set 2: total
    DECLARE @sqlCount NVARCHAR(MAX) = N'
        SELECT COUNT(*) AS total
        FROM dbo.cotizacion c
        INNER JOIN dbo.empresa e ON c.id_empresa = e.id_empresa
        WHERE 1=1';

    IF @incluir_eliminados = 0
        SET @sqlCount += N' AND c.eliminado = 0';
    IF @estado IS NOT NULL
        SET @sqlCount += N' AND c.estado = @estado';
    IF @id_empresa IS NOT NULL
        SET @sqlCount += N' AND c.id_empresa = @id_empresa';
    IF @id_tipo IS NOT NULL
        SET @sqlCount += N' AND c.id_tipo = @id_tipo';
    IF @id_usuario_creador IS NOT NULL
        SET @sqlCount += N' AND c.id_usuario_creador = @id_usuario_creador';
    IF @id_vendedor IS NOT NULL
        SET @sqlCount += N' AND c.id_vendedor = @id_vendedor';
    IF @fecha_desde IS NOT NULL
        SET @sqlCount += N' AND CAST(c.fecha_creacion AS DATE) >= @fecha_desde';
    IF @fecha_hasta IS NOT NULL
        SET @sqlCount += N' AND CAST(c.fecha_creacion AS DATE) <= @fecha_hasta';
    IF @anio IS NOT NULL
        SET @sqlCount += N' AND YEAR(c.fecha_creacion) = @anio';
    IF @search_term IS NOT NULL
        SET @sqlCount += N' AND (c.codigo LIKE ''%'' + @search_term + ''%''
                              OR c.motivo LIKE ''%'' + @search_term + ''%''
                              OR e.razon_social LIKE ''%'' + @search_term + ''%''
                              OR e.nombre_comercial LIKE ''%'' + @search_term + ''%''
                              OR e.ruc LIKE ''%'' + @search_term + ''%'')';

    DECLARE @paramsCount NVARCHAR(MAX) = N'
        @estado NVARCHAR(20), @id_empresa INT, @id_tipo INT,
        @id_usuario_creador INT, @id_vendedor INT,
        @fecha_desde DATE, @fecha_hasta DATE,
        @search_term NVARCHAR(255), @anio INT';

    EXEC sp_executesql @sqlCount, @paramsCount,
        @estado=@estado, @id_empresa=@id_empresa, @id_tipo=@id_tipo,
        @id_usuario_creador=@id_usuario_creador, @id_vendedor=@id_vendedor,
        @fecha_desde=@fecha_desde, @fecha_hasta=@fecha_hasta,
        @search_term=@search_term, @anio=@anio;
END;
GO

/* ============================================================================
 * 3) sp_cotizacion_update_status
 *
 *    Cambia el estado de una cotización validando transiciones permitidas.
 *
 *    Matriz de transiciones:
 *      BORRADOR         -> PENDIENTE_ENVIO, CANCELADA
 *      PENDIENTE_ENVIO  -> ENVIADA, CANCELADA
 *      ENVIADA          -> EN_SEGUIMIENTO, EN_NEGOCIACION, APROBADA, RECHAZADA, CANCELADA
 *      EN_SEGUIMIENTO   -> EN_NEGOCIACION, APROBADA, RECHAZADA, CANCELADA, ENVIADA
 *      EN_NEGOCIACION   -> APROBADA, RECHAZADA, CANCELADA, EN_SEGUIMIENTO
 *      APROBADA         -> CERRADA, CANCELADA
 *      RECHAZADA        -> CERRADA, PENDIENTE_ENVIO (revive)
 *      CANCELADA        -> PENDIENTE_ENVIO (revive con motivo)
 *      CERRADA          -> PENDIENTE_ENVIO (reabrir con motivo)
 *
 *    Estados terminales: CERRADA, RECHAZADA (si usuario revierte)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_update_status','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_update_status;
GO
CREATE PROCEDURE dbo.sp_cotizacion_update_status
    @id_cotizacion INT,
    @nuevo_estado  NVARCHAR(20),
    @id_usuario    INT,
    @motivo        NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @estado_anterior NVARCHAR(20);
        DECLARE @ahora DATETIME2(0) = SYSDATETIME();

        SELECT @estado_anterior = estado
        FROM dbo.cotizacion
        WHERE id_cotizacion = @id_cotizacion;

        IF @estado_anterior IS NULL
            THROW 51020, N'Cotización no encontrada.', 1;

        -- Matriz de transiciones permitidas
        DECLARE @transicion_valida BIT = 0;

        IF @estado_anterior = 'BORRADOR' AND @nuevo_estado IN ('PENDIENTE_ENVIO','CANCELADA') SET @transicion_valida = 1;
        IF @estado_anterior = 'PENDIENTE_ENVIO' AND @nuevo_estado IN ('ENVIADA','CANCELADA','BORRADOR') SET @transicion_valida = 1;
        IF @estado_anterior = 'ENVIADA' AND @nuevo_estado IN ('EN_SEGUIMIENTO','EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA') SET @transicion_valida = 1;
        IF @estado_anterior = 'EN_SEGUIMIENTO' AND @nuevo_estado IN ('EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA','ENVIADA') SET @transicion_valida = 1;
        IF @estado_anterior = 'EN_NEGOCIACION' AND @nuevo_estado IN ('APROBADA','RECHAZADA','CANCELADA','EN_SEGUIMIENTO') SET @transicion_valida = 1;
        IF @estado_anterior = 'APROBADA' AND @nuevo_estado IN ('CERRADA','CANCELADA') SET @transicion_valida = 1;
        IF @estado_anterior = 'RECHAZADA' AND @nuevo_estado IN ('CERRADA','PENDIENTE_ENVIO') SET @transicion_valida = 1;
        IF @estado_anterior = 'CANCELADA' AND @nuevo_estado IN ('PENDIENTE_ENVIO','BORRADOR') SET @transicion_valida = 1;
        IF @estado_anterior = 'CERRADA' AND @nuevo_estado IN ('PENDIENTE_ENVIO') SET @transicion_valida = 1;

        -- Misma transición (sin cambio real) también es válida para registrar
        -- observaciones o reenvíos
        IF @estado_anterior = @nuevo_estado SET @transicion_valida = 1;

        IF @transicion_valida = 0
        BEGIN
            DECLARE @msg_err NVARCHAR(500) =
                N'Transición no permitida: ' + ISNULL(@estado_anterior, '?') +
                N' -> ' + ISNULL(@nuevo_estado, '?');
            THROW 51021, @msg_err, 1;
        END

        -- Determinar tipo de evento según destino
        DECLARE @tipo_evento NVARCHAR(30) = 'CAMBIO_ESTADO';
        IF @nuevo_estado = 'ENVIADA' SET @tipo_evento = 'ENVIO';
        ELSE IF @nuevo_estado = 'APROBADA' SET @tipo_evento = 'APROBACION';
        ELSE IF @nuevo_estado = 'RECHAZADA' SET @tipo_evento = 'RECHAZO';
        ELSE IF @nuevo_estado = 'CANCELADA' SET @tipo_evento = 'CANCELACION';
        ELSE IF @nuevo_estado = 'CERRADA' SET @tipo_evento = 'CIERRE';

        -- Actualizar cabecera (campos según estado destino)
        UPDATE dbo.cotizacion
        SET
            estado = @nuevo_estado,
            id_usuario_actualizo = @id_usuario,
            fecha_actualizacion = @ahora,
            -- Campos específicos por estado:
            fecha_envio       = CASE WHEN @nuevo_estado = 'ENVIADA' THEN @ahora ELSE fecha_envio END,
            fecha_aprobacion  = CASE WHEN @nuevo_estado = 'APROBADA' THEN @ahora ELSE fecha_aprobacion END,
            id_usuario_aprobo = CASE WHEN @nuevo_estado = 'APROBADA' THEN @id_usuario ELSE id_usuario_aprobo END,
            fecha_rechazo     = CASE WHEN @nuevo_estado = 'RECHAZADA' THEN @ahora ELSE fecha_rechazo END,
            id_usuario_rechazo = CASE WHEN @nuevo_estado = 'RECHAZADA' THEN @id_usuario ELSE id_usuario_rechazo END,
            motivo_rechazo    = CASE WHEN @nuevo_estado = 'RECHAZADA' THEN ISNULL(@motivo, motivo_rechazo) ELSE motivo_rechazo END,
            fecha_cancelacion = CASE WHEN @nuevo_estado = 'CANCELADA' THEN @ahora ELSE fecha_cancelacion END,
            id_usuario_cancelo = CASE WHEN @nuevo_estado = 'CANCELADA' THEN @id_usuario ELSE id_usuario_cancelo END,
            motivo_cancelacion = CASE WHEN @nuevo_estado = 'CANCELADA' THEN ISNULL(@motivo, motivo_cancelacion) ELSE motivo_cancelacion END,
            fecha_cierre      = CASE WHEN @nuevo_estado = 'CERRADA' THEN @ahora ELSE fecha_cierre END,
            id_usuario_cerro  = CASE WHEN @nuevo_estado = 'CERRADA' THEN @id_usuario ELSE id_usuario_cerro END,
            motivo_cierre     = CASE WHEN @nuevo_estado = 'CERRADA' THEN ISNULL(@motivo, motivo_cierre) ELSE motivo_cierre END
        WHERE id_cotizacion = @id_cotizacion;

        -- Registrar en historial
        INSERT INTO dbo.cotizacion_historial_estado (
            id_cotizacion, estado_anterior, estado_nuevo,
            id_usuario, motivo, tipo_evento, origen_evento, fecha_cambio
        ) VALUES (
            @id_cotizacion, @estado_anterior, @nuevo_estado,
            @id_usuario, ISNULL(@motivo, N'Transición: ' + @estado_anterior + N' → ' + @nuevo_estado),
            @tipo_evento, 'MANUAL', @ahora
        );

        -- Auditoría general
        BEGIN TRY
            DECLARE @correo_user NVARCHAR(100);
            SELECT @correo_user = correo FROM dbo.persona WHERE id_persona = @id_usuario;

            INSERT INTO dbo.auditoria_accion
                (id_usuario, correo_usuario, modulo, accion, descripcion,
                 tabla_afectada, pk_registro, fecha_hora, resultado)
            VALUES
                (@id_usuario, ISNULL(@correo_user, N'sistema'),
                 N'COTIZACIONES', N'CAMBIO_ESTADO',
                 N'Cotización #' + CAST(@id_cotizacion AS NVARCHAR(10)) +
                 N' estado: ' + @estado_anterior + N' → ' + @nuevo_estado +
                 N'. Motivo: ' + ISNULL(@motivo, N'(sin motivo)'),
                 N'cotizacion', CAST(@id_cotizacion AS NVARCHAR(20)),
                 SYSDATETIME(), 'EXITO');
        END TRY
        BEGIN CATCH
            PRINT N'[WARN] Auditoría no registrada: ' + ERROR_MESSAGE();
        END CATCH

        COMMIT TRANSACTION;

        SELECT 1 AS success,
               @estado_anterior AS estado_anterior,
               @nuevo_estado AS estado_nuevo,
               @tipo_evento AS tipo_evento;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO

/* ============================================================================
 * 4) sp_cotizacion_add_comunicacion
 *
 *    Registra una nueva comunicación para una cotización.
 *    NUNCA sobrescribe las anteriores: cada INSERT es un nuevo registro.
 *
 *    Además, actualiza fecha_ultima_comunicacion y fecha_proximo_seguimiento
 *    en la cabecera.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_add_comunicacion','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_add_comunicacion;
GO
CREATE PROCEDURE dbo.sp_cotizacion_add_comunicacion
    @id_cotizacion              INT,
    @id_usuario                 INT,
    @medio                      NVARCHAR(30),
    @tipo_comunicacion          NVARCHAR(30),
    @descripcion                NVARCHAR(MAX),
    @fecha_comunicacion         DATETIME2(0) = NULL,
    @respuesta_cliente          NVARCHAR(MAX) = NULL,
    @resultado_compromiso       NVARCHAR(MAX) = NULL,
    @fecha_proximo_seguimiento  DATE = NULL,
    @observaciones              NVARCHAR(MAX) = NULL,
    @id_comunicacion INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion)
            THROW 51030, N'Cotización no encontrada.', 1;

        IF @fecha_comunicacion IS NULL SET @fecha_comunicacion = SYSDATETIME();

        INSERT INTO dbo.cotizacion_comunicacion (
            id_cotizacion, id_usuario, fecha_registro, fecha_comunicacion,
            medio, tipo_comunicacion, descripcion,
            respuesta_cliente, resultado_compromiso,
            fecha_proximo_seguimiento, observaciones
        ) VALUES (
            @id_cotizacion, @id_usuario, SYSDATETIME(), @fecha_comunicacion,
            @medio, @tipo_comunicacion, @descripcion,
            @respuesta_cliente, @resultado_compromiso,
            @fecha_proximo_seguimiento, @observaciones
        );

        SET @id_comunicacion = SCOPE_IDENTITY();

        -- Actualizar cabecera con la última comunicación y próximo seguimiento
        UPDATE dbo.cotizacion
        SET fecha_ultima_comunicacion = SYSDATETIME(),
            fecha_proximo_seguimiento = ISNULL(@fecha_proximo_seguimiento, fecha_proximo_seguimiento),
            fecha_actualizacion = SYSDATETIME(),
            id_usuario_actualizo = @id_usuario
        WHERE id_cotizacion = @id_cotizacion;

        -- Si la comunicación es de aprobación/rechazo/cierre, no cambiamos estado aquí
        -- (eso lo hace sp_cotizacion_update_status). Pero sí registramos la
        -- comunicación como evento en el historial.
        INSERT INTO dbo.cotizacion_historial_estado (
            id_cotizacion, estado_anterior, estado_nuevo,
            id_usuario, motivo, tipo_evento, origen_evento, fecha_cambio
        )
        SELECT id_cotizacion, estado, estado, @id_usuario,
               N'Comunicación registrada: ' + @tipo_comunicacion + N' (' + @medio + N')',
               'COMUNICACION', 'MANUAL', SYSDATETIME()
        FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion;

        COMMIT TRANSACTION;
        SELECT @id_comunicacion AS id_comunicacion;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO

/* ============================================================================
 * 5) sp_cotizacion_update (cabecera + detalle atómico)
 *
 *    Solo permite editar cotizaciones en BORRADOR o PENDIENTE_ENVIO.
 *    Para cotizaciones ENVIADAS o superiores, usar sp_cotizacion_create_version.
 *
 *    Recibe el detalle_json COMPLETO: reemplaza todos los ítems existentes.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_update','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_update;
GO
CREATE PROCEDURE dbo.sp_cotizacion_update
    @id_cotizacion           INT,
    @id_usuario              INT,
    @id_empresa_contacto     INT = NULL,
    @id_sucursal             INT = NULL,
    @id_tipo                 INT = NULL,
    @id_forma_pago           INT = NULL,
    @id_garantia             INT = NULL,
    @id_plazo_entrega        INT = NULL,
    @id_validez              INT = NULL,
    @id_cuenta_bancaria      INT = NULL,
    @id_zona_pricing         INT = NULL,
    @id_vendedor             INT = NULL,
    @titulo_documento        NVARCHAR(200) = NULL,
    @motivo                  NVARCHAR(500) = NULL,
    @observaciones           NVARCHAR(MAX) = NULL,
    @moneda                  NVARCHAR(10) = NULL,
    @igv_pct                 DECIMAL(5,2) = NULL,
    @descuento_global_pct    DECIMAL(5,2) = NULL,
    @detalle_json            NVARCHAR(MAX) = NULL,
    @forzar_version          BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @estado_actual NVARCHAR(20);
        SELECT @estado_actual = estado FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion;

        IF @estado_actual IS NULL
            THROW 51040, N'Cotización no encontrada.', 1;

        -- Si la cotización está enviada o posterior y no se fuerza versión,
        -- bloquear la edición directa.
        IF @estado_actual NOT IN ('BORRADOR','PENDIENTE_ENVIO') AND @forzar_version = 0
            THROW 51041,
                N'La cotización no puede editarse directamente en su estado actual. Use versionado.',
                1;

        -- Si la cotización está enviada/aprobada y se fuerza versión, delegar a
        -- sp_cotizacion_create_version
        IF @estado_actual NOT IN ('BORRADOR','PENDIENTE_ENVIO') AND @forzar_version = 1
        BEGIN
            -- Snapshot del estado actual y crear versión nueva
            DECLARE @v_actual INT;
            SELECT @v_actual = version_numero FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion;

            -- Tomar snapshot del estado actual (cabecera + detalle) en JSON
            DECLARE @snapshot NVARCHAR(MAX);
            DECLARE @codigo_actual NVARCHAR(40);
            SELECT @codigo_actual = codigo FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion;

            SET @snapshot = (
                SELECT
                    (SELECT * FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion FOR JSON PATH, WITHOUT_ARRAY_WRAPPER) AS cabecera,
                    (SELECT * FROM dbo.cotizacion_detalle WHERE id_cotizacion = @id_cotizacion FOR JSON PATH) AS detalle
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER
            );

            INSERT INTO dbo.cotizacion_version (id_cotizacion, version_numero, codigo_versionado, id_usuario, motivo, snapshot_json, fecha_version)
            VALUES (@id_cotizacion, @v_actual, @codigo_actual + N'.' + CAST(@v_actual AS NVARCHAR(5)),
                    @id_usuario, N'Versión antes de edición', @snapshot, SYSDATETIME());

            UPDATE dbo.cotizacion SET version_numero = @v_actual + 1
            WHERE id_cotizacion = @id_cotizacion;
        END

        -- Snapshot de condiciones (si se cambian los catálogos)
        DECLARE @snap_forma_pago    NVARCHAR(500) = NULL;
        DECLARE @snap_garantia      NVARCHAR(500) = NULL;
        DECLARE @snap_plazo         NVARCHAR(500) = NULL;
        DECLARE @snap_validez       NVARCHAR(500) = NULL;
        DECLARE @snap_cuenta        NVARCHAR(MAX) = NULL;

        IF @id_forma_pago IS NOT NULL
            SELECT @snap_forma_pago = descripcion FROM dbo.cotizacion_forma_pago WHERE id_forma_pago = @id_forma_pago;
        IF @id_garantia IS NOT NULL
            SELECT @snap_garantia = descripcion FROM dbo.cotizacion_garantia WHERE id_garantia = @id_garantia;
        IF @id_plazo_entrega IS NOT NULL
            SELECT @snap_plazo = descripcion FROM dbo.cotizacion_plazo_entrega WHERE id_plazo = @id_plazo_entrega;
        IF @id_validez IS NOT NULL
            SELECT @snap_validez = descripcion FROM dbo.cotizacion_validez WHERE id_validez = @id_validez;
        IF @id_cuenta_bancaria IS NOT NULL
            SELECT @snap_cuenta = banco + N' - Cuenta: ' + numero_cuenta
                                 + CASE WHEN cci IS NOT NULL THEN N' - CCI: ' + cci ELSE N'' END
            FROM dbo.cotizacion_cuenta_bancaria WHERE id_cuenta = @id_cuenta_bancaria;

        -- Actualizar cabecera
        UPDATE dbo.cotizacion
        SET
            id_empresa_contacto = ISNULL(@id_empresa_contacto, id_empresa_contacto),
            id_sucursal         = @id_sucursal,
            id_tipo             = ISNULL(@id_tipo, id_tipo),
            id_forma_pago       = @id_forma_pago,
            id_garantia         = @id_garantia,
            id_plazo_entrega    = @id_plazo_entrega,
            id_validez          = @id_validez,
            id_cuenta_bancaria  = @id_cuenta_bancaria,
            id_zona_pricing     = @id_zona_pricing,
            id_vendedor         = @id_vendedor,
            titulo_documento    = ISNULL(@titulo_documento, titulo_documento),
            motivo              = @motivo,
            observaciones       = @observaciones,
            moneda              = ISNULL(@moneda, moneda),
            igv_pct             = ISNULL(@igv_pct, igv_pct),
            descuento_global_pct = ISNULL(@descuento_global_pct, descuento_global_pct),
            snapshot_forma_pago    = ISNULL(@snap_forma_pago, snapshot_forma_pago),
            snapshot_garantia      = ISNULL(@snap_garantia, snapshot_garantia),
            snapshot_plazo_entrega = ISNULL(@snap_plazo, snapshot_plazo_entrega),
            snapshot_validez       = ISNULL(@snap_validez, snapshot_validez),
            snapshot_cuenta_bancaria = ISNULL(@snap_cuenta, snapshot_cuenta_bancaria),
            id_usuario_actualizo = @id_usuario,
            fecha_actualizacion = SYSDATETIME()
        WHERE id_cotizacion = @id_cotizacion;

        -- Reemplazar detalle si viene en JSON
        IF @detalle_json IS NOT NULL AND LEN(@detalle_json) > 2
        BEGIN
            DELETE FROM dbo.cotizacion_detalle WHERE id_cotizacion = @id_cotizacion;

            INSERT INTO dbo.cotizacion_detalle (
                id_cotizacion, orden, id_producto_catalogo, codigo, descripcion,
                cantidad, unidad_medida, precio_unitario, descuento_pct,
                descuento_monto, subtotal, afecto_igv, id_zona_pricing
            )
            SELECT
                @id_cotizacion,
                ROW_NUMBER() OVER (ORDER BY (SELECT 1)),
                TRY_CAST(JSON_VALUE(d.[value], '$.id_producto_catalogo') AS INT),
                JSON_VALUE(d.[value], '$.codigo'),
                JSON_VALUE(d.[value], '$.descripcion'),
                ISNULL(TRY_CAST(JSON_VALUE(d.[value], '$.cantidad') AS DECIMAL(10,2)), 1),
                ISNULL(JSON_VALUE(d.[value], '$.unidad_medida'), 'UND'),
                ISNULL(TRY_CAST(JSON_VALUE(d.[value], '$.precio_unitario') AS DECIMAL(12,2)), 0),
                ISNULL(TRY_CAST(JSON_VALUE(d.[value], '$.descuento_pct') AS DECIMAL(5,2)), 0),
                0, 0,
                ISNULL(TRY_CAST(JSON_VALUE(d.[value], '$.afecto_igv') AS BIT), 1),
                TRY_CAST(JSON_VALUE(d.[value], '$.id_zona_pricing') AS INT)
            FROM OPENJSON(@detalle_json) AS d;

            UPDATE dbo.cotizacion_detalle
            SET descuento_monto = ROUND(cantidad * precio_unitario * descuento_pct / 100.0, 2),
                subtotal = ROUND(cantidad * precio_unitario * (1 - descuento_pct/100.0), 2)
            WHERE id_cotizacion = @id_cotizacion;

            EXEC dbo.sp_cotizacion_calcular_totales @id_cotizacion = @id_cotizacion;
        END

        COMMIT TRANSACTION;

        SELECT 1 AS success, @id_cotizacion AS id_cotizacion;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO

/* ============================================================================
 * 6) sp_cotizacion_documento_save (adjuntos, mismo patrón que ticket_documento)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_documento_save','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_documento_save;
GO
CREATE PROCEDURE dbo.sp_cotizacion_documento_save
    @id_cotizacion       INT,
    @id_usuario          INT = NULL,
    @id_comunicacion     INT = NULL,
    @nombre_original     NVARCHAR(255),
    @archivo_key         CHAR(48) = NULL,
    @tipo_mime           VARCHAR(127),
    @tamano_bytes        BIGINT,
    @tipo_documento      NVARCHAR(50) = 'ADJUNTO_GENERAL',
    @base64_contenido    NVARCHAR(MAX) = NULL,
    @contenido_bin       VARBINARY(MAX) = NULL,
    @id_documento        INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        BEGIN TRANSACTION;

        IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion)
            THROW 51050, N'La cotización indicada no existe.', 1;

        IF @id_usuario IS NULL
            SELECT @id_usuario = id_usuario_creador FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion;

        IF @archivo_key IS NULL OR LTRIM(RTRIM(@archivo_key)) = N''
            SET @archivo_key = SUBSTRING(REPLACE(NEWID(),'-',''),1,32) +
                               SUBSTRING(CONVERT(VARCHAR(64),
                                        CONVERT(VARBINARY(32), CRYPT_GEN_RANDOM(8)), 2), 1, 16);

        -- Decodificar base64 → VARBINARY(MAX)
        DECLARE @contenido_final VARBINARY(MAX);
        IF @contenido_bin IS NOT NULL
            SET @contenido_final = @contenido_bin;
        ELSE IF @base64_contenido IS NOT NULL AND LEN(@base64_contenido) > 0
            SET @contenido_final =
                CONVERT(VARBINARY(MAX),
                        CONVERT(XML, N'<x>' + @base64_contenido + N'</x>')
                            .value(N'xs:base64Binary(/x[1])', N'VARBINARY(MAX)'));
        ELSE
            SET @contenido_final = NULL;

        INSERT INTO dbo.cotizacion_documento (
            id_cotizacion, id_comunicacion, nombre_original, archivo_key,
            tipo_mime, tamano_bytes, tipo_documento, id_usuario, fecha_registro, contenido
        ) VALUES (
            @id_cotizacion, @id_comunicacion, @nombre_original, @archivo_key,
            @tipo_mime, @tamano_bytes, @tipo_documento, @id_usuario, SYSDATETIME(), @contenido_final
        );

        SET @id_documento = SCOPE_IDENTITY();

        COMMIT TRANSACTION;

        SELECT @id_documento AS id_documento, @archivo_key AS archivo_key;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO

/* ============================================================================
 * 7) sp_cotizacion_documento_get (descarga de un adjunto)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_documento_get','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_documento_get;
GO
CREATE PROCEDURE dbo.sp_cotizacion_documento_get
    @id_documento INT
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        id_documento,
        id_cotizacion,
        id_comunicacion,
        nombre_original,
        archivo_key,
        tipo_mime,
        tamano_bytes,
        tipo_documento,
        fecha_registro,
        contenido
    FROM dbo.cotizacion_documento
    WHERE id_documento = @id_documento;
END;
GO

/* ============================================================================
 * 8) sp_cotizacion_delete_admin (soft delete con motivo)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_delete_admin','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_delete_admin;
GO
CREATE PROCEDURE dbo.sp_cotizacion_delete_admin
    @id_cotizacion INT,
    @id_usuario    INT,
    @motivo        NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @estado_actual NVARCHAR(20), @codigo NVARCHAR(40), @ya_eliminado BIT;
        SELECT @estado_actual = estado, @codigo = codigo, @ya_eliminado = eliminado
        FROM dbo.cotizacion WHERE id_cotizacion = @id_cotizacion;

        IF @estado_actual IS NULL
            THROW 51060, N'Cotización no encontrada.', 1;
        IF @ya_eliminado = 1
            THROW 51061, N'La cotización ya está eliminada.', 1;
        IF @motivo IS NULL OR LTRIM(RTRIM(@motivo)) = N''
            THROW 51062, N'El motivo de eliminación es obligatorio.', 1;

        DECLARE @ahora DATETIME2(0) = SYSDATETIME();
        UPDATE dbo.cotizacion
        SET eliminado = 1,
            fecha_eliminacion = @ahora,
            id_usuario_eliminacion = @id_usuario,
            motivo_eliminacion = @motivo
        WHERE id_cotizacion = @id_cotizacion;

        INSERT INTO dbo.cotizacion_historial_estado (
            id_cotizacion, estado_anterior, estado_nuevo,
            id_usuario, motivo, tipo_evento, origen_evento, fecha_cambio
        ) VALUES (
            @id_cotizacion, @estado_actual, @estado_actual,
            @id_usuario, N'[ELIMINACIÓN] ' + @motivo,
            'ELIMINACION', 'MANUAL', @ahora
        );

        COMMIT TRANSACTION;
        SELECT 1 AS success, @codigo AS codigo_eliminado;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO

/* ============================================================================
 * 9) sp_cotizacion_catalogos_listar (devuelve todos los catálogos en 1 llamada)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_catalogos_listar','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_catalogos_listar;
GO
CREATE PROCEDURE dbo.sp_cotizacion_catalogos_listar
AS
BEGIN
    SET NOCOUNT ON;
    SELECT id_tipo, codigo, nombre, titulo_documento, prefijo_codigo, orden
    FROM dbo.cotizacion_tipo_catalogo WHERE activo = 1 ORDER BY orden;

    SELECT id_emisora, razon_social, nombre_comercial, ruc, direccion, telefono, correo, es_principal
    FROM dbo.cotizacion_empresa_emisora WHERE activo = 1 ORDER BY es_principal DESC, razon_social;

    SELECT id_zona, codigo, nombre, descripcion
    FROM dbo.cotizacion_zona_pricing WHERE activo = 1 ORDER BY orden;

    SELECT id_forma_pago, descripcion, descuento_pct
    FROM dbo.cotizacion_forma_pago WHERE activo = 1 ORDER BY orden;

    SELECT id_garantia, descripcion
    FROM dbo.cotizacion_garantia WHERE activo = 1 ORDER BY orden;

    SELECT id_plazo, descripcion
    FROM dbo.cotizacion_plazo_entrega WHERE activo = 1 ORDER BY orden;

    SELECT id_validez, descripcion, dias
    FROM dbo.cotizacion_validez WHERE activo = 1 ORDER BY orden;

    SELECT id_cuenta, id_emisora, banco, numero_cuenta, cci, moneda, titular, acepta_tarjeta, orden
    FROM dbo.cotizacion_cuenta_bancaria WHERE activo = 1 ORDER BY id_emisora, orden;
END;
GO

/* ============================================================================
 * 10) sp_cotizacion_producto_listar (catálogo de productos con filtros)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_producto_listar','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_producto_listar;
GO
CREATE PROCEDURE dbo.sp_cotizacion_producto_listar
    @id_tipo_producto  NVARCHAR(30) = NULL,
    @search_term       NVARCHAR(100) = NULL,
    @incluir_inactivos BIT = 0,
    @limite           INT = 500
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (@limite)
        p.id_producto,
        p.codigo,
        p.descripcion,
        p.id_tipo_producto,
        p.unidad_medida,
        p.precio_cliente,
        p.precio_distribuidor,
        p.precio_mayorista,
        p.afecto_igv,
        p.moneda,
        p.aplica_precio_zona,
        p.categoria,
        p.subcategoria,
        p.activo,
        p.es_producto_agotado,
        -- Precios por zona si aplica
        (SELECT pz.precio FROM dbo.cotizacion_producto_precio_zona pz
          WHERE pz.id_producto = p.id_producto AND pz.id_zona = 1) AS precio_lima,
        (SELECT pz.precio FROM dbo.cotizacion_producto_precio_zona pz
          WHERE pz.id_producto = p.id_producto AND pz.id_zona = 2) AS precio_conos,
        (SELECT pz.precio FROM dbo.cotizacion_producto_precio_zona pz
          WHERE pz.id_producto = p.id_producto AND pz.id_zona = 3) AS precio_balne
    FROM dbo.cotizacion_producto_catalogo p
    WHERE (@incluir_inactivos = 1 OR p.activo = 1)
      AND (@id_tipo_producto IS NULL OR p.id_tipo_producto = @id_tipo_producto)
      AND (@search_term IS NULL
           OR p.codigo LIKE N'%' + @search_term + N'%'
           OR p.descripcion LIKE N'%' + @search_term + N'%')
    ORDER BY p.id_tipo_producto, p.codigo;
END;
GO

PRINT N'[OK] 05_cotizaciones_sps_parte2.sql (Parte 2) ejecutado correctamente.';
GO
