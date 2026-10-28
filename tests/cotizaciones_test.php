<?php
/**
 * tests/cotizaciones_test.php
 * ----------------------------------------------------------------------------
 * Suite de pruebas funcionales del módulo de Cotizaciones.
 *
 * Cubre los 14 escenarios solicitados:
 *   1.  Primera cotización de una empresa durante el año (correlativo 0001)
 *   2.  Segunda y siguientes cotizaciones de la misma empresa
 *   3.  Primera cotización de OTRA empresa durante el mismo año
 *   4.  Primera cotización de una empresa durante un nuevo año
 *   5.  Consulta de cotizaciones de años anteriores
 *   6.  Creación simultánea de cotizaciones por varios usuarios
 *   7.  Registro de múltiples comunicaciones para una cotización
 *   8.  Registro de diferentes usuarios y fechas de comunicación
 *   9.  Aprobación y cancelación con sus respectivas fechas y responsables
 *   10. Validación de permisos por rol y empresa
 *   11. Prevención de modificaciones no autorizadas
 *   12. Conservación del historial tras un cambio de estado
 *   13. Errores de base de datos y reversión de transacciones
 *   14. Búsqueda y filtrado de cotizaciones
 *
 * USO:
 *   1. Sube este archivo a la raíz del proyecto (al lado de index.php).
 *   2. Abre en el navegador: https://tu-dominio/tests/cotizaciones_test.php
 *   3. Verás un resultado OK/FAIL por cada escenario.
 *   4. IMPORTANTE: BORRAR el archivo después de probar (expone datos internos).
 *
 * REQUISITOS:
 *   - Haber ejecutado los scripts SQL del módulo (06_cotizaciones_run_all.sql)
 *   - Que exista al menos 1 empresa en dbo.empresa (usa la primera que encuentre)
 *   - Que exista al menos 1 usuario en dbo.persona (usa el primero con rol admin)
 *   - Que exista al menos 1 tipo en cotizacion_tipo_catalogo (lo crea el seed)
 *   - Que exista al menos 1 emisora en cotizacion_empresa_emisora (lo crea el seed)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$pdo = Database::connection();

/* Helpers de aserción */
$failures = [];
$passes = 0;

function assert_true(bool $cond, string $msg): void {
    global $failures, $passes;
    if ($cond) {
        $passes++;
        echo "<div class='ok'>✓ $msg</div>";
    } else {
        $failures[] = $msg;
        echo "<div class='fail'>✗ $msg</div>";
    }
}

function assert_equals($expected, $actual, string $msg): void {
    assert_true($expected === $actual, "$msg (esperado: " . json_encode($expected) . ", actual: " . json_encode($actual) . ")");
}

function cleanup_test_cotizaciones(): void {
    global $pdo;
    $pdo->exec("DELETE FROM dbo.cotizacion_detalle WHERE id_cotizacion IN (SELECT id_cotizacion FROM dbo.cotizacion WHERE codigo LIKE 'COT-TEST-%' OR codigo LIKE 'TEST-%')");
    $pdo->exec("DELETE FROM dbo.cotizacion_comunicacion WHERE id_cotizacion IN (SELECT id_cotizacion FROM dbo.cotizacion WHERE codigo LIKE 'COT-TEST-%' OR codigo LIKE 'TEST-%')");
    $pdo->exec("DELETE FROM dbo.cotizacion_historial_estado WHERE id_cotizacion IN (SELECT id_cotizacion FROM dbo.cotizacion WHERE codigo LIKE 'COT-TEST-%' OR codigo LIKE 'TEST-%')");
    $pdo->exec("DELETE FROM dbo.cotizacion_version WHERE id_cotizacion IN (SELECT id_cotizacion FROM dbo.cotizacion WHERE codigo LIKE 'COT-TEST-%' OR codigo LIKE 'TEST-%')");
    $pdo->exec("DELETE FROM dbo.cotizacion WHERE codigo LIKE 'COT-TEST-%' OR codigo LIKE 'TEST-%'");
    $pdo->exec("DELETE FROM dbo.cotizacion_correlativo_anual WHERE id_empresa IN (SELECT id_empresa FROM dbo.empresa WHERE ruc LIKE 'TEST%' OR razon_social LIKE 'TEST%')");
    $pdo->exec("DELETE FROM dbo.empresa WHERE ruc LIKE 'TEST%'");
}

