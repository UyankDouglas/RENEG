<?php
session_start();
require_once __DIR__ . '/../config/database.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = $db->prepare('SELECT * FROM usuarios WHERE email = ?');
    $stmt->bindValue(1, $email);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($result && password_verify($senha, $result['senha_hash'])) {
        $_SESSION['usuario_id'] = $result['id'];
        $_SESSION['usuario_nome'] = $result['nome'];
        $_SESSION['usuario_nivel'] = $result['nivel'];
        $_SESSION['usuario_equipe'] = $result['equipe']; // <--- Salvando a equipe na sessão!
        header('Location: dashboard.php');
        exit;
    } else {
        $msg = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="login-container">
        <h2>Acesso ao Sistema</h2>
        <?php if ($msg): ?>
            <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        <form method="POST" class="form-login">
            <input type="email" name="email" placeholder="E-mail" required>
            <input type="password" name="senha" placeholder="Senha" required>
            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
