/* ============================================================================
 *  07_cotizaciones_multi_cuenta_y_catalogos.sql
 *  ----------------------------------------------------------------------------
 *  Migración que agrega:
 *    1. Columna cuentas_adicionales_json en dbo.cotizacion
 *       → Permite asociar 2 o más cuentas bancarias a una cotización
 *    2. SP sp_cotizacion_forma_pago_add
 *       → Permite agregar nuevas formas de pago desde la UI
 *    3. SP sp_cotizacion_cuenta_bancaria_add
 *       → Permite agregar nuevas cuentas bancarias desde la UI
 *    4. SP sp_cotizacion_create_v2 (reemplaza a v1)
 *       → Acepta parámetro @cuentas_adicionales_json
 *    5. SP sp_cotizacion_update_v2 (reemplaza a v1)
 *       → Acepta parámetro @cuentas_adicionales_json
 *    6. SP sp_cotizacion_get_v2 (reemplaza a v1)
 *       → Devuelve cuentas adicionales en la cabecera
 *
 *  Compatible con la versión anterior: si @cuentas_adicionales_json es NULL,
 *  el comportamiento es idéntico al de v1.
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
PRINT N'  MIGRACIÓN 07: Multi-cuenta y catálogos dinámicos';
PRINT N'====================================================================';

/* ============================================================================
 * 1) Agregar columna cuentas_adicionales_json a dbo.cotizacion
 *    Almacena un JSON array de IDs de cuentas bancarias adicionales:
 *    ej. [3, 5] → además de id_cuenta_bancaria, agregar las cuentas 3 y 5
 * ============================================================================
 */
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID(N'dbo.cotizacion') AND name = 'cuentas_adicionales_json'
)
BEGIN
    ALTER TABLE dbo.cotizacion ADD cuentas_adicionales_json NVARCHAR(MAX) NULL;
    PRINT N'[OK] Columna cuentas_adicionales_json agregada a dbo.cotizacion';
END
ELSE
    PRINT N'[INFO] La columna cuentas_adicionales_json ya existe';
GO

