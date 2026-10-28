<?php
/**
 * test_upload_fix.php
 *
 * Script de DIAGNÓSTICO para probar el fix de subida de archivos.
 *
 * CÓMO USARLO:
 *   1. Copia este archivo a la raíz del proyecto Atenciones.
 *   2. Asegúrate de haber ejecutado los scripts SQL:
 *         scripts_sql/01_fix_upload_varbinary.sql
 *         scripts_sql/01b_verificar_sp.sql
 *   3. Abre en el navegador: https://tu-dominio/test_upload_fix.php
 *   4. Verás 4 pruebas con su resultado. Si todo dice OK, el fix funciona.
 *
 * BORRAR ESTE ARCHIVO DESPUÉS DE PROBAR (expone datos internos).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "================================================================\n";
echo "  TEST DEL FIX DE SUBIDA DE DOCUMENTOS (base64 → VARBINARY)\n";
echo "================================================================\n\n";

// Función helper
function ok($msg)  { echo "[OK]   $msg\n"; }
function fail($msg) { echo "[FAIL] $msg\n"; }
function info($msg) { echo "[INFO] $msg\n"; }

try {
    $pdo = Database::connection();

    // ---------------------------------------------------------------
    // PRUEBA 1: ¿Existe el SP sp_ticket_documento_save?
    // ---------------------------------------------------------------
    echo "\n--- PRUEBA 1: SP existe ---\n";
    $stmt = $pdo->query("SELECT OBJECT_ID(N'dbo.sp_ticket_documento_save', N'P') AS id");
    $spId = $stmt->fetchColumn();
    if ($spId) {
        ok("SP sp_ticket_documento_save existe (id=$spId).");
    } else {
        fail("SP NO existe. Ejecuta: scripts_sql/01_fix_upload_varbinary.sql");
        exit(1);
    }

    // ---------------------------------------------------------------
    // PRUEBA 2: ¿Existe la columna contenido en ticket_documento?
    // ---------------------------------------------------------------
    echo "\n--- PRUEBA 2: Columna contenido ---\n";
    $stmt = $pdo->query("SELECT COL_LENGTH(N'dbo.ticket_documento', N'contenido') AS len");
    $len  = $stmt->fetchColumn();
    if ($len !== false && $len !== null) {
        ok("Columna ticket_documento.contenido existe.");
    } else {
        fail("Columna NO existe. Ejecuta el script del repo scripts_sql/15_*.sql");
        exit(1);
    }

    // ---------------------------------------------------------------
    // PRUEBA 3: Decodificación base64 → VARBINARY(MAX) en SQL Server.
    // ---------------------------------------------------------------
    echo "\n--- PRUEBA 3: Decodificación base64 en SQL Server ---\n";
    $testText  = 'Hola Mundo - test de upload';
    $testB64   = base64_encode($testText);
    $sql = "SELECT CONVERT(NVARCHAR(100),
                CONVERT(VARBINARY(MAX),
                    CONVERT(XML, N'<x>' + ? + N'</x>')
                        .value(N'xs:base64Binary(/x[1])', N'VARBINARY(MAX)')
                )
            AS decoded";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $testB64, PDO::PARAM_STR);
    $stmt->execute();
    $decoded = $stmt->fetchColumn();
    if ($decoded === $testText) {
        ok("Decodificación base64 OK. Texto recuperado: \"$decoded\"");
    } else {
        fail("Decodificación falló. Esperado: \"$testText\". Recibido: \"$decoded\"");
        exit(1);
    }

    // ---------------------------------------------------------------
    // PRUEBA 4: Llamar al SP con base64 y verificar que inserta.
    // Usa transacción + ROLLBACK para no dejar basura.
    // ---------------------------------------------------------------
    echo "\n--- PRUEBA 4: SP completo (insert + rollback) ---\n";

    // Necesitamos un ticket existente para la FK
    $stmt = $pdo->query("SELECT TOP 1 id_ticket, id_usuario FROM dbo.ticket WHERE eliminado = 0 ORDER BY id_ticket DESC");
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        info("No hay tickets en la BD. Creando ticket de prueba...");
        // Crear persona dummy si no existe
        $pdo->exec("IF NOT EXISTS (SELECT 1 FROM dbo.persona WHERE correo = N'test@upload-fix.local')
                    INSERT INTO dbo.persona (numero_documento, nombres, apellido_paterno, correo, contrasena)
                    VALUES (N'TEST001', N'Test', N'Upload', N'test@upload-fix.local', N'x')");
        $idPersona = (int)$pdo->query("SELECT id_persona FROM dbo.persona WHERE correo = N'test@upload-fix.local'")->fetchColumn();

        // Crear empresa dummy
        $pdo->exec("IF NOT EXISTS (SELECT 1 FROM dbo.empresa WHERE ruc = N'00000000000')
                    INSERT INTO dbo.empresa (ruc, razon_social, correo, id_rubro, id_ubicacion)
                    VALUES (N'00000000000', N'Test Upload Fix', N'test@upload-fix.local', 1, 1)");
        $idEmpresa = (int)$pdo->query("SELECT id_empresa FROM dbo.empresa WHERE ruc = N'00000000000'")->fetchColumn();

        // Crear ubicación
        $pdo->exec("IF NOT EXISTS (SELECT 1 FROM dbo.ubicacion WHERE direccion = N'Test Upload Fix')
                    INSERT INTO dbo.ubicacion (direccion, id_division) VALUES (N'Test Upload Fix', 1)");
        $idUbic = (int)$pdo->query("SELECT id_ubicacion FROM dbo.ubicacion WHERE direccion = N'Test Upload Fix'")->fetchColumn();

        info("Para prueba real, crea un ticket en la app y vuelve a ejecutar este script.");
        info("Saltando PRUEBA 4 (no hay tickets en BD).");
    } else {
        $idTicket   = (int)$ticket['id_ticket'];
        $idUsuario  = (int)$ticket['id_usuario'];

        // Generar 1 KB de bytes aleatorios y codificar a base64
        $binario = random_bytes(1024);
        $b64     = base64_encode($binario);
        $size    = strlen($binario);

        // Buscar si hay atención para este ticket
        $stmt = $pdo->prepare("SELECT TOP 1 id_atencion FROM dbo.atencion WHERE id_ticket = ? ORDER BY id_atencion DESC");
        $stmt->execute([$idTicket]);
        $idAtencion = $stmt->fetchColumn() ?: null;

        info("Probando con ticket id=$idTicket, usuario id=$idUsuario, atención id=" . ($idAtencion ?: 'NULL'));

        // Llamar al SP
        $stmt = $pdo->prepare(
            "EXEC dbo.sp_ticket_documento_save
                @id_ticket        = ?,
                @id_usuario       = ?,
                @id_atencion      = ?,
                @nombre_original  = ?,
                @archivo_key      = ?,
                @tipo_mime        = ?,
                @tamano_bytes     = ?,
                @tipo_documento   = ?,
                @contenido_bin    = NULL,
                @base64_contenido = ?,
                @id_documento     = ?"
        );
        $stmt->bindValue(1, $idTicket, PDO::PARAM_INT);
        $stmt->bindValue(2, $idUsuario, PDO::PARAM_INT);
        $stmt->bindValue(3, $idAtencion, $idAtencion === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(4, 'test_upload_fix.bin', PDO::PARAM_STR);
        $stmt->bindValue(5, bin2hex(random_bytes(24)), PDO::PARAM_STR);
        $stmt->bindValue(6, 'application/octet-stream', PDO::PARAM_STR);
        $stmt->bindValue(7, $size, PDO::PARAM_INT);
        $stmt->bindValue(8, 'EVIDENCIA_ADICIONAL', PDO::PARAM_STR);
        $stmt->bindValue(9, $b64, PDO::PARAM_STR);

        $idDoc = null;
        $stmt->bindParam(10, $idDoc, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 11);

        try {
            $pdo->beginTransaction();
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!empty($row['id_documento'])) {
                $idDoc = (int)$row['id_documento'];
            } elseif ($idDoc !== null) {
                $idDoc = (int)$idDoc;
            }

            if ($idDoc > 0) {
                // Verificar que el contenido se guardó correctamente
                $stmt = $pdo->prepare("SELECT DATALENGTH(contenido) AS len FROM dbo.ticket_documento WHERE id_documento = ?");
                $stmt->execute([$idDoc]);
                $savedLen = (int)$stmt->fetchColumn();

                if ($savedLen === $size) {
                    ok("SP insertó documento id=$idDoc con contenido de $savedLen bytes (igual al original).");
                } else {
                    fail("SP insertó pero el tamaño no coincide. Esperado: $size. Guardado: $savedLen");
                }
            } else {
                fail("SP no devolvió id_documento. Revisa los logs de SQL Server.");
            }

            $pdo->rollback();
            info("Rollback efectuado (no se dejó basura en la BD).");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollback();
            fail("SP falló: " . $e->getMessage());
            fail("Trace: " . $e->getTraceAsString());
        }
    }

    // ---------------------------------------------------------------
    // PRUEBA 5: Verificar parámetros del SP (que coincidan con PHP).
    // ---------------------------------------------------------------
    echo "\n--- PRUEBA 5: Parámetros del SP ---\n";
    $stmt = $pdo->query("
        SELECT name, TYPE_NAME(system_type_id) AS tipo,
               CASE WHEN max_length = -1 THEN 'MAX' ELSE CAST(max_length AS VARCHAR(10)) END AS largo,
               CASE WHEN is_output = 1 THEN 'OUTPUT' ELSE '' END AS dir
        FROM sys.parameters
        WHERE object_id = OBJECT_ID(N'dbo.sp_ticket_documento_save')
        ORDER BY parameter_id
    ");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$r['name']} {$r['tipo']}({$r['largo']}) {$r['dir']}\n";
    }

    echo "\n================================================================\n";
    echo "  TEST COMPLETO.\n";
    echo "================================================================\n";
    echo "\nSi todas las pruebas dicen [OK], el fix está funcionando y puedes\n";
    echo "subir archivos desde la app. Si alguna dice [FAIL], sigue las\n";
    echo "instrucciones del mensaje para corregirla.\n";
    echo "\n*** BORRA ESTE ARCHIVO (test_upload_fix.php) DESPUÉS DE PROBAR ***\n";

} catch (Throwable $e) {
    echo "\n[FAIL] Error fatal: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
