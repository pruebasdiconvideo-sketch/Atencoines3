/* ============================================================================
 *  08_cotizaciones_crud_catalogos_y_codigo.sql
 *  ----------------------------------------------------------------------------
 *  Agrega:
 *    1. SPs CRUD completos para zonas de pricing:
 *       sp_cotizacion_zona_add / update / delete
 *    2. SPs CRUD para formas de pago (crear ya existe, falta editar/eliminar):
 *       sp_cotizacion_forma_pago_update / delete
 *    3. SPs CRUD para cuentas bancarias (crear ya existe, falta editar/eliminar):
 *       sp_cotizacion_cuenta_bancaria_update / delete
 *    4. NUEVO formato de código por tipo:
 *       - VENTAS:        VT-2026-0001     (sin empresa, correlativo global por año)
 *       - SOPORTE:       SOP-EMP000001-2026-0001 (con empresa, correlativo por empresa/año)
 *       - MANTENIMIENTO: MAN-EMP000001-2026-0001
 *       - REPARACION:    REP-EMP000001-2026-0001
 *       - CABLEADO:      CAB-EMP000001-2026-0001
 *       - REPUESTOS:      RPT-EMP000001-2026-0001
 *       - SOPORTE_Y_MANT: SYM-EMP000001-2026-0001
 *
 *    El sp_cotizacion_create_v3 decide el formato según el tipo:
 *       - Si el tipo tiene codigo_por_empresa = 0 → correlativo global por año (sin empresa)
 *       - Si el tipo tiene codigo_por_empresa = 1 → correlativo por empresa por año
 *
 *    Para no romper la compatibilidad, agregamos la columna codigo_por_empresa
 *    a cotizacion_tipo_catalogo. Por defecto:
 *       - VENTAS = 0 (correlativo global)
 *       - Los demás = 1 (correlativo por empresa)
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
PRINT N'  MIGRACIÓN 08: CRUD catálogos + nuevo formato de código';
PRINT N'====================================================================';

/* ============================================================================
 * 1) Agregar columna codigo_por_empresa a cotizacion_tipo_catalogo
 * ============================================================================
 */
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID(N'dbo.cotizacion_tipo_catalogo') AND name = 'codigo_por_empresa'
)
BEGIN
    ALTER TABLE dbo.cotizacion_tipo_catalogo ADD codigo_por_empresa BIT NOT NULL DEFAULT(1);
    PRINT N'[OK] Columna codigo_por_empresa agregada a cotizacion_tipo_catalogo';
END
ELSE
    PRINT N'[INFO] La columna codigo_por_empresa ya existe';
GO

-- Por defecto, VENTAS = 0 (correlativo global por año), los demás = 1 (por empresa)
UPDATE dbo.cotizacion_tipo_catalogo SET codigo_por_empresa = 0 WHERE codigo = 'VENTAS';
UPDATE dbo.cotizacion_tipo_catalogo SET codigo_por_empresa = 1 WHERE codigo <> 'VENTAS';
PRINT N'[OK] codigo_por_empresa configurado (VENTAS=0, demás=1)';
GO

/* ============================================================================
 * 2) Cambiar el prefijo_codigo de VENTAS a 'VT' (era 'COT')
 * ============================================================================
 */
UPDATE dbo.cotizacion_tipo_catalogo
SET prefijo_codigo = 'VT'
WHERE codigo = 'VENTAS' AND prefijo_codigo = 'COT';
PRINT N'[OK] Prefijo de VENTAS actualizado: COT → VT';
GO

-- Para SOPORTE, el prefijo debe ser 'SOP' (era 'SERV')
UPDATE dbo.cotizacion_tipo_catalogo
SET prefijo_codigo = 'SOP'
WHERE codigo = 'SOPORTE' AND prefijo_codigo = 'SERV';
PRINT N'[OK] Prefijo de SOPORTE actualizado: SERV → SOP';
GO

