<?php
// config/exportacao.php
// Downloads em Excel do Acompanhamento: dados do lead (modelo CNPJ/TELEFONE/LINHAS) + resultado da tabulação.

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Coluna => largura no Excel
const COLUNAS_EXPORTACAO = [
    'CNPJ'                => 17,
    'Telefone'            => 15,
    'Linhas'              => 10,
    'Status'              => 34,
    'Telefone da Ligação' => 20,
    'Observação'          => 40,
    'Data Tabulação'      => 17,
];

function exportacao_linha($lead) {
    $telefone = ($lead['tel_comercial'] ?? '') !== '' ? $lead['tel_comercial'] : ($lead['tel_celular'] ?? '');
    $tabulado = !in_array($lead['status'] ?? '', ['', 'Novo'], true);
    return [
        $lead['cpf_cnpj'] ?? '',
        $telefone,
        $lead['qtd_linhas'] ?? '',
        $lead['status'] ?? '',
        $lead['telefone_ligacao'] ?? '',
        $lead['observacao'] ?? '',
        ($tabulado && !empty($lead['data_retorno'])) ? date('d/m/Y H:i', strtotime($lead['data_retorno'])) : '',
    ];
}

/** Planilha com cabeçalho em negrito, 1ª linha fixa e larguras definidas ($colunas: nome => largura). */
function exportacao_planilha($colunas) {
    // Tudo como texto: CNPJ e telefone não viram número (sem notação científica nem perda de zero à esquerda)
    Cell::setValueBinder(new StringValueBinder());

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Leads');
    $sheet->fromArray(array_keys($colunas), null, 'A1');
    $i = 1;
    foreach ($colunas as $largura) $sheet->getColumnDimensionByColumn($i++)->setWidth($largura);
    $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex(count($colunas)) . '1')->getFont()->setBold(true);
    $sheet->freezePane('A2');
    return $spreadsheet;
}

function exportacao_enviar($spreadsheet, $arquivo) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $arquivo . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}
