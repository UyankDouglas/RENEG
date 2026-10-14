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

date_default_timezone_set('America/Recife');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] != 'Consultor') {
    header('Location: login.php');
    exit;
}
include 'menu.php';

$consultor_id = (int)$_SESSION['usuario_id'];

// Status disponíveis
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

// ---- failsafe: garante colunas necessárias ----
(function($db){
    $need = ['sfa'];
    $have = [];
    $res = $db->query("PRAGMA table_info(leads)");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $have[strtolower($row['name'])] = true;
    }
    foreach ($need as $c) {
        if (empty($have[$c])) $db->exec("ALTER TABLE leads ADD COLUMN $c TEXT");
    }
})($db);

// FILTROS
$busca = trim($_GET['busca'] ?? '');
$status_filtro = trim($_GET['status_filtro'] ?? '');
$page = max(1, intval($_GET['p'] ?? 1));
$por_pagina = 50;

// Datas do mês vigente (em Recife)
$inicio_mes = date('Y-m-01 00:00:00');
$fim_mes    = date('Y-m-t 23:59:59');

// ---- Salvar em lote: status, observação, SFA e telefone ----
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lead_id']) && is_array($_POST['lead_id'])) {
    $ids   = $_POST['lead_id'];
    $sts   = $_POST['status'] ?? [];
    $obs   = $_POST['observacao'] ?? [];
    $sfas  = $_POST['sfa'] ?? [];
    $tels  = $_POST['telefone_ligacao'] ?? [];

    $agora = date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        UPDATE leads
           SET status = :st,
               observacao = :obs,
               sfa = :sfa,
               telefone_ligacao = :tel,
               data_retorno = :dt
         WHERE id = :id AND consultor_id = :cid
    ");

    for ($i=0; $i<count($ids); $i++) {
        $lead_id = (int)$ids[$i];
        $status  = trim($sts[$i] ?? '');
        $observ  = trim($obs[$i] ?? '');
        $sfa     = trim($sfas[$i] ?? '');
        $tel     = trim($tels[$i] ?? '');

        // SFA normalizado (aceita vazio, Sim, Não)
        if ($sfa === '') { $sfa = null; }
        elseif (!in_array($sfa, ['Sim','Não'], true)) { $sfa = null; }

        $stmt->bindValue(':st',  $status, SQLITE3_TEXT);
        $stmt->bindValue(':obs', $observ, SQLITE3_TEXT);
        $stmt->bindValue(':sfa', $sfa, SQLITE3_TEXT);
        $stmt->bindValue(':tel', $tel, SQLITE3_TEXT);
        $stmt->bindValue(':dt',  $agora, SQLITE3_TEXT);
        $stmt->bindValue(':id',  $lead_id, SQLITE3_INTEGER);
        $stmt->bindValue(':cid', $consultor_id, SQLITE3_INTEGER);
        $stmt->execute();
        // (opcional: registrar log)
    }
    $msg = "Registros atualizados com sucesso!";
}

// ---- Monta o WHERE para filtro (removidos cidade/cep) ----
$where = "consultor_id = $consultor_id AND status != 'Novo' AND status != '' AND data_retorno >= '$inicio_mes' AND data_retorno <= '$fim_mes'";

if ($busca !== '') {
    $b = SQLite3::escapeString($busca);
    $where .= " AND (
        empresa LIKE '%$b%' OR 
        cpf_cnpj LIKE '%$b%' OR 
        email LIKE '%$b%' OR
        estado LIKE '%$b%' OR
        telefone_ligacao LIKE '%$b%'
    )";
}
if ($status_filtro !== '' && in_array($status_filtro, $status_options, true)) {
    $b = SQLite3::escapeString($status_filtro);
    $where .= " AND status = '$b'";
}

// Conta total para paginação
$total = (int)$db->querySingle("SELECT COUNT(*) FROM leads WHERE $where");
$paginas = max(1, (int)ceil($total / $por_pagina));
$offset = ($page - 1) * $por_pagina;

