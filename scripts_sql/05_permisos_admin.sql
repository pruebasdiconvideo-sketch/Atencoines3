/* ============================================================================
 *  Script v2: Asignar TODOS los permisos al rol ADMINISTRADOR
 *  Corregido: usa variables escalares (no subqueries en INSERT)
 * ========================================================================== */
USE [MonosyncERP];
GO
SET NOCOUNT ON;
GO

PRINT '=== Asignando permisos al rol ADMINISTRADOR ===';
GO

-- 1. Verificar/crear rol ADMINISTRADOR si no existe
IF NOT EXISTS (SELECT 1 FROM persona_rol WHERE nombre = 'ADMINISTRADOR')
BEGIN
    INSERT INTO persona_rol (nombre, descripcion)
    VALUES ('ADMINISTRADOR', 'Acceso total al sistema');
    PRINT '[OK] Rol ADMINISTRADOR creado';
END
GO

-- 2. Obtener IDs en variables escalares
DECLARE @id_rol_admin INT;
DECLARE @id_persona_admin INT;

SELECT @id_rol_admin = id_rol FROM persona_rol WHERE nombre = 'ADMINISTRADOR';
SELECT @id_persona_admin = id_persona FROM persona WHERE correo = 'admin@soporte.com';

PRINT '[INFO] Rol ADMINISTRADOR ID: ' + ISNULL(CAST(@id_rol_admin AS VARCHAR), 'NULL');
PRINT '[INFO] admin@soporte.com ID: ' + ISNULL(CAST(@id_persona_admin AS VARCHAR), 'NULL');
GO

-- 3. Eliminar permisos previos del rol ADMINISTRADOR
DELETE FROM role_permissions WHERE id_rol IN (SELECT id_rol FROM persona_rol WHERE nombre = 'ADMINISTRADOR');
GO

-- 4. Insertar permisos totales usando JOIN (no subquery en VALUES)
INSERT INTO role_permissions (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
SELECT r.id_rol, m.codigo, 1, 1, 1, 1, 1, 1, 1, GETDATE()
FROM persona_rol r
CROSS JOIN modulo_catalogo m
WHERE r.nombre = 'ADMINISTRADOR' AND m.activo = 1;
GO

PRINT '[OK] Permisos totales asignados al rol ADMINISTRADOR';
GO

-- 5. Asignar rol al usuario admin@soporte.com (usando variables escalares)
DECLARE @id_rol_admin2 INT;
DECLARE @id_persona_admin2 INT;

SELECT @id_rol_admin2 = id_rol FROM persona_rol WHERE nombre = 'ADMINISTRADOR';
SELECT @id_persona_admin2 = id_persona FROM persona WHERE correo = 'admin@soporte.com';

IF @id_persona_admin2 IS NOT NULL AND @id_rol_admin2 IS NOT NULL
BEGIN
    -- Eliminar asignaciones previas del usuario
    DELETE FROM persona_rol_asignacion WHERE id_persona = @id_persona_admin2;

    -- Asignar rol ADMINISTRADOR
    INSERT INTO persona_rol_asignacion (id_persona, id_rol, asignado_por, fecha_asignacion)
    VALUES (@id_persona_admin2, @id_rol_admin2, @id_persona_admin2, GETDATE());

    PRINT '[OK] Rol ADMINISTRADOR asignado a admin@soporte.com (ID: ' + CAST(@id_persona_admin2 AS VARCHAR) + ')';
END
ELSE
BEGIN
    PRINT '[WARN] No se encontro admin@soporte.com o rol ADMINISTRADOR';
END
GO

-- 6. Permisos para rol Tecnico (si existe)
IF EXISTS (SELECT 1 FROM persona_rol WHERE nombre = 'Tecnico')
BEGIN
    DECLARE @id_rol_tecnico INT;
    SELECT @id_rol_tecnico = id_rol FROM persona_rol WHERE nombre = 'Tecnico';

    DELETE FROM role_permissions WHERE id_rol = @id_rol_tecnico;

    INSERT INTO role_permissions (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
    SELECT @id_rol_tecnico, codigo, 1, 1, 1, 0, 0, 1, 1, GETDATE()
    FROM modulo_catalogo WHERE activo = 1;

    PRINT '[OK] Permisos asignados al rol Tecnico';
END
GO

-- 7. Permisos para rol Supervisor (si existe)
IF EXISTS (SELECT 1 FROM persona_rol WHERE nombre = 'Supervisor')
BEGIN
    DECLARE @id_rol_supervisor INT;
    SELECT @id_rol_supervisor = id_rol FROM persona_rol WHERE nombre = 'Supervisor';

    DELETE FROM role_permissions WHERE id_rol = @id_rol_supervisor;

    INSERT INTO role_permissions (id_rol, modulo, ver, registrar, modificar, eliminar, aprobar, exportar, imprimir, fecha_actualizacion)
    SELECT @id_rol_supervisor, codigo, 1, 1, 1, 1, 1, 1, 1, GETDATE()
    FROM modulo_catalogo WHERE activo = 1;

    PRINT '[OK] Permisos asignados al rol Supervisor';
END
GO

-- 8. Verificacion final
DECLARE @total_permisos INT;
SELECT @total_permisos = COUNT(*)
FROM role_permissions rp
INNER JOIN persona_rol r ON rp.id_rol = r.id_rol
WHERE r.nombre = 'ADMINISTRADOR';

PRINT '';
PRINT '=== VERIFICACION ===';
PRINT 'Permisos del rol ADMINISTRADOR: ' + CAST(@total_permisos AS VARCHAR) + ' modulos';
PRINT 'Modulos disponibles: ' + CAST((SELECT COUNT(*) FROM modulo_catalogo WHERE activo = 1) AS VARCHAR);
PRINT '';
PRINT '=== Script completado ===';
GO
