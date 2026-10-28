/* ============================================================================
 *  03_cotizaciones_seed_productos.sql
 *  ----------------------------------------------------------------------------
 *  Importa TODOS los productos/servicios del Excel "Cotizacion Ventas V9.53".
 *
 *  Fuentes:
 *    - Hoja "V. Software": SOA-* (productos de software)
 *    - Hoja "V. Hardware": EQA-*, NFD-* (relojes, lectores, no fabricados)
 *    - Hoja "V. Facial":   FVA-*, FHA-* (relojes faciales)
 *    - Hoja "Software":    ATS-* (servicios de software)
 *    - Hoja "Hardware":    STE-* (servicios de hardware)
 *    - Hoja "Accesorios":  ACE-* (accesorios)
 *    - Hoja "Repuestos":    RCA-* (repuestos)
 *    - Hoja "Fotocheck":    FOT-* (tarjetas PVC)
 *
 *  Tipos de producto:
 *    SOFTWARE, HARDWARE, FACIAL, SERVICIO_SOFTWARE, SERVICIO_HARDWARE,
 *    ACCESORIO, REPUESTO, FOTOCHECK, DESARROLLO, INSTALACION
 *
 *  Los servicios (SERVICIO_SOFTWARE, SERVICIO_HARDWARE, INSTALACION) tienen
 *  aplica_precio_zona = 1 y se guardan 3 precios en cotizacion_producto_precio_zona.
 *  Los productos físicos tienen aplica_precio_zona = 0 (precio único).
 * ============================================================================
 */

USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

DECLARE @id_zona_L INT = (SELECT id_zona FROM dbo.cotizacion_zona_pricing WHERE codigo = 'L');
DECLARE @id_zona_C INT = (SELECT id_zona FROM dbo.cotizacion_zona_pricing WHERE codigo = 'C');
DECLARE @id_zona_B INT = (SELECT id_zona FROM dbo.cotizacion_zona_pricing WHERE codigo = 'B');

/* ============================================================================
 * Helper temporal: tabla para hacer INSERT masivo y luego poblar precio_zona
 * ============================================================================
 */
IF OBJECT_ID('tempdb..#prod_import') IS NOT NULL DROP TABLE #prod_import;
CREATE TABLE #prod_import (
    codigo NVARCHAR(50),
    descripcion NVARCHAR(MAX),
    tipo NVARCHAR(30),
    precio_cliente DECIMAL(12,2),
    precio_distribuidor DECIMAL(12,2),
    precio_mayorista DECIMAL(12,2),
    aplica_zona BIT,
    precio_lima DECIMAL(12,2),
    precio_conos DECIMAL(12,2),
    precio_balne DECIMAL(12,2),
    es_agotado BIT DEFAULT 0
);