/* ============================================================================
 * 3) SP sp_cotizacion_zona_add
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_zona_add','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_zona_add;
GO
CREATE PROCEDURE dbo.sp_cotizacion_zona_add
    @codigo       NVARCHAR(10),
    @nombre       NVARCHAR(100),
    @descripcion  NVARCHAR(500) = NULL,
    @id_zona      INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM dbo.cotizacion_zona_pricing WHERE codigo = @codigo)
        THROW 51110, N'El código de zona ya existe.', 1;
    INSERT INTO dbo.cotizacion_zona_pricing (codigo, nombre, descripcion, orden, activo)
    VALUES (@codigo, @nombre, @descripcion,
            ISNULL((SELECT MAX(orden) FROM dbo.cotizacion_zona_pricing), 0) + 1, 1);
    SET @id_zona = SCOPE_IDENTITY();
    SELECT @id_zona AS id_zona, @codigo AS codigo, @nombre AS nombre;
END;
GO
PRINT N'[OK] SP sp_cotizacion_zona_add';
GO

/* ============================================================================
 * 4) SP sp_cotizacion_zona_update
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_zona_update','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_zona_update;
GO
CREATE PROCEDURE dbo.sp_cotizacion_zona_update
    @id_zona      INT,
    @codigo       NVARCHAR(10) = NULL,
    @nombre       NVARCHAR(100) = NULL,
    @descripcion  NVARCHAR(500) = NULL,
    @activo       BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_zona_pricing WHERE id_zona = @id_zona)
        THROW 51111, N'Zona no encontrada.', 1;
    UPDATE dbo.cotizacion_zona_pricing
    SET codigo = ISNULL(@codigo, codigo),
        nombre = ISNULL(@nombre, nombre),
        descripcion = ISNULL(@descripcion, descripcion),
        activo = @activo
    WHERE id_zona = @id_zona;
    SELECT 1 AS success;
END;
GO
PRINT N'[OK] SP sp_cotizacion_zona_update';
GO

/* ============================================================================
 * 5) SP sp_cotizacion_zona_delete (soft delete: activo = 0)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_zona_delete','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_zona_delete;
GO
CREATE PROCEDURE dbo.sp_cotizacion_zona_delete
    @id_zona INT
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_zona_pricing WHERE id_zona = @id_zona)
        THROW 51112, N'Zona no encontrada.', 1;
    -- Soft delete (no eliminamos físicamente por integridad referencial)
    UPDATE dbo.cotizacion_zona_pricing SET activo = 0 WHERE id_zona = @id_zona;
    SELECT 1 AS success;
END;
GO
PRINT N'[OK] SP sp_cotizacion_zona_delete';
GO

/* ============================================================================
 * 6) SP sp_cotizacion_forma_pago_update
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_forma_pago_update','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_forma_pago_update;
GO
CREATE PROCEDURE dbo.sp_cotizacion_forma_pago_update
    @id_forma_pago  INT,
    @descripcion    NVARCHAR(500) = NULL,
    @descuento_pct  DECIMAL(5,2) = NULL,
    @activo         BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE id_forma_pago = @id_forma_pago)
        THROW 51113, N'Forma de pago no encontrada.', 1;
    UPDATE dbo.cotizacion_forma_pago
    SET descripcion = ISNULL(@descripcion, descripcion),
        descuento_pct = ISNULL(@descuento_pct, descuento_pct),
        activo = @activo
    WHERE id_forma_pago = @id_forma_pago;
    SELECT 1 AS success;
END;
GO
PRINT N'[OK] SP sp_cotizacion_forma_pago_update';
GO

/* ============================================================================
 * 7) SP sp_cotizacion_forma_pago_delete
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_forma_pago_delete','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_forma_pago_delete;
GO
CREATE PROCEDURE dbo.sp_cotizacion_forma_pago_delete
    @id_forma_pago INT
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE id_forma_pago = @id_forma_pago)
        THROW 51114, N'Forma de pago no encontrada.', 1;
    -- Verificar que no esté en uso
    IF EXISTS (SELECT 1 FROM dbo.cotizacion WHERE id_forma_pago = @id_forma_pago)
        THROW 51115, N'No se puede eliminar: la forma de pago está asignada a cotizaciones existentes.', 1;
    DELETE FROM dbo.cotizacion_forma_pago WHERE id_forma_pago = @id_forma_pago;
    SELECT 1 AS success;
END;
GO
PRINT N'[OK] SP sp_cotizacion_forma_pago_delete';
GO

/* ============================================================================
 * 8) SP sp_cotizacion_cuenta_bancaria_update
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_cuenta_bancaria_update','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_cuenta_bancaria_update;
GO
CREATE PROCEDURE dbo.sp_cotizacion_cuenta_bancaria_update
    @id_cuenta       INT,
    @banco           NVARCHAR(50) = NULL,
    @numero_cuenta   NVARCHAR(50) = NULL,
    @cci             NVARCHAR(50) = NULL,
    @moneda          NVARCHAR(10) = NULL,
    @titular         NVARCHAR(255) = NULL,
    @acepta_tarjeta  BIT = NULL,
    @activo          BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_cuenta_bancaria WHERE id_cuenta = @id_cuenta)
        THROW 51116, N'Cuenta bancaria no encontrada.', 1;
    UPDATE dbo.cotizacion_cuenta_bancaria
    SET banco = ISNULL(@banco, banco),
        numero_cuenta = ISNULL(@numero_cuenta, numero_cuenta),
        cci = ISNULL(@cci, cci),
        moneda = ISNULL(@moneda, moneda),
        titular = ISNULL(@titular, titular),
        acepta_tarjeta = ISNULL(@acepta_tarjeta, acepta_tarjeta),
        activo = @activo
    WHERE id_cuenta = @id_cuenta;
    SELECT 1 AS success;
END;
GO
PRINT N'[OK] SP sp_cotizacion_cuenta_bancaria_update';
GO

/* ============================================================================
 * 9) SP sp_cotizacion_cuenta_bancaria_delete
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_cuenta_bancaria_delete','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_cuenta_bancaria_delete;
GO
CREATE PROCEDURE dbo.sp_cotizacion_cuenta_bancaria_delete
    @id_cuenta INT
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_cuenta_bancaria WHERE id_cuenta = @id_cuenta)
        THROW 51117, N'Cuenta bancaria no encontrada.', 1;
    -- Verificar que no esté en uso como cuenta principal ni como adicional
    IF EXISTS (SELECT 1 FROM dbo.cotizacion WHERE id_cuenta_bancaria = @id_cuenta)
        THROW 51118, N'No se puede eliminar: la cuenta está asignada como principal a cotizaciones existentes.', 1;
    IF EXISTS (SELECT 1 FROM dbo.cotizacion WHERE cuentas_adicionales_json LIKE '%"' + CAST(@id_cuenta AS NVARCHAR(10)) + '"%'
                                            OR cuentas_adicionales_json LIKE '%' + CAST(@id_cuenta AS NVARCHAR(10)) + ',%'
                                            OR cuentas_adicionales_json LIKE '[' + CAST(@id_cuenta AS NVARCHAR(10)) + ']'
                                            OR cuentas_adicionales_json LIKE '[' + CAST(@id_cuenta AS NVARCHAR(10)) + ',%')
        THROW 51119, N'No se puede eliminar: la cuenta está como adicional en al menos una cotización.', 1;
    DELETE FROM dbo.cotizacion_cuenta_bancaria WHERE id_cuenta = @id_cuenta;
    SELECT 1 AS success;
END;
GO
PRINT N'[OK] SP sp_cotizacion_cuenta_bancaria_delete';
GO

/* ============================================================================
 * 10) SP sp_cotizacion_create_v3
 *     Soporta el NUEVO formato de código según el tipo:
 *       - VENTAS (codigo_por_empresa=0): VT-2026-0001 (sin empresa)
 *       - SOPORTE (codigo_por_empresa=1): SOP-EMP000001-2026-0001 (con empresa)
 * ============================================================================
 */
