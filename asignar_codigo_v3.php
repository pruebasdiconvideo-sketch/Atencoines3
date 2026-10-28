<?php
/**
 * asignar_codigo_v3.php — Asigna codigo_empresa a una empresa
 * Recibe POST: id_empresa, codigo_empresa
 */

declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';
startAppSession();

if (empty($_SESSION['user']['id_persona'])) {
    header('Location: /login.php');
    exit;
}

$userId = (int)$_SESSION['user']['id_persona'];
$pdo = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idEmpresa = (int)($_POST['id_empresa'] ?? 0);
    $codigo = strtoupper(trim($_POST['codigo_empresa'] ?? ''));

    if (!$idEmpresa || !$codigo) {
        header('Location: test_v3.php?error=datos_invalidos');
        exit;
    }

    // Validar formato
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $codigo)) {
        header('Location: test_v3.php?error=formato_invalido');
        exit;
    }

    try {
        $chk = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.sp_empresa_set_codigo','P') IS NOT NULL THEN 1 ELSE 0 END");
        if (!(int)$chk->fetchColumn()) {
            // Si no existe el SP, hacer UPDATE directo
            $stmt = $pdo->prepare("UPDATE dbo.empresa SET codigo_empresa = ?, fecha_modificacion = SYSDATETIME(), id_usuario_modificacion = ? WHERE id_empresa = ?");
            $stmt->bindValue(1, $codigo, PDO::PARAM_STR);
            $stmt->bindValue(2, $userId, PDO::PARAM_INT);
            $stmt->bindValue(3, $idEmpresa, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("EXEC dbo.sp_empresa_set_codigo @id_empresa = ?, @codigo_empresa = ?, @id_usuario = ?");
            $stmt->bindValue(1, $idEmpresa, PDO::PARAM_INT);
            $stmt->bindValue(2, $codigo, PDO::PARAM_STR);
            $stmt->bindValue(3, $userId, PDO::PARAM_INT);
            $stmt->execute();
        }
        header('Location: test_v3.php?ok=1');
        exit;
    } catch (Throwable $e) {
        header('Location: test_v3.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

header('Location: test_v3.php');
exit;
