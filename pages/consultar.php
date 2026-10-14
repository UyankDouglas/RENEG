<?php
session_start();
$timeout = 30 * 60; // 30 min

// timeout
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        session_unset(); session_destroy();
        header('Location: login.php?msg=Sessão expirada por inatividade.'); exit;
    }
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/opcoes.php';

// Somente Consultor
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_nivel'] != 'Consultor') {
    header('Location: login.php'); exit;
}
include 'menu.php';

$consultor_id = (int)$_SESSION['usuario_id'];
$msg = "";
$ultimo_atualizado = null;

// garante colunas necessárias (SFA e Observação)
(function($db){
    $cols = ['sfa','observacao'];
    $info = [];
    $res = $db->query("PRAGMA table_info(leads)");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $info[strtolower($row['name'])] = true;
    foreach ($cols as $c) if (empty($info[$c])) $db->exec("ALTER TABLE leads ADD COLUMN $c TEXT");
})($db);

// Lista canônica de status (para validar no back-end; editável em Configurações)
$status_options = listar_status($db);

// salvar (TODOS os campos do formulário obrigatórios)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lead_id'])) {
    $lead_id = (int)($_POST['lead_id'] ?? 0);
    $ultimo_atualizado = $lead_id;

    $erros = [];

    // Telefone (obrigatório, 8-20 dígitos)
    $telefone_ligacao = trim($_POST['telefone_ligacao'] ?? '');
    $so_digitos = preg_replace('/\D+/', '', $telefone_ligacao);
    if ($telefone_ligacao === '' || strlen($so_digitos) < 8 || strlen($so_digitos) > 20) {
        $erros[] = "Telefone da ligação é obrigatório (8 a 20 dígitos).";
    }

    // Observação (obrigatória)
    $observacao = trim($_POST['observacao'] ?? '');
    if ($observacao === '') {
        $erros[] = "Observação é obrigatória.";
    } elseif (mb_strlen($observacao) > 255) {
        $erros[] = "Observação deve ter no máximo 255 caracteres.";
    }

    // SFA (obrigatório: Sim/Não)
    $sfa = trim($_POST['sfa'] ?? '');
    if (!in_array($sfa, ['Sim','Não'], true)) {
        $erros[] = "Selecione SFA (Sim ou Não).";
    }

    // Status (obrigatório e válido)
    $status = trim($_POST['status'] ?? '');
    if ($status === '' || !in_array($status, $status_options, true)) {
        $erros[] = "Selecione um Status válido.";
    }

    if (empty($erros)) {
        $stmt = $db->prepare("
            UPDATE leads 
               SET status = :st, 
                   telefone_ligacao = :tel, 
                   data_retorno = :dt,
                   sfa = :sfa,
                   observacao = :obs
             WHERE id = :id AND consultor_id = :cid
        ");
        $stmt->bindValue(':st',  $status, SQLITE3_TEXT);
        $stmt->bindValue(':tel', $telefone_ligacao, SQLITE3_TEXT);
        $stmt->bindValue(':dt',  date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':sfa', $sfa, SQLITE3_TEXT);
        $stmt->bindValue(':obs', $observacao, SQLITE3_TEXT);
        $stmt->bindValue(':id',  $lead_id, SQLITE3_INTEGER);
        $stmt->bindValue(':cid', $consultor_id, SQLITE3_INTEGER);
        $msg = $stmt->execute() ? "Dados atualizados com sucesso!" : "Falha ao atualizar!";
    } else {
        // Mostra mensagens unificadas
        $msg = "Preencha os campos obrigatórios: " . implode(' ', $erros);
    }
}