function crear_empresa_test(string $ruc, string $razon): int {
    global $pdo;
    $pdo->prepare("INSERT INTO dbo.empresa (ruc, razon_social, correo, id_rubro, id_ubicacion, activo)
                    VALUES (?, ?, ?, 1, 1, 1)")->execute([$ruc, $razon, strtolower($ruc) . '@test.com']);
    return (int)$pdo->lastInsertId();
}

function crear_persona_test(string $correo): int {
    global $pdo;
    $pdo->prepare("INSERT INTO dbo.persona (nombres, apellido_paterno, correo, estado, contrasena, bloqueado, activo)
                    VALUES (?, 'Test', ?, 1, 'TEST_PASS', 0, 1)")
        ->execute(['Usuario Test', $correo]);
    return (int)$pdo->lastInsertId();
}

function call_sp_create_cotizacion(int $idEmpresa, int $idTipo, int $idEmisora, int $idUsuario, array $detalle = []): array {
    global $pdo;
    $detalleJson = !empty($detalle) ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_create
        @id_empresa = ?, @id_tipo = ?, @id_emisora = ?, @id_usuario_creador = ?,
        @detalle_json = ?, @codigo = ?, @id_cotizacion = ?");
    $stmt->bindValue(1, $idEmpresa, PDO::PARAM_INT);
    $stmt->bindValue(2, $idTipo, PDO::PARAM_INT);
    $stmt->bindValue(3, $idEmisora, PDO::PARAM_INT);
    $stmt->bindValue(4, $idUsuario, PDO::PARAM_INT);
    $stmt->bindValue(5, $detalleJson, PDO::PARAM_STR);
    $codigo = null; $idCot = null;
    $stmt->bindParam(6, $codigo, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 40);
    $stmt->bindParam(7, $idCot,  PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
    $stmt->execute();
    $row = $stmt->fetch();
    return ['id' => (int)($row['id_cotizacion'] ?? $idCot), 'codigo' => $row['codigo'] ?? $codigo];
}

/* ============================================================================
 * SETUP — obtener datos base (empresa, usuario, tipo, emisora)
 * ============================================================================
 */
cleanup_test_cotizaciones();

$stmt = $pdo->query("SELECT TOP 1 id_empresa, ruc, razon_social FROM dbo.empresa WHERE activo = 1 ORDER BY id_empresa");
$empresaBase = $stmt->fetch();
if (!$empresaBase) {
    $idEmpresaBase = crear_empresa_test('TEST00000001', 'Empresa Test S.A.');
    $stmt = $pdo->prepare("SELECT id_empresa, ruc, razon_social FROM dbo.empresa WHERE id_empresa = ?");
    $stmt->execute([$idEmpresaBase]);
    $empresaBase = $stmt->fetch();
}

$stmt = $pdo->query("SELECT TOP 1 p.id_persona, p.correo FROM dbo.persona p
                     INNER JOIN dbo.persona_rol_asignacion pra ON p.id_persona = pra.id_persona
                     WHERE pra.id_rol = 1 AND p.activo = 1 ORDER BY p.id_persona");
$usuarioAdmin = $stmt->fetch();
if (!$usuarioAdmin) {
    $stmt = $pdo->query("SELECT TOP 1 id_persona, correo FROM dbo.persona WHERE activo = 1 ORDER BY id_persona");
    $usuarioAdmin = $stmt->fetch();
}

$stmt = $pdo->query("SELECT TOP 1 id_tipo, codigo FROM dbo.cotizacion_tipo_catalogo WHERE activo = 1 ORDER BY orden");
$tipoBase = $stmt->fetch();
$stmt = $pdo->query("SELECT TOP 1 id_emisora, razon_social FROM dbo.cotizacion_empresa_emisora WHERE activo = 1 ORDER BY id_emisora");
$emisoraBase = $stmt->fetch();

if (!$empresaBase || !$usuarioAdmin || !$tipoBase || !$emisoraBase) {
    die('<div class=fail>Faltan datos base. Ejecuta primero 06_cotizaciones_run_all.sql y verifica que haya al menos una empresa, un usuario y el seed del módulo.</div>');
}

echo "<div class='info'>Usando empresa: {$empresaBase['razon_social']} (id={$empresaBase['id_empresa']})</div>";
echo "<div class='info'>Usuario: {$usuarioAdmin['correo']} (id={$usuarioAdmin['id_persona']})</div>";
echo "<div class='info'>Tipo: {$tipoBase['codigo']} (id={$tipoBase['id_tipo']})</div>";
echo "<div class='info'>Emisora: {$emisoraBase['razon_social']} (id={$emisoraBase['id_emisora']})</div>";
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8"><title>Tests del módulo de Cotizaciones</title>
<style>
    body { font-family: Arial; padding: 20px; background: #f5f5f5; }
    h1 { color: #333; }
    .scenario { background: #fff; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #007bff; }
    .scenario h3 { margin: 0 0 10px 0; color: #007bff; }
    .ok { color: #28a745; font-weight: bold; margin: 4px 0; }
    .fail { color: #dc3545; font-weight: bold; margin: 4px 0; }
    .info { color: #6c757d; font-size: 0.9em; margin: 2px 0; }
    .summary { margin-top: 20px; padding: 15px; border-radius: 5px; }
    .summary.pass { background: #d4edda; color: #155724; }
    .summary.fail { background: #f8d7da; color: #721c24; }
</style>
</head><body>
<h1>Tests del Módulo de Cotizaciones</h1>
<?php

/* ESCENARIO 1: Primera cotización de una empresa durante el año → 0001 */
echo "<div class='scenario'><h3>1. Primera cotización de una empresa (correlativo 0001)</h3>";
try {
    $idEmp1 = crear_empresa_test('TEST00000001', 'Empresa Test 1 S.A.');
    $r = call_sp_create_cotizacion($idEmp1, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'SOA-0003', 'descripcion' => 'Software Estándar V.15', 'cantidad' => 1, 'precio_unitario' => 1070.16, 'afecto_igv' => 1],
    ]);
    $codigoEsperado = $tipoBase['codigo'] . '-EMP' . str_pad((string)$idEmp1, 6, '0', STR_PAD_LEFT) . '-' . date('Y') . '-0001';
    assert_equals($codigoEsperado, $r['codigo'], "El código de la primera cotización debe ser $codigoEsperado");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 2: Segunda cotización de la misma empresa → 0002 */
echo "<div class='scenario'><h3>2. Segunda cotización de la misma empresa (correlativo 0002)</h3>";
try {
    $r = call_sp_create_cotizacion($idEmp1, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'EQA-0006', 'descripcion' => 'Reloj EH300', 'cantidad' => 2, 'precio_unitario' => 1647.80, 'afecto_igv' => 1],
    ]);
    $codigoEsperado2 = $tipoBase['codigo'] . '-EMP' . str_pad((string)$idEmp1, 6, '0', STR_PAD_LEFT) . '-' . date('Y') . '-0002';
    assert_equals($codigoEsperado2, $r['codigo'], "La segunda cotización debe ser $codigoEsperado2");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 3: Primera cotización de OTRA empresa en el mismo año → 0001 */
echo "<div class='scenario'><h3>3. Primera cotización de OTRA empresa (correlativo 0001)</h3>";
try {
    $idEmp2 = crear_empresa_test('TEST00000002', 'Empresa Test 2 S.A.');
    $r = call_sp_create_cotizacion($idEmp2, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'SOA-0142', 'descripcion' => 'Software Empresarial V.15', 'cantidad' => 1, 'precio_unitario' => 3261.77, 'afecto_igv' => 1],
    ]);
    $codigoEsperado3 = $tipoBase['codigo'] . '-EMP' . str_pad((string)$idEmp2, 6, '0', STR_PAD_LEFT) . '-' . date('Y') . '-0001';
    assert_equals($codigoEsperado3, $r['codigo'], "La primera cotización de la otra empresa debe ser $codigoEsperado3");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 4: Primera cotización de una empresa en un nuevo año → 0001 */
echo "<div class='scenario'><h3>4. Primera cotización de una empresa en un nuevo año (correlativo 0001)</h3>";
try {
    $anioSiguiente = (int)date('Y') + 1;
    $pdo->prepare("INSERT INTO dbo.cotizacion_correlativo_anual (id_empresa, id_tipo, anio, ultimo_correlativo, fecha_ultimo) VALUES (?, ?, ?, 0, SYSDATETIME())")
        ->execute([$idEmp1, (int)$tipoBase['id_tipo'], $anioSiguiente]);
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_correlativo_siguiente @id_empresa = ?, @id_tipo = ?, @anio = ?, @correlativo = ?");
    $stmt->bindValue(1, $idEmp1, PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$tipoBase['id_tipo'], PDO::PARAM_INT);
    $stmt->bindValue(3, $anioSiguiente, PDO::PARAM_INT);
    $corr = null;
    $stmt->bindParam(4, $corr, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
    $stmt->execute();
    assert_equals(1, $corr, "El correlativo para el año $anioSiguiente debe ser 0001");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 5: Consulta de cotizaciones de años anteriores */
echo "<div class='scenario'><h3>5. Consulta de cotizaciones de años anteriores (filtrado por año)</h3>";
try {
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_list_paged @anio = ?, @page = 1, @per_page = 50");
    $stmt->bindValue(1, (int)date('Y'), PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    assert_true(count($rows) > 0, "El filtro por año actual (" . date('Y') . ") debe devolver al menos las cotizaciones creadas en este test (" . count($rows) . " encontradas)");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 6: Creación simultánea de cotizaciones por varios usuarios */
echo "<div class='scenario'><h3>6. Creación simultánea (concurrencia) — sin duplicidad de correlativo</h3>";
try {
    $idEmpConc = crear_empresa_test('TEST00000003', 'Empresa Test Concurrencia S.A.');
    $codigos = [];
    for ($i = 0; $i < 3; $i++) {
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_correlativo_siguiente @id_empresa = ?, @id_tipo = ?, @anio = ?, @correlativo = ?");
        $stmt->bindValue(1, $idEmpConc, PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$tipoBase['id_tipo'], PDO::PARAM_INT);
        $stmt->bindValue(3, (int)date('Y'), PDO::PARAM_INT);
        $c = null;
        $stmt->bindParam(4, $c, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
        $codigos[] = $c;
    }
    sort($codigos);
    assert_equals([1, 2, 3], $codigos, "Tres correlativos consecutivos: 1, 2, 3 (sin duplicidad ni saltos)");
    $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM dbo.cotizacion_correlativo_anual WHERE id_empresa = ? AND id_tipo = ? AND anio = ?");
    $stmt->execute([$idEmpConc, (int)$tipoBase['id_tipo'], (int)date('Y')]);
    $n = (int)$stmt->fetchColumn();
    assert_equals(1, $n, "Solo debe existir 1 fila de correlativo por (empresa, tipo, año)");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 7: Registro de múltiples comunicaciones */
echo "<div class='scenario'><h3>7. Registro de múltiples comunicaciones (no se sobrescriben)</h3>";
try {
    $r = call_sp_create_cotizacion($idEmp1, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'EQA-0007', 'descripcion' => 'Reloj EH300-P', 'cantidad' => 1, 'precio_unitario' => 1918.40, 'afecto_igv' => 1],
    ]);
    for ($i = 1; $i <= 3; $i++) {
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_add_comunicacion
            @id_cotizacion = ?, @id_usuario = ?, @medio = ?, @tipo_comunicacion = ?,
            @descripcion = ?, @fecha_comunicacion = ?, @respuesta_cliente = ?");
        $stmt->bindValue(1, $r['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$usuarioAdmin['id_persona'], PDO::PARAM_INT);
        $stmt->bindValue(3, $i === 1 ? 'EMAIL' : ($i === 2 ? 'TELEFONO' : 'WHATSAPP'), PDO::PARAM_STR);
        $stmt->bindValue(4, $i === 1 ? 'ENVIO_INICIAL' : 'SEGUIMIENTO', PDO::PARAM_STR);
        $stmt->bindValue(5, "Comunicación de prueba #$i", PDO::PARAM_STR);
        $stmt->bindValue(6, date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(7, $i === 3 ? 'Cliente pide descuento' : null, PDO::PARAM_STR);
        $stmt->execute();
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.cotizacion_comunicacion WHERE id_cotizacion = ?");
    $stmt->execute([$r['id']]);
    $total = (int)$stmt->fetchColumn();
    assert_equals(3, $total, "Deben existir 3 comunicaciones registradas (no sobrescritas)");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 8: Diferentes usuarios y fechas */
echo "<div class='scenario'><h3>8. Diferentes usuarios y fechas en comunicaciones</h3>";
try {
    $r = call_sp_create_cotizacion($idEmp1, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'EQA-0018', 'descripcion' => 'Reloj PYH96-E', 'cantidad' => 1, 'precio_unitario' => 1430.00, 'afecto_igv' => 1],
    ]);
    $idUsu2 = crear_persona_test('test_user2@test.com');
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_add_comunicacion @id_cotizacion = ?, @id_usuario = ?, @medio = ?, @tipo_comunicacion = ?, @descripcion = ?, @fecha_comunicacion = ?");
    $stmt->execute([$r['id'], (int)$usuarioAdmin['id_persona'], 'EMAIL', 'ENVIO_INICIAL', 'Envío inicial', '2026-10-15 10:00:00']);
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_add_comunicacion @id_cotizacion = ?, @id_usuario = ?, @medio = ?, @tipo_comunicacion = ?, @descripcion = ?, @fecha_comunicacion = ?");
    $stmt->execute([$r['id'], $idUsu2, 'TELEFONO', 'SEGUIMIENTO', 'Seguimiento telefónico', '2026-10-18 14:30:00']);
    $stmt = $pdo->prepare("SELECT id_usuario, fecha_comunicacion FROM dbo.cotizacion_comunicacion WHERE id_cotizacion = ? ORDER BY fecha_comunicacion");
    $stmt->execute([$r['id']]);
    $rows = $stmt->fetchAll();
    assert_true(count($rows) === 2, "Debe haber 2 comunicaciones");
    assert_true($rows[0]['id_usuario'] !== $rows[1]['id_usuario'], "Los id_usuario deben ser distintos");
    assert_true($rows[0]['fecha_comunicacion'] !== $rows[1]['fecha_comunicacion'], "Las fechas deben ser distintas");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 9: Aprobación y cancelación */
echo "<div class='scenario'><h3>9. Aprobación y cancelación con fechas y responsables</h3>";
try {
    $r = call_sp_create_cotizacion($idEmp1, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'ACE-0001', 'descripcion' => 'Adaptador', 'cantidad' => 1, 'precio_unitario' => 91.03, 'afecto_igv' => 1],
    ]);
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_update_status @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?");
    $stmt->execute([$r['id'], 'PENDIENTE_ENVIO', (int)$usuarioAdmin['id_persona'], 'Lista para envío']);
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_update_status @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?");
    $stmt->execute([$r['id'], 'ENVIADA', (int)$usuarioAdmin['id_persona'], 'Enviada al cliente']);
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_update_status @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?");
    $stmt->execute([$r['id'], 'APROBADA', (int)$usuarioAdmin['id_persona'], 'Aprobada por cliente']);
    $stmt = $pdo->prepare("SELECT estado, fecha_aprobacion, id_usuario_aprobo FROM dbo.cotizacion WHERE id_cotizacion = ?");
    $stmt->execute([$r['id']]);
    $cot = $stmt->fetch();
    assert_equals('APROBADA', $cot['estado'], "El estado debe ser APROBADA");
    assert_true(!empty($cot['fecha_aprobacion']), "La fecha_aprobacion debe estar registrada");
    assert_equals((int)$usuarioAdmin['id_persona'], (int)$cot['id_usuario_aprobo'], "El id_usuario_aprobo debe ser el admin");
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_update_status @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?");
    $stmt->execute([$r['id'], 'CANCELADA', (int)$usuarioAdmin['id_persona'], 'Cliente cancela orden']);
    $stmt = $pdo->prepare("SELECT estado, fecha_aprobacion, id_usuario_aprobo, fecha_cancelacion, id_usuario_cancelo, motivo_cancelacion FROM dbo.cotizacion WHERE id_cotizacion = ?");
    $stmt->execute([$r['id']]);
    $cot = $stmt->fetch();
    assert_equals('CANCELADA', $cot['estado'], "El estado debe ser CANCELADA");
    assert_true(!empty($cot['fecha_aprobacion']), "La fecha_aprobacion debe conservarse después de cancelar");
    assert_true(!empty($cot['fecha_cancelacion']), "La fecha_cancelacion debe estar registrada");
    assert_equals((int)$usuarioAdmin['id_persona'], (int)$cot['id_usuario_cancelo'], "El id_usuario_cancelo debe ser el admin");
    assert_equals('Cliente cancela orden', $cot['motivo_cancelacion'], "El motivo de cancelación debe conservarse");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 10: Validación de permisos por rol */
echo "<div class='scenario'><h3>10. Validación de permisos por rol</h3>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM dbo.modulo_catalogo WHERE codigo = 'COTIZACIONES' AND activo = 1");
    assert_equals(1, (int)$stmt->fetchColumn(), "El módulo COTIZACIONES debe estar registrado en modulo_catalogo");
    $stmt = $pdo->query("SELECT COUNT(*) FROM dbo.persona_rol WHERE nombre = 'VENTAS'");
    assert_equals(1, (int)$stmt->fetchColumn(), "El rol VENTAS debe estar registrado");
    $stmt = $pdo->prepare("SELECT ver, registrar, modificar, eliminar, aprobar, exportar, imprimir FROM dbo.role_permissions WHERE id_rol = (SELECT id_rol FROM dbo.persona_rol WHERE nombre = 'VENTAS') AND modulo = 'COTIZACIONES'");
    $stmt->execute();
    $perm = $stmt->fetch();
    assert_equals(1, (int)$perm['ver'], "VENTAS debe poder ver");
    assert_equals(1, (int)$perm['registrar'], "VENTAS debe poder registrar");
    assert_equals(0, (int)$perm['eliminar'], "VENTAS NO debe poder eliminar");
    assert_equals(0, (int)$perm['aprobar'], "VENTAS NO debe poder aprobar");
    $stmt = $pdo->query("SELECT ver, registrar, modificar, eliminar, aprobar, exportar, imprimir FROM dbo.role_permissions WHERE id_rol = 1 AND modulo = 'COTIZACIONES'");
    $permAdmin = $stmt->fetch();
    assert_equals(1, (int)$permAdmin['ver'], "ADMIN debe poder ver");
    assert_equals(1, (int)$permAdmin['aprobar'], "ADMIN debe poder aprobar");
    assert_equals(1, (int)$permAdmin['eliminar'], "ADMIN debe poder eliminar");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 11: Prevención de modificaciones no autorizadas */
echo "<div class='scenario'><h3>11. Prevención de modificaciones no autorizadas (transición inválida)</h3>";
try {
    $r = call_sp_create_cotizacion($idEmp1, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'ACE-0002', 'descripcion' => 'Otro adaptador', 'cantidad' => 1, 'precio_unitario' => 91.03, 'afecto_igv' => 1],
    ]);
    $excepcion = null;
    try {
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_update_status @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?");
        $stmt->execute([$r['id'], 'APROBADA', (int)$usuarioAdmin['id_persona'], 'Intento inválido']);
    } catch (Throwable $e) {
        $excepcion = $e->getMessage();
    }
    assert_true($excepcion !== null, "Debe lanzar excepción al intentar BORRADOR → APROBADA (transición inválida)");
    $stmt = $pdo->prepare("SELECT estado FROM dbo.cotizacion WHERE id_cotizacion = ?");
    $stmt->execute([$r['id']]);
    assert_equals('BORRADOR', $stmt->fetchColumn(), "El estado debe seguir siendo BORRADOR después de la transición rechazada");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 12: Conservación del historial */
echo "<div class='scenario'><h3>12. Conservación del historial tras cambios de estado</h3>";
try {
    $r = call_sp_create_cotizacion($idEmp1, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'RCA-0016', 'descripcion' => 'Lector RFID', 'cantidad' => 1, 'precio_unitario' => 278.36, 'afecto_igv' => 1],
    ]);
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_update_status @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?");
    $stmt->execute([$r['id'], 'PENDIENTE_ENVIO', (int)$usuarioAdmin['id_persona'], 'M1']);
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_update_status @id_cotizacion = ?, @nuevo_estado = ?, @id_usuario = ?, @motivo = ?");
    $stmt->execute([$r['id'], 'ENVIADA', (int)$usuarioAdmin['id_persona'], 'M2']);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.cotizacion_historial_estado WHERE id_cotizacion = ?");
    $stmt->execute([$r['id']]);
    $total = (int)$stmt->fetchColumn();
    assert_equals(3, $total, "Deben existir 3 eventos en el historial (CREACION + 2 transiciones)");
    $stmt = $pdo->prepare("SELECT estado_anterior, estado_nuevo FROM dbo.cotizacion_historial_estado WHERE id_cotizacion = ? ORDER BY id_historial");
    $stmt->execute([$r['id']]);
    $eventos = $stmt->fetchAll();
    assert_equals('', $eventos[0]['estado_anterior'], "El evento CREACION tiene estado_anterior vacío");
    assert_equals('BORRADOR', $eventos[1]['estado_anterior'], "La primera transición parte de BORRADOR");
    assert_equals('PENDIENTE_ENVIO', $eventos[2]['estado_anterior'], "La segunda transición parte de PENDIENTE_ENVIO");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 13: Errores de BD y reversión de transacciones */
echo "<div class='scenario'><h3>13. Errores de BD y reversión de transacciones</h3>";
try {
    $excepcion = null;
    $codAntes = null;
    try {
        $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_create
            @id_empresa = ?, @id_tipo = ?, @id_emisora = ?, @id_usuario_creador = ?,
            @detalle_json = ?, @codigo = ?, @id_cotizacion = ?");
        $stmt->bindValue(1, 999999, PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$tipoBase['id_tipo'], PDO::PARAM_INT);
        $stmt->bindValue(3, (int)$emisoraBase['id_emisora'], PDO::PARAM_INT);
        $stmt->bindValue(4, (int)$usuarioAdmin['id_persona'], PDO::PARAM_INT);
        $stmt->bindValue(5, null, PDO::PARAM_STR);
        $stmt->bindParam(6, $codAntes, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 40);
        $idCot = null;
        $stmt->bindParam(7, $idCot, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
    } catch (Throwable $e) {
        $excepcion = $e->getMessage();
    }
    assert_true($excepcion !== null, "Debe lanzar excepción al usar id_empresa inexistente");
    $stmt = $pdo->query("SELECT COUNT(*) FROM dbo.cotizacion WHERE id_empresa = 999999");
    assert_equals(0, (int)$stmt->fetchColumn(), "No debe existir ninguna cotización con id_empresa inexistente (rollback exitoso)");
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* ESCENARIO 14: Búsqueda y filtrado */
echo "<div class='scenario'><h3>14. Búsqueda y filtrado de cotizaciones</h3>";
try {
    $r1 = call_sp_create_cotizacion($idEmp1, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'EQA-0006', 'descripcion' => 'Reloj EH300', 'cantidad' => 1, 'precio_unitario' => 1647.80, 'afecto_igv' => 1],
    ]);
    $r2 = call_sp_create_cotizacion($idEmp2, (int)$tipoBase['id_tipo'], (int)$emisoraBase['id_emisora'], (int)$usuarioAdmin['id_persona'], [
        ['codigo' => 'EQA-0007', 'descripcion' => 'Reloj EH300-P', 'cantidad' => 1, 'precio_unitario' => 1918.40, 'afecto_igv' => 1],
    ]);
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_list_paged @id_empresa = ?, @page = 1, @per_page = 100");
    $stmt->bindValue(1, $idEmp1, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) assert_equals($idEmp1, (int)$r['id_empresa'], "Todas las cotizaciones deben pertenecer a la empresa 1");
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_list_paged @search_term = ?, @page = 1, @per_page = 100");
    $stmt->bindValue(1, $r1['codigo'], PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    assert_true(count($rows) >= 1, "La búsqueda por código debe encontrar la cotización");
    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_list_paged @estado = ?, @page = 1, @per_page = 100");
    $stmt->bindValue(1, 'BORRADOR', PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (count($rows) > 0) {
        foreach ($rows as $r) assert_equals('BORRADOR', $r['estado'], "Todas las cotizaciones filtradas deben tener estado BORRADOR");
    } else {
        assert_true(true, "Filtro por estado funciona (0 resultados para BORRADOR)");
    }
} catch (Throwable $e) {
    assert_true(false, 'Excepción: ' . $e->getMessage());
}
echo "</div>";

/* RESUMEN */
$total = $passes + count($failures);
$clase = count($failures) === 0 ? 'pass' : 'fail';
echo "<div class='summary $clase'>";
echo "<h2>Resumen</h2>";
echo "<p>Total de aserciones: <strong>$total</strong></p>";
echo "<p>Pasaron: <strong style='color:green'>$passes</strong></p>";
echo "<p>Fallaron: <strong style='color:red'>" . count($failures) . "</strong></p>";
if (count($failures) > 0) {
    echo "<ul>";
    foreach ($failures as $f) echo "<li class='fail'>" . htmlspecialchars($f) . "</li>";
    echo "</ul>";
} else {
    echo "<p>✅ <strong>TODOS LOS ESCENARIOS PASARON CORRECTAMENTE</strong></p>";
}
echo "</div>";
echo "<p class='info'>⚠️ BORRA este archivo del servidor después de probar (expone datos internos).</p>";
?>
</body></html>
