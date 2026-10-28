<?php
/**
 * config_logo_v3.php — VERSIÓN CORREGIDA (V5)
 * ----------------------------------------------------------------------------
 * CAMBIO: Usa startAppSession() del sistema original para compartir la sesión.
 *
 * Acceder directamente: http://su-servidor/config_logo_v3.php
 */

declare(strict_types=1);

// Cargar el sistema completo (igual que index.php)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

// Iniciar la sesión con el MISMO nombre que el sistema principal (ATN_SESSID)
startAppSession();

// Verificar sesión
if (empty($_SESSION['user']['id_persona'])) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso requerido</title>
    <style>
    body { font-family: "Segoe UI", Arial, sans-serif; padding: 40px; background: linear-gradient(135deg,#1976d2,#1565c0); min-height: 100vh; margin: 0; }
    .card { background: #fff; padding: 30px; border-radius: 8px; max-width: 500px; margin: 0 auto; box-shadow: 0 4px 20px rgba(0,0,0,0.2); text-align: center; }
    .card h2 { color: #1976d2; margin-top: 0; }
    .card p { color: #555; margin: 16px 0; }
    .btn { display: inline-block; padding: 12px 24px; background: #1976d2; color: #fff; text-decoration: none; border-radius: 4px; font-weight: 600; }
    .btn:hover { background: #1565c0; }
    </style></head>
    <body><div class="card">
    <h2>🔒 Acceso requerido</h2>
    <p>Para acceder a la configuración de logos, primero debe iniciar sesión en el sistema.</p>
    <p style="font-size:13px;color:#888;">Use las mismas credenciales que usa para entrar al sistema principal.</p>
    <a href="/login.php" class="btn">Iniciar sesión</a>
    </div></body></html>';
    exit;
}

$userId = (int)$_SESSION['user']['id_persona'];
$pdo = Database::connection();

// Procesar POST (subir o eliminar logo)
$mensaje = '';
$mensajeTipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload' && !empty($_POST['id_emisora'])) {
        $idEmisora = (int)$_POST['id_emisora'];

        if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];

            if ($file['size'] > 2 * 1024 * 1024) {
                $mensaje = 'El archivo excede el máximo permitido de 2 MB.';
                $mensajeTipo = 'error';
            } else {
                $allowedMimes = ['image/png','image/jpeg','image/jpg','image/svg+xml','image/gif','image/webp'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                if (!in_array($mime, $allowedMimes, true)) {
                    $mensaje = 'Tipo de archivo no permitido: ' . $mime . '. Use PNG, JPG, SVG, GIF o WebP.';
                    $mensajeTipo = 'error';
                } else {
                    $content = file_get_contents($file['tmp_name']);
                    $logoDataUrl = 'data:' . $mime . ';base64,' . base64_encode($content);

                    $stmt = $pdo->prepare("EXEC dbo.sp_cotizacion_emisora_logo_save @id_emisora = ?, @logo_base64 = ?, @id_usuario = ?");
                    $stmt->bindValue(1, $idEmisora, PDO::PARAM_INT);
                    $stmt->bindValue(2, $logoDataUrl, PDO::PARAM_STR);
                    $stmt->bindValue(3, $userId, PDO::PARAM_INT);

                    try {
                        $stmt->execute();
                        $mensaje = '✓ Logo actualizado correctamente para la emisora #' . $idEmisora;
                        $mensajeTipo = 'success';
                    } catch (Throwable $e) {
                        $mensaje = 'Error al guardar: ' . $e->getMessage();
                        $mensajeTipo = 'error';
                    }
                }
            }
        } else {
            $mensaje = 'No se seleccionó archivo.';
            $mensajeTipo = 'error';
        }
    } elseif ($action === 'delete' && !empty($_POST['id_emisora'])) {
        $idEmisora = (int)$_POST['id_emisora'];
        $stmt = $pdo->prepare("UPDATE dbo.cotizacion_empresa_emisora SET logo_base64 = NULL, fecha_modificacion = SYSDATETIME(), id_usuario_modificacion = ? WHERE id_emisora = ?");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $idEmisora, PDO::PARAM_INT);
        $stmt->execute();
        $mensaje = '✓ Logo eliminado de la emisora #' . $idEmisora;
        $mensajeTipo = 'success';
    }
}

// Listar emisoras
$stmt = $pdo->query("SELECT id_emisora, razon_social, nombre_comercial, ruc,
                            logo_base64,
                            CASE WHEN logo_base64 IS NULL THEN 0 ELSE 1 END AS tiene_logo,
                            activo, es_principal,
                            CONVERT(VARCHAR(19), fecha_modificacion, 120) AS fecha_modificacion
                     FROM dbo.cotizacion_empresa_emisora
                     ORDER BY es_principal DESC, razon_social");
$emisoras = $stmt->fetchAll();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Configuración de Logo — Cotizaciones V5</title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; padding: 0; background: #f5f5f5; color: #333; margin: 0; }
.container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.menu-superior { background: #2c3e50; padding: 8px 20px; display: flex; gap: 8px; flex-wrap: wrap; }
.menu-superior a { color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; font-size: 13px; }
.menu-superior a:hover { background: rgba(255,255,255,0.1); }
.menu-superior a.active { background: #1976d2; }
.header { background: #1976d2; color: #fff; padding: 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; }
.header h1 { margin: 0; font-size: 22px; }
.header .user-info { font-size: 12px; opacity: 0.9; }
.header a { color: #fff; text-decoration: none; padding: 8px 14px; background: rgba(255,255,255,0.2); border-radius: 4px; }
.card { background: #fff; padding: 20px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
.emisora-card { border: 1px solid #e0e0e0; border-radius: 8px; padding: 16px; margin-bottom: 16px; display: grid; grid-template-columns: 200px 1fr; gap: 16px; }
.emisora-card.principal { border-color: #1976d2; background: #f3f9ff; }
.logo-preview { width: 200px; height: 120px; border: 2px dashed #bdc3c7; display: flex; align-items: center; justify-content: center; background: #fafbfc; border-radius: 4px; overflow: hidden; }
.logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.btn { display: inline-block; padding: 8px 16px; background: #1976d2; color: #fff; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; font-size: 13px; margin-right: 6px; }
.btn:hover { background: #1565c0; }
.btn-success { background: #28a745; }
.btn-success:hover { background: #218838; }
.btn-danger { background: #dc3545; }
.btn-danger:hover { background: #c82333; }
.btn-secondary { background: #6c757d; }
.alert { padding: 12px; border-radius: 4px; margin-bottom: 16px; }
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.badge-success { background: #28a745; color: #fff; }
.badge-warning { background: #ffc107; color: #212529; }
.badge-primary { background: #1976d2; color: #fff; }
.form-group { margin-bottom: 10px; }
.form-group label { display: block; font-size: 12px; color: #7f8c8d; margin-bottom: 4px; }
input[type="file"] { padding: 6px; border: 1px solid #ccc; border-radius: 4px; width: 100%; }
</style>
</head>
<body>

<div class="menu-superior">
    <a href="/index.php">🏠 Inicio</a>
    <a href="test_v3.php">🔧 Diagnóstico</a>
    <a href="nueva_cotizacion_v3.php">📄 Nueva Cotización</a>
    <a href="config_logo_v3.php" class="active">🖼️ Configurar Logo</a>
    <a href="seguimiento_comercial_v3.php">📋 Seguimiento</a>
</div>

<div class="container">
    <div class="header">
        <div>
            <h1>🖼️ Configuración de Logo de Cotizaciones</h1>
            <div class="user-info">
                Conectado como: <strong><?= htmlspecialchars($_SESSION['user']['correo'] ?? $_SESSION['user']['nombres'] ?? 'Usuario') ?></strong>
            </div>
        </div>
        <a href="/index.php">← Volver al sistema</a>
    </div>

    <div class="card">
        <p style="color: #7f8c8d; margin: 0 0 16px 0; font-size: 14px;">
            Suba o reemplace el logo que aparecerá en el encabezado de las cotizaciones en PDF y reportes.<br>
            <strong>Formatos permitidos:</strong> PNG, JPG, SVG, GIF, WebP · <strong>Tamaño máximo:</strong> 2 MB
        </p>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?= $mensajeTipo === 'success' ? 'success' : 'error' ?>">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>

        <?php if (empty($emisoras)): ?>
            <div class="alert alert-error">No hay emisoras configuradas en el sistema.</div>
        <?php else: ?>
            <?php foreach ($emisoras as $e): ?>
                <div class="emisora-card <?= $e['es_principal'] ? 'principal' : '' ?>" id="empresa-<?= $e['id_emisora'] ?>">
                    <div>
                        <div class="logo-preview">
                            <?php if (!empty($e['logo_base64'])): ?>
                                <img src="<?= htmlspecialchars($e['logo_base64']) ?>" alt="Logo">
                            <?php else: ?>
                                <span style="color: #bdc3c7; font-size: 11px;">(Sin logo)</span>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 6px; font-size: 10px; color: #7f8c8d; text-align: center;">
                            <?php if (!empty($e['fecha_modificacion'])): ?>
                                Última modificación: <?= htmlspecialchars($e['fecha_modificacion']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 4px 0; color: #2c3e50;">
                            <?= htmlspecialchars($e['razon_social']) ?>
                            <?php if ($e['es_principal']): ?>
                                <span class="badge badge-primary">PRINCIPAL</span>
                            <?php endif; ?>
                            <?php if ((int)$e['tiene_logo'] === 1): ?>
                                <span class="badge badge-success">✓ Con logo</span>
                            <?php else: ?>
                                <span class="badge badge-warning">⚠ Sin logo</span>
                            <?php endif; ?>
                        </h3>
                        <?php if (!empty($e['nombre_comercial'])): ?>
                            <div style="color: #7f8c8d; font-size: 13px; margin-bottom: 4px;"><?= htmlspecialchars($e['nombre_comercial']) ?></div>
                        <?php endif; ?>
                        <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 12px;">
                            RUC: <?= htmlspecialchars($e['ruc']) ?> · ID: <?= $e['id_emisora'] ?>
                        </div>

                        <form method="post" enctype="multipart/form-data" style="display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap;">
                            <input type="hidden" name="action" value="upload">
                            <input type="hidden" name="id_emisora" value="<?= $e['id_emisora'] ?>">
                            <div class="form-group" style="flex: 1; min-width: 250px;">
                                <label>Seleccionar archivo de logo:</label>
                                <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp" required>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <?= (int)$e['tiene_logo'] === 1 ? '🔄 Reemplazar logo' : '⬆ Subir logo' ?>
                            </button>
                        </form>

                        <?php if ((int)$e['tiene_logo'] === 1): ?>
                            <form method="post" style="margin-top: 8px;" onsubmit="return confirm('¿Eliminar el logo de esta emisora?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id_emisora" value="<?= $e['id_emisora'] ?>">
                                <button type="submit" class="btn btn-danger">🗑️ Eliminar logo</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