// Busca leads já trabalhados (do mês, filtro, paginação)
$leads = [];
$res = $db->query("SELECT * FROM leads WHERE $where ORDER BY data_retorno DESC LIMIT $por_pagina OFFSET $offset");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $leads[] = $row;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Leads Trabalhados | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .status-select { width: 120px; }
        .sfa-select    { width: 76px; }
        .tel-input     { width: 140px; }
        .obs-input     { width: 180px; }

        .paginacao { margin:22px 0 0 0; text-align:center; }
        .paginacao a, .paginacao span {
            display:inline-block; background:#ecebfa;
            color:#444; margin:0 2px; border-radius:6px;
            padding:4px 12px; text-decoration:none; min-width:28px;
        }
        .paginacao .ativo { background:#6f5dff; color:#fff; font-weight:bold; }
        .filtro-busca { display:flex; gap:16px; align-items:center; margin-bottom:15px; }
        .filtro-busca input, .filtro-busca select { padding:7px 12px; border-radius:8px; border:1px solid #bbb; }

        .status-venda { background:#24ad49; color:#fff; font-weight:bold; border-radius:7px; padding:2px 8px; }
        .status-inad  { background:#e55039; color:#fff; font-weight:bold; border-radius:7px; padding:2px 8px; }

        @media (max-width:600px) {
            .tabela-responsive { padding: 0; }
            .status-select { width: 96px; }
            .sfa-select    { width: 66px; }
            .tel-input     { width: 120px; }
            .obs-input     { width: 120px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Leads Trabalhados</h2>
    <?php if ($msg): ?>
        <div class="alert" style="background:#3ac47d; color:#fff;"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="GET" class="filtro-busca">
        <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar: empresa, CPF/CNPJ, email, estado, telefone">
        <select name="status_filtro">
            <option value="">Todos os Status</option>
            <?php foreach ($status_options as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($status_filtro==$opt?'selected':'') ?>><?= htmlspecialchars($opt) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filtrar</button>
    </form>

    <form method="POST">
        <div class="tabela-responsive">
            <table class="table-destaque">
                <tr>
                    <th>Empresa/Cliente</th>
                    <th>CPF/CNPJ</th>
                    <th>Email</th>
                    <th>Estado</th>
                    <th>Telefone da Ligação</th> <!-- NOVO -->
                    <th>SFA</th>                   <!-- NOVO -->
                    <th>Status</th>
                    <th>Data Tabulação</th>
                    <th>Observação</th>
                </tr>
                <?php foreach ($leads as $idx => $lead): ?>
                <tr>
                    <td><?= htmlspecialchars($lead['empresa']) ?></td>
                    <td><?= htmlspecialchars($lead['cpf_cnpj']) ?></td>
                    <td><?= htmlspecialchars($lead['email']) ?></td>
                    <td><?= htmlspecialchars($lead['estado']) ?></td>

                    <td>
                        <input type="hidden" name="lead_id[]" value="<?= (int)$lead['id'] ?>">
                        <input
                            type="text"
                            name="telefone_ligacao[]"
                            class="tel-input"
                            value="<?= htmlspecialchars($lead['telefone_ligacao'] ?? '') ?>"
                            maxlength="20"
                            pattern="[\d\s()+\-]{8,20}"
                            title="8 a 20 dígitos; permitido: números, espaços e + ( ) - "
                            placeholder="(xx) xxxxx-xxxx"
                        >
                    </td>

                    <td>
                        <select name="sfa[]" class="sfa-select">
                            <option value="" <?= (!in_array(($lead['sfa'] ?? ''), ['Sim','Não'], true) ? 'selected' : '') ?>>—</option>
                            <option value="Sim" <?= (($lead['sfa'] ?? '')==='Sim' ? 'selected' : '') ?>>Sim</option>
                            <option value="Não" <?= (($lead['sfa'] ?? '')==='Não' ? 'selected' : '') ?>>Não</option>
                        </select>
                    </td>

                    <td>
                        <select name="status[]" class="status-select" required
                            style="<?php
                                if ($lead['status'] === 'VENDA')       echo 'background:#24ad49;color:#fff;font-weight:bold;';
                                elseif ($lead['status'] === 'INADIMPLENTE') echo 'background:#e55039;color:#fff;font-weight:bold;';
                            ?>">
                            <option value="">Selecione</option>
                            <?php foreach ($status_options as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= ($lead['status']===$opt?'selected':'') ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>

                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($lead['data_retorno']))) ?></td>

                    <td>
                        <input type="text" name="observacao[]" value="<?= htmlspecialchars($lead['observacao'] ?? '') ?>" class="obs-input">
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($leads)): ?>
                    <tr><td colspan="9" style="text-align:center; color:#bbb;">Nenhum lead trabalhado ainda este mês.</td></tr>
                <?php endif; ?>
            </table>
        </div>

        <button type="submit" style="margin-top:20px;">Salvar Alterações</button>
    </form>

    <!-- Paginação -->
    <div class="paginacao">
        <?php for($p=1; $p<=$paginas; $p++): ?>
            <?php
                $params = http_build_query([
                    'busca' => $busca,
                    'status_filtro' => $status_filtro,
                    'p' => $p
                ]);
            ?>
            <?php if($p == $page): ?>
                <span class="ativo"><?= $p ?></span>
            <?php else: ?>
                <a href="?<?= $params ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
</div>
</body>
</html>
