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
use PhpOffice\PhpSpreadsheet\IOFactory;

// Helper robusto: busca por várias opções de nome de coluna
function procurar_coluna($header, $possiveis) {
    if (!is_array($possiveis)) $possiveis = [$possiveis];
    foreach ($header as $i => $col) {
        $colNorm = preg_replace('/[^a-z0-9]/', '', mb_strtolower(@iconv('UTF-8','ASCII//TRANSLIT',trim($col))));
        foreach ($possiveis as $nome) {
            $nomeNorm = preg_replace('/[^a-z0-9]/', '', mb_strtolower(@iconv('UTF-8','ASCII//TRANSLIT',trim($nome))));
            if ($colNorm === $nomeNorm) return $i;
        }
    }
    // Fallback: se não encontrar por nome, retorna 0 (primeira coluna)
    return 0;
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['planilha'])) {
    $file = $_FILES['planilha'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $dest = __DIR__ . '/../uploads/base/' . date('Ymd_His_') . basename($file['name']);

    if ($file['error'] === UPLOAD_ERR_OK && in_array($ext, ['xlsx', 'csv'])) {
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            if ($ext === 'csv') {
                $rows = [];
                if (($handle = fopen($dest, 'r')) !== false) {
                    $header = fgetcsv($handle, 0, ';');
                    // Mapeamento robusto de colunas
                    $empresa_idx    = procurar_coluna($header, ['Empresa/Cliente','empresa','Empresa','EMPRESA/CLIENTE','EMPRESA']);
                    $tel_com_idx    = procurar_coluna($header, ['Telefone Comercial','tel_comercial']);
                    $tel_cel_idx    = procurar_coluna($header, ['Telefone Celular','tel_celular']);
                    $email_idx      = procurar_coluna($header, ['Email','email']);
                    $cidade_idx     = procurar_coluna($header, ['Cidade','cidade']);
                    $estado_idx     = procurar_coluna($header, ['Estado','estado']);
                    $cep_idx        = procurar_coluna($header, ['CEP','cep']);
                    $codigolead_idx = procurar_coluna($header, ['CodigoLead','codigolead','codigo_lead']);
                    $descricao_idx  = procurar_coluna($header, ['Descrição','descricao']);
                    $cpf_cnpj_idx   = procurar_coluna($header, ['CPF/CNPJ','cpf_cnpj']);

                    while (($linha = fgetcsv($handle, 0, ';')) !== false) {
                        $empresa    = isset($linha[$empresa_idx])    ? $linha[$empresa_idx] : '';
                        $tel_com    = isset($linha[$tel_com_idx])    ? $linha[$tel_com_idx] : '';
                        $tel_cel    = isset($linha[$tel_cel_idx])    ? $linha[$tel_cel_idx] : '';
                        $email      = isset($linha[$email_idx])      ? $linha[$email_idx] : '';
                        $cidade     = isset($linha[$cidade_idx])     ? $linha[$cidade_idx] : '';
                        $estado     = isset($linha[$estado_idx])     ? $linha[$estado_idx] : '';
                        $cep        = isset($linha[$cep_idx])        ? $linha[$cep_idx] : '';
                        $codigolead = isset($linha[$codigolead_idx]) ? $linha[$codigolead_idx] : '';
                        $descricao  = isset($linha[$descricao_idx])  ? $linha[$descricao_idx] : '';
                        $cpf_cnpj   = isset($linha[$cpf_cnpj_idx])   ? $linha[$cpf_cnpj_idx] : '';
                        if ($empresa === '' && $codigolead === '') continue;
                        $rows[] = [
                            $empresa, $tel_com, $tel_cel, $email, $cidade, $estado, $cep, $codigolead, $descricao, $cpf_cnpj
                        ];
                    }
                    fclose($handle);
                }

                // Registra a nova lista
                $nome_arquivo = basename($file['name']);
                $data_importacao = date('Y-m-d H:i:s');
                $total_leads = count($rows);
                $stmtLista = $db->prepare('INSERT INTO listas (nome_arquivo, data_importacao, total_leads) VALUES (?, ?, ?)');
                $stmtLista->bindValue(1, $nome_arquivo);
                $stmtLista->bindValue(2, $data_importacao);
                $stmtLista->bindValue(3, $total_leads);
                $stmtLista->execute();
                $lista_id = $db->lastInsertRowID();

                $inserted = 0;
                foreach ($rows as $linha) {
                    $stmt = $db->prepare('INSERT INTO leads (empresa, tel_comercial, tel_celular, email, cidade, estado, cep, codigolead, descricao, cpf_cnpj, status, lista_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Novo", ?)');
                    for ($i=0; $i<10; $i++) $stmt->bindValue($i+1, $linha[$i]);
                    $stmt->bindValue(11, $lista_id);
                    if ($stmt->execute()) $inserted++;
                }
                $msg = "Base CSV carregada e $inserted leads importados!";
            } else {
                // === IMPORTAÇÃO DE XLSX ===
                try {
                    $spreadsheet = IOFactory::load($dest);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows = $sheet->toArray();
                    $header = $rows[0];
                    $empresa_idx    = procurar_coluna($header, ['Empresa/Cliente','empresa','Empresa','EMPRESA/CLIENTE','EMPRESA']);
                    $tel_com_idx    = procurar_coluna($header, ['Telefone Comercial','tel_comercial']);
                    $tel_cel_idx    = procurar_coluna($header, ['Telefone Celular','tel_celular']);
                    $email_idx      = procurar_coluna($header, ['Email','email']);
                    $cidade_idx     = procurar_coluna($header, ['Cidade','cidade']);
                    $estado_idx     = procurar_coluna($header, ['Estado','estado']);
                    $cep_idx        = procurar_coluna($header, ['CEP','cep']);
                    $codigolead_idx = procurar_coluna($header, ['CodigoLead','codigolead','codigo_lead']);
                    $descricao_idx  = procurar_coluna($header, ['Descrição','descricao']);
                    $cpf_cnpj_idx   = procurar_coluna($header, ['CPF/CNPJ','cpf_cnpj']);

                    $dados = array_slice($rows, 1);

                    // Registra a nova lista
                    $nome_arquivo = basename($file['name']);
                    $data_importacao = date('Y-m-d H:i:s');
                    $total_leads = count($dados);
                    $stmtLista = $db->prepare('INSERT INTO listas (nome_arquivo, data_importacao, total_leads) VALUES (?, ?, ?)');
                    $stmtLista->bindValue(1, $nome_arquivo);
                    $stmtLista->bindValue(2, $data_importacao);
                    $stmtLista->bindValue(3, $total_leads);
                    $stmtLista->execute();
                    $lista_id = $db->lastInsertRowID();

                    $inserted = 0;
                    foreach ($dados as $linha) {
                        $empresa    = isset($linha[$empresa_idx])    ? $linha[$empresa_idx] : '';
                        $tel_com    = isset($linha[$tel_com_idx])    ? $linha[$tel_com_idx] : '';
                        $tel_cel    = isset($linha[$tel_cel_idx])    ? $linha[$tel_cel_idx] : '';
                        $email      = isset($linha[$email_idx])      ? $linha[$email_idx] : '';
                        $cidade     = isset($linha[$cidade_idx])     ? $linha[$cidade_idx] : '';
                        $estado     = isset($linha[$estado_idx])     ? $linha[$estado_idx] : '';
                        $cep        = isset($linha[$cep_idx])        ? $linha[$cep_idx] : '';
                        $codigolead = isset($linha[$codigolead_idx]) ? $linha[$codigolead_idx] : '';
                        $descricao  = isset($linha[$descricao_idx])  ? $linha[$descricao_idx] : '';
                        $cpf_cnpj   = isset($linha[$cpf_cnpj_idx])   ? $linha[$cpf_cnpj_idx] : '';
                        if ($empresa === '' && $codigolead === '') continue;
                        $stmt = $db->prepare('INSERT INTO leads (empresa, tel_comercial, tel_celular, email, cidade, estado, cep, codigolead, descricao, cpf_cnpj, status, lista_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Novo", ?)');
                        $stmt->bindValue(1, $empresa);
                        $stmt->bindValue(2, $tel_com);
                        $stmt->bindValue(3, $tel_cel);
                        $stmt->bindValue(4, $email);
                        $stmt->bindValue(5, $cidade);
                        $stmt->bindValue(6, $estado);
                        $stmt->bindValue(7, $cep);
                        $stmt->bindValue(8, $codigolead);
                        $stmt->bindValue(9, $descricao);
                        $stmt->bindValue(10, $cpf_cnpj);
                        $stmt->bindValue(11, $lista_id);
                        if ($stmt->execute()) $inserted++;
                    }
                    $msg = "Base XLSX carregada e $inserted leads importados!";
                } catch (Exception $e) {
                    $msg = "Erro ao processar planilha: " . $e->getMessage();
                }
            }
        } else {
            $msg = "Erro ao salvar o arquivo.";
        }
    } else {
        $msg = "Arquivo inválido. Envie apenas arquivos .xlsx ou .csv";
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
        Aceita arquivos XLSX (Excel) ou CSV (separador ponto e vírgula). Os nomes das colunas podem estar em qualquer formato.
    </p>
</div>
</body>
</html>
