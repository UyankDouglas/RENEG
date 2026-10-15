<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_nivel'], ['ADM', 'Supervisor'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/exportacao.php';

if (!isset($_GET['consultor']) || !isset($_GET['lote'])) {
    exit('Parâmetros ausentes!');
}
$consultor_id = (int)$_GET['consultor'];
$lote_id = (int)$_GET['lote'];

// Busca dados do consultor
$usuario = $db->query("SELECT nome FROM usuarios WHERE id = $consultor_id")->fetchArray(SQLITE3_ASSOC);
$nome_consultor = $usuario ? $usuario['nome'] : 'Consultor';

// Leads desse consultor e lote: CNPJ, telefone, linhas e resultado da tabulação
$spreadsheet = exportacao_planilha(COLUNAS_EXPORTACAO);
$sheet = $spreadsheet->getActiveSheet();

$res = $db->query("
    SELECT *
    FROM leads
    WHERE consultor_id = $consultor_id AND lista_id = $lote_id
    ORDER BY id ASC
");
$rowNum = 2;
while ($lead = $res->fetchArray(SQLITE3_ASSOC)) {
    $sheet->fromArray(exportacao_linha($lead), null, "A{$rowNum}");
    $rowNum++;
}

// Download
$filename = 'Leads_' . preg_replace('/\W+/', '_', $nome_consultor) . '_lote_' . $lote_id . '.xlsx';
exportacao_enviar($spreadsheet, $filename);
