<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_GET['consultor']) || !isset($_GET['lote'])) {
    exit('Parâmetros ausentes!');
}
$consultor_id = (int)$_GET['consultor'];
$lote_id = (int)$_GET['lote'];

// Busca dados do consultor
$usuario = $db->query("SELECT nome FROM usuarios WHERE id = $consultor_id")->fetchArray(SQLITE3_ASSOC);
$nome_consultor = $usuario ? $usuario['nome'] : 'Consultor';

// Busca leads desse consultor e lote
$leads = [];
$res = $db->query("SELECT * FROM leads WHERE consultor_id = $consultor_id AND lista_id = $lote_id");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $leads[] = $row;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$header = [
    'Empresa/Cliente', 'CPF/CNPJ', 'Email', 'Cidade', 'Estado', 'CEP', 'Status'
];
$sheet->fromArray($header, NULL, 'A1');

$rowNum = 2;
foreach ($leads as $lead) {
    $sheet->fromArray([
        $lead['empresa'],
        $lead['cpf_cnpj'],
        $lead['email'],
        $lead['cidade'],
        $lead['estado'],
        $lead['cep'],
        $lead['status']
    ], NULL, "A$rowNum");
    $rowNum++;
}

// Download:
$filename = 'Leads_' . preg_replace('/\W+/', '_', $nome_consultor) . '_lote_' . $lote_id . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
