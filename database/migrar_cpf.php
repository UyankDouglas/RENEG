<?php
// database/migrar_cpf.php
// Login por CPF: adiciona a coluna cpf em usuarios e torna o e-mail opcional.
// Uso (somente no terminal, uma vez; pode rodar de novo sem efeito): php database/migrar_cpf.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$db = new SQLite3(__DIR__ . '/database.db');
$db->busyTimeout(5000);

$colunas = [];
$res = $db->query('PRAGMA table_info(usuarios)');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $colunas[] = $row['name'];
if (in_array('cpf', $colunas, true)) {
    echo "Já migrado (usuarios.cpf existe).\n";
    exit;
}

// SQLite não remove o NOT NULL do e-mail: recria a tabela mantendo os mesmos ids
// (leads.consultor_id aponta para eles) e o contador do AUTOINCREMENT
// (para um usuário novo nunca herdar o id de um usuário excluído).
$seq = (int)$db->querySingle("SELECT seq FROM sqlite_sequence WHERE name = 'usuarios'");
$antes = (int)$db->querySingle('SELECT COUNT(*) FROM usuarios');

$db->exec('BEGIN IMMEDIATE;');
$ok = $db->exec('CREATE TABLE usuarios_novo (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        cpf TEXT UNIQUE,
        email TEXT UNIQUE,
        senha_hash TEXT NOT NULL,
        nivel TEXT NOT NULL,
        equipe TEXT
    )')
    && $db->exec('INSERT INTO usuarios_novo (id, nome, email, senha_hash, nivel, equipe)
                   SELECT id, nome, email, senha_hash, nivel, equipe FROM usuarios')
    && $db->exec('DROP TABLE usuarios')
    && $db->exec('ALTER TABLE usuarios_novo RENAME TO usuarios')
    && $db->exec("UPDATE sqlite_sequence SET seq = MAX(seq, $seq) WHERE name = 'usuarios'");

$depois = $ok ? (int)$db->querySingle('SELECT COUNT(*) FROM usuarios') : -1;
if (!$ok || $depois !== $antes) {
    $db->exec('ROLLBACK;');
    echo "ERRO na migração; nada foi alterado.\n";
    exit(1);
}
$db->exec('COMMIT;');

echo "Migrado: $depois usuário(s). Quem ainda não tem CPF entra pelo e-mail até ter um CPF definido em Usuários.\n";