IF OBJECT_ID(N'dbo.sp_cotizacion_create_v3','P') IS NOT NULL DROP PROCEDURE dbo.sp_cotizacion_create_v3;
GO
CREATE PROCEDURE dbo.sp_cotizacion_create_v3
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
        DECLARE @codigo_por_empresa BIT;

        SELECT @prefijo = prefijo_codigo,
               @codigo_por_empresa = codigo_por_empresa
        FROM dbo.cotizacion_tipo_catalogo WHERE id_tipo = @id_tipo;

        DECLARE @codigo_generado NVARCHAR(40);

        IF @codigo_por_empresa = 1
        BEGIN
            -- Formato: PREFIJO-EMP000001-2026-0001
            EXEC dbo.sp_cotizacion_correlativo_siguiente
                 @id_empresa = @id_empresa,
                 @id_tipo = @id_tipo,
                 @anio = @anio,
                 @correlativo = @correlativo OUTPUT;

            IF @correlativo <= 0
                THROW 51008, N'No se pudo obtener correlativo de cotización.', 1;

            SET @codigo_generado =
                @prefijo + '-EMP' + RIGHT('000000' + CAST(@id_empresa AS NVARCHAR(10)), 6)
                        + '-' + CAST(@anio AS NVARCHAR(4))
                        + '-' + RIGHT('0000' + CAST(@correlativo AS NVARCHAR(10)), 4);
        END
        ELSE
        BEGIN
            -- Formato: PREFIJO-2026-0001 (sin empresa, correlativo global por año por tipo)
            -- Usamos id_empresa = 0 como "empresa genérica" en la tabla de correlativos
            DECLARE @id_empresa_global INT = 0;
            EXEC dbo.sp_cotizacion_correlativo_siguiente
                 @id_empresa = @id_empresa_global,
                 @id_tipo = @id_tipo,
                 @anio = @anio,
                 @correlativo = @correlativo OUTPUT;

            IF @correlativo <= 0
                THROW 51008, N'No se pudo obtener correlativo global de cotización.', 1;

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
            N'sp_cotizacion_create_v3 ERROR [' + CAST(@errNum AS NVARCHAR(10)) +
            N'] línea ' + CAST(@errLine AS NVARCHAR(10)) + N': ' + @errMsg;
        THROW 51099, @throwMsg, 1;
    END CATCH
END;
GO
PRINT N'[OK] SP sp_cotizacion_create_v3 (formato de código por tipo)';
GO

PRINT N'';
PRINT N'====================================================================';
PRINT N'  ✅ MIGRACIÓN 08 COMPLETADA';
PRINT N'====================================================================';
PRINT N'  Formato de código:';
PRINT N'    VENTAS  → VT-2026-0001          (correlativo global por año)';
PRINT N'    SOPORTE → SOP-EMP000001-2026-0001 (correlativo por empresa y año)';
PRINT N'    Otros   → PREFIJO-EMP000001-2026-0001 (correlativo por empresa y año)';
PRINT N'';
PRINT N'  SPs creados:';
PRINT N'    sp_cotizacion_zona_add / update / delete';
PRINT N'    sp_cotizacion_forma_pago_update / delete';
PRINT N'    sp_cotizacion_cuenta_bancaria_update / delete';
PRINT N'    sp_cotizacion_create_v3 (nuevo formato de código)';
PRINT N'====================================================================';
GO
