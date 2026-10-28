<?php
/**
 * api/routes/tickets.php v2
 *
 * CRUD RESTful de tickets con soporte para:
 *   - Creación manual (flujo habitual: Pendiente → En atención → Solucionado → Cerrado)
 *   - Creación automática en estado CERRADO (cierre directo, sin atención técnica)
 *   - Múltiples atenciones por ticket, con marca de "atención solucionadora"
 *   - Cálculo automático de tiempos (6 indicadores A-F)
 *   - Eliminación lógica (solo admin, solo Cerrado/Anulado)
 *   - Reutilización de código visible tras eliminación
 *   - Documentos generales del ticket y por atención
 *   - Historial completo con tipo_evento y origen_evento
 *
 * Endpoints:
 *   GET    /tickets                              Listado con filtros (excluye eliminados)
 *   POST   /tickets                              Crear ticket manual (PENDIENTE)
 *   POST   /tickets/auto-closed                  Crear ticket directamente CERRADO
 *   GET    /tickets/{id}                         Detalle + atenciones + historial + tiempos
 *   PATCH  /tickets/{id}/status                  Cambiar estado (valida transiciones)
 *   POST   /tickets/{id}/solucionar              Marca atención como solucionadora
 *   POST   /tickets/{id}/cerrar                  Cerrar definitivo
 *   POST   /tickets/{id}/anular                  Anular
 *   DELETE /tickets/{id}                         Eliminación lógica admin (solo CERRADO/ANULADO)
 *   GET    /tickets/{id}/files                   Listar documentos generales
 *   POST   /tickets/{id}/files                   Subir documento general
 *   DELETE /tickets/{id}/files/{fileId}          Eliminar documento
 *   GET    /tickets/{id}/atenciones              Listar atenciones del ticket
 *   GET    /tickets/{id}/tiempos                 Indicadores de tiempo (A-F)
 */

Auth::requirePermission('TICKETS', 'ver');

$method = $_SERVER['REQUEST_METHOD'];
$id     = $segments[1] ?? null;
$action = $segments[2] ?? null;
$fileId = $segments[3] ?? null;
$documentStorage = dirname(__DIR__, 2) . '/storage/ticket_documents';

/* ---- GET /tickets ----
 *  OPTIMIZACIÓN: usa sp_ticket_list_paged (OFFSET/FETCH en servidor).
 *  Antes: fetchAll() + array_slice() → con 10K tickets eran ~5s.
 *  Ahora: el motor SQL devuelve solo la página solicitada (~30ms).
 */
if ($method === 'GET' && $id === null) {
    Auth::requirePermission('TICKETS', 'ver');
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));

    // Excluir eliminados por defecto (eliminado=0). Solo admin con ?incluir_eliminados=1 los ve.
    $incluirEliminados = (!empty($_GET['incluir_eliminados']) && Auth::hasPermission('TICKETS', 'eliminar')) ? 1 : 0;

    /* Llamar al SP paginado. Devuelve DOS result sets:
     *   1) Filas de la página actual.
     *   2) Una fila con el total de registros que matchean los filtros.
     */
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        "EXEC dbo.sp_ticket_list_paged
            @estado = ?, @prioridad = ?, @id_empresa = ?, @id_sucursal = ?,
            @id_rubro = ?, @id_contacto = ?, @id_usuario = ?,
            @fecha_desde = ?, @fecha_hasta = ?, @search_term = ?,
            @id_motivo_contacto = ?, @origen = ?, @origen_cierre = ?,
            @incluir_eliminados = ?, @page = ?, @per_page = ?"
    );
    $stmt->bindValue(1,  isset($_GET['estado'])              ? $_GET['estado']              : null, PDO::PARAM_STR);
    $stmt->bindValue(2,  isset($_GET['prioridad'])           ? $_GET['prioridad']           : null, PDO::PARAM_STR);
    $stmt->bindValue(3,  isset($_GET['id_empresa'])         ? (int)$_GET['id_empresa']     : null, PDO::PARAM_INT);
    $stmt->bindValue(4,  isset($_GET['id_sucursal'])         ? (int)$_GET['id_sucursal']    : null, PDO::PARAM_INT);
    $stmt->bindValue(5,  isset($_GET['id_rubro'])           ? (int)$_GET['id_rubro']       : null, PDO::PARAM_INT);
    $stmt->bindValue(6,  isset($_GET['id_contacto'])         ? (int)$_GET['id_contacto']    : null, PDO::PARAM_INT);
    $stmt->bindValue(7,  isset($_GET['id_usuario'])         ? (int)$_GET['id_usuario']     : null, PDO::PARAM_INT);
    $stmt->bindValue(8,  isset($_GET['fecha_desde'])        ? $_GET['fecha_desde']         : null, PDO::PARAM_STR);
    $stmt->bindValue(9,  isset($_GET['fecha_hasta'])        ? $_GET['fecha_hasta']         : null, PDO::PARAM_STR);
    $stmt->bindValue(10, isset($_GET['search'])             ? $_GET['search']              : null, PDO::PARAM_STR);
    $stmt->bindValue(11, isset($_GET['id_motivo_contacto']) ? (int)$_GET['id_motivo_contacto'] : null, PDO::PARAM_INT);
    $stmt->bindValue(12, isset($_GET['origen'])             ? $_GET['origen']              : null, PDO::PARAM_STR);
    $stmt->bindValue(13, isset($_GET['origen_cierre'])      ? $_GET['origen_cierre']       : null, PDO::PARAM_STR);
    $stmt->bindValue(14, $incluirEliminados, PDO::PARAM_INT);
    $stmt->bindValue(15, $page,    PDO::PARAM_INT);
    $stmt->bindValue(16, $perPage,  PDO::PARAM_INT);
    $stmt->execute();

    // Result set 1: filas de la página actual
    $rows = $stmt->fetchAll();

    // Avanzar al result set 2: total de registros que matchean los filtros
    $stmt->nextRowset();
    $totalRow = $stmt->fetch();
    $total = (int)($totalRow['total'] ?? count($rows));

    ApiResponse::paginated($rows, $total, $page, $perPage);
}

