<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] != 'Consultor') {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php'; // Ajuste o caminho se necessário
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$consultor_id = $_SESSION['usuario_id'];

// Buscar os leads do consultor
$res = $db->query("SELECT * FROM leads WHERE consultor_id = $consultor_id");
$leads = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $leads[] = $row;
}

// Cria a planilha
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
// Cabeçalho
$sheet->fromArray([
    'Empresa/Cliente', 'Telefone Comercial', 'Telefone Celular', 'Email',
    'Cidade', 'Estado', 'CEP', 'CodigoLead', 'Descrição', 'CPF/CNPJ', 'Status'
], NULL, 'A1');

$rowNum = 2;
foreach ($leads as $lead) {
    $sheet->setCellValue("A$rowNum", $lead['empresa']);
    $sheet->setCellValue("B$rowNum", $lead['tel_comercial']);
    $sheet->setCellValue("C$rowNum", $lead['tel_celular']);
    $sheet->setCellValue("D$rowNum", $lead['email']);
    $sheet->setCellValue("E$rowNum", $lead['cidade']);
    $sheet->setCellValue("F$rowNum", $lead['estado']);
    $sheet->setCellValue("G$rowNum", $lead['cep']);
    $sheet->setCellValue("H$rowNum", $lead['codigolead']);
    $sheet->setCellValue("I$rowNum", $lead['descricao']);
    $sheet->setCellValue("J$rowNum", $lead['cpf_cnpj']);
    $sheet->setCellValue("K$rowNum", $lead['status']);
    $rowNum++;
}

// Download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="leads_consultor.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
