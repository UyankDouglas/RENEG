<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_nivel'], ['ADM', 'Supervisor'])) {
    header('Location: login.php');
    exit;
}
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
$res = $db->query("
    SELECT empresa, cpf_cnpj, email, cidade, estado, cep, status, telefone_ligacao
    FROM leads
    WHERE consultor_id = $consultor_id AND lista_id = $lote_id
    ORDER BY id ASC
");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $leads[] = $row;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Leads');

// Cabeçalho (inclui Telefone da Ligação)
$header = [
    'Empresa/Cliente', 'CPF/CNPJ', 'Email', 'Cidade', 'Estado', 'CEP',
    'Status', 'Telefone da Ligação'
];
$sheet->fromArray($header, null, 'A1');

$rowNum = 2;
foreach ($leads as $lead) {
    $sheet->fromArray([
        $lead['empresa'] ?? '',
        $lead['cpf_cnpj'] ?? '',
        $lead['email'] ?? '',
        $lead['cidade'] ?? '',
        $lead['estado'] ?? '',
        $lead['cep'] ?? '',
        $lead['status'] ?? '',
        $lead['telefone_ligacao'] ?? ''   // << novo campo exportado
    ], null, "A{$rowNum}");
    $rowNum++;
}

// (Opcional) Autoajuste de colunas
foreach (range('A', 'H') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Download
$filename = 'Leads_' . preg_replace('/\W+/', '_', $nome_consultor) . '_lote_' . $lote_id . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
