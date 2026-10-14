<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/cpf.php';

$msg = '';
$login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['cpf'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $result = false;
    if (strpos($login, '@') === false) {
        $stmt = $db->prepare('SELECT * FROM usuarios WHERE cpf = ?');
        $stmt->bindValue(1, cpf_normalizar($login));
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    } else {
        // Transição: quem foi cadastrado antes do CPF entra pelo e-mail até ter um CPF definido em Usuários
        $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ? AND (cpf IS NULL OR cpf = '')");
        $stmt->bindValue(1, $login);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    if ($result && password_verify($senha, $result['senha_hash'])) {
        $_SESSION['usuario_id'] = $result['id'];
        $_SESSION['usuario_nome'] = $result['nome'];
        $_SESSION['usuario_nivel'] = $result['nivel'];
        $_SESSION['usuario_equipe'] = $result['equipe']; // <--- Salvando a equipe na sessão!
        header('Location: dashboard.php');
        exit;
    } else {
        $msg = 'CPF ou senha inválidos.';
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
            <input type="text" name="cpf" id="cpf" placeholder="CPF" value="<?= htmlspecialchars($login) ?>"
                   inputmode="numeric" autocomplete="username" maxlength="60" required autofocus>
            <input type="password" name="senha" placeholder="Senha" required>
            <button type="submit">Entrar</button>
        </form>
    </div>
<script>
// Máscara 000.000.000-00 (não mexe se for e-mail de usuário antigo, ainda sem CPF)
(function () {
    const el = document.getElementById('cpf');
    el.addEventListener('input', function () {
        if (/[a-z@]/i.test(el.value)) return;
        const d = el.value.replace(/\D/g, '').slice(0, 11);
        el.value = d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2');
    });
})();
</script>
</body>
</html>
