/* ============================================================================
 *  02_cotizaciones_seed_catalogos.sql
 *  ----------------------------------------------------------------------------
 *  Puebla TODOS los catálogos del módulo de cotizaciones con los datos
 *  extraídos del archivo Excel "Cotizacion Ventas V9.53 - V15.xlsx".
 *
 *  Fuentes:
 *    - Hoja "Valores": formas de pago, garantías, plazos, validez,
 *      títulos de cotización, colaboradores
 *    - Hoja "VENTAS" / "SOPORTE": datos de la empresa emisora DICON,
 *      cuentas bancarias BBVA y BCP
 *    - Hoja "Datos": definición de zonas L/C/B
 *
 *  Idempotente: usa IF NOT EXISTS para no duplicar.
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

/* ============================================================================
 * 1) TIPOS DE COTIZACIÓN (7 tipos del Excel)
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE codigo = 'VENTAS')
    INSERT INTO dbo.cotizacion_tipo_catalogo (codigo, nombre, titulo_documento, prefijo_codigo, orden, activo)
    VALUES ('VENTAS', 'Cotización de Ventas', 'COTIZACION DE VENTAS', 'COT', 1, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE codigo = 'SOPORTE')
    INSERT INTO dbo.cotizacion_tipo_catalogo (codigo, nombre, titulo_documento, prefijo_codigo, orden, activo)
    VALUES ('SOPORTE', 'Cotización de Soporte', 'COTIZACION DE SOPORTE', 'SERV', 2, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE codigo = 'MANTENIMIENTO')
    INSERT INTO dbo.cotizacion_tipo_catalogo (codigo, nombre, titulo_documento, prefijo_codigo, orden, activo)
    VALUES ('MANTENIMIENTO', 'Cotización de Mantenimiento', 'COTIZACION DE MANTENIMIENTO', 'MAN', 3, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE codigo = 'REPARACION')
    INSERT INTO dbo.cotizacion_tipo_catalogo (codigo, nombre, titulo_documento, prefijo_codigo, orden, activo)
    VALUES ('REPARACION', 'Cotización de Reparación', 'COTIZACION DE REPARACION', 'REP', 4, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE codigo = 'CABLEADO')
    INSERT INTO dbo.cotizacion_tipo_catalogo (codigo, nombre, titulo_documento, prefijo_codigo, orden, activo)
    VALUES ('CABLEADO', 'Cotización de Cableado', 'COTIZACION DE CABLEADO', 'CAB', 5, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE codigo = 'REPUESTOS')
    INSERT INTO dbo.cotizacion_tipo_catalogo (codigo, nombre, titulo_documento, prefijo_codigo, orden, activo)
    VALUES ('REPUESTOS', 'Cotización de Repuestos', 'COTIZACION DE REPUESTOS', 'RPT', 6, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_tipo_catalogo WHERE codigo = 'SOPORTE_Y_MANT')
    INSERT INTO dbo.cotizacion_tipo_catalogo (codigo, nombre, titulo_documento, prefijo_codigo, orden, activo)
    VALUES ('SOPORTE_Y_MANT', 'Cotización de Soporte y Mantenimiento', 'COTIZACION DE SOPORTE Y MANTENIMIENTO', 'SYM', 7, 1);
GO

/* ============================================================================
 * 2) EMPRESA EMISORA — Ingeniería de Control Digital S.A.
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_empresa_emisora WHERE ruc = '20468246954')
    INSERT INTO dbo.cotizacion_empresa_emisora
        (razon_social, nombre_comercial, ruc, direccion, telefono, correo, web, es_principal, activo)
    VALUES (
        N'Ingeniería de Control Digital S.A.',
        N'DICON',
        N'20468246954',
        N'Av. República de Panamá 5163, San Isidro, Lima',
        N'+51 1 422-8520',
        N'asanchez@dicon.com.pe',
        N'www.dicon.com.pe',
        1, 1
    );
GO

DECLARE @id_emisora_dicon INT = (SELECT TOP 1 id_emisora FROM dbo.cotizacion_empresa_emisora WHERE ruc = '20468246954');

/* ============================================================================
 * 3) ZONAS DE PRICING — Lima Metropolitana / Conos / Balnearios
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_zona_pricing WHERE codigo = 'L')
    INSERT INTO dbo.cotizacion_zona_pricing (codigo, nombre, descripcion, orden, activo)
    VALUES ('L', 'Lima Metropolitana',
        N'Solo para Lima Metropolitana (Cercado, San Luis, Breña, La Victoria, Rímac, Lince, San Miguel, Jesús María, Magdalena, Pueblo Libre, Barranco, Miraflores, Surco, San Borja, Surquillo y San Isidro)',
        1, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_zona_pricing WHERE codigo = 'C')
    INSERT INTO dbo.cotizacion_zona_pricing (codigo, nombre, descripcion, orden, activo)
    VALUES ('C', 'Conos',
        N'Fuera de Lima Metropolitana (Santa Rosa, Carabayllo, Comas, Los Olivos, Independencia, SMP, SJL, Santa Anita, Cieneguilla, Ate Vitarte, La Molina, Chaclacayo, El Agustino, Chorrillos, SJM, VMT, VES, Callao)',
        2, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_zona_pricing WHERE codigo = 'B')
    INSERT INTO dbo.cotizacion_zona_pricing (codigo, nombre, descripcion, orden, activo)
    VALUES ('B', 'Balnearios',
        N'Fuera de Lima Metropolitana (Lurín, Pachacamac, Punta Hermosa, Pucusana, Punta Negra, San Bartolo, Santa María, Ancón, Puente Piedra, Lurigancho, Chosica y Ventanilla)',
        3, 1);
GO

/* ============================================================================
 * 4) FORMAS DE PAGO (9 opciones + descuentos)
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE '70% Adelantado, saldo Finalizado%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'70% Adelantado, saldo Finalizado Instalación de Software y/o Equipo (no la capacitación).', 0.00, 1, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE '70% Adelantado, saldo a 15 días%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'70% Adelantado, saldo a 15 días de Finalizar solo la Instalación de Software y Equipos.', 3.00, 2, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE '70% Adelantado, saldo a 30 días%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'70% Adelantado, saldo a 30 días de Finalizar solo la Instalación de Software y Equipos.', 4.00, 3, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE '100% Adelantado.%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'100% Adelantado.', 5.00, 4, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE '100% Finalizado Instalación%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'100% Finalizado Instalación de Software y Equipos (no la capacitación).', 10.00, 5, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE '100% a 15 días%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'100% a 15 días de Finalizado Instalación de Software y Equipos (no la capacitación).', 15.00, 6, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE '100% a 30 días%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'100% a 30 días de Finalizado Instalación de Software y Equipos (no la capacitación).', 40.00, 7, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE 'Contado Contraentrega%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'Contado Contraentrega', 0.00, 8, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_forma_pago WHERE descripcion LIKE '50% Adelantado, saldo Finalizado%')
    INSERT INTO dbo.cotizacion_forma_pago (descripcion, descuento_pct, orden, activo)
    VALUES (N'50% Adelantado, saldo Finalizado Instalación de Software. (no la capacitación).', 0.00, 9, 1);
GO

/* ============================================================================
 * 5) GARANTÍAS (6 opciones del Excel)
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_garantia WHERE descripcion LIKE '3 Años para los equipos%')
    INSERT INTO dbo.cotizacion_garantia (descripcion, orden, activo)
    VALUES (N'3 Años para los equipos fabricados en Peru y 1 Año para los productos Importados(3)', 1, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_garantia WHERE descripcion LIKE '1 Año por servicios%')
    INSERT INTO dbo.cotizacion_garantia (descripcion, orden, activo)
    VALUES (N'1 Año por servicios realizados', 2, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_garantia WHERE descripcion LIKE '1 Mes por errores%')
    INSERT INTO dbo.cotizacion_garantia (descripcion, orden, activo)
    VALUES (N'1 Mes por errores de impresión(3)', 3, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_garantia WHERE descripcion LIKE '2 Año por repuestos%')
    INSERT INTO dbo.cotizacion_garantia (descripcion, orden, activo)
    VALUES (N'2 Año por repuestos vendidos y 3 meses de mano de obra (3)', 4, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_garantia WHERE descripcion LIKE '2 Años por fallas%')
    INSERT INTO dbo.cotizacion_garantia (descripcion, orden, activo)
    VALUES (N'2 Años por fallas y/o defectos de funcionamiento (3)', 5, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_garantia WHERE descripcion LIKE '1 Año por interface%')
    INSERT INTO dbo.cotizacion_garantia (descripcion, orden, activo)
    VALUES (N'1 Año por interface a sistemas de Planillas', 6, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_garantia WHERE descripcion LIKE '1 Mes por servicios realizados%')
    INSERT INTO dbo.cotizacion_garantia (descripcion, orden, activo)
    VALUES (N'1 Mes por servicios realizados', 7, 1);
GO

/* ============================================================================
 * 6) PLAZOS DE ENTREGA (3 opciones del Excel)
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_plazo_entrega WHERE descripcion LIKE '01 a 07 días útiles a partir de recibida O. Compra%')
    INSERT INTO dbo.cotizacion_plazo_entrega (descripcion, orden, activo)
    VALUES (N'01 a 07 días útiles a partir de recibida O. Compra, pago acordado y cableado aprobado.', 1, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_plazo_entrega WHERE descripcion LIKE '04 a 07 días útiles a partir de recibida O. Compra%')
    INSERT INTO dbo.cotizacion_plazo_entrega (descripcion, orden, activo)
    VALUES (N'04 a 07 días útiles a partir de recibida O. Compra, pago acordado y cableado aprobado.', 2, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_plazo_entrega WHERE descripcion LIKE '01 a 07 días útiles a partir de recibida O. Compra y pago%')
    INSERT INTO dbo.cotizacion_plazo_entrega (descripcion, orden, activo)
    VALUES (N'01 a 07 días útiles a partir de recibida O. Compra y pago acordado.', 3, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_plazo_entrega WHERE descripcion LIKE '05 días útiles a partir de recibida la conformidad%')
    INSERT INTO dbo.cotizacion_plazo_entrega (descripcion, orden, activo)
    VALUES (N'05 días útiles a partir de recibida la conformidad y pago acordado.', 4, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_plazo_entrega WHERE descripcion LIKE '10 días útiles a partir de recibida la conformidad%')
    INSERT INTO dbo.cotizacion_plazo_entrega (descripcion, orden, activo)
    VALUES (N'10 días útiles a partir de recibida la conformidad y pago acordado.', 5, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_plazo_entrega WHERE descripcion LIKE '15 días útiles a partir de recibida la conformidad%')
    INSERT INTO dbo.cotizacion_plazo_entrega (descripcion, orden, activo)
    VALUES (N'15 días útiles a partir de recibida la conformidad y pago acordado.', 6, 1);
GO

/* ============================================================================
 * 7) VALIDEZ (3 opciones: 7, 15, 30 días)
 * ============================================================================
 */
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_validez WHERE dias = 7)
    INSERT INTO dbo.cotizacion_validez (descripcion, dias, orden, activo)
    VALUES (N'La cotización tiene una validez de 7 días calendario', 7, 1, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_validez WHERE dias = 15)
    INSERT INTO dbo.cotizacion_validez (descripcion, dias, orden, activo)
    VALUES (N'La cotización tiene una validez de 15 días calendario', 15, 2, 1);
IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_validez WHERE dias = 30)
    INSERT INTO dbo.cotizacion_validez (descripcion, dias, orden, activo)
    VALUES (N'La cotización tiene una validez de 30 días calendario', 30, 3, 1);
GO

/* ============================================================================
 * 8) CUENTAS BANCARIAS (BBVA y BCP de DICON + tarjeta)
 * ============================================================================
 */
DECLARE @id_emisora INT;
SELECT @id_emisora = id_emisora FROM dbo.cotizacion_empresa_emisora WHERE ruc = '20468246954';

IF @id_emisora IS NOT NULL
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_cuenta_bancaria WHERE id_emisora = @id_emisora AND banco = 'BBVA' AND numero_cuenta = '0011-0147-0100090376')
        INSERT INTO dbo.cotizacion_cuenta_bancaria (id_emisora, banco, numero_cuenta, cci, moneda, titular, acepta_tarjeta, orden, activo)
        VALUES (@id_emisora, 'BBVA', '0011-0147-0100090376', '011-147-000100090376-63', 'PEN',
            N'Ingeniería de Control Digital S.A. - RUC 20468246954', 0, 1, 1);

    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_cuenta_bancaria WHERE id_emisora = @id_emisora AND banco = 'BCP' AND numero_cuenta = '193-1580665-0-11')
        INSERT INTO dbo.cotizacion_cuenta_bancaria (id_emisora, banco, numero_cuenta, cci, moneda, titular, acepta_tarjeta, orden, activo)
        VALUES (@id_emisora, 'BCP', '193-1580665-0-11', '002-193-001580665011-13', 'PEN',
            N'Ingeniería de Control Digital S.A. - RUC 20468246954', 0, 2, 1);

    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_cuenta_bancaria WHERE id_emisora = @id_emisora AND banco = 'BN' AND numero_cuenta = '00068027527')
        INSERT INTO dbo.cotizacion_cuenta_bancaria (id_emisora, banco, numero_cuenta, cci, moneda, titular, acepta_tarjeta, orden, activo)
        VALUES (@id_emisora, 'BN', '00068027527', NULL, 'PEN',
            N'Ingeniería de Control Digital S.A. - Banco de la Nación (detracción)', 0, 3, 1);

    IF NOT EXISTS (SELECT 1 FROM dbo.cotizacion_cuenta_bancaria WHERE id_emisora = @id_emisora AND banco = 'TARJETA')
        INSERT INTO dbo.cotizacion_cuenta_bancaria (id_emisora, banco, numero_cuenta, cci, moneda, titular, acepta_tarjeta, orden, activo)
        VALUES (@id_emisora, 'TARJETA', 'N/A', NULL, 'PEN',
            N'Pago con Tarjeta de Crédito o Débito (MASTERCARD - VISA - DINERS CLUB - AMERICAN EXPRESS)',
            1, 4, 1);
END
GO

PRINT N'[OK] 02_cotizaciones_seed_catalogos.sql ejecutado correctamente.';
GO