// dados (apenas não tabulados)
$leads = [];
$res = $db->query("
    SELECT *
      FROM leads
     WHERE consultor_id = $consultor_id
       AND (status IS NULL OR status = '' OR status = 'Novo')
  ORDER BY id ASC
");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $leads[] = $row;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Meus Leads | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .table-destaque { width:100%; border-collapse: collapse; table-layout: auto; }
        .table-destaque th, .table-destaque td { padding: 8px 10px; vertical-align: middle; }
        .table-destaque td, .table-destaque th { word-break: normal !important; overflow-wrap: normal !important; hyphens: manual; }

        .col-empresa { min-width: 260px; }
        .col-cpf     { min-width: 140px; text-align: center; white-space: nowrap; }
        .col-email   { min-width: 220px; }
        .col-estado  { width: 52px; text-align: center; white-space: nowrap; padding-left:4px; padding-right:4px; }
        .col-tel     { min-width: 150px; }
        .col-obs     { min-width: 180px; }
        .col-sfa     { width: 1%; white-space: nowrap; text-align: center; padding-left:4px; padding-right:4px; }
        .col-status  { min-width: 180px; max-width: 220px; }
        .col-acao    { width: 100px; text-align: center; }

        .form-inline { display:flex; gap:4px; align-items:center; flex-wrap: wrap; }
        .status-select { min-width: 170px; max-width: 220px; }
        .sfa-select {
            width: auto; min-width: 0; max-width: 68px; padding: 2px 4px;
        }
        .input-telefone { min-width:150px; padding:2px 5px; }
        .input-observacao { min-width:180px; max-width:300px; padding:2px 5px; }
        .atualizar-btn { padding:2px 10px; border-radius:5px; background:#6f5dff; color:#fff; border:none; cursor:pointer; }
        .atualizar-btn:hover { background:#5242c0; }

        .alert { background:#f6ffed; border:1px solid #b7eb8f; color:#135200; padding:10px; border-radius:6px; margin:10px 0; }
        .alert-err { background:#fff1f0; border:1px solid #ffa39e; color:#a8071a; padding:10px; border-radius:6px; margin:10px 0; }
    </style>
</head>
<body>
<div class="container">
    <h2>Meus Leads</h2>
    <?php if ($msg): ?>
        <div class="<?= (stripos($msg,'sucesso')!==false ? 'alert' : 'alert-err') ?>">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <div class="tabela-responsive">
        <table class="table-destaque">
            <tr>
                <th class="col-empresa">Empresa/Cliente</th>
                <th class="col-cpf">CPF/CNPJ</th>
                <th class="col-email">Email</th>
                <th class="col-estado">Estado</th>
                <th class="col-tel">Telefone da Ligação</th>
                <th class="col-obs">Observação</th>
                <th class="col-sfa">SFA</th>
                <th class="col-status">Status</th>
                <th class="col-acao">Ação</th>
            </tr>
            <?php foreach ($leads as $lead): ?>
            <tr id="lead<?= (int)$lead['id'] ?>"<?= ($lead['id'] == $ultimo_atualizado ? ' style="outline:2px solid #6f5dff;"' : '') ?>>
                <td class="col-empresa"><?= htmlspecialchars($lead['empresa']) ?></td>
                <td class="col-cpf"><?= htmlspecialchars($lead['cpf_cnpj']) ?></td>
                <td class="col-email"><?= htmlspecialchars($lead['email']) ?></td>
                <td class="col-estado"><?= htmlspecialchars($lead['estado']) ?></td>

                <!-- Form começa aqui e vai até o botão -->
                <td class="col-tel">
                    <form method="POST" class="form-inline" action="?#lead<?= (int)$lead['id'] ?>">
                        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                        <input 
                            type="text" 
                            name="telefone_ligacao" 
                            placeholder="Telefone da ligação" 
                            value="<?= htmlspecialchars($lead['telefone_ligacao'] ?? '') ?>" 
                            class="input-telefone"
                            maxlength="20"
                            inputmode="tel"
                            required
                            pattern="[\d\s()+\-]{8,20}"
                            title="Informe o telefone (8 a 20 dígitos; permitido: números, espaços e + ( ) - )"
                        />
                </td>
                <td class="col-obs">
                        <input 
                            type="text" 
                            name="observacao" 
                            placeholder="Observação"
                            value="<?= htmlspecialchars($lead['observacao'] ?? '') ?>"
                            class="input-observacao"
                            maxlength="255"
                            required
                        />
                </td>
                <td class="col-sfa">
                        <select name="sfa" class="sfa-select" title="SFA" required>
                            <!-- placeholder vazio e desabilitado para forçar escolha -->
                            <option value="" disabled <?= (!in_array(($lead['sfa'] ?? ''), ['Sim','Não'], true) ? 'selected' : '') ?>>—</option>
                            <option value="Sim" <?= (isset($lead['sfa']) && $lead['sfa']==='Sim') ? 'selected' : '' ?>>Sim</option>
                            <option value="Não" <?= (isset($lead['sfa']) && $lead['sfa']==='Não') ? 'selected' : '' ?>>Não</option>
                        </select>
                </td>
                <td class="col-status">
                        <select name="status" class="status-select" required>
                            <option value="" disabled <?= (empty($lead['status']) || $lead['status']==='Novo') ? 'selected' : '' ?>>Selecione</option>
                            <?php foreach ($status_options as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= ($lead['status']===$opt ? 'selected' : '') ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                </td>
                <td class="col-acao">
                        <button type="submit" class="atualizar-btn">Atualizar</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($leads)): ?>
            <tr>
                <td colspan="9" style="text-align:center;color:#bbb;">Nenhum lead atribuído.</td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>
