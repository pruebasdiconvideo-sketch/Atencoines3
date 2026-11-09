/*============================================================================
 *  SCRIPT 09 — CONFIGURACIÓN FINAL V3 (OVERRIDE)
 *  ---------------------------------------------------------------------------
 *  Garantiza:
 *    1. Solo 2 tipos de cotización: SERV y VENT (los demás inactivos).
 *    2. SP sp_cotizacion_create_v5 con formato SERV-2026-001.1 (sin EMP).
 *    3. SP sp_cotizacion_preview_codigo con mismo formato.
 *    4. SP sp_cotizacion_update_status con manejo de errores que NO lanza
 *       THROW (devuelve success=0 + mensaje, evitando HTTP 500).
 *    5. La columna codigo_empresa existe en dbo.empresa.
 *
 *  EJECUTAR CON: usuario db_owner o sysadmin (sa).
 *  IDEMPOTENTE: puede ejecutarse múltiples veces.
 *==========================================================================*/

USE [MonosyncERP];
GO
SET NOCOUNT ON;
PRINT '======================================================================';
PRINT '  SCRIPT 09 — CONFIGURACIÓN FINAL V3';
PRINT '  Fecha inicio: ' + CONVERT(VARCHAR(19), SYSDATETIME(), 120);
PRINT '======================================================================';

/* 1. Verificar permisos */
DECLARE @EsSysadmin BIT = IS_SRVROLEMEMBER('sysadmin');
DECLARE @EsDbOwner  BIT = IS_MEMBER('db_owner');
PRINT '  Usuario: ' + SUSER_NAME();
PRINT '  ¿Es sysadmin? ' + CASE WHEN @EsSysadmin = 1 THEN 'SÍ' ELSE 'NO' END;
PRINT '  ¿Es db_owner?  ' + CASE WHEN @EsDbOwner = 1 THEN 'SÍ' ELSE 'NO' END;

/* 2. Asegurar columna codigo_empresa */
IF COL_LENGTH('dbo.empresa','codigo_empresa') IS NULL
BEGIN
    PRINT '  -> Agregando columna codigo_empresa...';
    ALTER TABLE dbo.empresa ADD codigo_empresa NVARCHAR(10) NULL;
END
ELSE
    PRINT '  [OK] codigo_empresa ya existe.';

/* 3. Tipos: solo SERV y VENT activos */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE prefijo_codigo = 'SERV' AND activo = 1)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE prefijo_codigo = 'SERV')
    BEGIN
        INSERT INTO dbo.cotizacion_tipo_catalogo
            (codigo, nombre, titulo_documento, prefijo_codigo, descripcion, orden, activo, codigo_por_empresa)
        VALUES
            ('SERV', 'Soporte', 'COTIZACIÓN DE SERVICIO DE SOPORTE', 'SERV',
             'Cotización para servicios de soporte técnico', 1, 1, 1);
    END
    ELSE
    BEGIN
        UPDATE dbo.cotizacion_tipo_catalogo
        SET activo = 1, codigo_por_empresa = 1,
            codigo = 'SERV', prefijo_codigo = 'SERV', nombre = 'Soporte'
        WHERE prefijo_codigo = 'SERV' OR codigo = 'SERV';
    END
END

IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE prefijo_codigo = 'VENT' AND activo = 1)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE prefijo_codigo = 'VENT')
    BEGIN
        INSERT INTO dbo.cotizacion_tipo_catalogo
            (codigo, nombre, titulo_documento, prefijo_codigo, descripcion, orden, activo, codigo_por_empresa)
        VALUES
            ('VENT', 'Ventas', 'COTIZACIÓN DE VENTAS', 'VENT',
             'Cotización para ventas de productos y servicios', 2, 1, 1);
    END
    ELSE
    BEGIN
        UPDATE dbo.cotizacion_tipo_catalogo
        SET activo = 1, codigo_por_empresa = 1,
            codigo = 'VENT', prefijo_codigo = 'VENT', nombre = 'Ventas'
        WHERE prefijo_codigo = 'VENT' OR codigo = 'VENT';
    END
END

/* Renombrar SOP -> SERV si existe */
UPDATE dbo.cotizacion_tipo_catalogo
SET prefijo_codigo = 'SERV', codigo = 'SERV', activo = 1, codigo_por_empresa = 1
WHERE prefijo_codigo = 'SOP';

/* Desactivar todos los demás */
UPDATE dbo.cotizacion_tipo_catalogo
SET activo = 0
WHERE prefijo_codigo NOT IN ('SERV','VENT');