/* ============================================================================
 * V. SOFTWARE — Productos (SOA-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('SOA-0003', N'Software de Control de Asistencia Dicon Estándar V.15 - Licencia de 250 Trabajadores', 'SOFTWARE', 1070.16, 891.80, 743.17, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0013', N'Licencia del Modulo Administrador Dicon Estándar V.15 - Licencia de 250 Trabajadores', 'SOFTWARE', 214.03, 178.36, 148.63, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0022', N'Ampliación del Software de Control de Asistencia Dicon Estándar V.15 - Licencia de 100 Trabajadores', 'SOFTWARE', -89.18, -74.32, -61.93, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0032', N'Ampliación de la Licencia del Modulo Administrador Dicon Estándar V.15 - Licencia de 100 Trabajadores', 'SOFTWARE', -17.84, -14.86, -12.39, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0041', N'Migracion al Software de Control de Asistencia Dicon Profesional V.15 - Licencia de 50 Trabajadores', 'SOFTWARE', 490.49, 408.75, 340.62, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0051', N'Migracion de Licencia del Modulo Administrador Dicon Profesional V.15 - Licencia de 50 Trabajadores', 'SOFTWARE', 98.10, 81.75, 68.12, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0061', N'Licencia del Modulo Capturador Dicon - Licencia de 50 Trabajadores', 'SOFTWARE', 244.89, 204.08, 170.06, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0073', N'Software de Control de Asistencia Dicon Profesional V.15 - Licencia de 250 Trabajadores', 'SOFTWARE', 1872.79, 1560.66, 1300.55, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0083', N'Licencia del Modulo Administrador Dicon Profesional V.15 - Licencia de 250 Trabajadores', 'SOFTWARE', 374.56, 312.13, 260.11, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0092', N'Ampliación del Software de Control de Asistencia Dicon Profesional V.15 - Licencia de 100 Trabajadores', 'SOFTWARE', -156.07, -130.05, -108.38, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0102', N'Ampliación de la Licencia del Modulo Administrador Dicon Profesional V.15 - Licencia de 100 Trabajadores', 'SOFTWARE', -31.21, -26.01, -21.68, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0111', N'Migracion al Software de Control de Asistencia Dicon Empresarial V.15 - Licencia de 50 Trabajadores', 'SOFTWARE', 1092.46, 910.38, 758.65, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0121', N'Migracion de Licencia del Modulo Administrador Dicon Empresarial V.15 - Licencia de 50 Trabajadores', 'SOFTWARE', 218.49, 182.08, 151.73, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0136', N'Licencia del Modulo Capturador Dicon - Licencia de 2,000 Trabajadores', 'SOFTWARE', 525.30, 437.75, 364.79, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0142', N'Software de Control de Asistencia Dicon Empresarial V.15 - Licencia de 100 Trabajadores', 'SOFTWARE', 3261.77, 2718.14, 2265.12, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0152', N'Licencia del Modulo Administrador Dicon Empresarial V.15 - Licencia de 100 Trabajadores', 'SOFTWARE', 652.35, 543.63, 453.02, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0166', N'Ampliación del Software de Control de Asistencia Dicon Empresarial V.15 - Licencia de 2,000 Trabajadores', 'SOFTWARE', 1630.89, 1359.07, 1132.56, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0176', N'Ampliación de la Licencia del Modulo Administrador Dicon Empresarial V.15 - Licencia de 2,000 Trabajadores', 'SOFTWARE', 326.18, 271.81, 226.51, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0183', N'Licencia del Modulo Capturador Dicon - Licencia de 250 Trabajadores', 'SOFTWARE', 496.65, 413.88, 344.90, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0301', N'Modulo Web Supervisor Para marcación de asistencia, reporte de marcaciones, solicitud y aprobación de permisos y asignación de horarios. Incluye manual de instalación y configuración del Servidor Web. Licencia de 50 Trabajadores', 'SOFTWARE', 807.52, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0201', N'Aplicativo APP para Marcar Asistencia con toma de foto y geolocalización. Requiere Android 13.0+, GPS, Memoria 20 MB. Licencia de 50 Trabajadores', 'SOFTWARE', 605.00, 504.17, 420.14, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SOA-0202', N'Modulo para Visualizar por TV los Accesos del Trabajador V.01', 'SOFTWARE', 950.00, 791.67, 659.72, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('DES-1234', N'Desarrollo de Software según especificaciones en anexo. No incluye código fuente.', 'DESARROLLO', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * V. SOFTWARE — Servicios (SSO-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('SSO-0001R', N'Instalación y capacitación REMOTA del software Estándar. Instalación y Configuración del Modulo Administrador y Modulo Capturador. Instalación de Base de Datos SQL Server Express 2012. Capacitación: Configuración y Reportes del Modulo Administrador (máximo: 1 hora). Incluye registro de 1 trabajador, 1 horario y en el equipo el registro de 5 trabajadores.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SSO-0002C', N'Instalación y capacitación PRESENCIAL del software Estándar (Conos). Igual alcance que SSO-0001R pero presencial.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SSO-0003L', N'Instalación y capacitación presencial+remoto del software Estándar (Lima Metropolitana). Servicio 1 Presencial + Servicio 2 Remoto.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, 276.86, 361.56, 446.26, 0);
INSERT INTO #prod_import VALUES ('SSO-0004L', N'Instalación y doble capacitación del software Estándar (Lima Metropolitana). Presencial, 2 capacitaciones.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, 347.62, 432.32, 517.02, 0);
INSERT INTO #prod_import VALUES ('SSO-0005C', N'Instalación y capacitación del software Profesional (Conos). Incluye 2 capacitaciones.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, 467.70, 514.97, 599.67, 0);
INSERT INTO #prod_import VALUES ('SSO-0006B', N'Instalación, capacitación y asesoría del software Profesional (Balnearios). Incluye 3 capacitaciones.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, 748.76, 739.95, 824.65, 0);
INSERT INTO #prod_import VALUES ('SSO-0007L', N'Instalación y capacitación del software Empresarial (Lima Metropolitana). 2 capacitaciones.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, 430.25, 514.97, 599.67, 0);
INSERT INTO #prod_import VALUES ('SSO-0008L', N'Instalación, capacitación y asesoría del software Empresarial (Lima Metropolitana). 3 capacitaciones.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, 655.25, 739.95, 824.65, 0);
INSERT INTO #prod_import VALUES ('SSO-0009C', N'Configuración del Servicio de Comunicación a Nivel de una Intranet (Conos). Instalación y configuración del IIS.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, 276.63, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('SSO-0010L', N'Interface de integración a sistema de planillas (Lima Metropolitana). Instalación, configuración y capacitación de la interfaz.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 0, 1200.00, 1200.00, 1200.00, 0);

/* ============================================================================
 * V. HARDWARE — Productos (EQA-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('EQA-0001', N'Reloj Marcador Dicon Modelo CHA600 - AGOTADO. Lector Huella, 15,000 huellas, 100,000 marcaciones, USB/TCP-IP.', 'HARDWARE', 0.00, 0.00, 0.00, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0002', N'Reloj Marcador Dicon Modelo CHA600-P - AGOTADO. Lector Huella+Proximidad, USB/TCP-IP.', 'HARDWARE', 0.00, 0.00, 0.00, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0003', N'Reloj Marcador Dicon Modelo CHA600-GPRS - AGOTADO. Lector Huella, USB/GPRS.', 'HARDWARE', 0.00, 0.00, 0.00, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0004', N'Reloj Marcador Dicon Modelo CHA600-P-GPRS - AGOTADO. Lector Huella+Proximidad, USB/GPRS.', 'HARDWARE', 0.00, 0.00, 0.00, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0005', N'Reloj Marcador Dicon Modelo CTA100-P - AGOTADO. Lector Proximidad, USB/TCP-IP.', 'HARDWARE', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0022', N'Reloj Marcador Dicon Modelo CTA100-P-GPRS - AGOTADO. Lector Proximidad, USB/GPRS.', 'HARDWARE', 0.00, 0.00, 0.00, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0006', N'Reloj Marcador Dicon Modelo EH300. Lector Huella, 7,500 huellas, 50,000 marcaciones, USB/TCP-IP.', 'HARDWARE', 1647.80, 1498.00, 1361.82, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0007', N'Reloj Marcador Dicon Modelo EH300-P. Lector Huella+Proximidad, 7,500 huellas, 2,000 tarjetas, USB/TCP-IP.', 'HARDWARE', 1918.40, 1744.00, 1585.45, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0008', N'Reloj Marcador Dicon Modelo EH300-GPRS. Lector Huella, USB/GPRS.', 'HARDWARE', 1688.50, 1535.00, 1395.45, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0009', N'Reloj Marcador Dicon Modelo EH300-P-GPRS. Lector Huella+Proximidad, USB/GPRS.', 'HARDWARE', 1958.00, 1780.00, 1618.18, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0010', N'Reloj Marcador Dicon Modelo EH300-E. Lector Huella, 15,000 huellas, USB/TCP-IP.', 'HARDWARE', 2071.30, 1883.00, 1711.82, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0011', N'Reloj Marcador Dicon Modelo EH300-E-P. Lector Huella+Proximidad, USB/TCP-IP.', 'HARDWARE', 2083.40, 1894.00, 1721.82, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0012', N'Reloj Marcador Dicon Modelo ET100-P. Lector Proximidad, 5,000 tarjetas, USB/TCP-IP.', 'HARDWARE', 1270.50, 1155.00, 1050.00, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0013', N'Reloj Marcador Dicon Modelo ET100-P-GPRS. Lector Proximidad, USB/GPRS.', 'HARDWARE', 1309.00, 1190.00, 1081.82, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0014', N'Reloj Marcador Dicon Modelo PYH96 - AGOTADO. Lector Huella, 100 huellas, USB/TCP-IP.', 'HARDWARE', 0.00, 0.00, 0.00, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0024', N'Reloj Marcador Dicon Modelo PYH96-P - AGOTADO. Lector Huella+Proximidad, USB/TCP-IP.', 'HARDWARE', 0.00, 0.00, 0.00, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0015', N'Reloj Marcador Dicon Modelo PYT10-P-GPRS. Lector Proximidad, 480 tarjetas, USB/TCP-IP.', 'HARDWARE', 1149.50, 1045.00, 950.00, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0016', N'Reloj Marcador Dicon Modelo PYH96-GPRS - AGOTADO.', 'HARDWARE', 0.00, 0.00, 0.00, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0026', N'Reloj Marcador Dicon Modelo PYH96-P-GPRS - AGOTADO.', 'HARDWARE', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('EQA-0017', N'Reloj Marcador Dicon Modelo PYH96-E. Lector Huella, 480 huellas, USB/TCP-IP.', 'HARDWARE', 1430.00, 1300.00, 1181.82, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0023', N'Reloj Marcador Dicon Modelo PYH96-E-P. Lector Huella+Proximidad, 480/480, USB/TCP-IP.', 'HARDWARE', 1705.00, 1550.00, 1409.09, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0018', N'Reloj Marcador Dicon Modelo PYH96-E-GPRS. Lector Huella, 480 huellas, USB/GPRS.', 'HARDWARE', 1470.70, 1337.00, 1215.45, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0025', N'Reloj Marcador Dicon Modelo PYH96-E-P-GPRS. Lector Huella+Proximidad, USB/TCP-IP.', 'HARDWARE', 1744.60, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0019', N'Lector Externo de Proximidad. Voltaje 5Vdc, Comunicación ASCII.', 'HARDWARE', 462.66, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0020', N'Lector Externo de Huella Digital. Voltaje 12Vdc, RS232, 7,000 huellas.', 'HARDWARE', 878.90, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0021', N'Lector Externo de Huella Digital + Proximidad. Voltaje 12Vdc, RS232, 7,000 huellas.', 'HARDWARE', 1173.70, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0027', N'Lector de DNI. Voltaje 12Vdc, RS232, Escaneado Lineal CCD.', 'HARDWARE', 659.89, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('EQA-0028', N'Lector de Temperatura. Voltaje 12Vdc, RS232, Precisión 0.5°C.', 'HARDWARE', 659.89, NULL, NULL, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * V. HARDWARE — No fabricados por DICON (NFD-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('NFD-0001', N'Lector VIRDI de Huella Digital. Modelo FOH02, USB. Garantía 1 año.', 'HARDWARE', 723.80, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('NFD-0002', N'UPS Forza NT500U. Autonomía 15 minutos. Garantía 1 año.', 'HARDWARE', 225.50, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('NFD-0003', N'Switch TRENDNET Modelo TE100-S8. Garantía 1 año.', 'HARDWARE', 80.76, NULL, NULL, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * V. HARDWARE — Instalación (SEQ-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('SEQ-0001L', N'Instalación y Configuración del Equipo (Lima Metropolitana). Incluye tendido de cableado UTP hasta 6m en canaleta. Servicio en horario de oficina L-V 9-18h.', 'INSTALACION', 0.00, NULL, NULL, 0, 291.53, 386.40, 479.55, 0);

/* ============================================================================
 * V. FACIAL — Relojes faciales (FVA-, FHA-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('FVA-0002', N'Reloj Facial VIRDI Modelo UBio-X Pro2. Distancia de lectura hasta 3m. Detección de mascarilla. Anti-falsificación. Facial+Huella+RFID+PIN. Capacidad: 20,000 faciales, 200,000 huellas. Pantalla 5" táctil. TCP/IP, WIFI opcional.', 'FACIAL', 5342.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FVA-0003', N'Reloj Facial VIRDI Modelo UBio-X Pro2 con Módulo Térmico. Distancia de lectura y temperatura hasta 2m. Mismas características que FVA-0002 + temperatura.', 'FACIAL', 6343.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FVA-0004', N'Reloj Facial VIRDI Modelo UBio-X Face - AGOTADO. Distancia hasta 3m. Facial+RFID+PIN. 20,000 plantillas. Pantalla 5" táctil. TCP/IP, USB 2.0.', 'FACIAL', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('FVA-0005', N'Reloj Facial VIRDI Modelo UBio-X Face con Módulo Térmico - AGOTADO. Distancia 2m. +Temperatura.', 'FACIAL', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('FVA-0006', N'Reloj Facial VIRDI Modelo UBio-X Face PRO. Distancia hasta 2m. Detección de mascarilla. Anti-falsificación. Facial+RFID+PIN. 30,000 plantillas. Pantalla 5". TCP/IP, USB 2.0, WIFI. Clasificación IP55/IP65 opcional.', 'FACIAL', 4400.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FVA-0006.MH', N'Reloj Facial VIRDI Modelo UBio-X Face PRO con Módulo de Huella XSCAN-FP5. Incluye lector de huella hasta 100,000 plantillas, 500 DPI.', 'FACIAL', 5058.00, 4598.18, 4180.17, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FHA-0002', N'Reloj de Reconocimiento Facial Modelo HF-R2. 20,000 plantillas faciales. 100,000 registros. Pantalla IPS 8". Velocidad <1s. TCP/IP y WIFI.', 'FACIAL', 3099.95, 2818.14, 2561.95, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * Software — Servicios de soporte técnico de software (ATS-)
 *   3 precios por zona: L/C/B
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('ATS-001L', N'Instalación del Motor de Base de Datos SQL Server y SQL Management. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 279.66, 356.66, 433.66, 0);
INSERT INTO #prod_import VALUES ('ATS-002L', N'Soporte Técnico de Software. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 205.10, 289.80, 357.47, 0);
INSERT INTO #prod_import VALUES ('ATS-003L', N'Instalación del Software. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 195.77, 276.62, 357.47, 0);
INSERT INTO #prod_import VALUES ('ATS-004L', N'Capacitación del Software Versión Estándar. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 205.10, 289.80, 374.50, 0);
INSERT INTO #prod_import VALUES ('ATS-005L', N'Capacitación del Software Versión Profesional. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 257.03, 341.07, 425.77, 0);
INSERT INTO #prod_import VALUES ('ATS-006L', N'Capacitación del Software Versión Empresarial. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 307.63, 392.33, 477.03, 0);
INSERT INTO #prod_import VALUES ('ATS-007L', N'Actualización del Software Estándar. Incluye: Instalación, Actualización de BD, Capacitación, DVD. Garantía 1 año. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 333.07, 417.77, 502.47, 0);
INSERT INTO #prod_import VALUES ('ATS-008L', N'Actualización del Software Profesional. Igual paquete que ATS-007L pero versión Profesional. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 549.84, 634.54, 719.24, 0);
INSERT INTO #prod_import VALUES ('ATS-009L', N'Actualización del Software Empresarial. Igual paquete pero versión Empresarial. Lima Metropolitana.', 'SERVICIO_SOFTWARE', 0.00, NULL, NULL, 1, 769.77, 854.47, 939.17, 0);
INSERT INTO #prod_import VALUES ('ATS-0010', N'Servicio de movilidad adicional para Callao y Conos.', 'SERVICIO_SOFTWARE', 70.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ATS-0011', N'Servicio de movilidad adicional para Balnearios.', 'SERVICIO_SOFTWARE', 140.00, NULL, NULL, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * Hardware — Servicios de soporte técnico de equipo (STE-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('STE-001D', N'Diagnóstico de Equipo. Revisión y mediciones de placa electrónica y accesorios. Informe técnico. Solo en instalaciones de DICON.', 'SERVICIO_HARDWARE', 111.86, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-001R', N'Servicio de Cambio de Repuesto (EN GARANTÍA). Solo en DICON.', 'SERVICIO_HARDWARE', 76.80, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-002R', N'Servicio de Cambio de Repuesto (SIN GARANTÍA). Solo en DICON.', 'SERVICIO_HARDWARE', 127.12, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-003R', N'Servicio de Reparación de Equipo en DICON. Reparar componentes dañados o malogrados. Pruebas de funcionamiento.', 'SERVICIO_HARDWARE', 390.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-002L', N'Soporte Técnico de Equipo en Sitio. Revisión y pruebas en instalaciones del cliente. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 167.81, 244.81, 321.81, 0);
INSERT INTO #prod_import VALUES ('STE-002B', N'Soporte Técnico de Equipo en Sitio. Balnearios (Lurín, Pachacamac, etc.).', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 321.81, 310.04, 387.04, 0);
INSERT INTO #prod_import VALUES ('STE-003C', N'Soporte Técnico de Equipo en Sitio con Reemplazo de Equipo. Recojo, diagnóstico, cotización de reparación, instalación reparada. Conos.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 310.05, 386.40, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-004D', N'Mantenimiento Preventivo. En DICON. Incluye desmontaje, limpieza, descarbonización, cambio de capacitores, conectores, memoria y chip del reloj. NO incluye LCD, teclado, tarjeta TCP/IP o placa principal.', 'SERVICIO_HARDWARE', 160.35, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-005L', N'Mantenimiento Preventivo con Servicio de Movilidad. Lima Metropolitana. Mismo alcance que STE-004D pero en sitio.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 233.04, 310.04, 387.04, 0);
INSERT INTO #prod_import VALUES ('STE-006C', N'Mantenimiento Preventivo con Servicio de Movilidad y Reemplazo de Equipo. Conos.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 356.66, 356.66, 433.66, 0);
INSERT INTO #prod_import VALUES ('STE-0007', N'Cambio de Tarjeta GPRS. Solo en DICON.', 'SERVICIO_HARDWARE', 167.79, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-008L', N'Cambio de Tarjeta GPRS en Sitio. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 261.02, 338.02, 415.02, 0);
INSERT INTO #prod_import VALUES ('STE-0022', N'Actualización de Equipo de Asistencia. Actualización de firmware, cambio de memoria, limpieza básica. En DICON.', 'SERVICIO_HARDWARE', 115.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-0009', N'Cableado. Incluye materiales y mano de obra.', 'SERVICIO_HARDWARE', 508.47, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-010L', N'Revisión de Cableado. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 33.00, 66.00, 99.00, 0);
INSERT INTO #prod_import VALUES ('STE-020', N'Servicio de Mantenimiento. En DICON. Mismo alcance que STE-004D.', 'SERVICIO_HARDWARE', 233.05, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-011D', N'Mantenimiento Preventivo Plus. En DICON. Incluye informe técnico. NO incluye LCD, teclado, TCP/IP o placa principal.', 'SERVICIO_HARDWARE', 344.96, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-012L', N'Mantenimiento Preventivo Plus con Servicio de Movilidad. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 399.05, 477.86, 554.86, 0);
INSERT INTO #prod_import VALUES ('STE-013B', N'Mantenimiento Preventivo Plus con Servicio de Movilidad y Reemplazo de Equipo. Balnearios.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 620.09, 543.09, 620.09, 0);
INSERT INTO #prod_import VALUES ('STE-014L', N'Servicio de grabación de Huellas Digitales por 1 Hora. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 186.58, 263.58, 220.51, 0);
INSERT INTO #prod_import VALUES ('STE-015L', N'Servicio de grabación de Huellas Digitales por 2 Horas. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 220.51, 297.51, 374.51, 0);
INSERT INTO #prod_import VALUES ('STE-016L', N'Servicio de grabación de Huellas Digitales por 3 Horas. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 254.43, 331.43, 408.43, 0);
INSERT INTO #prod_import VALUES ('STE-017L', N'Servicio de grabación de Huellas Digitales por 4 Horas. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 288.35, 365.35, 442.35, 0);
INSERT INTO #prod_import VALUES ('STE-018L', N'Servicio de grabación de Huellas Digitales por 5 Horas. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 322.28, 399.28, 476.28, 0);
INSERT INTO #prod_import VALUES ('STE-019L', N'Servicio de grabación de Huellas Digitales por 6 Horas. Lima Metropolitana.', 'SERVICIO_HARDWARE', 0.00, NULL, NULL, 1, 356.20, 433.20, 510.20, 0);
INSERT INTO #prod_import VALUES ('STE-0020', N'Servicio de movilidad adicional para Callao y Conos (Herramientas Pesadas o Gran Altura).', 'SERVICIO_HARDWARE', 110.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('STE-0021', N'Servicio de movilidad adicional para Balnearios.', 'SERVICIO_HARDWARE', 200.00, NULL, NULL, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * Accesorios (ACE-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('ACE-0001', N'Adaptador de Voltaje 12Vdc / 5Amp. Para Relojes Marcadores Modelos Pyme, Empresarial y Corporativo.', 'ACCESORIO', 91.03, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0002', N'Adaptador de voltaje (para cerradura electromagnética). Voltaje de salida 12VDD a 5Amp.', 'ACCESORIO', 91.03, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0003', N'Base Protectora Metálica. Para equipo Pyme.', 'ACCESORIO', 112.39, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0004', N'Base Protectora Metálica. Para Equipo Empresarial.', 'ACCESORIO', 127.48, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0005', N'Base Protectora Metálica. Para Equipo Corporativo.', 'ACCESORIO', 166.36, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0006', N'Caja Metálica para Equipo de Asistencia. Para equipos Pyme, empresarial y corporativo.', 'ACCESORIO', 152.54, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0007', N'Caja de Protección Acrílico para Equipo Facial. Para equipos UBio-X FACE y UBio-X FACE PRO.', 'ACCESORIO', 199.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0008', N'Contacto Magnético Adosable. Tipo NC. Distancia 15-20mm. Tamaño 64x19x13mm.', 'ACCESORIO', 21.19, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0009', N'Cerradura Electromagnética de 600lbs. Para control de Acceso.', 'ACCESORIO', 195.77, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0010', N'Soporte tipo U, L y Z. Para chapa electromagnética.', 'ACCESORIO', 69.50, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0011', N'Base Acrílica para Equipo de Asistencia. Para equipo Pyme.', 'ACCESORIO', 98.10, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('ACE-0012', N'Placa Lector de Proximidad de 125Khz. Incluye servicio de instalación de la placa y pruebas.', 'ACCESORIO', 367.07, NULL, NULL, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * Repuestos (RCA-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('RCA-0016', N'Lector de proximidad (RFID).', 'REPUESTO', 278.36, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0020', N'Lector de Huella Digital - DESCONTINUADO. Capacidad de 100 plantillas.', 'REPUESTO', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('RCA-0021', N'Lector de Huella Digital - DESCONTINUADO. Capacidad de 480 plantillas.', 'REPUESTO', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('RCA-0022', N'Lector de Huella Digital - DESCONTINUADO. Capacidad de 3,500 plantillas.', 'REPUESTO', 0.00, NULL, NULL, 0, NULL, NULL, NULL, 1);
INSERT INTO #prod_import VALUES ('RCA-0023', N'Lector de Huella Digital. Capacidad de 7,000 plantillas.', 'REPUESTO', 670.25, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0024', N'Escaner del Lector de Huella Digital. Capacidad de 7,000 plantillas.', 'REPUESTO', 302.50, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0008', N'Placa Convertidor de RS232 a TCP/IP. Interfase de red 10/100 (Detección automática).', 'REPUESTO', 248.05, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0052', N'Placa de Comunicación GPRS. Adaptable a equipos modelo Empresarial y Corporativo.', 'REPUESTO', 260.09, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0025', N'Teclado 4X4 con botones Blancos (para reloj Corporativo).', 'REPUESTO', 292.37, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0026', N'Teclado 4X3 con botones Negros (para reloj Empresarial y Pyme).', 'REPUESTO', 100.07, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0027', N'Pantalla de Cristal Liquido LCD 2X16 Con Backlight. Para reloj Pyme.', 'REPUESTO', 36.36, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0028', N'Pantalla de Cristal Liquido LCD 4X20 Con Backlight. Para Equipo Empresarial.', 'REPUESTO', 101.69, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0029', N'Pantalla Grafica de Cristal Liquido GLCD 128X64 Con Backlight. Para reloj Corporativo.', 'REPUESTO', 220.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0031', N'Microcontrolador.', 'REPUESTO', 181.50, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0053', N'Memoria de 480 huella para lector Pyme.', 'REPUESTO', 275.00, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0040', N'Sticker Adhesivo (para reloj Corporativo).', 'REPUESTO', 23.30, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0041', N'Sticker Adhesivo (para reloj Empresarial).', 'REPUESTO', 13.98, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0042', N'Sticker Adhesivo (para reloj Pyme).', 'REPUESTO', 13.98, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0049', N'Caja Corporativa.', 'REPUESTO', 154.84, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0050', N'Caja Empresarial.', 'REPUESTO', 120.09, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('RCA-0051', N'Caja Pyme.', 'REPUESTO', 89.58, NULL, NULL, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * Fotocheck (FOT-)
 * ============================================================================
 */
