<?php
// database/criar_adm.php
// Uso (somente no terminal): php database/criar_adm.php cpf senha ["Nome"]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/cpf.php';

if ($argc < 3) {
    echo "Uso: php database/criar_adm.php cpf senha [\"Nome\"]\n";
    exit(1);
}
if (!cpf_valido($argv[1])) {
    echo "CPF inválido.\n";
    exit(1);
}

$db = new SQLite3(__DIR__ . '/database.db');

$cpf   = cpf_normalizar($argv[1]);
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

$stmt = $db->prepare('INSERT INTO usuarios (nome, cpf, senha_hash, nivel) VALUES (?, ?, ?, ?)');
$stmt->bindValue(1, $nome);
$stmt->bindValue(2, $cpf);
$stmt->bindValue(3, $senha_hash);
$stmt->bindValue(4, $nivel);

if ($stmt->execute()) {
    echo "Usuário ADM criado com sucesso! CPF: " . cpf_formatar($cpf) . "\n";
} else {
    echo "Erro ao criar usuário ADM.\n";
}
