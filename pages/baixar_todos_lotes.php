<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_nivel'], ['ADM', 'Supervisor'])) {
    header('Location: login.php');
    exit;
}
set_time_limit(300);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="Leads_Todos_Lotes.csv"');

$fp = fopen('php://output', 'w');

// BOM UTF-8
fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

// Cabeçalho com SFA adicionado
fputcsv($fp, [
    'Lote',
    'Data Importação',
    'Consultor',
    'Empresa/Cliente',
    'CPF/CNPJ',
    'Email',
    'Cidade',
    'Estado',
    'CEP',
    'Telefone da Ligação',
    'SFA',                  // <<< NOVO
    'Status'
], ',', '"', '\\');

$res = $db->query("
    SELECT 
        leads.*, 
        listas.nome_arquivo AS lote_nome, 
        listas.data_importacao AS lote_data, 
        usuarios.nome AS consultor_nome
    FROM leads
    LEFT JOIN listas   ON leads.lista_id = listas.id
    LEFT JOIN usuarios ON leads.consultor_id = usuarios.id
    ORDER BY leads.lista_id, leads.consultor_id, leads.id
");

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    fputcsv($fp, [
        $row['lote_nome'] ?? '',
        $row['lote_data'] ? date('d/m/Y H:i', strtotime($row['lote_data'])) : '',
        $row['consultor_nome'] ?? '',
        $row['empresa'] ?? '',
        $row['cpf_cnpj'] ?? '',
        $row['email'] ?? '',
        $row['cidade'] ?? '',
        $row['estado'] ?? '',
        $row['cep'] ?? '',
        $row['telefone_ligacao'] ?? '',
        $row['sfa'] ?? '',                 // <<< NOVO: valor SFA
        $row['status'] ?? ''
    ], ',', '"', '\\');
}

fclose($fp);
exit;
