<?php
// config/opcoes.php
// Listas editáveis pelo ADM em Configurações: tabulações (status dos leads) e equipes.

// Valores iniciais (eram fixos no código antes da página de Configurações)
const STATUS_PADRAO = [
    "ACHOU CARO",
    "CLIENTE JÁ ESTÁ SENDO ATENDIDO POR OUTRO CONSULTOR/CANAL",
    "CLIENTE NAO QUIS OUVIR A OFERTA",
    "CLIENTE PEDIU RETORNO",
    "CLIENTE PREFERE A CONCORRENCIA",
    "VENDA",
    "CLIENTES COM ORDEM EM ANDAMENTO NO SMART",
    "CLIENTE FIDELIZADO RECENTEMENTE",
    "CPF/CNPJ SUSPENSO/INATIVO",
    "DECISOR NÃO LOCALIZADO",
    "INADIMPLENTE",
    "INSATISFEITO/ NÃO QUER CONTATO COM A TELEFONICA|VIVO",
    "INVIABILIDADE TÉCNICA",
    "NÃO ACEITA FIDELIDADE 24 MESES",
    "NÃO ATENDE",
    "NÃO É MAIS CLIENTE VIVO/DESCONECTADO",
    "NÃO QUIS CONFIRMAR DOCUMENTOS/NÃO FORNECE INFORMAÇÕES POR TELEFONE",
    "CLIENTES SEM RECOMENDAÇÃO"
];
const EQUIPES_PADRAO = ['Tallyta', 'Marcela'];

// "Novo" marca lead ainda não tabulado e "Todos" é o filtro do dashboard
const STATUS_RESERVADOS = ['novo', 'todos'];

/**
 * Cria as tabelas na primeira vez e preenche com os valores iniciais.
 * Só preenche quando a tabela acabou de ser criada, para não recriar o que o ADM apagou.
 */
function opcoes_garantir_tabelas($db) {
    static $pronto = false;
    if ($pronto) return;

    if (!$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='status_opcoes'")) {
        $db->exec('CREATE TABLE IF NOT EXISTS status_opcoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL UNIQUE COLLATE NOCASE,
            ordem INTEGER NOT NULL DEFAULT 0
        )');
        $stmt = $db->prepare('INSERT OR IGNORE INTO status_opcoes (nome, ordem) VALUES (:n, :o)');
        foreach (STATUS_PADRAO as $i => $nome) {
            $stmt->bindValue(':n', $nome, SQLITE3_TEXT);
            $stmt->bindValue(':o', $i + 1, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->reset();
        }
    }

    if (!$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='equipes'")) {
        $db->exec('CREATE TABLE IF NOT EXISTS equipes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL UNIQUE COLLATE NOCASE
        )');
        // Equipes iniciais + as que já estiverem em uso por algum usuário
        $nomes = EQUIPES_PADRAO;
        $res = $db->query("SELECT DISTINCT equipe FROM usuarios WHERE equipe IS NOT NULL AND TRIM(equipe) != ''");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) $nomes[] = $row['equipe'];
        $stmt = $db->prepare('INSERT OR IGNORE INTO equipes (nome) VALUES (:n)');
        foreach ($nomes as $nome) {
            $stmt->bindValue(':n', $nome, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
        }
    }

    $pronto = true;
}

/** Nomes das tabulações, na ordem definida em Configurações. */
function listar_status($db) {
    opcoes_garantir_tabelas($db);
    $lista = [];
    $res = $db->query('SELECT nome FROM status_opcoes ORDER BY ordem, id');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $lista[] = $row['nome'];
    return $lista;
}

/** Nomes das equipes, em ordem alfabética. */
function listar_equipes($db) {
    opcoes_garantir_tabelas($db);
    $lista = [];
    $res = $db->query('SELECT nome FROM equipes ORDER BY nome COLLATE NOCASE');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $lista[] = $row['nome'];
    return $lista;
}
