<?php
session_start();
$timeout = 30 * 60; // 30 minutos em segundos

if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        // Inativo por mais de 30 min
        session_unset();
        session_destroy();
        header('Location: login.php?msg=Sessão expirada por inatividade.');
        exit;
    }
}
$_SESSION['last_activity'] = time();
require_once __DIR__ . '/../config/database.php';
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_nivel'], ['ADM', 'Supervisor'])) {
    header('Location: login.php');
    exit;
}

include 'menu.php';

require_once __DIR__ . '/../vendor/autoload.php'; // Composer PhpSpreadsheet
require_once __DIR__ . '/../config/importacao.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['planilha'])) {
    $file = $_FILES['planilha'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $dest = __DIR__ . '/../uploads/base/' . date('Ymd_His_') . basename($file['name']);

    if ($file['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['xlsx', 'csv'])) {
        $msg = "Arquivo inválido. Envie apenas arquivos .xlsx ou .csv";
    } elseif (!move_uploaded_file($file['tmp_name'], $dest)) {
        $msg = "Erro ao salvar o arquivo.";
    } else {
        try {
            [$header, $dados] = ler_planilha($dest, $ext);
            $idx = mapear_colunas($header);

            if ($idx['cpf_cnpj'] === null && $idx['empresa'] === null && $idx['codigolead'] === null) {
                $colunas = implode(', ', array_filter(array_map('strval', $header), 'strlen'));
                $msg = "Planilha sem coluna CNPJ (colunas encontradas: $colunas). Nada foi importado.";
            } else {
                $leads = [];
                foreach ($dados as $linha) {
                    $lead = extrair_lead($linha, $idx);
                    if (!lead_vazio($lead)) $leads[] = $lead;
                }

                garantir_coluna_qtd_linhas($db);

                // Uma transação só: importa ~30x mais rápido e não deixa lista pela metade se falhar
                $db->exec('BEGIN TRANSACTION;');

                // Registra a nova lista
                $stmtLista = $db->prepare('INSERT INTO listas (nome_arquivo, data_importacao, total_leads) VALUES (?, ?, ?)');
                $stmtLista->bindValue(1, basename($file['name']));
                $stmtLista->bindValue(2, date('Y-m-d H:i:s'));
                $stmtLista->bindValue(3, count($leads));
                $stmtLista->execute();
                $lista_id = $db->lastInsertRowID();

                $campos = array_keys(COLUNAS_LEAD);
                $stmt = $db->prepare('INSERT INTO leads (' . implode(', ', $campos) . ', status, lista_id)
                                      VALUES (:' . implode(', :', $campos) . ', "Novo", :lista_id)');
                $inserted = 0;
                foreach ($leads as $lead) {
                    foreach ($lead as $campo => $valor) $stmt->bindValue(":$campo", $valor, SQLITE3_TEXT);
                    $stmt->bindValue(':lista_id', $lista_id, SQLITE3_INTEGER);
                    if ($stmt->execute()) $inserted++;
                    $stmt->reset();
                }
                $db->exec('COMMIT;');
                $msg = "Base " . strtoupper($ext) . " carregada e $inserted leads importados!";
            }
        } catch (Exception $e) {
            $msg = "Erro ao processar planilha: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Upload da Base | Sistema de Leads</title>
      <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="container">
    <h2>Upload da Base de Leads</h2>
    <?php if ($msg): ?>
        <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="planilha" accept=".xlsx,.csv" required>
        <button type="submit">Enviar Base</button>
    </form>
    <p style="font-size:0.98em; color:#555;">
        Aceita arquivos XLSX (Excel) ou CSV (separador ponto e vírgula), com as colunas <b>CNPJ</b>, <b>TELEFONE</b> e <b>LINHAS</b> (quantidade de linhas).
        Maiúsculas, acentos e a ordem das colunas não importam.
    </p>
</div>
</body>
</html>
