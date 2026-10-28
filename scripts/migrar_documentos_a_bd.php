<?php
/**
 * scripts/migrar_documentos_a_bd.php
 * ----------------------------------------------------------------------------
 * Migra los archivos adjuntos existentes del filesystem (storage/ticket_documents/)
 * a la columna contenido (VARBINARY MAX) de ticket_documento.
 *
 * USO (desde línea de comandos, en el servidor PHP):
 *   php scripts/migrar_documentos_a_bd.php
 *
 * O vía web (temporalmente):
 *   Coloca este archivo en una ruta accesible solo para admin y ábrelo en el navegador.
 *
 * El script:
 *   1. Lista todos los ticket_documento con contenido=NULL y archivo_key válido.
 *   2. Para cada uno, lee el archivo físico de storage/ticket_documents/{archivo_key}.
 *   3. Actualiza la fila con el contenido binario.
 *   4. Muestra progreso.
 *
 * No borra los archivos físicos al terminar (lo haces manualmente tras verificar).
 * ----------------------------------------------------------------------------
 */
declare(strict_types=1);

// Configuración de rutas (ajustar si el proyecto está en otra ubición)
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/database.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "==============================================================================\n";
echo "  Migracion de documentos adjuntos: filesystem -> base de datos\n";
echo "==============================================================================\n\n";

// Verificar que la columna contenido exista
if (!Database::columnExists('ticket_documento', 'contenido')) {
    echo "[ERROR] La columna 'contenido' no existe en ticket_documento.\n";
    echo "        Ejecuta primero el script scripts_sql/15_sp_print_export_y_documentos_en_bd.sql\n";
    echo "        contra la base de datos MonosyncERP.\n";
    exit(1);
}

$documentStorage = $projectRoot . '/storage/ticket_documents';
if (!is_dir($documentStorage)) {
    echo "[INFO] No existe el directorio storage/ticket_documents/. No hay nada que migrar.\n";
    exit(0);
}

// Listar documentos pendientes de migración
$pdo = Database::connection();
$stmt = $pdo->query("SELECT id_documento, id_ticket, nro_ticket, nombre_original, archivo_key
                     FROM dbo.ticket_documento
                     WHERE contenido IS NULL
                       AND archivo_key IS NOT NULL
                       AND LEN(archivo_key) = 48
                     ORDER BY id_documento");

$pendientes = $stmt->fetchAll();
$total = count($pendientes);
echo "[INFO] Documentos pendientes de migracion: {$total}\n\n";

if ($total === 0) {
    echo "[OK] No hay documentos para migrar. Todos ya estan en la BD.\n";
    exit(0);
}

$migrados = 0;
$errores = 0;
$noEncontrados = 0;

foreach ($pendientes as $doc) {
    $idDocumento = (int)$doc['id_documento'];
    $archivoKey = $doc['archivo_key'];
    $path = $documentStorage . '/' . $archivoKey;

    if (!is_file($path)) {
        echo "[SKIP] Documento #{$idDocumento} (ticket {$doc['nro_ticket']}, '{$doc['nombre_original']}'): archivo fisico no encontrado en {$path}\n";
        $noEncontrados++;
        continue;
    }

    $binario = file_get_contents($path);
    if ($binario === false) {
        echo "[ERR]  Documento #{$idDocumento}: no se pudo leer el archivo {$path}\n";
        $errores++;
        continue;
    }

    try {
        $upd = $pdo->prepare("UPDATE dbo.ticket_documento
                                SET contenido = ?
                                WHERE id_documento = ? AND contenido IS NULL");
        $upd->bindValue(1, $binario, PDO::PARAM_LOB);
        $upd->bindValue(2, $idDocumento, PDO::PARAM_INT);
        $upd->execute();
        $filas = $upd->rowCount();
        if ($filas > 0) {
            $sizeKb = round(strlen($binario) / 1024, 1);
            echo "[OK]   Documento #{$idDocumento} (ticket {$doc['nro_ticket']}, '{$doc['nombre_original']}') -> {$sizeKb} KB migrados\n";
            $migrados++;
        } else {
            echo "[SKIP] Documento #{$idDocumento}: ninguna fila actualizada (¿ya migrado?)\n";
        }
    } catch (Throwable $e) {
        echo "[ERR]  Documento #{$idDocumento}: " . $e->getMessage() . "\n";
        $errores++;
    }
}

echo "\n==============================================================================\n";
echo "  Resumen de migracion\n";
echo "==============================================================================\n";
echo "  Total pendientes:   {$total}\n";
echo "  Migrados:           {$migrados}\n";
echo "  Errores:            {$errores}\n";
echo "  Sin archivo fisico: {$noEncontrados}\n";
echo "==============================================================================\n\n";

if ($migrados > 0 && $errores === 0 && $noEncontrados === 0) {
    echo "[OK] Todos los documentos fueron migrados a la BD.\n";
    echo "     Puedes ELIMINAR MANUALMENTE la carpeta storage/ticket_documents/ \n";
    echo "     despues de verificar que la descarga funciona correctamente.\n";
} else {
    echo "[AVISO] Revisa los errores arriba antes de eliminar archivos fisicos.\n";
}
