<?php
$db = new SQLite3(__DIR__ . '/database.db');

// Altere esses dados se quiser
$nome = "Administrador";
$email = "adm@empresa.com";
$senha = "senha123"; // Altere para a senha desejada!
$nivel = "ADM";

$senha_hash = password_hash($senha, PASSWORD_DEFAULT);

// Verifica se já existe um ADM cadastrado
$res = $db->querySingle("SELECT COUNT(*) as total FROM usuarios WHERE nivel='ADM'");
if ($res > 0) {
    echo "Já existe um usuário ADM cadastrado!";
    exit;
}

$stmt = $db->prepare('INSERT INTO usuarios (nome, email, senha_hash, nivel) VALUES (?, ?, ?, ?)');
$stmt->bindValue(1, $nome);
$stmt->bindValue(2, $email);
$stmt->bindValue(3, $senha_hash);
$stmt->bindValue(4, $nivel);

if ($stmt->execute()) {
    echo "Usuário ADM criado com sucesso!<br>";
    echo "Email: $email<br>";
    echo "Senha: $senha";
} else {
    echo "Erro ao criar usuário ADM.";
}
