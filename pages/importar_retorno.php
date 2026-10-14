<?php
session_start();
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        // Inativo por mais de 30 min
        session_unset();
        session_destroy();
        header('Location: login.php?msg=Sessão expirada por inatividade.');
        exit;
    }
}
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] != 'Consultor') {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$consultor_id = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['planilha_retorno'])) {
    $file = $_FILES['planilha_retorno'];
    if ($file['error'] === UPLOAD_ERR_OK && pathinfo($file['name'], PATHINFO_EXTENSION) === 'xlsx') {
        $path = $file['tmp_name'];
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        // Pega os índices das colunas pelo cabeçalho
        $header = array_map('strtolower', $rows[0]);
        $colStatus = array_search('status', $header);
        $colCodigoLead = array_search('codigolead', $header);

        // Processa cada linha
        for ($i = 1; $i < count($rows); $i++) {
            $status = trim($rows[$i][$colStatus]);
            $codigolead = trim($rows[$i][$colCodigoLead]);
            if ($codigolead !== '') {
                // Busca o id do lead pelo código
                $lead_id = $db->querySingle("SELECT id FROM leads WHERE codigolead = '$codigolead' AND consultor_id = $consultor_id");
                if ($lead_id) {
                    // Atualiza status do lead
                    $db->exec("UPDATE leads SET status = '$status', data_retorno = '".date('Y-m-d H:i:s')."' WHERE id = $lead_id");
                    // Registra na tabela tabulacoes
                    $db->exec("INSERT INTO tabulacoes (lead_id, consultor_id, status, data_envio) VALUES ($lead_id, $consultor_id, '$status', '".date('Y-m-d H:i:s')."')");
                }
            }
        }
        header('Location: consultar.php?msg=sucesso');
        exit;
    } else {
        header('Location: consultar.php?msg=erro');
        exit;
    }
}
header('Location: consultar.php?msg=erro');
exit;