/* ---- POST /tickets (creación manual, estado PENDIENTE) ---- */
if ($method === 'POST' && $id === null) {
    Auth::requirePermission('TICKETS', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['id_usuario','id_empresa_contacto','id_empresa','detalle_reporte'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    $pdo  = Database::connection();
    /*
     * ORDEN DE PARÁMETROS de sp_ticket_create (script 17):
     *   1: id_usuario          2: id_empresa_contacto  3: id_empresa
     *   4: id_sucursal         5: detalle_reporte      6: prioridad
     *   7: medio_contacto      8: id_motivo_contacto
     *   9: nro_ticket (OUT)   10: id_ticket (OUT)
     */
    try {
        $stmt = $pdo->prepare("EXEC sp_ticket_create ?, ?, ?, ?, ?, ?, ?, ?, ?, ?");
        $stmt->bindValue(1, (int)$body['id_usuario'],            PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$body['id_empresa_contacto'], PDO::PARAM_INT);
        $stmt->bindValue(3, (int)$body['id_empresa'],            PDO::PARAM_INT);
        $stmt->bindValue(4, $body['id_sucursal'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(5, $body['detalle_reporte'],     PDO::PARAM_STR);
        $stmt->bindValue(6, $body['prioridad']     ?? 'MEDIA',  PDO::PARAM_STR);
        $stmt->bindValue(7, $body['medio_contacto'] ?? 'SISTEMA', PDO::PARAM_STR);
        $stmt->bindValue(8, $body['id_motivo_contacto'] ?? null, PDO::PARAM_INT);
        $nroTicket = null;
        $idTicket  = null;
        $stmt->bindParam(9,  $nroTicket, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 50);
        $stmt->bindParam(10, $idTicket,  PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        // Capturar el mensaje de error real del SP para diagnóstico
        $errMsg = $e->getMessage();
        error_log('[Tickets POST] Error sp_ticket_create: ' . $errMsg);
        // Devolver el mensaje real (no "Error interno") para que el usuario sepa qué falló
        ApiResponse::error(
            'ticket_create_failed',
            'No se pudo crear el ticket: ' . $errMsg,
            422
        );
    }

    $idNew = (int)($row['id_ticket'] ?? $idTicket);
    $nro   = $row['nro_ticket'] ?? $nroTicket;

    if (!$idNew) {
        ApiResponse::error('ticket_create_failed', 'El SP no devolvió id_ticket', 422);
    }

    // Auditoría: registrar creación de ticket con IP, hostname y usuario
    Audit::log('TICKETS', 'CREAR', 'Ticket ' . ($nro ?? '?') . ' creado',
        'ticket', (string)$idNew,
        ['nro_ticket' => $nro, 'id_empresa' => $body['id_empresa'], 'prioridad' => $body['prioridad'] ?? 'MEDIA']);

    // Disparar notificaciones automaticas a tecnicos (no romper el flujo si fallan)
    try {
        require_once __DIR__ . '/../../modules/notificaciones/push_dispatcher.php';
        $tecnicos = $pdo->query(
            "SELECT p.id_persona FROM persona_rol_asignacion pra
             INNER JOIN persona p ON pra.id_persona = p.id_persona
             INNER JOIN persona_rol pr ON pra.id_rol = pr.id_rol
             WHERE pr.nombre IN ('TECNICO','SOPORTE') AND p.activo = 1
             FOR JSON PATH")->fetchColumn();
        if ($tecnicos) {
            Database::callSP('sp_notificacion_enviar', [
                'TICKET_CREADO', $tecnicos,
                json_encode(['nro_ticket' => $nro, 'prioridad' => $body['prioridad'] ?? 'MEDIA']),
                $idNew, 'TICKETS'
            ]);
        }
    } catch (Throwable $e) {
        // Las notificaciones son opcionales - no rompen la creación del ticket
        error_log('[Tickets POST] Notificación fallida (no crítico): ' . $e->getMessage());
    }

    ApiResponse::success(['id_ticket' => $idNew, 'nro_ticket' => $nro], 201, 'Ticket creado');
}

/* ---- POST /tickets/auto-closed (creación automática en CERRADO) ---- */
if ($method === 'POST' && $id === 'auto-closed') {
    Auth::requirePermission('TICKETS', 'registrar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    foreach (['id_usuario','id_empresa_contacto','id_empresa','detalle_reporte','motivo_cierre'] as $k) {
        if (empty($body[$k])) ApiResponse::validationError([$k => 'Requerido']);
    }
    $origenCierre = $body['origen_cierre'] ?? 'AUTOMATICO';
    if (!in_array($origenCierre, ['AUTOMATICO','MANUAL'], true)) {
        ApiResponse::validationError(['origen_cierre' => 'Valor inválido (AUTOMATICO o MANUAL)']);
    }
    $pdo  = Database::connection();
    /*
     * ORDEN DE PARÁMETROS de sp_ticket_create_closed (script 17):
     *   1: id_usuario           2: id_empresa_contacto   3: id_empresa
     *   4: id_sucursal          5: detalle_reporte       6: prioridad
     *   7: medio_contacto       8: id_motivo_contacto
     *   9: motivo_cierre       10: origen_cierre        11: id_usuario_cierre
     *  12: nro_ticket (OUT)   13: id_ticket (OUT)
     */
    try {
        $stmt = $pdo->prepare("EXEC sp_ticket_create_closed ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?");
        $stmt->bindValue(1, (int)$body['id_usuario'],            PDO::PARAM_INT);
        $stmt->bindValue(2, (int)$body['id_empresa_contacto'], PDO::PARAM_INT);
        $stmt->bindValue(3, (int)$body['id_empresa'],            PDO::PARAM_INT);
        $stmt->bindValue(4, $body['id_sucursal'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(5, $body['detalle_reporte'],     PDO::PARAM_STR);
        $stmt->bindValue(6, $body['prioridad']     ?? 'MEDIA',  PDO::PARAM_STR);
        $stmt->bindValue(7, $body['medio_contacto'] ?? 'SISTEMA', PDO::PARAM_STR);
        $stmt->bindValue(8, $body['id_motivo_contacto'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(9, $body['motivo_cierre'], PDO::PARAM_STR);
        $stmt->bindValue(10, $origenCierre, PDO::PARAM_STR);
        $stmt->bindValue(11, $body['id_usuario_cierre'] ?? $body['id_usuario'], PDO::PARAM_INT);
        $nroTicket = null;
        $idTicket  = null;
        $stmt->bindParam(12, $nroTicket, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 50);
        $stmt->bindParam(13, $idTicket, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
        $stmt->execute();
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $errMsg = $e->getMessage();
        error_log('[Tickets auto-closed] Error sp_ticket_create_closed: ' . $errMsg);
        ApiResponse::error(
            'ticket_create_closed_failed',
            'No se pudo crear el ticket cerrado: ' . $errMsg,
            422
        );
    }

    ApiResponse::success([
        'id_ticket' => (int)($row['id_ticket'] ?? $idTicket),
        'nro_ticket' => $row['nro_ticket'] ?? $nroTicket,
        'tiempo_total_segundos' => (int)($row['tiempo_total_segundos'] ?? 0),
    ], 201, 'Ticket cerrado automáticamente creado');
}

/* ---- GET /tickets/deleted (historial de tickets eliminados - solo admin) ----
 *  DEBE estar ANTES del handler genérico GET /tickets/{id} para que la ruta
 *  /tickets/deleted no sea capturada como si 'deleted' fuera un id_ticket.
 *  Lista los tickets eliminados con su motivo, usuario y fecha de eliminación.
 *  Orden de prioridad de fuentes:
 *    1. vw_tickets_eliminados (vista con JOIN a ticket_eliminacion_historial)
 *    2. ticket + bajas_registro (tabla unificada del sistema)
 *    3. ticket sola (solo eliminado=1, sin contexto de eliminación)
 */
if ($method === 'GET' && $id === 'deleted' && $action === null) {
    Auth::requirePermission('TICKETS', 'eliminar');
    $pdo = Database::connection();
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(10, (int)($_GET['per_page'] ?? 50)));

    // Verificar qué fuentes existen
    $hasView = (bool)$pdo->query(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.vw_tickets_eliminados', N'V') IS NOT NULL THEN 1 ELSE 0 END"
    )->fetchColumn();
    $hasBajasTable = (bool)$pdo->query(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.bajas_registro', N'U') IS NOT NULL THEN 1 ELSE 0 END"
    )->fetchColumn();

    if ($hasView) {
        // Fuente 1: vista dedicada con contexto completo
        $sql = "SELECT id_ticket, nro_ticket, estado, prioridad,
                       CONVERT(VARCHAR(10), fecha_registro, 23) AS fecha_registro,
                       empresa_razon_social, empresa_ruc,
                       detalle_reporte,
                       motivo_eliminacion, id_usuario_elimino, correo_usuario,
                       CONVERT(VARCHAR(19), fecha_eliminacion, 120) AS fecha_eliminacion,
                       ip_address, hostname, estado_anterior, detalle_resumen
                FROM dbo.vw_tickets_eliminados WITH (NOLOCK)
                ORDER BY fecha_eliminacion DESC";
    } elseif ($hasBajasTable) {
        // Fuente 2: JOIN ticket × bajas_registro (tabla unificada de bajas)
        $sql = "SELECT t.id_ticket, t.nro_ticket, t.estado, t.prioridad,
                       CONVERT(VARCHAR(10), t.fecha_registro, 23) AS fecha_registro,
                       e.razon_social AS empresa_razon_social, e.ruc AS empresa_ruc,
                       t.detalle_reporte,
                       b.motivo AS motivo_eliminacion,
                       b.id_usuario AS id_usuario_elimino,
                       p.correo AS correo_usuario,
                       CONVERT(VARCHAR(19), b.fecha_baja, 120) AS fecha_eliminacion,
                       b.ip_address, NULL AS hostname,
                       t.estado AS estado_anterior,
                       NULL AS detalle_resumen
                FROM dbo.ticket t WITH (NOLOCK)
                LEFT JOIN dbo.empresa e WITH (NOLOCK) ON t.id_empresa = e.id_empresa
                LEFT JOIN dbo.bajas_registro b WITH (NOLOCK)
                    ON b.tipo_entidad = 'TICKET' AND b.id_entidad = t.id_ticket
                LEFT JOIN dbo.persona p WITH (NOLOCK) ON b.id_usuario = p.id_persona
                WHERE t.eliminado = 1
                ORDER BY b.fecha_baja DESC, t.id_ticket DESC";
    } else {
        // Fuente 3: fallback — solo tabla ticket con eliminado=1
        // (sin contexto de eliminación, pero aún así lista los tickets)
        $sql = "SELECT t.id_ticket, t.nro_ticket, t.estado, t.prioridad,
                       CONVERT(VARCHAR(10), t.fecha_registro, 23) AS fecha_registro,
                       e.razon_social AS empresa_razon_social, e.ruc AS empresa_ruc,
                       t.detalle_reporte,
                       t.motivo_eliminacion,
                       t.id_usuario_eliminacion AS id_usuario_elimino,
                       p.correo AS correo_usuario,
                       CONVERT(VARCHAR(19), t.fecha_eliminacion, 120) AS fecha_eliminacion,
                       NULL AS ip_address, NULL AS hostname,
                       t.estado AS estado_anterior,
                       NULL AS detalle_resumen
                FROM dbo.ticket t WITH (NOLOCK)
                LEFT JOIN dbo.empresa e WITH (NOLOCK) ON t.id_empresa = e.id_empresa
                LEFT JOIN dbo.persona p WITH (NOLOCK) ON t.id_usuario_eliminacion = p.id_persona
                WHERE t.eliminado = 1
                ORDER BY t.fecha_eliminacion DESC, t.id_ticket DESC";
    }
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    $total = count($rows);
    $paged = array_slice($rows, ($page - 1) * $perPage, $perPage);
    ApiResponse::paginated($paged, $total, $page, $perPage);
}

/* ---- GET /tickets/export (exportación masiva a Excel/CSV/JSON) ----
 *  DEBE estar ANTES del handler GET /tickets/{id} para que 'export' no sea
 *  interpretado como un id_ticket. Igual que '/tickets/deleted' que ya
 *  está antes.
 *
 *  Formatos soportados:
 *    ?format=xlsx  → descarga un archivo .xlsx simplificado (HTML table
 *                    con content-type Excel; abre en Excel/Google Sheets)
 *    ?format=csv   → CSV con BOM UTF-8 (compatible con Excel en español)
 *    ?format=json  → JSON plano (para integraciones)
 *  Filtros: mismos que GET /tickets (estado, prioridad, id_empresa, etc.)
 *  Por defecto EXCLUYE eliminados. Si ?incluir_eliminados=1 y tiene
 *  permiso TICKETS.eliminar, los incluye.
 */
if ($method === 'GET' && $id === 'export') {
    Auth::requirePermission('TICKETS', 'exportar');

    $format = strtolower($_GET['format'] ?? 'xlsx');
    if (!in_array($format, ['xlsx','csv','json'], true)) {
        ApiResponse::validationError(['format' => 'Formato inválido. Use xlsx, csv o json']);
    }

    $incluirEliminados = (!empty($_GET['incluir_eliminados']) && Auth::hasPermission('TICKETS', 'eliminar')) ? 1 : 0;

    try {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "EXEC dbo.sp_ticket_export
                @estado = ?, @prioridad = ?, @id_empresa = ?, @id_sucursal = ?,
                @id_rubro = ?, @id_contacto = ?, @id_usuario = ?,
                @fecha_desde = ?, @fecha_hasta = ?, @search_term = ?,
                @id_motivo_contacto = ?, @origen = ?, @origen_cierre = ?,
                @incluir_eliminados = ?, @limite = ?"
        );
        $stmt->bindValue(1,  isset($_GET['estado'])              ? $_GET['estado']              : null, PDO::PARAM_STR);
        $stmt->bindValue(2,  isset($_GET['prioridad'])           ? $_GET['prioridad']           : null, PDO::PARAM_STR);
        $stmt->bindValue(3,  isset($_GET['id_empresa'])         ? (int)$_GET['id_empresa']     : null, PDO::PARAM_INT);
        $stmt->bindValue(4,  isset($_GET['id_sucursal'])         ? (int)$_GET['id_sucursal']    : null, PDO::PARAM_INT);
        $stmt->bindValue(5,  isset($_GET['id_rubro'])           ? (int)$_GET['id_rubro']       : null, PDO::PARAM_INT);
        $stmt->bindValue(6,  isset($_GET['id_contacto'])         ? (int)$_GET['id_contacto']    : null, PDO::PARAM_INT);
        $stmt->bindValue(7,  isset($_GET['id_usuario'])         ? (int)$_GET['id_usuario']     : null, PDO::PARAM_INT);
        $stmt->bindValue(8,  isset($_GET['fecha_desde'])        ? $_GET['fecha_desde']         : null, PDO::PARAM_STR);
        $stmt->bindValue(9,  isset($_GET['fecha_hasta'])        ? $_GET['fecha_hasta']         : null, PDO::PARAM_STR);
        $stmt->bindValue(10, isset($_GET['search'])             ? $_GET['search']              : null, PDO::PARAM_STR);
        $stmt->bindValue(11, isset($_GET['id_motivo_contacto']) ? (int)$_GET['id_motivo_contacto'] : null, PDO::PARAM_INT);
        $stmt->bindValue(12, isset($_GET['origen'])             ? $_GET['origen']              : null, PDO::PARAM_STR);
        $stmt->bindValue(13, isset($_GET['origen_cierre'])      ? $_GET['origen_cierre']       : null, PDO::PARAM_STR);
        $stmt->bindValue(14, $incluirEliminados, PDO::PARAM_INT);
        $stmt->bindValue(15, 5000, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Si el SP falla (por ejemplo porque sp_ticket_export no existe),
        // devolver error claro en vez de internal_error genérico.
        error_log('[Tickets export] SP falló: ' . $e->getMessage());
        ApiResponse::error(
            'export_failed',
            'No se pudo ejecutar la exportación: ' . $e->getMessage(),
            500
        );
    }

    // Auditar exportación
    try {
        Audit::log('TICKETS', 'EXPORTAR',
            'Exportó ' . count($rows) . ' tickets a ' . $format,
            'ticket', null,
            ['formato' => $format, 'total' => count($rows), 'filtros' => $_GET]);
    } catch (Throwable $e) { /* auditoría no rompe la exportación */ }

    $timestamp = date('Y-m-d_His');
    $filename = 'tickets_' . $timestamp;

    if ($format === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo json_encode(['data' => $rows, 'total' => count($rows), 'generated_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        // BOM UTF-8 para que Excel detecte el encoding correctamente
        fwrite($out, "\xEF\xBB\xBF");
        if (!empty($rows)) {
            // Cabeceras amigables
            $headers = [
                'id_ticket' => 'ID',
                'nro_ticket' => 'Nro Ticket',
                'fecha_registro' => 'Fecha Registro',
                'hora_registro' => 'Hora Registro',
                'fecha_cierre' => 'Fecha Cierre',
                'hora_cierre' => 'Hora Cierre',
                'estado' => 'Estado',
                'prioridad' => 'Prioridad',
                'medio_contacto' => 'Medio Contacto',
                'empresa_razon_social' => 'Empresa',
                'empresa_nombre' => 'Nombre Comercial',
                'ruc' => 'RUC',
                'empresa_correo' => 'Correo Empresa',
                'empresa_telefono' => 'Teléfono Empresa',
                'sucursal_nombre' => 'Sucursal',
                'contacto_nombre' => 'Contacto',
                'contacto_documento' => 'Doc Contacto',
                'contacto_telefono' => 'Teléfono Contacto',
                'tecnico_nombre' => 'Técnico',
                'motivo_nombre' => 'Motivo',
                'rubro_nombre' => 'Rubro',
                'grupo_nombre' => 'Grupo',
                'division_nombre' => 'División Geográfica',
                'detalle_reporte' => 'Detalle del Reporte',
                'origen' => 'Origen',
                'origen_cierre' => 'Origen Cierre',
                'tiempo_total_segundos' => 'Tiempo Total (s)',
                'eliminado' => 'Eliminado',
            ];
            fputcsv($out, array_values($headers));
            foreach ($rows as $r) {
                $line = [];
                foreach (array_keys($headers) as $key) {
                    $line[] = $r[$key] ?? '';
                }
                fputcsv($out, $line);
            }
        }
        fclose($out);
        exit;
    }

    // format = xlsx → usamos HTML table con content-type Excel (compatible
    // con MS Excel 2003+ y Google Sheets). Es la forma más ligera de generar
    // un .xlsx sin librerías externas (PhpSpreadsheet no está en el proyecto).
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
       . 'xmlns:o="urn:schemas-microsoft-com:office:office" '
       . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
       . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" '
       . 'xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
    echo '<Worksheet ss:Name="Tickets"><Table>' . "\n";

    // Estilo de cabecera
    echo '<Row ss:StyleID="Header">';
    $headers = ['ID','Nro Ticket','Fecha Registro','Hora Registro','Fecha Cierre','Hora Cierre',
                'Estado','Prioridad','Medio Contacto','Empresa','Nombre Comercial','RUC',
                'Correo Empresa','Teléfono Empresa','Sucursal','Contacto','Doc Contacto',
                'Teléfono Contacto','Técnico','Motivo','Rubro','Grupo','División Geográfica',
                'Detalle del Reporte','Origen','Origen Cierre','Tiempo Total (s)','Eliminado'];
    foreach ($headers as $h) {
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($h, ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    foreach ($rows as $r) {
        echo '<Row>';
        $cells = [
            $r['id_ticket'] ?? '',
            $r['nro_ticket'] ?? '',
            $r['fecha_registro'] ?? '',
            $r['hora_registro'] ?? '',
            $r['fecha_cierre'] ?? '',
            $r['hora_cierre'] ?? '',
            $r['estado'] ?? '',
            $r['prioridad'] ?? '',
            $r['medio_contacto'] ?? '',
            $r['empresa_razon_social'] ?? '',
            $r['empresa_nombre'] ?? '',
            $r['ruc'] ?? '',
            $r['empresa_correo'] ?? '',
            $r['empresa_telefono'] ?? '',
            $r['sucursal_nombre'] ?? '',
            $r['contacto_nombre'] ?? '',
            $r['contacto_documento'] ?? '',
            $r['contacto_telefono'] ?? '',
            $r['tecnico_nombre'] ?? '',
            $r['motivo_nombre'] ?? '',
            $r['rubro_nombre'] ?? '',
            $r['grupo_nombre'] ?? '',
            $r['division_nombre'] ?? '',
            $r['detalle_reporte'] ?? '',
            $r['origen'] ?? '',
            $r['origen_cierre'] ?? '',
            $r['tiempo_total_segundos'] ?? '',
            $r['eliminado'] ?? '',
        ];
        foreach ($cells as $idx => $cell) {
            $type = ($idx === 0 || $idx === 26) ? 'Number' : 'String';
            $val = htmlspecialchars((string)($cell ?? ''), ENT_QUOTES, 'UTF-8');
            echo '<Cell><Data ss:Type="' . $type . '">' . $val . '</Data></Cell>';
        }
        echo '</Row>' . "\n";
    }
    echo '</Table></Worksheet></Workbook>' . "\n";
    exit;
}

/* ---- GET /tickets/{id} (detalle con atenciones, historial, tiempos) ----
 *  OPTIMIZACIÓN: sp_ticket_traceability_v2 ya devuelve 3 result sets
 *  (ticket, historial, atenciones). Antes se hacia UNA llamada al SP y luego
 *  DOS queries más para traer exactamente lo mismo. Eliminado.
 *  NOTA: Este handler debe estar DESPUÉS de los handlers específicos para
 *  /tickets/deleted y /tickets/export para que 'deleted' y 'export' no sean
 *  capturados aquí como si fueran id_ticket.
 */
if ($method === 'GET' && $id !== null && $action === null) {
    $pdo = Database::connection();
    $stmt = $pdo->prepare("EXEC dbo.sp_ticket_traceability_v2 ?");
    $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
    $stmt->execute();

    // Result set 1: ticket principal
    $ticket = $stmt->fetch();
    if (!$ticket) ApiResponse::error('not_found', 'Ticket no encontrado.', 404);

    // Result set 2: historial completo
    $stmt->nextRowset();
    $hist = $stmt->fetchAll();

    // Result set 3: atenciones
    $stmt->nextRowset();
    $atenc = $stmt->fetchAll();

    // Tiempos calculados (SP independiente — solo 1 viaje extra)
    $tiempos = Database::callSP('sp_ticket_tiempos', [(int)$id]);
    $tiemposData = $tiempos[0] ?? null;

    // Formatear tiempos a formato legible (2h 35m 20s)
    $formatDuration = function($seg) {
        if ($seg === null) return null;
        $seg = (int)$seg;
        $h = intdiv($seg, 3600);
        $m = intdiv($seg % 3600, 60);
        $s = $seg % 60;
        $parts = [];
        if ($h > 0) $parts[] = $h . 'h';
        if ($m > 0) $parts[] = $m . 'm';
        if ($s > 0 || count($parts) === 0) $parts[] = $s . 's';
        return implode(' ', $parts);
    };

    if ($tiemposData) {
        $tiemposData['tiempo_atenciones_formato']         = $formatDuration($tiemposData['tiempo_atenciones_seg'] ?? 0);
        $tiemposData['tiempo_resolucion_formato']         = $formatDuration($tiemposData['tiempo_resolucion_seg'] ?? null);
        $tiemposData['tiempo_cierre_formato']             = $formatDuration($tiemposData['tiempo_cierre_seg'] ?? null);
        $tiemposData['tiempo_primera_atencion_formato']   = $formatDuration($tiemposData['tiempo_primera_atencion_seg'] ?? null);
        $tiemposData['tiempo_transcurrido_formato']       = $formatDuration($tiemposData['tiempo_transcurrido_seg'] ?? null);
    }

    ApiResponse::success([
        'ticket' => $ticket,
        'historial' => $hist,
        'atenciones' => $atenc,
        'tiempos' => $tiemposData,
    ], 200);
}

/* ---- GET /tickets/{id}/print (ficha de impresión imprimible HTML) ----
 *  Devuelve un HTML standalone (con estilos inline) que el navegador
 *  puede imprimir directamente (Ctrl+P) o guardar como PDF. No requiere
 *  JS ni CSS externos. Usa sp_ticket_print (3 result sets).
 */
if ($method === 'GET' && $id !== null && $action === 'print') {
    Auth::requirePermission('TICKETS', 'ver');

    // Verificar que el SP sp_ticket_print exista antes de invocarlo.
    // Si el script SQL 14 no se aplicó, dar error claro en vez de internal_error.
    try {
        $pdo = Database::connection();
        $spCheck = $pdo->prepare("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_ticket_print', N'P') IS NOT NULL THEN 1 ELSE 0 END AS existe");
        $spCheck->execute();
        $spExiste = (int)$spCheck->fetchColumn();
        if ($spExiste === 0) {
            ApiResponse::error(
                'sp_missing',
                'El stored procedure sp_ticket_print no existe en la base de datos. ' .
                'Ejecute el script scripts_sql/14_auditoria_fechas_dashboard_impresion.sql para crearlo.',
                500
            );
        }
        $stmt = $pdo->prepare("EXEC dbo.sp_ticket_print ?");
        $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
        $stmt->execute();
    } catch (Throwable $e) {
        error_log('[Tickets print] SP falló: ' . $e->getMessage());
        ApiResponse::error(
            'print_failed',
            'No se pudo generar la ficha de impresión: ' . $e->getMessage(),
            500
        );
    }

    // Result set 1: cabecera
    $t = $stmt->fetch();
    if (!$t) ApiResponse::error('not_found', 'Ticket no encontrado.', 404);

    // Result set 2: atenciones
    try { $stmt->nextRowset(); $atenciones = $stmt->fetchAll(); }
    catch (Throwable $e) { $atenciones = []; }

    // Result set 3: historial
    try { $stmt->nextRowset(); $historial = $stmt->fetchAll(); }
    catch (Throwable $e) { $historial = []; }

    $esc = function($s) {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    };
    // Función única para formatear fechas. Usa $esc vía use() para que esté
    // disponible dentro del closure (en PHP las closures NO capturan
    // automáticamente el scope exterior como en JavaScript).
    $fmtFn = function($s) use ($esc) {
        if (!$s) return '—';
        $d = date_create($s);
        return $d ? $d->format('d/m/Y H:i') : $esc((string)$s);
    };

    // Estado badge color
    $estadoColor = [
        'PENDIENTE'  => '#f59e0b',
        'EN_ATENCION'=> '#3b82f6',
        'RESUELTO'   => '#10b981',
        'CERRADO'    => '#6b7280',
        'ANULADO'    => '#ef4444',
    ];
    $estadoUpper = strtoupper($t['estado'] ?? '');
    $colorEstado = $estadoColor[$estadoUpper] ?? '#6b7280';

    // Duración formateada a partir de segundos
    $fmtDur = function($seg) {
        if ($seg === null || $seg === '') return '—';
        $seg = (int)$seg;
        $h = intdiv($seg, 3600);
        $m = intdiv($seg % 3600, 60);
        $s = $seg % 60;
        $parts = [];
        if ($h > 0) $parts[] = $h . 'h';
        if ($m > 0) $parts[] = $m . 'm';
        if ($s > 0 || count($parts) === 0) $parts[] = $s . 's';
        return implode(' ', $parts);
    };

    $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ticket {$esc($t['nro_ticket'])} — Ficha</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
    margin: 0; padding: 24px; color: #1f2937; font-size: 13px;
    background: #fff;
  }
  .print-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 3px solid #1e40af; padding-bottom: 16px; margin-bottom: 20px;
  }
  .print-header h1 { margin: 0; font-size: 22px; color: #1e3a8a; }
  .print-header .ticket-nro { font-size: 28px; font-weight: 700; color: #1e40af; }
  .print-header .ticket-estado {
    display: inline-block; padding: 6px 14px; border-radius: 999px;
    color: #fff; font-weight: 600; font-size: 12px; background: {$colorEstado};
    text-transform: uppercase; letter-spacing: .5px;
  }
  .print-section { margin-bottom: 18px; page-break-inside: avoid; }
  .print-section h2 {
    font-size: 13px; text-transform: uppercase; letter-spacing: 1px;
    color: #6b7280; border-bottom: 1px solid #e5e7eb;
    padding-bottom: 6px; margin: 0 0 10px 0;
  }
  .kv-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px 24px; }
  .kv { display: flex; }
  .kv__k { font-weight: 600; color: #6b7280; min-width: 140px; }
  .kv__v { color: #111827; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
  th { background: #f9fafb; font-weight: 600; color: #374151; }
  .detalle-text { background: #f9fafb; padding: 10px; border-left: 4px solid #1e40af; white-space: pre-wrap; }
  .footer-print {
    margin-top: 30px; padding-top: 12px; border-top: 1px solid #e5e7eb;
    font-size: 11px; color: #6b7280; display: flex; justify-content: space-between;
  }
  .badge-sol { background: #10b981; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 10px; }
  @media print {
    body { padding: 0; }
    .no-print { display: none; }
    .print-section { page-break-inside: avoid; }
  }
  .print-bar {
    position: sticky; top: 0; background: #1e40af; color: #fff;
    padding: 10px 16px; margin: -24px -24px 20px -24px;
    display: flex; justify-content: space-between; align-items: center;
  }
  .print-bar button {
    background: #fff; color: #1e40af; border: none; padding: 8px 16px;
    border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px;
  }
  .print-bar button:hover { background: #f3f4f6; }
</style>
</head>
<body>
  <div class="print-bar no-print">
    <strong>Ficha de Ticket — {$esc($t['nro_ticket'])}</strong>
    <div>
      <button onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
      <button onclick="window.close()" style="margin-left:8px">Cerrar</button>
    </div>
  </div>

  <div class="print-header">
    <div>
      <h1>Ficha de Ticket</h1>
      <div class="ticket-nro">{$esc($t['nro_ticket'])}</div>
      <div style="margin-top:6px;font-size:12px;color:#6b7280">
        Generado: {$esc(date('d/m/Y H:i'))} · ID #{$esc($t['id_ticket'])}
      </div>
    </div>
    <div style="text-align:right">
      <span class="ticket-estado">{$esc($t['estado'])}</span>
      <div style="margin-top:6px;font-size:12px;color:#6b7280">
        Prioridad: <strong>{$esc($t['prioridad'])}</strong>
      </div>
    </div>
  </div>

  <div class="print-section">
    <h2>Datos del Ticket</h2>
    <div class="kv-grid">
      <div class="kv"><span class="kv__k">Fecha de registro:</span><span class="kv__v">{$esc($t['fecha_registro'])} {$esc($t['hora_registro'])}</span></div>
      <div class="kv"><span class="kv__k">Medio de contacto:</span><span class="kv__v">{$esc($t['medio_contacto'])}</span></div>
      <div class="kv"><span class="kv__k">Motivo:</span><span class="kv__v">{$esc($t['motivo_nombre'])}</span></div>
      <div class="kv"><span class="kv__k">Origen:</span><span class="kv__v">{$esc($t['origen'])}</span></div>
      <div class="kv"><span class="kv__k">Inicio atención:</span><span class="kv__v">{$fmtFn($t['fecha_inicio_atencion'])}</span></div>
      <div class="kv"><span class="kv__k">Solución:</span><span class="kv__v">{$fmtFn($t['fecha_solucion'])}</span></div>
      <div class="kv"><span class="kv__k">Cierre:</span><span class="kv__v">{$fmtFn($t['fecha_cierre'] ? $t['fecha_cierre'] . ' ' . $t['hora_cierre'] : null)}</span></div>
      <div class="kv"><span class="kv__k">Tiempo total:</span><span class="kv__v">{$fmtDur($t['tiempo_total_segundos'])}</span></div>
    </div>
  </div>

  <div class="print-section">
    <h2>Detalle del Reporte</h2>
    <div class="detalle-text">{$esc($t['detalle_reporte'])}</div>
  </div>

  <div class="print-section">
    <h2>Empresa</h2>
    <div class="kv-grid">
      <div class="kv"><span class="kv__k">Razón social:</span><span class="kv__v">{$esc($t['empresa_razon_social'])}</span></div>
      <div class="kv"><span class="kv__k">Nombre comercial:</span><span class="kv__v">{$esc($t['empresa_nombre_comercial'])}</span></div>
      <div class="kv"><span class="kv__k">RUC:</span><span class="kv__v">{$esc($t['empresa_ruc'])}</span></div>
      <div class="kv"><span class="kv__k">Rubro:</span><span class="kv__v">{$esc($t['rubro_nombre'])}</span></div>
      <div class="kv"><span class="kv__k">Correo:</span><span class="kv__v">{$esc($t['empresa_correo'])}</span></div>
      <div class="kv"><span class="kv__k">Teléfono:</span><span class="kv__v">{$esc($t['empresa_telefono'])}</span></div>
      <div class="kv"><span class="kv__k">Dirección:</span><span class="kv__v">{$esc($t['empresa_direccion'])}</span></div>
      <div class="kv"><span class="kv__k">División geográfica:</span><span class="kv__v">{$esc($t['division_nombre'])}</span></div>
      <div class="kv"><span class="kv__k">Sucursal:</span><span class="kv__v">{$esc($t['sucursal_nombre'])}</span></div>
      <div class="kv"><span class="kv__k">Teléfono sucursal:</span><span class="kv__v">{$esc($t['sucursal_telefono'])}</span></div>
    </div>
  </div>

  <div class="print-section">
    <h2>Contacto</h2>
    <div class="kv-grid">
      <div class="kv"><span class="kv__k">Nombre:</span><span class="kv__v">{$esc($t['contacto_nombres'])} {$esc($t['contacto_apellido'])}</span></div>
      <div class="kv"><span class="kv__k">Documento:</span><span class="kv__v">{$esc($t['contacto_documento'])}</span></div>
      <div class="kv"><span class="kv__k">Cargo:</span><span class="kv__v">{$esc($t['contacto_cargo'])}</span></div>
      <div class="kv"><span class="kv__k">Teléfono empresa:</span><span class="kv__v">{$esc($t['contacto_telefono'])}</span></div>
      <div class="kv"><span class="kv__k">Correo empresa:</span><span class="kv__v">{$esc($t['contacto_correo'])}</span></div>
      <div class="kv"><span class="kv__k">Teléfono personal:</span><span class="kv__v">{$esc($t['contacto_telefono_personal'])}</span></div>
    </div>
  </div>

  <div class="print-section">
    <h2>Técnico Asignado</h2>
    <div class="kv-grid">
      <div class="kv"><span class="kv__k">Nombre:</span><span class="kv__v">{$esc($t['tecnico_nombres'])} {$esc($t['tecnico_apellido'])}</span></div>
    </div>
  </div>

HTML;

    if (!empty($t['motivo_solucion'])) {
        $html .= '<div class="print-section"><h2>Motivo de Solución</h2><div class="detalle-text">' . $esc($t['motivo_solucion']) . '</div></div>';
    }
    if (!empty($t['motivo_cierre'])) {
        $html .= '<div class="print-section"><h2>Motivo de Cierre</h2><div class="detalle-text">' . $esc($t['motivo_cierre']) . '</div></div>';
    }
    if (!empty($t['motivo_anulacion'])) {
        $html .= '<div class="print-section"><h2>Motivo de Anulación</h2><div class="detalle-text">' . $esc($t['motivo_anulacion']) . '</div></div>';
    }

    // Atenciones
    if (!empty($atenciones)) {
        $html .= '<div class="print-section"><h2>Atenciones Realizadas</h2>';
        $html .= '<table><thead><tr><th>#</th><th>Fecha</th><th>Técnico</th><th>Estado</th><th>Duración</th><th>Detalle</th><th>Solución</th></tr></thead><tbody>';
        $i = 1;
        foreach ($atenciones as $a) {
            $solBadge = !empty($a['es_solucionadora']) ? ' <span class="badge-sol">SOLUCIONADORA</span>' : '';
            $html .= '<tr>';
            $html .= '<td>' . $i++ . '</td>';
            $html .= '<td>' . $esc($a['fecha_inicio']) . ' ' . $esc($a['hora_inicio']) . '</td>';
            $html .= '<td>' . $esc($a['tecnico_nombre']) . '</td>';
            $html .= '<td>' . $esc($a['estado']) . $solBadge . '</td>';
            $html .= '<td>' . $esc($a['duracion_formato']) . '</td>';
            $html .= '<td>' . $esc(mb_substr((string)$a['detalle_atencion'], 0, 200)) . (mb_strlen((string)$a['detalle_atencion']) > 200 ? '…' : '') . '</td>';
            $html .= '<td>' . $esc(mb_substr((string)$a['solucion_aplicada'], 0, 200)) . (mb_strlen((string)$a['solucion_aplicada']) > 200 ? '…' : '') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }

    // Historial
    if (!empty($historial)) {
        $html .= '<div class="print-section"><h2>Historial de Estados</h2>';
        $html .= '<table><thead><tr><th>Fecha</th><th>Evento</th><th>De</th><th>A</th><th>Usuario</th><th>Motivo</th></tr></thead><tbody>';
        foreach ($historial as $h) {
            $html .= '<tr>';
            $html .= '<td>' . $esc($h['fecha_cambio']) . '</td>';
            $html .= '<td>' . $esc($h['tipo_evento']) . '</td>';
            $html .= '<td>' . $esc($h['estado_anterior']) . '</td>';
            $html .= '<td>' . $esc($h['estado_nuevo']) . '</td>';
            $html .= '<td>' . $esc($h['usuario_nombre']) . '</td>';
            $html .= '<td>' . $esc(mb_substr((string)$h['motivo'], 0, 150)) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }

    $html .= '<div class="footer-print"><span>Generado por MonosyncERP · Atenciones</span><span>Página 1 de 1</span></div>';
    $html .= '</body></html>';

    // Devolver HTML directamente (no JSON)
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $html;
    exit;
}

/* ---- GET /tickets/{id}/tiempos (indicadores de tiempo A-F) ---- */
if ($method === 'GET' && $id !== null && $action === 'tiempos') {
    $rows = Database::callSP('sp_ticket_tiempos', [(int)$id]);
    if (empty($rows)) ApiResponse::error('not_found', 'Ticket no encontrado.', 404);
    $t = $rows[0];
    // Convertir segundos a formato legible (2h 35m 20s)
    $formatDuration = function($seg) {
        if ($seg === null) return null;
        $seg = (int)$seg;
        $h = intdiv($seg, 3600);
        $m = intdiv($seg % 3600, 60);
        $s = $seg % 60;
        $parts = [];
        if ($h > 0) $parts[] = $h . 'h';
        if ($m > 0) $parts[] = $m . 'm';
        if ($s > 0 || count($parts) === 0) $parts[] = $s . 's';
        return implode(' ', $parts);
    };
    ApiResponse::success([
        'tiempo_atenciones_seg'         => (int)($t['tiempo_atenciones_seg'] ?? 0),
        'tiempo_atenciones_formato'     => $formatDuration($t['tiempo_atenciones_seg'] ?? 0),
        'tiempo_resolucion_seg'         => isset($t['tiempo_resolucion_seg']) ? (int)$t['tiempo_resolucion_seg'] : null,
        'tiempo_resolucion_formato'     => $formatDuration($t['tiempo_resolucion_seg'] ?? null),
        'tiempo_cierre_seg'             => isset($t['tiempo_cierre_seg']) ? (int)$t['tiempo_cierre_seg'] : null,
        'tiempo_cierre_formato'         => $formatDuration($t['tiempo_cierre_seg'] ?? null),
        'tiempo_primera_atencion_seg'   => isset($t['tiempo_primera_atencion_seg']) ? (int)$t['tiempo_primera_atencion_seg'] : null,
        'tiempo_primera_atencion_formato'=> $formatDuration($t['tiempo_primera_atencion_seg'] ?? null),
        'tiempo_transcurrido_seg'       => isset($t['tiempo_transcurrido_seg']) ? (int)$t['tiempo_transcurrido_seg'] : null,
        'tiempo_transcurrido_formato'   => $formatDuration($t['tiempo_transcurrido_seg'] ?? null),
        'estado'                        => $t['estado'] ?? null,
        'fecha_creacion'                => $t['fecha_creacion'] ?? null,
        'fecha_inicio_atencion'         => $t['fecha_inicio_atencion'] ?? null,
        'fecha_solucion'                => $t['fecha_solucion'] ?? null,
        'fecha_cierre'                  => $t['fecha_cierre'] ?? null,
    ], 200);
}

/* ---- POST /tickets/{id}/solucionar (marca atención como solucionadora) ---- */
if ($method === 'POST' && $id !== null && $action === 'solucionar') {
    Auth::requirePermission('TICKETS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['id_atencion'])) ApiResponse::validationError(['id_atencion' => 'Requerido']);
    try {
        Database::callSP('sp_ticket_solucionar', [
            (int)$id,
            (int)$body['id_atencion'],
            (int)$user['id_persona'],
            $body['motivo_solucion'] ?? null,
            $body['resultado'] ?? null,
        ]);
        Audit::log('TICKETS', 'SOLUCIONAR', 'Ticket #' . $id . ' solucionado por atención #' . $body['id_atencion'],
            'ticket', (string)$id, ['id_atencion' => $body['id_atencion']]);
        // Mensaje al cliente SIN el número de atención (por requisito del usuario).
        ApiResponse::success(null, 200, 'Ticket solucionado correctamente');
    } catch (Throwable $e) {
        Audit::log('TICKETS', 'SOLUCIONAR', 'Error al solucionar ticket #' . $id . ': ' . $e->getMessage(),
            'ticket', (string)$id, ['error' => $e->getMessage()], 'ERROR');
        ApiResponse::error('validation_error', $e->getMessage(), 422);
    }
}

/* ---- POST /tickets/{id}/cerrar (cierre definitivo) ---- */
if ($method === 'POST' && $id !== null && $action === 'cerrar') {
    Auth::requirePermission('TICKETS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['motivo_cierre'])) ApiResponse::validationError(['motivo_cierre' => 'Requerido']);
    try {
        Database::callSP('sp_ticket_cerrar', [
            (int)$id,
            (int)$user['id_persona'],
            $body['motivo_cierre'],
        ]);
        Audit::log('TICKETS', 'CERRAR', 'Ticket #' . $id . ' cerrado',
            'ticket', (string)$id, ['motivo' => $body['motivo_cierre']]);
        ApiResponse::success(null, 200, 'Ticket cerrado');
    } catch (Throwable $e) {
        Audit::log('TICKETS', 'CERRAR', 'Error al cerrar ticket #' . $id . ': ' . $e->getMessage(),
            'ticket', (string)$id, ['error' => $e->getMessage()], 'ERROR');
        ApiResponse::error('validation_error', $e->getMessage(), 422);
    }
}

/* ---- POST /tickets/{id}/anular (anulación) ---- */
if ($method === 'POST' && $id !== null && $action === 'anular') {
    Auth::requirePermission('TICKETS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['motivo_anulacion'])) ApiResponse::validationError(['motivo_anulacion' => 'Requerido']);
    try {
        Database::callSP('sp_ticket_anular', [
            (int)$id,
            (int)$user['id_persona'],
            $body['motivo_anulacion'],
        ]);
        Audit::log('TICKETS', 'ANULAR', 'Ticket #' . $id . ' anulado',
            'ticket', (string)$id, ['motivo' => $body['motivo_anulacion']]);
        ApiResponse::success(null, 200, 'Ticket anulado');
    } catch (Throwable $e) {
        Audit::log('TICKETS', 'ANULAR', 'Error al anular ticket #' . $id . ': ' . $e->getMessage(),
            'ticket', (string)$id, ['error' => $e->getMessage()], 'ERROR');
        ApiResponse::error('validation_error', $e->getMessage(), 422);
    }
}

/* ---- DELETE /tickets/{id} (eliminación lógica, solo admin, solo CERRADO/ANULADO) ---- */
if ($method === 'DELETE' && $id !== null && $action === null) {
    // Solo administradores pueden eliminar
    Auth::requirePermission('TICKETS', 'eliminar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['motivo_eliminacion'])) ApiResponse::validationError(['motivo_eliminacion' => 'Requerido']);
    try {
        // Antes de eliminar, capturar el contexto del ticket para el historial.
        $pdo = Database::connection();
        $ctxStmt = $pdo->prepare(
            "SELECT t.nro_ticket, t.estado, t.detalle_reporte, t.id_empresa,
                    e.razon_social AS empresa_razon_social,
                    p.correo AS correo_usuario
             FROM dbo.ticket t WITH (NOLOCK)
             LEFT JOIN dbo.empresa e WITH (NOLOCK) ON t.id_empresa = e.id_empresa
             LEFT JOIN dbo.persona p WITH (NOLOCK) ON p.id_persona = ?
             WHERE t.id_ticket = ?");
        $ctxStmt->bindValue(1, (int)$user['id_persona'], PDO::PARAM_INT);
        $ctxStmt->bindValue(2, (int)$id, PDO::PARAM_INT);
        $ctxStmt->execute();
        $ctx = $ctxStmt->fetch();

        $rows = Database::callSP('sp_ticket_delete_admin', [
            (int)$id,
            (int)$user['id_persona'],
            $body['motivo_eliminacion'],
        ]);
        $nroLiberado = $rows[0]['nro_ticket_liberado'] ?? null;

        // ============================================================
        // Registrar en la tabla de historial de eliminaciones.
        // ============================================================
        try {
            $hasHistoryTable = (bool)$pdo->query(
                "SELECT CASE WHEN OBJECT_ID(N'dbo.ticket_eliminacion_historial', N'U') IS NOT NULL THEN 1 ELSE 0 END"
            )->fetchColumn();

            if ($hasHistoryTable) {
                $insStmt = $pdo->prepare(
                    "INSERT INTO dbo.ticket_eliminacion_historial
                        (id_ticket, nro_ticket, estado_anterior, detalle_resumen,
                         empresa_razon_social, motivo_eliminacion,
                         id_usuario_elimino, correo_usuario, ip_address, hostname)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insStmt->bindValue(1, (int)$id, PDO::PARAM_INT);
                $insStmt->bindValue(2, $ctx['nro_ticket'] ?? ($nroLiberado ?? ''), PDO::PARAM_STR);
                $insStmt->bindValue(3, $ctx['estado'] ?? null, PDO::PARAM_STR);
                $insStmt->bindValue(4, mb_substr((string)($ctx['detalle_reporte'] ?? ''), 0, 500), PDO::PARAM_STR);
                $insStmt->bindValue(5, $ctx['empresa_razon_social'] ?? null, PDO::PARAM_STR);
                $insStmt->bindValue(6, $body['motivo_eliminacion'], PDO::PARAM_STR);
                $insStmt->bindValue(7, (int)$user['id_persona'], PDO::PARAM_INT);
                $insStmt->bindValue(8, $ctx['correo_usuario'] ?? ($user['correo'] ?? null), PDO::PARAM_STR);
                $ip = Audit::getClientIp();
                $host = Audit::getHostname();
                $insStmt->bindValue(9, $ip, PDO::PARAM_STR);
                $insStmt->bindValue(10, mb_substr($host, 0, 255), PDO::PARAM_STR);
                $insStmt->execute();
            }
        } catch (Throwable $histErr) {
            // El historial es complementario: no debe romper la eliminación.
            error_log('[Tickets DELETE] Historial de eliminación no registrado (no crítico): ' . $histErr->getMessage());
        }

        // ============================================================
        // Registrar también en bajas_registro (patrón unificado del sistema).
        // Esta tabla existe en el esquema y es la forma estándar de auditar
        // bajas de cualquier entidad (empresas, contactos, sucursales, tickets).
        // ============================================================
        try {
            $hasBajasTable = (bool)$pdo->query(
                "SELECT CASE WHEN OBJECT_ID(N'dbo.bajas_registro', N'U') IS NOT NULL THEN 1 ELSE 0 END"
            )->fetchColumn();
            if ($hasBajasTable) {
                $bajasStmt = $pdo->prepare(
                    "INSERT INTO dbo.bajas_registro
                        (tipo_entidad, id_entidad, id_usuario, motivo, fecha_baja, ip_address)
                     VALUES ('TICKET', ?, ?, ?, GETDATE(), ?)");
                $bajasStmt->bindValue(1, (int)$id, PDO::PARAM_INT);
                $bajasStmt->bindValue(2, (int)$user['id_persona'], PDO::PARAM_INT);
                $bajasStmt->bindValue(3, mb_substr($body['motivo_eliminacion'], 0, 500), PDO::PARAM_STR);
                $bajasStmt->bindValue(4, Audit::getClientIp(), PDO::PARAM_STR);
                $bajasStmt->execute();
            }
        } catch (Throwable $bajasErr) {
            error_log('[Tickets DELETE] bajas_registro no registrado (no crítico): ' . $bajasErr->getMessage());
        }

        Audit::log('TICKETS', 'ELIMINAR', 'Ticket #' . $id . ' (' . ($nroLiberado ?? '?') . ') eliminado por admin',
            'ticket', (string)$id, ['motivo' => $body['motivo_eliminacion'], 'nro_liberado' => $nroLiberado]);
        ApiResponse::success([
            'nro_ticket_liberado' => $nroLiberado,
        ], 200, 'Ticket eliminado (lógico). Código visible disponible para reutilización.');
    } catch (Throwable $e) {
        Audit::log('TICKETS', 'ELIMINAR', 'Error al eliminar ticket #' . $id . ': ' . $e->getMessage(),
            'ticket', (string)$id, ['error' => $e->getMessage()], 'ERROR');
        ApiResponse::error('validation_error', $e->getMessage(), 422);
    }
}

/* ---- GET /tickets/deleted (historial de tickets eliminados - solo admin) ----
 *  NOTA: el handler real está más arriba, ANTES del GET /tickets/{id}, para
 *  evitar que 'deleted' sea interpretado como id_ticket. Esta nota se deja
 *  aquí como documentación del orden de los handlers.
 */

/* ---- Archivos del ticket ---- */
if ($id !== null && $action === 'files' && $method === 'GET') {
    $pdo = Database::connection();
    $check = $pdo->prepare('SELECT 1 FROM dbo.ticket WHERE id_ticket = ?');
    $check->execute([(int)$id]);
    if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Ticket no encontrado.', 404);

    if ($fileId === null) {
        // Detectar columnas disponibles
        $pdo = Database::connection();
        $hasIdAtencionCol = Database::columnExists('ticket_documento', 'id_atencion');
        $hasTipoDocCol = Database::columnExists('ticket_documento', 'tipo_documento');

        $extraCols = '';
        if ($hasIdAtencionCol) $extraCols .= ', id_atencion';
        if ($hasTipoDocCol) $extraCols .= ', tipo_documento';

        if (!empty($_GET['id_atencion']) && $hasIdAtencionCol) {
            $stmt = $pdo->prepare("SELECT id_documento, nombre_original, tipo_mime, tamano_bytes,
                                          fecha_registro{$extraCols}
                                   FROM dbo.ticket_documento
                                   WHERE id_ticket = ? AND id_atencion = ?
                                   ORDER BY id_documento DESC");
            $stmt->execute([(int)$id, (int)$_GET['id_atencion']]);
        } else {
            $whereExtra = $hasIdAtencionCol ? ' AND id_atencion IS NULL' : '';
            $stmt = $pdo->prepare("SELECT id_documento, nombre_original, tipo_mime, tamano_bytes,
                                          fecha_registro{$extraCols}
                                   FROM dbo.ticket_documento
                                   WHERE id_ticket = ?{$whereExtra}
                                   ORDER BY id_documento DESC");
            $stmt->execute([(int)$id]);
        }
        ApiResponse::success($stmt->fetchAll(), 200);
    }

    // El documento se sirve desde la BD (columna contenido VARBINARY(MAX))
    // No se accede al filesystem.
    $stmt = $pdo->prepare("SELECT nombre_original, archivo_key, tipo_mime, tamano_bytes, contenido
                           FROM dbo.ticket_documento WHERE id_ticket = ? AND id_documento = ?");
    $stmt->execute([(int)$id, (int)$fileId]);
    $document = $stmt->fetch();
    if (!$document) ApiResponse::error('not_found', 'Documento no encontrado.', 404);

    // Compatibilidad: si contenido es NULL pero archivo_key existe, intentar
    // recuperar del filesystem (archivos viejos no migrados a BD).
    $contenido = $document['contenido'];
    if ($contenido === null) {
        // Solo si archivo_key es válido Y el archivo existe en disco,
        // servirlo del filesystem (migración pendiente).
        if (!preg_match('/^[a-f0-9]{48}$/', $document['archivo_key'] ?? '')) {
            ApiResponse::error('not_found', 'Documento no disponible (no está en BD ni en filesystem).', 404);
        }
        $path = $documentStorage . '/' . $document['archivo_key'];
        if (!is_file($path)) {
            ApiResponse::error('not_found', 'Archivo físico no encontrado. El documento debe migrarse a la BD.', 404);
        }
        // Servir del filesystem (fallback para archivos antiguos)
        header('Content-Type: ' . ($document['tipo_mime'] ?: 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($document['nombre_original']));
        readfile($path);
        exit;
    }

    // Servir contenido desde la BD
    // PDO sqlsrv devuelve VARBINARY(MAX) como recurso PHP (stream)
    if (is_resource($contenido)) {
        $contenido = stream_get_contents($contenido);
    }
    if (empty($contenido)) {
        ApiResponse::error('not_found', 'Documento vacío en la BD.', 404);
    }

    header('Content-Type: ' . ($document['tipo_mime'] ?: 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($contenido));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($document['nombre_original']));
    echo $contenido;
    exit;
}

if ($id !== null && $action === 'files' && $method === 'POST' && $fileId === null) {
    Auth::requirePermission('TICKETS', 'modificar');
    $file = $_FILES['documento'] ?? null;
    if (!$file || !isset($file['error'])) {
        // Diagnosticar por qué no llegó el archivo
        $postMax = ini_get('post_max_size');
        $uploadMax = ini_get('upload_max_filesize');
        $serverMax = $postMax ?: 'ilimitado';
        ApiResponse::validationError(['documento' => 'No se recibió el archivo. Verifique que el archivo no exceda ' . $serverMax . ' (post_max_size) o ' . ($uploadMax ?: 'ilimitado') . ' (upload_max_filesize) en PHP.']);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede upload_max_filesize (' . ini_get('upload_max_filesize') . '). SOLUCIÓN: sube el archivo .user.ini a la raíz del sitio y reinicia IIS. Ver /test_php_limits.php para diagnosticar.',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede MAX_FILE_SIZE del formulario',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo',
            UPLOAD_ERR_EXTENSION => 'Extensión bloqueada por PHP',
        ];
        $msg = $errorMessages[$file['error']] ?? 'Error de subida código ' . $file['error'];
        ApiResponse::validationError(['documento' => $msg]);
    }
    if ($file['size'] < 1 || $file['size'] > 200 * 1024 * 1024) {
        ApiResponse::validationError(['documento' => 'El archivo debe pesar hasta 200 MB.']);
    }

    $originalName = basename(str_replace('\\', '/', (string)$file['name']));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? '';
    $originalName = mb_substr($originalName, 0, 255);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    $allowedTypes = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        // Formatos adicionales solicitados: permitir mayor variedad de evidencias.
        'mp4' => ['video/mp4', 'application/octet-stream'],
        'mov' => ['video/quicktime', 'application/octet-stream'],
        'webm' => ['video/webm'],
        'mp3' => ['audio/mpeg'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar-compressed', 'application/octet-stream'],
        '7z' => ['application/x-7z-compressed', 'application/octet-stream'],
        'msg' => ['application/vnd.ms-outlook', 'application/octet-stream'],
        'eml' => ['message/rfc822'],
        'html' => ['text/html'],
        'htm' => ['text/html'],
        'json' => ['application/json'],
        'xml' => ['application/xml', 'text/xml'],
    ];
    if ($originalName === '' || !isset($allowedTypes[$extension]) || !in_array($mime, $allowedTypes[$extension], true)) {
        ApiResponse::validationError(['documento' => 'Formato de documento no permitido.']);
    }

    $pdo = Database::connection();
    $check = $pdo->prepare('SELECT 1 FROM dbo.ticket WHERE id_ticket = ?');
    $check->execute([(int)$id]);
    if (!$check->fetchColumn()) ApiResponse::error('not_found', 'Ticket no encontrado.', 404);

    // IMPORTANTE: Los archivos se guardan AHORA directamente en la BD
    // (columna contenido VARBINARY(MAX) de ticket_documento), no en el
    // filesystem. Esto resuelve el requisito del usuario.
    // La verificación del directorio storage/ ya NO es necesaria, pero
    // la dejamos para retrocompatibilidad con archivos viejos.
    if (!is_dir($documentStorage) && !mkdir($documentStorage, 0770, true) && !is_dir($documentStorage)) {
        // No es crítico: si no se puede crear, igualmente guardamos en BD
        error_log('[Tickets files POST] No se pudo crear storage dir, pero se guardará en BD');
    }

    // Si se especifica id_atencion, validar que exista y pertenezca al ticket
    $idAtencion = null;
    if (!empty($_POST['id_atencion'])) {
        $idAtencion = (int)$_POST['id_atencion'];
        $stmt = $pdo->prepare("SELECT 1 FROM dbo.atencion WHERE id_atencion = ? AND id_ticket = ?");
        $stmt->execute([$idAtencion, (int)$id]);
        if (!$stmt->fetchColumn()) {
            ApiResponse::validationError(['id_atencion' => 'La atención no existe o no pertenece al ticket.']);
        }
    }
    $tipoDocumento = $_POST['tipo_documento'] ?? 'EVIDENCIA_ADICIONAL';

    // Validar que el usuario esté autenticado y tenga id_persona válido (FK a persona)
    $idUsuarioActual = (int)($user['id_persona'] ?? 0);
    if ($idUsuarioActual <= 0) {
        ApiResponse::error('auth_required', 'Su sesión no tiene un usuario válido para asociar al documento.', 401);
    }

    // Detectar qué columnas existen en ticket_documento (compatibilidad con schema viejo/nuevo)
    $hasIdAtencionCol = Database::columnExists('ticket_documento', 'id_atencion');
    $hasTipoDocCol    = Database::columnExists('ticket_documento', 'tipo_documento');
    $hasContenidoCol  = Database::columnExists('ticket_documento', 'contenido');

    // Generar archivo_key (sigue siendo necesario para identificar el documento,
    // aunque el contenido se guarde en la BD).
    $fileKey = bin2hex(random_bytes(24));

    // Leer el contenido binario del archivo temporal
    $binario = file_get_contents($file['tmp_name']);
    if ($binario === false) {
        ApiResponse::error('upload_failed', 'No se pudo leer el archivo subido.', 500);
    }

    try {
        // ============================================================
        // FIX varbinary(max): mandar el archivo como base64 (NVARCHAR)
        // al SP dbo.sp_ticket_documento_save. El SP lo decodifica a
        // VARBINARY(MAX) dentro de SQL Server usando XML xs:base64Binary.
        //
        // Esto evita el bug de pdo_sqlsrv 5.11/5.12/5.13 con PHP 8.x
        // donde PDO::PARAM_LOB manda el binario como NVARCHAR(MAX),
        // lo que hace que SQL Server rechace con:
        //   "Implicit conversion from nvarchar(max) to varbinary(max)
        //    is not allowed."
        //
        // Requiere ejecutar primero:
        //   scripts_sql/01_sp_documento_save.sql
        // ============================================================
        if ($hasContenidoCol) {
            // Codificar binario a base64 (string NVARCHAR) en PHP
            $base64 = base64_encode($binario);

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
            $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
            $stmt->bindValue(2, $idUsuarioActual, PDO::PARAM_INT);
            $stmt->bindValue(3, $idAtencion,
                             $idAtencion === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(4, $originalName, PDO::PARAM_STR);
            $stmt->bindValue(5, $fileKey, PDO::PARAM_STR);
            $stmt->bindValue(6, $mime, PDO::PARAM_STR);
            $stmt->bindValue(7, (int)$file['size'], PDO::PARAM_INT);
            $stmt->bindValue(8, $tipoDocumento, PDO::PARAM_STR);
            $stmt->bindValue(9, $base64, PDO::PARAM_STR);
            // OUTPUT
            $idDocumentoOut = null;
            $stmt->bindParam(10, $idDocumentoOut,
                             PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 11);
            try {
                $stmt->execute();

                $spRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($spRow && !empty($spRow['id_documento'])) {
                    $documentId = (int)$spRow['id_documento'];
                } elseif ($idDocumentoOut !== null) {
                    $documentId = (int)$idDocumentoOut;
                } else {
                    $documentId = (int)$pdo->lastInsertId();
                }
            } catch (Throwable $spEx) {
                // Log del error completo para diagnóstico
                error_log('[Tickets files] SP sp_ticket_documento_save falló: ' . $spEx->getMessage());
                // Si el SP falla (por ejemplo, la columna contenido no existe o el SP no está actualizado),
                // hacer fallback a filesystem
                $path = $documentStorage . '/' . $fileKey;
                if (!move_uploaded_file($file['tmp_name'], $path)) {
                    ApiResponse::error('upload_failed',
                        'No se pudo guardar el documento. Error del SP: ' . $spEx->getMessage() .
                        '. Ni BD ni filesystem funcionaron.', 500);
                }
                // Guardar registro en ticket_documento SIN contenido (solo metadatos)
                if ($hasIdAtencionCol && $hasTipoDocCol) {
                    $stmt2 = $pdo->prepare("INSERT INTO dbo.ticket_documento
                        (id_ticket, id_atencion, nombre_original, archivo_key, tipo_mime, tamano_bytes, id_usuario, tipo_documento)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt2->bindValue(1, (int)$id, PDO::PARAM_INT);
                    $stmt2->bindValue(2, $idAtencion, $idAtencion === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $stmt2->bindValue(3, $originalName, PDO::PARAM_STR);
                    $stmt2->bindValue(4, $fileKey, PDO::PARAM_STR);
                    $stmt2->bindValue(5, $mime, PDO::PARAM_STR);
                    $stmt2->bindValue(6, (int)$file['size'], PDO::PARAM_INT);
                    $stmt2->bindValue(7, $idUsuarioActual, PDO::PARAM_INT);
                    $stmt2->bindValue(8, $tipoDocumento, PDO::PARAM_STR);
                    $stmt2->execute();
                    $documentId = (int)$pdo->lastInsertId();
                } else {
                    $documentId = 0;
                }
            }
        } else {
            // Schema viejo SIN columna contenido → fallback a filesystem
            $path = $documentStorage . '/' . $fileKey;
            if (!move_uploaded_file($file['tmp_name'], $path)) {
                ApiResponse::error('upload_failed', 'No se pudo guardar el documento (ni en BD ni en filesystem).', 500);
            }
            if ($hasIdAtencionCol && $hasTipoDocCol) {
                $stmt = $pdo->prepare("INSERT INTO dbo.ticket_documento
                    (id_ticket, id_atencion, nombre_original, archivo_key, tipo_mime, tamano_bytes, id_usuario, tipo_documento)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
                $stmt->bindValue(2, $idAtencion, $idAtencion === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(3, $originalName, PDO::PARAM_STR);
                $stmt->bindValue(4, $fileKey, PDO::PARAM_STR);
                $stmt->bindValue(5, $mime, PDO::PARAM_STR);
                $stmt->bindValue(6, (int)$file['size'], PDO::PARAM_INT);
                $stmt->bindValue(7, $idUsuarioActual, PDO::PARAM_INT);
                $stmt->bindValue(8, $tipoDocumento, PDO::PARAM_STR);
                $stmt->execute();
            } else {
                $stmt = $pdo->prepare("INSERT INTO dbo.ticket_documento
                    (id_ticket, nombre_original, archivo_key, tipo_mime, tamano_bytes, id_usuario)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
                $stmt->bindValue(2, $originalName, PDO::PARAM_STR);
                $stmt->bindValue(3, $fileKey, PDO::PARAM_STR);
                $stmt->bindValue(4, $mime, PDO::PARAM_STR);
                $stmt->bindValue(5, (int)$file['size'], PDO::PARAM_INT);
                $stmt->bindValue(6, $idUsuarioActual, PDO::PARAM_INT);
                $stmt->execute();
            }
            $documentId = (int)$pdo->lastInsertId();
        }

        // Historial: ADJUNTO (solo si la tabla tiene tipo_evento; si no, usar INSERT simple)
        // IMPORTANTE: la columna id_usuario tiene FK a persona.id_persona, NO acepta NULL.
        // Si por alguna razón el id_usuario no es válido, omitir el registro del historial
        // (mejor no auditar que romper toda la operación de upload).
        $hasTipoEventoCol = Database::columnExists('ticket_historial_estado', 'tipo_evento');
        $hasIdAtencionHistCol = Database::columnExists('ticket_historial_estado', 'id_atencion');

        // Verificar que el usuario existe en persona (por si la FK está validada)
        $userExists = $pdo->prepare("SELECT 1 FROM dbo.persona WHERE id_persona = ?");
        $userExists->execute([$idUsuarioActual]);
        if ($userExists->fetchColumn()) {
            try {
                if ($hasTipoEventoCol && $hasIdAtencionHistCol) {
                    $stmt = $pdo->prepare("INSERT INTO dbo.ticket_historial_estado
                        (id_ticket, estado_anterior, estado_nuevo, id_usuario, motivo, fecha_cambio, tipo_evento, id_atencion, origen_evento)
                        VALUES (?, (SELECT estado FROM dbo.ticket WHERE id_ticket = ?), (SELECT estado FROM dbo.ticket WHERE id_ticket = ?), ?, ?, SYSDATETIME(), 'ADJUNTO', ?, 'MANUAL')");
                    $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
                    $stmt->bindValue(2, (int)$id, PDO::PARAM_INT);
                    $stmt->bindValue(3, (int)$id, PDO::PARAM_INT);
                    $stmt->bindValue(4, $idUsuarioActual, PDO::PARAM_INT);
                    $stmt->bindValue(5, 'Documento adjuntado: ' . $originalName, PDO::PARAM_STR);
                    $stmt->bindValue(6, $idAtencion, $idAtencion === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $stmt->execute();
                } else {
                    // Schema viejo del historial
                    $stmt = $pdo->prepare("INSERT INTO dbo.ticket_historial_estado
                        (id_ticket, estado_anterior, estado_nuevo, id_usuario, motivo, fecha_cambio)
                        VALUES (?, (SELECT estado FROM dbo.ticket WHERE id_ticket = ?), (SELECT estado FROM dbo.ticket WHERE id_ticket = ?), ?, ?, SYSDATETIME())");
                    $stmt->bindValue(1, (int)$id, PDO::PARAM_INT);
                    $stmt->bindValue(2, (int)$id, PDO::PARAM_INT);
                    $stmt->bindValue(3, (int)$id, PDO::PARAM_INT);
                    $stmt->bindValue(4, $idUsuarioActual, PDO::PARAM_INT);
                    $stmt->bindValue(5, 'Documento adjuntado: ' . $originalName, PDO::PARAM_STR);
                    $stmt->execute();
                }
            } catch (Throwable $histErr) {
                // El historial es opcional: si falla, loguear pero NO romper el upload
                error_log('[Tickets files POST] Historial no registrado (no crítico): ' . $histErr->getMessage());
            }
        } else {
            error_log('[Tickets files POST] Usuario id=' . $idUsuarioActual . ' no existe en tabla persona, historial omitido');
        }
    } catch (Throwable $e) {
        // Solo borrar el archivo físico si se guardó en filesystem fallback
        // (modo BD no crea archivo, así que $path no está definido).
        if (isset($path) && is_file($path)) {
            @unlink($path);
        }
        error_log('[Tickets files POST] Error: ' . $e->getMessage());
        ApiResponse::error(
            'upload_failed',
            'No se pudo guardar el documento en BD: ' . $e->getMessage(),
            422
        );
    }
    ApiResponse::success(['id_documento' => $documentId], 201, 'Documento adjuntado al ticket y guardado en BD.');
}

if ($id !== null && $action === 'files' && $method === 'DELETE' && $fileId !== null) {
    Auth::requirePermission('TICKETS', 'modificar');
    $pdo = Database::connection();
    $stmt = $pdo->prepare("SELECT archivo_key, contenido
                           FROM dbo.ticket_documento
                           WHERE id_ticket = ? AND id_documento = ?");
    $stmt->execute([(int)$id, (int)$fileId]);
    $document = $stmt->fetch();
    if (!$document) ApiResponse::error('not_found', 'Documento no encontrado.', 404);

    // Eliminar el registro de la BD (la columna contenido se borra en cascada)
    $stmt = $pdo->prepare('DELETE FROM dbo.ticket_documento WHERE id_ticket = ? AND id_documento = ?');
    $stmt->execute([(int)$id, (int)$fileId]);

    // Solo intentar borrar el archivo físico si el documento NO estaba en BD
    // (es decir, si contenido era NULL y archivo_key apunta a un archivo físico).
    if ($document['contenido'] === null
        && preg_match('/^[a-f0-9]{48}$/', $document['archivo_key'] ?? '')) {
        $path = $documentStorage . '/' . $document['archivo_key'];
        if (is_file($path)) {
            @unlink($path);
        }
    }
    ApiResponse::success(null, 200, 'Documento eliminado.');
}

/* ---- PATCH /tickets/{id}/status (cambio de estado con validación de transiciones) ----
 *  REGLAS DE NEGOCIO:
 *   - RESUELTO solo puede pasar a CERRADO (validado por sp_ticket_update_status_v2).
 *   - CERRADO no puede cambiar de estado por esta vía. Para reabrir un ticket
 *     cerrado se debe usar POST /tickets/{id}/reabrir, que requiere permiso
 *     de administrador.
 *   - REABIERTO se normaliza a PENDIENTE (solo válido si el ticket NO está
 *     cerrado ni anulado; cerrado exige el endpoint /reabir).
 */
if ($method === 'PATCH' && $action === 'status') {
    Auth::requirePermission('TICKETS', 'modificar');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['nuevo_estado']) || empty($body['motivo'])) {
        ApiResponse::validationError(['nuevo_estado' => 'Requerido', 'motivo' => 'Requerido']);
    }

    // Normalizar REABIERTO -> PENDIENTE (igual que en el SP).
    $nuevoEstado = strtoupper($body['nuevo_estado']);
    if ($nuevoEstado === 'REABIERTO') $nuevoEstado = 'PENDIENTE';

    // Verificar estado actual del ticket para reglas adicionales
    $pdo = Database::connection();
    $checkStmt = $pdo->prepare('SELECT estado FROM dbo.ticket WITH (NOLOCK) WHERE id_ticket = ?');
    $checkStmt->execute([(int)$id]);
    $estadoActual = $checkStmt->fetchColumn();

    if ($estadoActual === false) {
        ApiResponse::error('not_found', 'Ticket no encontrado.', 404);
    }
    $estadoActualUpper = strtoupper($estadoActual);

    // Si el ticket está CERRADO, NO se permite cambiar el estado por PATCH /status.
    // Para reabrirlo, el usuario debe usar POST /tickets/{id}/reabrir.
    if ($estadoActualUpper === 'CERRADO') {
        ApiResponse::error(
            'invalid_transition',
            'El ticket está CERRADO. Para reabrirlo use el endpoint /tickets/{id}/reabir ' .
            '(requiere permiso administrativo).',
            422
        );
    }

    // Si el ticket está ANULADO, tampoco se permite cambio (debe eliminarse o recrearse).
    if ($estadoActualUpper === 'ANULADO') {
        ApiResponse::error(
            'invalid_transition',
            'El ticket está ANULADO. No se puede cambiar su estado.',
            422
        );
    }

    // Si está RESUELTO, solo se puede ir a CERRADO. Cualquier otro destino es error.
    if ($estadoActualUpper === 'RESUELTO' && $nuevoEstado !== 'CERRADO') {
        ApiResponse::error(
            'invalid_transition',
            'El ticket está RESUELTO. La única transición permitida es a CERRADO. ' .
            'Para reabrirlo, primero debe cerrarlo y luego usar el endpoint /reabir.',
            422
        );
    }

    try {
        Database::callSP('sp_ticket_update_status_v2', [
            (int)$id, $nuevoEstado, substr($body['motivo'], 0, 255), $user['id_persona']
        ]);
        Audit::log('TICKETS', 'CAMBIO_ESTADO', 'Ticket #' . $id . ' → ' . $nuevoEstado,
            'ticket', (string)$id, ['nuevo_estado' => $nuevoEstado, 'motivo' => $body['motivo']]);
        ApiResponse::success(null, 200, 'Estado actualizado');
    } catch (Throwable $e) {
        Audit::log('TICKETS', 'CAMBIO_ESTADO', 'Error al cambiar estado ticket #' . $id . ': ' . $e->getMessage(),
            'ticket', (string)$id, ['error' => $e->getMessage(), 'estado_destino' => $nuevoEstado], 'ERROR');
        ApiResponse::error('validation_error', $e->getMessage(), 422);
    }
}

/* ---- POST /tickets/{id}/reabrir (reapertura administrativa desde CERRADO) ----
 *  PERMISOS:
 *   - El usuario debe tener permiso 'modificar' sobre el módulo TICKETS.
 *   - Además debe ser administrador (cualquiera de los siguientes):
 *       a) Super admin (admin@soporte.com / admin@atenciones.local / admin@midominio.com).
 *       b) Rol con nombre ADMINISTRADOR o ADMIN.
 *  COMPORTAMIENTO:
 *   - Solo reabre tickets CERRADOS.
 *   - El ticket vuelve a PENDIENTE (no a EN_ATENCION ni RESUELTO).
 *   - Se limpian fechas de cierre y solución, y se desmarcan atenciones
 *     solucionadoras previas (quedan como historial).
 *   - El motivo se registra en el historial con el prefijo [REAPERTURA].
 */
if ($method === 'POST' && $id !== null && $action === 'reabrir') {
    Auth::requirePermission('TICKETS', 'modificar');

    // Verificar rol admin: el super-admin ya pasa por isSuperAdmin en Auth.
    // Para no-administradores comunes, validar que tengan rol ADMINISTRADOR.
    $userCorreo = strtolower(trim($user['correo'] ?? ''));
    $userRol    = strtoupper(trim($user['rol'] ?? ''));
    $esAdmin    = in_array($userCorreo, ['admin@soporte.com','admin@atenciones.local','admin@midominio.com'], true)
               || in_array($userRol, ['ADMINISTRADOR','ADMIN'], true);

    // Si no es admin por rol/correo, permitir igual si tiene permiso eliminar
    // (es la "acceso especial" solicitada por el usuario). Los admin con bypass
    // ya entraron por Auth::isSuperAdmin.
    if (!$esAdmin && !Auth::hasPermission('TICKETS', 'eliminar')) {
        ApiResponse::error(
            'forbidden',
            'Solo los administradores (o usuarios con permiso especial) pueden reabrir un ticket cerrado.',
            403
        );
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($body['motivo'])) {
        ApiResponse::validationError(['motivo' => 'El motivo de reapertura es obligatorio.']);
    }

    try {
        $rows = Database::callSP('sp_ticket_reabrir', [
            (int)$id,
            (int)$user['id_persona'],
            substr($body['motivo'], 0, 500),
        ]);
        Audit::log('TICKETS', 'REABRIR',
            'Ticket #' . $id . ' reabierto por administrador (de CERRADO a PENDIENTE)',
            'ticket', (string)$id,
            ['motivo' => $body['motivo'], 'usuario' => $userCorreo]);
        ApiResponse::success(null, 200, 'Ticket reabierto. Vuelve a estado PENDIENTE y debe seguir el flujo normal.');
    } catch (Throwable $e) {
        Audit::log('TICKETS', 'REABRIR',
            'Error al reabrir ticket #' . $id . ': ' . $e->getMessage(),
            'ticket', (string)$id, ['error' => $e->getMessage()], 'ERROR');
        ApiResponse::error('validation_error', $e->getMessage(), 422);
    }
}

ApiResponse::error('not_found', 'Sub-recurso de tickets no encontrado.', 404);
