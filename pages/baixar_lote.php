<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_GET['lote'])) {
    exit('Parâmetro de lote ausente!');
}
$lote_id = (int)$_GET['lote'];

$lote = $db->query("SELECT * FROM listas WHERE id = $lote_id")->fetchArray(SQLITE3_ASSOC);
if (!$lote) exit('Lote não encontrado!');

$leads = [];
$res = $db->query("SELECT * FROM leads WHERE lista_id = $lote_id");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $leads[] = $row;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$header = [
    'Empresa/Cliente', 'CPF/CNPJ', 'Email', 'Cidade', 'Estado', 'CEP', 'Status', 'Consultor'
];
$sheet->fromArray($header, NULL, 'A1');

$rowNum = 2;
foreach ($leads as $lead) {
    // Buscar nome do consultor
    $consultor_nome = '';
    if (!empty($lead['consultor_id'])) {
        $resUser = $db->query("SELECT nome FROM usuarios WHERE id = {$lead['consultor_id']}")->fetchArray(SQLITE3_ASSOC);
        $consultor_nome = $resUser ? $resUser['nome'] : '';
    }
    $sheet->fromArray([
        $lead['empresa'],
        $lead['cpf_cnpj'],
        $lead['email'],
        $lead['cidade'],
        $lead['estado'],
        $lead['cep'],
        $lead['status'],
        $consultor_nome
    ], NULL, "A$rowNum");
    $rowNum++;
}

// Download:
$filename = 'Leads_Lote_' . $lote_id . '_' . preg_replace('/\W+/', '_', $lote['nome_arquivo']) . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
