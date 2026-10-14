<?php
// database/criar_db.php
// Uso (somente no terminal): php database/criar_db.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$db = new SQLite3(__DIR__ . '/database.db');

// Tabela de usuários
$db->exec('CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    senha_hash TEXT NOT NULL,
    nivel TEXT NOT NULL,
    equipe TEXT
)');

// Tabela de leads
$db->exec('CREATE TABLE IF NOT EXISTS leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    empresa TEXT,
    tel_comercial TEXT,
    tel_celular TEXT,
    email TEXT,
    cidade TEXT,
    estado TEXT,
    cep TEXT,
    codigolead TEXT,
    descricao TEXT,
    cpf_cnpj TEXT,
    status TEXT DEFAULT "Novo",
    consultor_id INTEGER,
    data_atribuicao TEXT,
    data_retorno TEXT,
    lista_id INTEGER,
    observacao TEXT,
    telefone_ligacao TEXT,
    sfa TEXT
)');

// Tabela de tabulações (retorno dos consultores)
$db->exec('CREATE TABLE IF NOT EXISTS tabulacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER,
    consultor_id INTEGER,
    status TEXT,
    observacao TEXT,
    data_envio TEXT
)');

// Configurações (cota de distribuição)
$db->exec('CREATE TABLE IF NOT EXISTS configuracoes (
    chave TEXT PRIMARY KEY,
    valor TEXT
)');

// Listas (bases importadas)
$db->exec('CREATE TABLE IF NOT EXISTS listas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome_arquivo TEXT,
    data_importacao TEXT,
    total_leads INTEGER
)');

echo "Banco criado!\n";