INSERT INTO #prod_import VALUES ('FOT-0001', N'Tarjeta de PVC en Blanco y Negro. Anverso con Impresión Blanco y Negro y Laminado Térmico. Reverso en Blanco.', 'FOTOCHECK', 7.68, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0002', N'Tarjeta de PVC en Blanco y Negro Total. Anverso y Reverso con Impresión Blanco y Negro y Laminado Térmico. Diseño Personalizado.', 'FOTOCHECK', 11.75, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0003', N'Tarjeta de PVC Full Color Simple. Anverso Full Color y Laminado. Reverso en Blanco. Diseño Personalizado.', 'FOTOCHECK', 11.84, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0004', N'Tarjeta de PVC Full Color. Anverso Full Color, Reverso Blanco y Negro. Laminado. Diseño Personalizado.', 'FOTOCHECK', 15.31, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0005', N'Tarjeta de PVC Full Color Total. Anverso y Reverso Full Color. Laminado. Diseño Personalizado.', 'FOTOCHECK', 19.46, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0006', N'Portafotocheck Acrílico Transparente.', 'FOTOCHECK', 4.61, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0007', N'Cinta sin Impresión de 1cm. Color Azul Marino.', 'FOTOCHECK', 5.71, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0008', N'Tarjeta de Proximidad en Blanco - Delgada.', 'FOTOCHECK', 14.41, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0009', N'Sujetador Retráctil (yo-yo). Sin Logo Color Negro.', 'FOTOCHECK', 8.59, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0010', N'Adhesivo para Tarjeta de Proximidad. Impreso Full Color. Diseño Personalizado.', 'FOTOCHECK', 17.17, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0011', N'Adhesivo para Tarjeta de Proximidad. Impreso Blanco y Negro. Diseño Personalizado.', 'FOTOCHECK', 13.02, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0012', N'Tarjeta de Proximidad con Adhesivo. Impreso Full Color. Diseño Personalizado.', 'FOTOCHECK', 27.84, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0013', N'Tarjeta de Proximidad con Adhesivo. Impreso Blanco y Negro. Diseño Personalizado.', 'FOTOCHECK', 23.69, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0014', N'Tarjeta de Proximidad con Adhesivo. Anverso Full Color, Reverso Blanco y Negro. Laminado. Diseño Personalizado.', 'FOTOCHECK', 38.36, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0015', N'Diseño Grafico para Fotocheck. Solo pedidos < 50 unidades. No incluye servicio de toma de fotos ni retoques.', 'FOTOCHECK', 85.75, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0016', N'Servicio de Transporte. Solo para Lima Metropolitana.', 'FOTOCHECK', 43.38, NULL, NULL, 0, NULL, NULL, NULL, 0);
INSERT INTO #prod_import VALUES ('FOT-0017', N'Tarjeta de Proximidad en Blanco - Gruesa.', 'FOTOCHECK', 18.41, NULL, NULL, 0, NULL, NULL, NULL, 0);