PRINT '';
PRINT '  Tipos activos (deben ser solo SERV y VENT):';
SELECT id_tipo, codigo, nombre, prefijo_codigo, activo
FROM dbo.cotizacion_tipo_catalogo
WHERE activo = 1
ORDER BY orden;
GO

/* 4. SP sp_cotizacion_create_v5 — formato SERV-2026-001.1 (sin EMP) */
PRINT '';
PRINT '  -> Creando sp_cotizacion_create_v5 (formato sin EMP)...';
GO

CREATE OR ALTER PROCEDURE dbo.sp_cotizacion_create_v5
    @id_empresa              INT,
    @id_empresa_contacto     INT           = NULL,
    @id_sucursal             INT           = NULL,
    @id_tipo                 INT,
    @id_emisora              INT,
    @id_forma_pago           INT           = NULL,
    @id_garantia             INT           = NULL,
    @id_plazo_entrega        INT           = NULL,
    @id_validez              INT           = NULL,
    @id_cuenta_bancaria      INT           = NULL,
    @cuentas_adicionales_json NVARCHAR(MAX) = NULL,
    @id_usuario_creador      INT,
    @id_vendedor             INT           = NULL,
    @titulo_documento        NVARCHAR(200) = NULL,
    @motivo                  NVARCHAR(500) = NULL,
    @observaciones           NVARCHAR(MAX) = NULL,
    @moneda                  NVARCHAR(10)  = 'PEN',
    @igv_pct                 DECIMAL(5,2)  = 18.00,
    @descuento_global_pct    DECIMAL(5,2)  = 0.00,
    @detalle_json            NVARCHAR(MAX) = NULL,
    @codigo                  NVARCHAR(40)  OUTPUT,
    @id_cotizacion           INT           OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        BEGIN TRANSACTION;

        IF NOT EXISTS (SELECT 1 FROM dbo.empresa WHERE id_empresa = @id_empresa)
            THROW 51001, N'La empresa cliente indicada no existe.', 1;
        IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora WHERE id_emisora = @id_emisora AND activo = 1)
            THROW 51002, N'La empresa emisora indicada no existe o está inactiva.', 1;
        IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE id_tipo = @id_tipo AND activo = 1)
            THROW 51003, N'El tipo de cotización indicado no existe o está inactivo.', 1;
        IF NOT EXISTS (SELECT 1 FROM dbo.persona WHERE id_persona = @id_usuario_creador)
            THROW 51004, N'El usuario creador indicado no existe.', 1;

        DECLARE @codigo_empresa NVARCHAR(10);
        SELECT @codigo_empresa = codigo_empresa
        FROM dbo.empresa
        WHERE id_empresa = @id_empresa;

        IF @codigo_empresa IS NULL OR LTRIM(RTRIM(@codigo_empresa)) = N''
            THROW 51010, N'La empresa no tiene asignado un código identificador (codigo_empresa). Asígnelo con sp_empresa_set_codigo antes de crear cotizaciones.', 1;

        SET @codigo_empresa = UPPER(LTRIM(RTRIM(@codigo_empresa)));

        DECLARE @anio INT = YEAR(SYSDATETIME());
        DECLARE @correlativo INT = 0;

        EXEC dbo.sp_cotizacion_correlativo_siguiente
             @id_empresa = @id_empresa,
             @id_tipo = @id_tipo,
             @anio = @anio,
             @correlativo = @correlativo OUTPUT;

        IF @correlativo <= 0
            THROW 51008, N'No se pudo obtener correlativo de cotización.', 1;

        /* ===== FORMATO NUEVO: SERV-2026-001.1 ===== */
        DECLARE @codigo_generado NVARCHAR(40) =
            @codigo_empresa
            + N'-' + CAST(@anio AS NVARCHAR(4))
            + N'-' + RIGHT(N'000' + CAST(@correlativo AS NVARCHAR(10)), 3)
            + N'.1';

        /* Snapshots */
        DECLARE @snap_forma_pago NVARCHAR(500) = NULL;
        DECLARE @snap_garantia   NVARCHAR(500) = NULL;
        DECLARE @snap_plazo      NVARCHAR(500) = NULL;
        DECLARE @snap_validez    NVARCHAR(500) = NULL;
        DECLARE @snap_cuenta     NVARCHAR(MAX) = NULL;
        DECLARE @snap_titulo     NVARCHAR(200) = NULL;

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

        IF @cuentas_adicionales_json IS NOT NULL AND LEN(@cuentas_adicionales_json) > 2
        BEGIN
            DECLARE @extra_snap NVARCHAR(MAX) = N'';
            SELECT @extra_snap = @extra_snap +
                (SELECT banco + N' - Cuenta: ' + numero_cuenta
                              + CASE WHEN cci IS NOT NULL THEN N' - CCI: ' + cci ELSE N'' END + CHAR(13) + CHAR(10)
                 FROM dbo.cotizacion_cuenta_bancaria
                 WHERE id_cuenta IN (SELECT [value] FROM OPENJSON(@cuentas_adicionales_json))
                 FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)');
            IF LEN(@extra_snap) > 0
                SET @snap_cuenta = ISNULL(@snap_cuenta + CHAR(13) + CHAR(10), N'') + @extra_snap;
        END

        IF @titulo_documento IS NULL
            SELECT @snap_titulo = titulo_documento FROM dbo.cotizacion_tipo_catalogo WHERE id_tipo = @id_tipo;
        ELSE
            SET @snap_titulo = @titulo_documento;

        INSERT INTO dbo.cotizacion (
            codigo, version_numero,
            id_empresa, id_empresa_contacto, id_sucursal,
            id_tipo, id_emisora,
            id_forma_pago, id_garantia, id_plazo_entrega, id_validez, id_cuenta_bancaria,
            cuentas_adicionales_json,
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
            @cuentas_adicionales_json,
            NULL,
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

        IF @detalle_json IS NOT NULL AND LEN(@detalle_json) > 2
        BEGIN
            INSERT INTO dbo.cotizacion_detalle (
                id_cotizacion, orden, id_producto_catalogo, codigo, descripcion,
                cantidad, unidad_medida, precio_unitario, descuento_pct,
                descuento_monto, subtotal, afecto_igv
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
                ISNULL(TRY_CAST(JSON_VALUE(d.[value], '$.afecto_igv') AS BIT), 1)
            FROM OPENJSON(@detalle_json) AS d;

            UPDATE dbo.cotizacion_detalle
            SET descuento_monto = ROUND(cantidad * precio_unitario * descuento_pct / 100.0, 2),
                subtotal = ROUND(cantidad * precio_unitario * (1 - descuento_pct/100.0), 2),
                id_zona_pricing = NULL
            WHERE id_cotizacion = @id_cotizacion;
        END

        EXEC dbo.sp_cotizacion_calcular_totales @id_cotizacion = @id_cotizacion;

        INSERT INTO dbo.cotizacion_historial_estado (
            id_cotizacion, estado_anterior, estado_nuevo,
            id_usuario, motivo, tipo_evento, origen_evento, fecha_cambio
        ) VALUES (
            @id_cotizacion, '', 'BORRADOR',
            @id_usuario_creador, N'Creación de cotización ' + @codigo_generado,
            'CREACION', 'MANUAL', SYSDATETIME()
        );

        COMMIT TRANSACTION;
        SELECT @id_cotizacion AS id_cotizacion,
               @codigo         AS codigo,
               1               AS version_numero,
               'BORRADOR'      AS estado;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @errMsg NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum INT = ERROR_NUMBER();
        THROW 51099, @errMsg, 1;
    END CATCH
END;
GO

/* 5. SP sp_cotizacion_preview_codigo — formato nuevo */
PRINT '  -> Creando sp_cotizacion_preview_codigo...';
GO

CREATE OR ALTER PROCEDURE dbo.sp_cotizacion_preview_codigo
    @id_empresa INT,
    @id_tipo    INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @codigo_empresa NVARCHAR(10);
    SELECT @codigo_empresa = codigo_empresa
    FROM dbo.empresa
    WHERE id_empresa = @id_empresa;

    IF @codigo_empresa IS NULL OR LTRIM(RTRIM(@codigo_empresa)) = N''
    BEGIN
        SELECT
            NULL AS codigo_proximo,
            NULL AS codigo_empresa,
            N'La empresa no tiene asignado un código identificador (codigo_empresa). Configúrelo en el módulo de Empresas.' AS advertencia;
        RETURN;
    END

    SET @codigo_empresa = UPPER(LTRIM(RTRIM(@codigo_empresa)));
    DECLARE @anio INT = YEAR(SYSDATETIME());
    DECLARE @ultimo INT = 0;

    SELECT @ultimo = ISNULL(ultimo_correlativo, 0)
    FROM dbo.cotizacion_correlativo_anual
    WHERE id_empresa = @id_empresa
      AND id_tipo = @id_tipo
      AND anio = @anio;

    DECLARE @proximo INT = ISNULL(@ultimo, 0) + 1;

    SELECT
        @codigo_empresa + N'-' + CAST(@anio AS NVARCHAR(4))
        + N'-' + RIGHT(N'000' + CAST(@proximo AS NVARCHAR(10)), 3)
        + N'.1' AS codigo_proximo,
        @codigo_empresa AS codigo_empresa,
        @anio           AS anio,
        @proximo        AS proximo_correlativo,
        NULL            AS advertencia;
END;
GO

/* 6. SP sp_cotizacion_update_status — manejo mejorado (sin THROW) */
PRINT '  -> Creando sp_cotizacion_update_status (sin THROW)...';
GO

CREATE OR ALTER PROCEDURE dbo.sp_cotizacion_update_status
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
        BEGIN
            ROLLBACK TRANSACTION;
            SELECT 0 AS success,
                   N'Cotización no encontrada' AS error,
                   N'La cotización #' + CAST(@id_cotizacion AS NVARCHAR(20)) + N' no existe.' AS mensaje;
            RETURN;
        END

        IF @nuevo_estado NOT IN ('BORRADOR','PENDIENTE_ENVIO','ENVIADA','EN_SEGUIMIENTO',
                                  'EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA','CERRADA')
        BEGIN
            ROLLBACK TRANSACTION;
            SELECT 0 AS success,
                   N'Estado inválido' AS error,
                   N'El estado ''' + ISNULL(@nuevo_estado, N'') + N''' no es válido.' AS mensaje;
            RETURN;
        END

        /* Transiciones permitidas (más flexibles que antes) */
        DECLARE @transicion_valida BIT = 0;

        IF @estado_anterior = 'BORRADOR' AND @nuevo_estado IN ('PENDIENTE_ENVIO','CANCELADA','ENVIADA','EN_SEGUIMIENTO','APROBADA','RECHAZADA')
            SET @transicion_valida = 1;
        IF @estado_anterior = 'PENDIENTE_ENVIO' AND @nuevo_estado IN ('ENVIADA','CANCELADA','BORRADOR','EN_SEGUIMIENTO','APROBADA','RECHAZADA')
            SET @transicion_valida = 1;
        IF @estado_anterior = 'ENVIADA' AND @nuevo_estado IN ('EN_SEGUIMIENTO','EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA','PENDIENTE_ENVIO')
            SET @transicion_valida = 1;
        IF @estado_anterior = 'EN_SEGUIMIENTO' AND @nuevo_estado IN ('EN_NEGOCIACION','APROBADA','RECHAZADA','CANCELADA','ENVIADA','PENDIENTE_ENVIO')
            SET @transicion_valida = 1;
        IF @estado_anterior = 'EN_NEGOCIACION' AND @nuevo_estado IN ('APROBADA','RECHAZADA','CANCELADA','EN_SEGUIMIENTO','ENVIADA')
            SET @transicion_valida = 1;
        IF @estado_anterior = 'APROBADA' AND @nuevo_estado IN ('CERRADA','CANCELADA','ENVIADA')
            SET @transicion_valida = 1;
        IF @estado_anterior = 'RECHAZADA' AND @nuevo_estado IN ('CERRADA','PENDIENTE_ENVIO','ENVIADA','EN_NEGOCIACION')
            SET @transicion_valida = 1;
        IF @estado_anterior = 'CANCELADA' AND @nuevo_estado IN ('PENDIENTE_ENVIO','BORRADOR','ENVIADA')
            SET @transicion_valida = 1;
        IF @estado_anterior = 'CERRADA' AND @nuevo_estado IN ('PENDIENTE_ENVIO','ENVIADA')
            SET @transicion_valida = 1;
        IF @estado_anterior = @nuevo_estado SET @transicion_valida = 1;

        IF @transicion_valida = 0
        BEGIN
            ROLLBACK TRANSACTION;
            SELECT 0 AS success,
                   N'Transición no permitida' AS error,
                   N'No se puede cambiar de ''' + ISNULL(@estado_anterior, N'?') +
                   N''' a ''' + ISNULL(@nuevo_estado, N'?') + N'''.' AS mensaje,
                   @estado_anterior AS estado_anterior,
                   @nuevo_estado AS estado_nuevo;
            RETURN;
        END

        DECLARE @tipo_evento NVARCHAR(30) = 'CAMBIO_ESTADO';
        IF @nuevo_estado = 'ENVIADA' SET @tipo_evento = 'ENVIO';
        ELSE IF @nuevo_estado = 'APROBADA' SET @tipo_evento = 'APROBACION';
        ELSE IF @nuevo_estado = 'RECHAZADA' SET @tipo_evento = 'RECHAZO';
        ELSE IF @nuevo_estado = 'CANCELADA' SET @tipo_evento = 'CANCELACION';
        ELSE IF @nuevo_estado = 'CERRADA' SET @tipo_evento = 'CIERRE';

        UPDATE dbo.cotizacion
        SET
            estado = @nuevo_estado,
            id_usuario_actualizo = @id_usuario,
            fecha_actualizacion = @ahora,
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

        INSERT INTO dbo.cotizacion_historial_estado (
            id_cotizacion, estado_anterior, estado_nuevo,
            id_usuario, motivo, tipo_evento, origen_evento, fecha_cambio
        ) VALUES (
            @id_cotizacion, @estado_anterior, @nuevo_estado,
            @id_usuario, ISNULL(@motivo, N'Transición: ' + @estado_anterior + N' → ' + @nuevo_estado),
            @tipo_evento, 'MANUAL', @ahora
        );

        COMMIT TRANSACTION;

        SELECT 1 AS success,
               @estado_anterior AS estado_anterior,
               @nuevo_estado AS estado_nuevo,
               @tipo_evento AS tipo_evento,
               NULL AS error,
               NULL AS mensaje;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @errMsg NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum INT = ERROR_NUMBER();
        SELECT 0 AS success,
               N'Error inesperado' AS error,
               N'Error [' + CAST(@errNum AS NVARCHAR(10)) + N']: ' + @errMsg AS mensaje;
    END CATCH
END;
GO

/* 7. SP sp_empresa_set_codigo */
PRINT '  -> Creando sp_empresa_set_codigo...';
GO

CREATE OR ALTER PROCEDURE dbo.sp_empresa_set_codigo
    @id_empresa     INT,
    @codigo_empresa NVARCHAR(10),
    @id_usuario     INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.empresa WHERE id_empresa = @id_empresa)
            THROW 51050, N'La empresa indicada no existe.', 1;

        SET @codigo_empresa = UPPER(LTRIM(RTRIM(@codigo_empresa)));

        IF @codigo_empresa LIKE '%[^A-Z0-9]%'
           OR LEN(@codigo_empresa) < 2
           OR LEN(@codigo_empresa) > 10
            THROW 51051, N'El código debe tener 2-10 caracteres alfanuméricos.', 1;

        IF EXISTS (SELECT 1 FROM dbo.empresa WHERE codigo_empresa = @codigo_empresa AND id_empresa <> @id_empresa)
            THROW 51052, N'El código ya está asignado a otra empresa.', 1;

        UPDATE dbo.empresa
        SET codigo_empresa = @codigo_empresa,
            fecha_modificacion = SYSDATETIME(),
            id_usuario_modificacion = @id_usuario
        WHERE id_empresa = @id_empresa;

        SELECT @id_empresa AS id_empresa, @codigo_empresa AS codigo_empresa, 1 AS success;
    END TRY
    BEGIN CATCH
        DECLARE @errMsg NVARCHAR(4000) = ERROR_MESSAGE();
        THROW 51059, @errMsg, 1;
    END CATCH
END;
GO

/* 8. Verificación final */
PRINT '';
PRINT '--- Verificación final ---';
IF COL_LENGTH('dbo.empresa','codigo_empresa') IS NOT NULL
    PRINT '  [OK] codigo_empresa existe';
IF OBJECT_ID('dbo.sp_cotizacion_create_v5','P') IS NOT NULL
    PRINT '  [OK] sp_cotizacion_create_v5 existe';
IF OBJECT_ID('dbo.sp_cotizacion_preview_codigo','P') IS NOT NULL
    PRINT '  [OK] sp_cotizacion_preview_codigo existe';
IF OBJECT_ID('dbo.sp_cotizacion_update_status','P') IS NOT NULL
    PRINT '  [OK] sp_cotizacion_update_status existe (manejo mejorado)';
IF OBJECT_ID('dbo.sp_empresa_set_codigo','P') IS NOT NULL
    PRINT '  [OK] sp_empresa_set_codigo existe';

PRINT '';
PRINT '  Tipos activos:';
SELECT id_tipo, codigo, nombre, prefijo_codigo
FROM dbo.cotizacion_tipo_catalogo
WHERE activo = 1
ORDER BY orden;

PRINT '';
PRINT '  Empresas (asigne codigo_empresa con sp_empresa_set_codigo):';
SELECT id_empresa, ruc, razon_social, codigo_empresa,
       CASE WHEN codigo_empresa IS NULL THEN '⚠ Sin código' ELSE '✓ OK' END AS estado
FROM dbo.empresa
WHERE activo = 1
ORDER BY razon_social;

PRINT '';
PRINT '  Fecha fin: ' + CONVERT(VARCHAR(19), SYSDATETIME(), 120);
PRINT '======================================================================';
GO
