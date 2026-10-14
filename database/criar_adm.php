<?php
// database/criar_adm.php
// Uso (somente no terminal): php database/criar_adm.php email senha ["Nome"]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc < 3) {
    echo "Uso: php database/criar_adm.php email senha [\"Nome\"]\n";
    exit(1);
}

$db = new SQLite3(__DIR__ . '/database.db');

$email = $argv[1];
$senha = $argv[2];
$nome  = $argv[3] ?? "Administrador";
$nivel = "ADM";

$senha_hash = password_hash($senha, PASSWORD_DEFAULT);

// Verifica se já existe um ADM cadastrado
$res = $db->querySingle("SELECT COUNT(*) as total FROM usuarios WHERE nivel='ADM'");
if ($res > 0) {
    echo "Já existe um usuário ADM cadastrado!\n";
    exit;
}

$stmt = $db->prepare('INSERT INTO usuarios (nome, email, senha_hash, nivel) VALUES (?, ?, ?, ?)');
$stmt->bindValue(1, $nome);
$stmt->bindValue(2, $email);
$stmt->bindValue(3, $senha_hash);
$stmt->bindValue(4, $nivel);

if ($stmt->execute()) {
    echo "Usuário ADM criado com sucesso! Email: $email\n";
} else {
    echo "Erro ao criar usuário ADM.\n";
}
