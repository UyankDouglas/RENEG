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

$status_options = [
    "ACHOU CARO",
    "CLIENTE JÁ ESTÁ SENDO ATENDIDO POR OUTRO CONSULTOR/CANAL",
    "CLIENTE NAO QUIS OUVIR A OFERTA",
    "CLIENTE PEDIU RETORNO",
    "CLIENTE PREFERE A CONCORRENCIA",
    "VENDA",
    "CLIENTES COM ORDEM EM ANDAMENTO NO SMART",
    "CLIENTE FIDELIZADO RECENTEMENTE",
    "CPF/CNPJ SUSPENSO/INATIVO",
    "DECISOR NÃO LOCALIZADO",
    "INADIMPLENTE",
    "INSATISFEITO/ NÃO QUER CONTATO COM A TELEFONICA|VIVO",
    "INVIABILIDADE TÉCNICA",
    "NÃO ACEITA FIDELIDADE 24 MESES",
    "NÃO ATENDE",
    "NÃO É MAIS CLIENTE VIVO/DESCONECTADO",
    "NÃO QUIS CONFIRMAR DOCUMENTOS/NÃO FORNECE INFORMAÇÕES POR TELEFONE",
    "CLIENTES SEM RECOMENDAÇÃO"
];

$msg = "";

// Checa se há algum lead não tabulado (status 'Novo' ou vazio)
$nao_tab = $db->querySingle("SELECT COUNT(*) FROM leads WHERE status IS NULL OR status = '' OR status = 'Novo'");

$bloqueado = $nao_tab > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado && isset($_POST['status_redistribuir'])) {
    $statuses = $_POST['status_redistribuir'];
    if (count($statuses) > 0) {
        $in = implode(",", array_map(function($s) use ($db){ return "'".$db->escapeString($s)."'"; }, $statuses));
        // Atualiza leads que têm o status escolhido: status='Novo', consultor_id=NULL
        $sql = "UPDATE leads SET status = 'Novo', consultor_id = NULL WHERE status IN ($in)";
        $result = $db->exec($sql);
        $msg = "Leads com os status selecionados foram redistribuídos!";
    } else {
        $msg = "Selecione ao menos um status!";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Redistribuir por Status | Sistema de Leads</title>
      <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .submit-btn { padding: 7px 20px; border-radius: 7px; background: #6f5dff; color: #fff; border: none; font-size: 16px; margin-top: 15px; cursor:pointer;}
        .submit-btn:hover { background: #5142ad; }
        .info-msg {color:#555;margin:20px 0;font-size:16px;}
    </style>
</head>
<body>
<div class="container">
    <h2>Redistribuir Leads por Status</h2>
    <?php if ($msg): ?>
        <div class="alert"><?=htmlspecialchars($msg)?></div>
    <?php endif; ?>
    <?php if ($bloqueado): ?>
        <div class="info-msg">
            <b>Você só poderá redistribuir leads quando <u>todos os leads estiverem tabulados</u>.<br>
            Ainda restam <?=$nao_tab?> lead(s) pendente(s).</b>
        </div>
    <?php else: ?>
        <form method="POST">
            <label for="status_redistribuir"><b>Selecione os status para redistribuição:</b></label><br>
            <select name="status_redistribuir[]" id="status_redistribuir" multiple size="6" required style="width: 350px; font-size: 15px;">
                <?php foreach ($status_options as $status): ?>
                    <option value="<?=htmlspecialchars($status)?>"><?=htmlspecialchars($status)?></option>
                <?php endforeach; ?>
            </select>
            <br>
            <button type="submit" class="submit-btn">Redistribuir Leads Selecionados</button>
        </form>
        <p style="color:#666; font-size: 0.98em; margin-top:20px;">
            Os leads marcados voltarão para a fila de distribuição.<br>
            <b>Dica:</b> Para selecionar mais de um, segure Ctrl (Windows) ou Cmd (Mac).
        </p>
    <?php endif; ?>
</div>
</body>
</html>