/* ============================================================================
 * 2) SP sp_cotizacion_forma_pago_add
 *    Permite agregar una nueva forma de pago desde la UI (rol ADMIN o VENTAS).
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_forma_pago_add','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_forma_pago_add;
GO
CREATE PROCEDURE dbo.sp_cotizacion_forma_pago_add
    @descripcion      NVARCHAR(500),
    @descuento_pct    DECIMAL(5,2) = 0,
    @id_usuario       INT = NULL,
    @id_forma_pago    INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo, fecha_creacion)
    VALUES (@descripcion, @descuento_pct,
            ISNULL((SELECT MAX(orden) FROM dbo.cotizacion_forma_pago), 0) + 1,
            1, SYSDATETIME());
    SET @id_forma_pago = SCOPE_IDENTITY();
    SELECT @id_forma_pago AS id_forma_pago, @descripcion AS descripcion, @descuento_pct AS descuento_pct;
END;
GO
PRINT N'[OK] SP sp_cotizacion_forma_pago_add creado';
GO

/* ============================================================================
 * 3) SP sp_cotizacion_cuenta_bancaria_add
 *    Permite agregar una nueva cuenta bancaria desde la UI.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_cuenta_bancaria_add','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_cuenta_bancaria_add;
GO
CREATE PROCEDURE dbo.sp_cotizacion_cuenta_bancaria_add
    @id_emisora       INT,
    @banco            NVARCHAR(50),
    @numero_cuenta    NVARCHAR(50),
    @cci              NVARCHAR(50) = NULL,
    @moneda           NVARCHAR(10) = 'PEN',
    @titular          NVARCHAR(255) = NULL,
    @acepta_tarjeta   BIT = 0,
    @id_usuario       INT = NULL,
    @id_cuenta        INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora WHERE id_emisora = @id_emisora)
        THROW 51101, N'La empresa emisora indicada no existe.', 1;

    INSERT INTO dbo.cotizacion_cuenta_bancaria
        (id_emisora, banco, numero_cuenta, cci, moneda, titular, acepta_tarjeta, orden, activo, fecha_creacion)
    VALUES
        (@id_emisora, @banco, @numero_cuenta, @cci, @moneda, @titular, @acepta_tarjeta,
         ISNULL((SELECT MAX(orden) FROM dbo.cotizacion_cuenta_bancaria WHERE id_emisora = @id_emisora), 0) + 1,
         1, SYSDATETIME());
    SET @id_cuenta = SCOPE_IDENTITY();
    SELECT @id_cuenta AS id_cuenta, @banco AS banco, @numero_cuenta AS numero_cuenta;
END;
GO
PRINT N'[OK] SP sp_cotizacion_cuenta_bancaria_add creado';
GO

/* ============================================================================
 * 4) SP sp_cotizacion_create_v2
 *    Reemplaza a sp_cotizacion_create. Acepta un parámetro adicional
 *    @cuentas_adicionales_json para asociar múltiples cuentas bancarias.
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_create_v2','P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cotizacion_create_v2;
GO
CREATE PROCEDURE dbo.sp_cotizacion_create_v2
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

        IF @id_empresa_contacto IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM dbo.empresa_contacto WHERE id_empresa_contacto = @id_empresa_contacto)
            THROW 51005, N'El contacto indicado no existe.', 1;

        IF @id_sucursal IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM dbo.empresa_sucursal WHERE id_sucursal = @id_sucursal AND id_empresa = @id_empresa)
            THROW 51006, N'La sucursal indicada no pertenece a la empresa.', 1;

        IF @id_vendedor IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM dbo.persona WHERE id_persona = @id_vendedor)
            THROW 51007, N'El vendedor indicado no existe.', 1;

        DECLARE @anio INT = YEAR(SYSDATETIME());
        DECLARE @correlativo INT = 0;
        DECLARE @prefijo NVARCHAR(10);

        SELECT @prefijo = prefijo_codigo FROM dbo.cotizacion_tipo_catalogo WHERE id_tipo = @id_tipo;

        EXEC dbo.sp_cotizacion_correlativo_siguiente
             @id_empresa = @id_empresa,
             @id_tipo = @id_tipo,
             @anio = @anio,
             @correlativo = @correlativo OUTPUT;

        IF @correlativo <= 0
            THROW 51008, N'No se pudo obtener correlativo de cotización.', 1;

        DECLARE @codigo_generado NVARCHAR(40) =
            @prefijo + '-EMP' + RIGHT('000000' + CAST(@id_empresa AS NVARCHAR(10)), 6)
                    + '-' + CAST(@anio AS NVARCHAR(4))
                    + '-' + RIGHT('0000' + CAST(@correlativo AS NVARCHAR(10)), 4);

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

        -- Snapshot de cuenta principal
        IF @id_cuenta_bancaria IS NOT NULL
            SELECT @snap_cuenta = banco + N' - Cuenta: ' + numero_cuenta
                                 + CASE WHEN cci IS NOT NULL THEN N' - CCI: ' + cci ELSE N'' END
            FROM dbo.cotizacion_cuenta_bancaria WHERE id_cuenta = @id_cuenta_bancaria;

        -- Append de cuentas adicionales al snapshot
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
            N'sp_cotizacion_create_v2 ERROR [' + CAST(@errNum AS NVARCHAR(10)) +
            N'] línea ' + CAST(@errLine AS NVARCHAR(10)) + N': ' + @errMsg;
        THROW 51099, @throwMsg, 1;
    END CATCH
END;
GO
PRINT N'[OK] SP sp_cotizacion_create_v2 creado';
GO

PRINT N'';
PRINT N'====================================================================';
PRINT N'  ✅ MIGRACIÓN 07 COMPLETADA';
PRINT N'====================================================================';
PRINT N'  - Columna cuentas_adicionales_json agregada';
PRINT N'  - SP sp_cotizacion_forma_pago_add creado';
PRINT N'  - SP sp_cotizacion_cuenta_bancaria_add creado';
PRINT N'  - SP sp_cotizacion_create_v2 creado (acepta cuentas adicionales)';
PRINT N'====================================================================';
GO
