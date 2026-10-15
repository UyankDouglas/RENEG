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
require_once __DIR__ . '/../config/opcoes.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] != 'Consultor') {
    header('Location: login.php');
    exit;
}
include 'menu.php';

$consultor_id = (int)$_SESSION['usuario_id'];

// Status disponíveis (editáveis em Configurações)
$status_options = listar_status($db);

// ---- failsafe: garante colunas necessárias ----
(function($db){
    $need = ['qtd_linhas'];
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

// ---- Salvar em lote: status, observação e telefone ----
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lead_id']) && is_array($_POST['lead_id'])) {
    $ids   = $_POST['lead_id'];
    $sts   = $_POST['status'] ?? [];
    $obs   = $_POST['observacao'] ?? [];
    $tels  = $_POST['telefone_ligacao'] ?? [];

    $agora = date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        UPDATE leads
           SET status = :st,
               observacao = :obs,
               telefone_ligacao = :tel,
               data_retorno = :dt
         WHERE id = :id AND consultor_id = :cid
    ");

    for ($i=0; $i<count($ids); $i++) {
        $lead_id = (int)$ids[$i];
        $status  = trim($sts[$i] ?? '');
        $observ  = trim($obs[$i] ?? '');
        $tel     = trim($tels[$i] ?? '');

        $stmt->bindValue(':st',  $status, SQLITE3_TEXT);
        $stmt->bindValue(':obs', $observ, SQLITE3_TEXT);
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
        cpf_cnpj LIKE '%$b%' OR
        tel_comercial LIKE '%$b%' OR
        tel_celular LIKE '%$b%' OR
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
        .tel-input     { width: 140px; }
        .obs-input     { width: 180px; }

        /* Larguras próprias (anula as larguras por posição do style.css, feitas para o layout antigo) */
        .table-destaque.tabela-leads th,
        .table-destaque.tabela-leads td { width: auto; max-width: none; }
        .table-destaque.tabela-leads .col-cnpj,
        .table-destaque.tabela-leads .col-fone,
        .table-destaque.tabela-leads .col-linhas,
        .table-destaque.tabela-leads .col-data { white-space: nowrap; }

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
        <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar: CNPJ ou telefone">
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
            <table class="table-destaque tabela-leads">
                <tr>
                    <th class="col-cnpj">CNPJ</th>
                    <th class="col-fone">Telefone</th>
                    <th class="col-linhas">Linhas</th>
                    <th>Telefone da Ligação</th>
                    <th>Status</th>
                    <th class="col-data">Data Tabulação</th>
                    <th>Observação</th>
                </tr>
                <?php foreach ($leads as $idx => $lead): ?>
                <tr>
                    <td class="col-cnpj"><?= htmlspecialchars($lead['cpf_cnpj'] ?? '') ?></td>
                    <td class="col-fone"><?= htmlspecialchars(($lead['tel_comercial'] ?? '') !== '' ? $lead['tel_comercial'] : ($lead['tel_celular'] ?? '')) ?></td>
                    <td class="col-linhas"><?= htmlspecialchars($lead['qtd_linhas'] ?? '') ?></td>

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

                    <td class="col-data"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($lead['data_retorno']))) ?></td>

                    <td>
                        <input type="text" name="observacao[]" value="<?= htmlspecialchars($lead['observacao'] ?? '') ?>" class="obs-input">
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($leads)): ?>
                    <tr><td colspan="7" style="text-align:center; color:#bbb;">Nenhum lead trabalhado ainda este mês.</td></tr>
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
