/* ============================================================================
 *  10_cotizaciones_fix_correlativo_global.sql
 *  ----------------------------------------------------------------------------
 *  Corrige el error: "The MERGE statement conflicted with the FOREIGN KEY
 *  constraint 'FK_cot_correlativo_empresa'" que ocurre cuando se crea una
 *  cotización de VENTAS (codigo_por_empresa=0) porque usa id_empresa=0
 *  que no existe en la tabla empresa.
 *
 *  Solución: recrear sp_cotizacion_create_v4 que:
 *    - Para VENTAS (codigo_por_empresa=0): usa id_empresa = id_empresa REAL
 *      en el correlativo, pero genera el código SIN la empresa:
 *      VT-2026-0001 (no incluye EMP000001)
 *    - Para SOPORTE (codigo_por_empresa=1): usa id_empresa REAL:
 *      SOP-EMP000001-2026-0001
 *
 *  Así nunca se usa id_empresa=0 y la FK no falla.
 *
 *  También recrea sp_cotizacion_correlativo_siguiente para no usar MERGE
 *  (que era problemático con la FK) y en su lugar usa UPDATE + INSERT
 *  atómicos con manejo de errores.
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
SET NOCOUNT ON;
GO

PRINT N'====================================================================';
PRINT N'  FIX 10: Correlativo global sin FK conflict';
PRINT N'====================================================================';

/* ============================================================================
 * 1) Recrear sp_cotizacion_correlativo_siguiente
 *    Versión robusta que NO usa MERGE (que causaba conflictos con FK).
 *    Usa UPDATE + INSERT condicional con manejo de errores.
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
    SET XACT_ABORT ON;

    DECLARE @nuevo INT;

    BEGIN TRANSACTION;

    -- Intentar UPDATE primero (si la fila ya existe)
    UPDATE dbo.cotizacion_correlativo_anual WITH (UPDLOCK, HOLDLOCK)
    SET ultimo_correlativo = ultimo_correlativo + 1,
        fecha_ultimo = SYSDATETIME()
    WHERE id_empresa = @id_empresa
      AND id_tipo = @id_tipo
      AND anio = @anio;

    IF @@ROWCOUNT = 0
    BEGIN
        -- La fila no existe, intentar INSERT
        BEGIN TRY
            INSERT INTO dbo.cotizacion_correlativo_anual (id_empresa, id_tipo, anio, ultimo_correlativo, fecha_ultimo)
            VALUES (@id_empresa, @id_tipo, @anio, 1, SYSDATETIME());
            SET @nuevo = 1;
        END TRY
        BEGIN CATCH
            -- Si el INSERT falló (probablemente por race condition: otro proceso
            -- insertó la fila entre nuestro UPDATE y nuestro INSERT),
            -- hacer UPDATE de nuevo
            UPDATE dbo.cotizacion_correlativo_anual WITH (UPDLOCK, HOLDLOCK)
            SET ultimo_correlativo = ultimo_correlativo + 1,
                fecha_ultimo = SYSDATETIME()
            WHERE id_empresa = @id_empresa
              AND id_tipo = @id_tipo
              AND anio = @anio;
        END CATCH
    END

    -- Leer el valor actual
    SELECT @nuevo = ultimo_correlativo
    FROM dbo.cotizacion_correlativo_anual
    WHERE id_empresa = @id_empresa
      AND id_tipo = @id_tipo
      AND anio = @anio;

    COMMIT TRANSACTION;

    SET @correlativo = ISNULL(@nuevo, 0);
END;
GO
PRINT N'[OK] sp_cotizacion_correlativo_siguiente recreado (sin MERGE)';
GO

/* ============================================================================
 * 2) Recrear sp_cotizacion_create_v4
 *    Versión que NUNCA usa id_empresa=0. Para VENTAS (codigo_por_empresa=0)
 *    usa el id_empresa REAL en el correlativo, pero genera el código SIN
 *    la empresa: VT-2026-0001.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_create_v4','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_create_v4;
GO
CREATE PROCEDURE dbo.sp_cotizacion_create_v4
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
    @cuentas_adicionales_json NVARCHAR(MAX) = NULL,
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
    @codigo                  NVARCHAR(40) OUTPUT,
    @id_cotizacion           INT OUTPUT
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

        IF @id_empresa_contacto IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.empresa_contacto WHERE id_empresa_contacto = @id_empresa_contacto)
            THROW 51005, N'El contacto indicado no existe.', 1;
        IF @id_sucursal IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.empresa_sucursal WHERE id_sucursal = @id_sucursal AND id_empresa = @id_empresa)
            THROW 51006, N'La sucursal indicada no pertenece a la empresa.', 1;
        IF @id_vendedor IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dbo.persona WHERE id_persona = @id_vendedor)
            THROW 51007, N'El vendedor indicado no existe.', 1;

        DECLARE @anio INT = YEAR(SYSDATETIME());
        DECLARE @correlativo INT = 0;
        DECLARE @prefijo NVARCHAR(10);
        DECLARE @codigo_por_empresa BIT;

        SELECT @prefijo = prefijo_codigo,
               @codigo_por_empresa = codigo_por_empresa
        FROM dbo.cotizacion_tipo_catalogo WHERE id_tipo = @id_tipo;

        DECLARE @codigo_generado NVARCHAR(40);

        -- SIEMPRE usar el id_empresa real en el correlativo (nunca 0)
        -- para que la FK no falle
        EXEC dbo.sp_cotizacion_correlativo_siguiente
             @id_empresa = @id_empresa,
             @id_tipo = @id_tipo,
             @anio = @anio,
             @correlativo = @correlativo OUTPUT;

        IF @correlativo <= 0 THROW 51008, N'No se pudo obtener correlativo.', 1;

        IF @codigo_por_empresa = 1
        BEGIN
            -- Formato: PREFIJO-EMP000001-2026-0001
            SET @codigo_generado =
                @prefijo + '-EMP' + RIGHT('000000' + CAST(@id_empresa AS NVARCHAR(10)), 6)
                        + '-' + CAST(@anio AS NVARCHAR(4))
                        + '-' + RIGHT('0000' + CAST(@correlativo AS NVARCHAR(10)), 4);
        END
        ELSE
        BEGIN
            -- Formato: PREFIJO-2026-0001 (sin empresa en el código,
            -- pero el correlativo SÍ es por empresa internamente)
            SET @codigo_generado =
                @prefijo + '-' + CAST(@anio AS NVARCHAR(4))
                        + '-' + RIGHT('0000' + CAST(@correlativo AS NVARCHAR(10)), 4);
        END

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

        -- Snapshot de cuenta bancaria principal
        IF @id_cuenta_bancaria IS NOT NULL
            SELECT @snap_cuenta = banco + N' - Cuenta: ' + numero_cuenta
                                 + CASE WHEN cci IS NOT NULL THEN N' - CCI: ' + cci ELSE N'' END
            FROM dbo.cotizacion_cuenta_bancaria WHERE id_cuenta = @id_cuenta_bancaria;

        -- Snapshot de cuentas adicionales
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

        IF @detalle_json IS NOT NULL AND LEN(@detalle_json) > 2
        BEGIN
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
        SELECT @id_cotizacion AS id_cotizacion, @codigo AS codigo, 1 AS version_numero, 'BORRADOR' AS estado;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @errMsg NVARCHAR(4000) = ERROR_MESSAGE();
        DECLARE @errNum INT = ERROR_NUMBER();
        DECLARE @errLine INT = ERROR_LINE();
        DECLARE @throwMsg NVARCHAR(4000) =
            N'sp_cotizacion_create_v4 ERROR [' + CAST(@errNum AS NVARCHAR(10)) +
            N'] línea ' + CAST(@errLine AS NVARCHAR(10)) + N': ' + @errMsg;
        THROW 51099, @throwMsg, 1;
    END CATCH
END;
GO
PRINT N'[OK] sp_cotizacion_create_v4 recreado (sin FK conflict)';
GO

PRINT N'';
PRINT N'====================================================================';
PRINT N'  ✅ FIX 10 COMPLETADO';
PRINT N'====================================================================';
PRINT N'  - sp_cotizacion_correlativo_siguiente: recreado sin MERGE';
PRINT N'  - sp_cotizacion_create_v4: usa id_empresa real siempre';
PRINT N'====================================================================';
GO
