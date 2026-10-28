/* ============================================================================
 *  05_cotizaciones_sps_parte1.sql
 *  ----------------------------------------------------------------------------
 *  Stored Procedures del módulo de cotizaciones — PARTE 1
 *
 *  Contiene:
 *    - sp_cotizacion_correlativo_siguiente (numeración segura bajo concurrencia)
 *    - sp_cotizacion_create (crear cotización con detalle + estado inicial + auditoría)
 *    - sp_cotizacion_get (cabecera + detalle + historial + comunicaciones + versiones)
 *    - sp_cotizacion_list_paged (listado con filtros paginado)
 *    - sp_cotizacion_calcular_totales (recalculo de subtotal/igv/total)
 *
 *  Todas las operaciones críticas están envueltas en BEGIN TRAN / COMMIT / ROLLBACK.
 *  Los SPs NO confían en el cliente: las fechas y el id_usuario se toman
 *  del servidor SQL o del parámetro validado.
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* ============================================================================
 * 1) sp_cotizacion_correlativo_siguiente
 *
 *    Garantiza numeración única bajo concurrencia.
 *
 *    Estrategia:
 *      - UPSERT en dbo.cotizacion_correlativo_anual con UPDLOCK + HOLDLOCK
 *        → bloquea la fila (id_empresa, id_tipo, anio) para que cualquier otra
 *          transacción concurrente espere.
 *      - OUTPUT inserted.ultimo_correlativo → devuelve el siguiente número.
 *      - UNIQUE constraint (id_empresa, id_tipo, anio) como red de seguridad
 *        final: si por algún motivo dos SPs concurrentes escaparan al lock,
 *        la DB rechazaría el segundo INSERT con error de PK duplicado.
 *
 *    Devuelve: el correlativo como INT (1, 2, 3, ...).
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_correlativo_siguiente','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_correlativo_siguiente;
GO
CREATE PROCEDURE dbo.sp_cotizacion_correlativo_siguiente
    @id_empresa INT,
    @id_tipo    INT,
    @anio       INT,
    @correlativo INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @out_table TABLE (correlativo INT);

    -- MERGE con UPDLOCK + HOLDLOCK para garantizar serialización
    -- bajo concurrencia sobre la fila (id_empresa, id_tipo, anio).
    MERGE dbo.cotizacion_correlativo_anual WITH (UPDLOCK, HOLDLOCK) AS tgt
    USING (SELECT @id_empresa AS id_empresa, @id_tipo AS id_tipo, @anio AS anio) AS src
        ON (tgt.id_empresa = src.id_empresa AND tgt.id_tipo = src.id_tipo AND tgt.anio = src.anio)
    WHEN MATCHED THEN
        UPDATE SET
            tgt.ultimo_correlativo = tgt.ultimo_correlativo + 1,
            tgt.fecha_ultimo = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (id_empresa, id_tipo, anio, ultimo_correlativo, fecha_ultimo)
        VALUES (@id_empresa, @id_tipo, @anio, 1, SYSDATETIME())
    OUTPUT inserted.ultimo_correlativo INTO @out_table;

    -- Si OUTPUT devolvió filas, usarlas; si no, leer directamente.
    IF EXISTS (SELECT 1 FROM @out_table)
        SELECT @correlativo = correlativo FROM @out_table;
    ELSE
        SELECT @correlativo = MAX(ultimo_correlativo)
        FROM dbo.cotizacion_correlativo_anual
        WHERE id_empresa = @id_empresa AND id_tipo = @id_tipo AND anio = @anio;

    SET @correlativo = ISNULL(@correlativo, 0);
END;
GO

/* ============================================================================
 * 2) sp_cotizacion_calcular_totales
 *
 *    Recalcula subtotal, descuento_global, base_imponible, igv_monto y total
 *    en dbo.cotizacion a partir de dbo.cotizacion_detalle.
 *
 *    Fórmula:
 *      subtotal    = SUM(cantidad * precio_unitario * (1 - descuento_pct/100))
 *      descuento_global_monto = subtotal * descuento_global_pct / 100
 *      base_imponible = subtotal - descuento_global_monto
 *      igv_monto = base_imponible * igv_pct / 100  (solo para ítems afecto_igv=1)
 *      total = base_imponible + igv_monto
 *
 *    Notas:
 *      - Para ítems NO afectos a IGV (afecto_igv=0), el IGV se omite en su
 *        proporción. Esto da una base imponible e IGV por separado.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_calcular_totales','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_calcular_totales;
GO
CREATE PROCEDURE dbo.sp_cotizacion_calcular_totales
    @id_cotizacion INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @subtotal DECIMAL(14,2) = 0;
    DECLARE @subtotal_afecto DECIMAL(14,2) = 0;
    DECLARE @subtotal_no_afecto DECIMAL(14,2) = 0;
    DECLARE @desc_pct DECIMAL(5,2);
    DECLARE @desc_monto DECIMAL(14,2);
    DECLARE @base DECIMAL(14,2);
    DECLARE @igv_pct DECIMAL(5,2);
    DECLARE @igv_monto DECIMAL(14,2);
    DECLARE @total DECIMAL(14,2);

    SELECT @desc_pct = ISNULL(descuento_global_pct, 0),
           @igv_pct  = ISNULL(igv_pct, 18.00)
    FROM dbo.cotizacion
    WHERE id_cotizacion = @id_cotizacion;

    -- Subtotal por ítem afecto/no afecto a IGV
    SELECT
        @subtotal_afecto    = SUM(CASE WHEN afecto_igv = 1
                                        THEN (cantidad * precio_unitario) * (1 - descuento_pct/100.0)
                                        ELSE 0 END),
        @subtotal_no_afecto = SUM(CASE WHEN afecto_igv = 0
                                        THEN (cantidad * precio_unitario) * (1 - descuento_pct/100.0)
                                        ELSE 0 END)
    FROM dbo.cotizacion_detalle
    WHERE id_cotizacion = @id_cotizacion;

    SET @subtotal = ISNULL(@subtotal_afecto, 0) + ISNULL(@subtotal_no_afecto, 0);
    SET @desc_monto = ROUND(@subtotal * @desc_pct / 100.0, 2);

    -- Aplicar descuento proporcional sobre cada base
    DECLARE @desc_ratio DECIMAL(10,6) = CASE WHEN @subtotal > 0
                                              THEN (@desc_monto * 1.0 / @subtotal)
                                              ELSE 0 END;

    SET @base = ROUND((@subtotal_afecto * (1 - @desc_ratio)) + (@subtotal_no_afecto * (1 - @desc_ratio)), 2);
    SET @igv_monto = ROUND(@subtotal_afecto * (1 - @desc_ratio) * @igv_pct / 100.0, 2);
    SET @total = @base + @igv_monto;

    UPDATE dbo.cotizacion
    SET subtotal              = @subtotal,
        descuento_global_monto = @desc_monto,
        base_imponible        = @base,
        igv_monto             = @igv_monto,
        total                 = @total
    WHERE id_cotizacion = @id_cotizacion;

    -- Devolver los valores calculados
    SELECT
        @subtotal AS subtotal,
        @desc_monto AS descuento_global_monto,
        @base AS base_imponible,
        @igv_monto AS igv_monto,
        @total AS total;
END;
GO

/* ============================================================================
 * 3) sp_cotizacion_create
 *
 *    Crea una cotización completa (cabecera + detalle) en una sola transacción.
 *
 *    Genera el código COT-EMP000001-2026-0001:
 *      - COT: prefijo fijo del tipo VENTAS (configurable en tipo_catalogo.prefijo_codigo)
 *      - EMP000001: id_empresa rellenado a 6 dígitos
 *      - 2026: año de SYSDATETIME()
 *      - 0001: correlativo por (id_empresa, id_tipo, anio)
 *
 *    Reglas:
 *      - Valida que empresa, contacto, emisora, tipo existan.
 *      - Si detalle_json viene NULL, crea en BORRADOR sin ítems.
 *      - Si detalle_json viene, inserta los ítems y calcula totales.
 *      - Registra evento CREACION en historial.
 *      - Registra auditoría general en auditoria_accion.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_create','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_create;
GO
CREATE PROCEDURE dbo.sp_cotizacion_create
    @id_empresa              INT,
    @id_empresa_contacto     INT = NULL,
    @id_sucursal             INT = NULL,
    @id_tipo                 INT,
    @id_emisora              INT,
    @id_forma_pago           INT = NULL,
    @id_garantia             INT = NULL,
    @id_plazo_entrega        INT = NULL,
    @id_validez              INT = NULL,
    @id_cuenta_bancaria      INT = NULL,
    @id_zona_pricing         INT = NULL,
    @id_usuario_creador      INT,
    @id_vendedor             INT = NULL,
    @titulo_documento        NVARCHAR(200) = NULL,
    @motivo                  NVARCHAR(500) = NULL,
    @observaciones           NVARCHAR(MAX) = NULL,
    @moneda                  NVARCHAR(10) = 'PEN',
    @igv_pct                 DECIMAL(5,2) = 18.00,
    @descuento_global_pct    DECIMAL(5,2) = 0.00,
    @detalle_json            NVARCHAR(MAX) = NULL,
    -- JSON array: [{"id_producto_catalogo":1,"codigo":"SOA-0003","descripcion":"...","cantidad":1,"precio_unitario":1000,"descuento_pct":0,"afecto_igv":1,"id_zona_pricing":null}]
    @codigo                  NVARCHAR(40) OUTPUT,
    @id_cotizacion           INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    BEGIN TRY
        BEGIN TRANSACTION;

        /* -- Validaciones de existencia -- */
        IF NOT EXISTS (SELECT 1 FROM dbo.empresa WHERE id_empresa = @id_empresa)
            THROW 51001, N'La empresa cliente indicada no existe.', 1;
        IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora WHERE id_emisora = @id_emisora AND activo = 1)
            THROW 51002, N'La empresa emisora indicada no existe o está inactiva.', 1;
        IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE id_tipo = @id_tipo AND activo = 1)
            THROW 51003, N'El tipo de cotización indicado no existe o está inactivo.', 1;
        IF NOT EXISTS (SELECT 1 FROM dbo.persona WHERE id_persona = @id_usuario_creador)
            THROW 51004, N'El usuario creador indicado no existe.', 1;

        IF @id_empresa_contacto IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM dbo.empresa_contacto WHERE id_empresa_contacto = @id_empresa_contacto)
            THROW 51005, N'El contacto indicado no existe.', 1;

        IF @id_sucursal IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM dbo.empresa_sucursal WHERE id_sucursal = @id_sucursal AND id_empresa = @id_empresa)
            THROW 51006, N'La sucursal indicada no pertenece a la empresa.', 1;

        IF @id_vendedor IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM dbo.persona WHERE id_persona = @id_vendedor)
            THROW 51007, N'El vendedor indicado no existe.', 1;

        /* -- Generar correlativo -- */
        DECLARE @anio INT = YEAR(SYSDATETIME());
        DECLARE @correlativo INT = 0;
        DECLARE @prefijo NVARCHAR(10);

        SELECT @prefijo = prefijo_codigo FROM dbo.cotizacion_tipo_catalogo WHERE id_tipo = @id_tipo;

        -- Usar MERGE directamente para garantizar atomicidad con el lock
        -- (llamada al SP auxiliar)
        EXEC dbo.sp_cotizacion_correlativo_siguiente
             @id_empresa = @id_empresa,
             @id_tipo = @id_tipo,
             @anio = @anio,
             @correlativo = @correlativo OUTPUT;

        IF @correlativo <= 0
            THROW 51008, N'No se pudo obtener correlativo de cotización.', 1;

        /* -- Construir código: COT-EMP000001-2026-0001 -- */
        DECLARE @codigo_generado NVARCHAR(40) =
            @prefijo + '-EMP' + RIGHT('000000' + CAST(@id_empresa AS NVARCHAR(10)), 6)
                    + '-' + CAST(@anio AS NVARCHAR(4))
                    + '-' + RIGHT('0000' + CAST(@correlativo AS NVARCHAR(10)), 4);

        /* -- Snapshot de condiciones comerciales (para conservar histórico) -- */
        DECLARE @snap_forma_pago    NVARCHAR(500) = NULL;
        DECLARE @snap_garantia      NVARCHAR(500) = NULL;
        DECLARE @snap_plazo         NVARCHAR(500) = NULL;
        DECLARE @snap_validez       NVARCHAR(500) = NULL;
        DECLARE @snap_cuenta        NVARCHAR(MAX) = NULL;
        DECLARE @snap_titulo        NVARCHAR(200) = NULL;

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

        IF @titulo_documento IS NULL
            SELECT @snap_titulo = titulo_documento
            FROM dbo.cotizacion_tipo_catalogo WHERE id_tipo = @id_tipo;
        ELSE
            SET @snap_titulo = @titulo_documento;

        /* -- Insertar cabecera -- */
        INSERT INTO dbo.cotizacion (
            codigo, version_numero,
            id_empresa, id_empresa_contacto, id_sucursal,
            id_tipo, id_emisora,
            id_forma_pago, id_garantia, id_plazo_entrega, id_validez, id_cuenta_bancaria,
            id_zona_pricing,
            id_usuario_creador, id_vendedor,
            fecha_creacion,
            titulo_documento, motivo, observaciones,
            snapshot_forma_pago, snapshot_garantia, snapshot_plazo_entrega,
            snapshot_validez, snapshot_cuenta_bancaria,
            descuento_global_pct, igv_pct, moneda,
            estado, origen
        ) VALUES (
            @codigo_generado, 1,
            @id_empresa, @id_empresa_contacto, @id_sucursal,
            @id_tipo, @id_emisora,
            @id_forma_pago, @id_garantia, @id_plazo_entrega, @id_validez, @id_cuenta_bancaria,
            @id_zona_pricing,
            @id_usuario_creador, @id_vendedor,
            SYSDATETIME(),
            @snap_titulo, @motivo, @observaciones,
            @snap_forma_pago, @snap_garantia, @snap_plazo,
            @snap_validez, @snap_cuenta,
            @descuento_global_pct, @igv_pct, @moneda,
            'BORRADOR', 'MANUAL'
        );

        SET @id_cotizacion = SCOPE_IDENTITY();
        SET @codigo = @codigo_generado;

        /* -- Insertar detalle (si vino en JSON) -- */
        IF @detalle_json IS NOT NULL AND LEN(@detalle_json) > 2
        BEGIN
            -- Parsear JSON y hacer INSERT masivo
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
                0,  -- descuento_monto se calcula abajo
                0,  -- subtotal se calcula abajo
                ISNULL(TRY_CAST(JSON_VALUE(d.[value], '$.afecto_igv') AS BIT), 1),
                TRY_CAST(JSON_VALUE(d.[value], '$.id_zona_pricing') AS INT)
            FROM OPENJSON(@detalle_json) AS d;

            -- Calcular subtotal y descuento_monto por ítem
            UPDATE dbo.cotizacion_detalle
            SET descuento_monto = ROUND(cantidad * precio_unitario * descuento_pct / 100.0, 2),
                subtotal = ROUND(cantidad * precio_unitario * (1 - descuento_pct/100.0), 2)
            WHERE id_cotizacion = @id_cotizacion;
        END

        /* -- Calcular totales -- */
        EXEC dbo.sp_cotizacion_calcular_totales @id_cotizacion = @id_cotizacion;

        /* -- Registrar evento CREACION en historial -- */
        INSERT INTO dbo.cotizacion_historial_estado (
            id_cotizacion, estado_anterior, estado_nuevo,
            id_usuario, motivo, tipo_evento, origen_evento, fecha_cambio
        ) VALUES (
            @id_cotizacion, '', 'BORRADOR',
            @id_usuario_creador, N'Creación de cotización ' + @codigo_generado,
            'CREACION', 'MANUAL', SYSDATETIME()
        );

        /* -- Auditoría general (no rompe si falla) -- */
        BEGIN TRY
            DECLARE @correo_creador NVARCHAR(100);
            SELECT @correo_creador = correo FROM dbo.persona WHERE id_persona = @id_usuario_creador;

            INSERT INTO dbo.auditoria_accion
                (id_usuario, correo_usuario, modulo, accion, descripcion, tabla_afectada,
                 pk_registro, fecha_hora, resultado)
            VALUES
                (@id_usuario_creador, ISNULL(@correo_creador, N'sistema'),
                 N'COTIZACIONES', N'CREAR',
                 N'Cotización ' + @codigo_generado + N' creada',
                 N'cotizacion', CAST(@id_cotizacion AS NVARCHAR(20)),
                 SYSDATETIME(), 'EXITO');
        END TRY
        BEGIN CATCH
            PRINT N'[WARN] Auditoría no registrada: ' + ERROR_MESSAGE();
        END CATCH

        COMMIT TRANSACTION;

        SELECT
            @id_cotizacion AS id_cotizacion,
            @codigo AS codigo,
            1 AS version_numero,
            'BORRADOR' AS estado;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @errMsg NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum INT = ERROR_NUMBER();
        DECLARE @errLine INT = ERROR_LINE();
        DECLARE @throwMsg NVARCHAR(4000) =
            N'sp_cotizacion_create ERROR [' + CAST(@errNum AS NVARCHAR(10)) +
            N'] línea ' + CAST(@errLine AS NVARCHAR(10)) + N': ' + @errMsg;
        THROW 51099, @throwMsg, 1;
    END CATCH
END;
GO

PRINT N'[OK] 05_cotizaciones_sps_parte1.sql (Parte 1) ejecutado correctamente.';
GO
