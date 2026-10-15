<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_nivel'], ['ADM', 'Supervisor'])) {
    header('Location: login.php');
    exit;
}
set_time_limit(300);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/exportacao.php';

// Todos os leads em XLSX (~9s e ~125MB para 14 mil leads; 512MB do pool dão para ~70 mil)
$spreadsheet = exportacao_planilha(['Lote' => 28, 'Data Importação' => 17, 'Consultor' => 30] + COLUNAS_EXPORTACAO);
$sheet = $spreadsheet->getActiveSheet();

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

$rowNum = 2;
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $sheet->fromArray(array_merge([
        $row['lote_nome'] ?? '',
        $row['lote_data'] ? date('d/m/Y H:i', strtotime($row['lote_data'])) : '',
        $row['consultor_nome'] ?? '',
    ], exportacao_linha($row)), null, "A{$rowNum}");
    $rowNum++;
}

exportacao_enviar($spreadsheet, 'Leads_Todos_Lotes.xlsx');