/* ============================================================================
 * Insertar en cotizacion_producto_catalogo (solo si no existe)
 * ============================================================================
 */
INSERT INTO dbo.cotizacion_producto_catalogo
    (codigo, descripcion, id_tipo_producto, unidad_medida, precio_cliente,
     precio_distribuidor, precio_mayorista, afecto_igv, moneda,
     aplica_precio_zona, activo, es_producto_agotado)
SELECT
    p.codigo, p.descripcion, p.tipo, 'UND',
    p.precio_cliente, p.precio_distribuidor, p.precio_mayorista,
    1, 'PEN',
    p.aplica_zona, 1, p.es_agotado
FROM #prod_import p
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.cotizacion_producto_catalogo c WHERE c.codigo = p.codigo
);

/* ============================================================================
 * Insertar precios por zona en cotizacion_producto_precio_zona
 * Solo para productos con aplica_precio_zona = 1
 * ============================================================================
 */
INSERT INTO dbo.cotizacion_producto_precio_zona (id_producto, id_zona, precio)
SELECT c.id_producto, @id_zona_L, p.precio_lima
FROM #prod_import p
INNER JOIN dbo.cotizacion_producto_catalogo c ON c.codigo = p.codigo
WHERE p.aplica_zona = 1 AND p.precio_lima IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM dbo.cotizacion_producto_precio_zona pz
                  WHERE pz.id_producto = c.id_producto AND pz.id_zona = @id_zona_L);

INSERT INTO dbo.cotizacion_producto_precio_zona (id_producto, id_zona, precio)
SELECT c.id_producto, @id_zona_C, p.precio_conos
FROM #prod_import p
INNER JOIN dbo.cotizacion_producto_catalogo c ON c.codigo = p.codigo
WHERE p.aplica_zona = 1 AND p.precio_conos IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM dbo.cotizacion_producto_precio_zona pz
                  WHERE pz.id_producto = c.id_producto AND pz.id_zona = @id_zona_C);

INSERT INTO dbo.cotizacion_producto_precio_zona (id_producto, id_zona, precio)
SELECT c.id_producto, @id_zona_B, p.precio_balne
FROM #prod_import p
INNER JOIN dbo.cotizacion_producto_catalogo c ON c.codigo = p.codigo
WHERE p.aplica_zona = 1 AND p.precio_balne IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM dbo.cotizacion_producto_precio_zona pz
                  WHERE pz.id_producto = c.id_producto AND pz.id_zona = @id_zona_B);

DROP TABLE #prod_import;

PRINT N'[OK] 03_cotizaciones_seed_productos.sql ejecutado correctamente.';
GO

/* ============================================================================
 * Verificación: contadores
 * ============================================================================
 */
DECLARE @total INT;
SELECT @total = COUNT(*) FROM dbo.cotizacion_producto_catalogo;
PRINT N'[INFO] Productos importados en catálogo: ' + CAST(@total AS NVARCHAR(10));

DECLARE @total_zonas INT;
SELECT @total_zonas = COUNT(*) FROM dbo.cotizacion_producto_precio_zona;
PRINT N'[INFO] Precios por zona registrados: ' + CAST(@total_zonas AS NVARCHAR(10));
GO
