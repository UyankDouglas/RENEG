<?php
session_start();

// Timeout
$timeout = 30 * 60; // 30 minutos
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        session_unset();
        session_destroy();
        header('Location: login.php?msg=Sessão expirada por inatividade.');
        exit;
    }
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/opcoes.php';

// Somente ADM pode acessar
$nivelSess = strtoupper(trim($_SESSION['usuario_nivel'] ?? ''));
if (!isset($_SESSION['usuario_id']) || $nivelSess !== 'ADM') {
    header('Location: login.php');
    exit;
}

opcoes_garantir_tabelas($db);

/** Remove espaços extras e valida o tamanho. Retorna [nome, erro]. */
function validar_nome($nome, $rotulo) {
    $nome = preg_replace('/\s+/u', ' ', (string)$nome);
    if ($nome === null) return [null, "O nome da $rotulo tem caracteres inválidos."];
    $nome = trim($nome);
    if ($nome === '') return [null, "Informe o nome da $rotulo."];
    if (mb_strlen($nome) > 120) return [null, "O nome da $rotulo deve ter no máximo 120 caracteres."];
    return [$nome, null];
}

/** COUNT(*) com parâmetros. */
function contar($db, $sql, $params) {
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
    return (int)($row[0] ?? 0);
}

/** Executa um comando com parâmetros e devolve quantas linhas mudaram. */
function executar($db, $sql, $params) {
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    $stmt->execute();
    return $db->changes();
}

/**
 * AÇÕES (POST) — depois de cada uma, volta para a página (evita reenviar ao atualizar)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $acao  = $_POST['acao'];
    $id    = (int)($_POST['id'] ?? 0);
    $erro  = '';
    $ok    = '';
    $secao = (strpos($acao, 'equipe_') === 0) ? 'equipes' : 'tabulacoes';

    switch ($acao) {
        // ===== TABULAÇÕES =====
        case 'status_adicionar':
        case 'status_renomear':
            [$nome, $erro] = validar_nome($_POST['nome'] ?? '', 'tabulação');
            if ($erro) break;
            if (in_array(mb_strtolower($nome), STATUS_RESERVADOS, true)) {
                $erro = "\"$nome\" é um nome reservado do sistema.";
                break;
            }
            if (contar($db, 'SELECT COUNT(*) FROM status_opcoes WHERE nome = :n AND id != :id', [':n' => $nome, ':id' => $id]) > 0) {
                $erro = "Já existe a tabulação \"$nome\".";
                break;
            }

            if ($acao === 'status_adicionar') {
                $ordem = (int)$db->querySingle('SELECT COALESCE(MAX(ordem), 0) + 1 FROM status_opcoes');
                executar($db, 'INSERT INTO status_opcoes (nome, ordem) VALUES (:n, :o)', [':n' => $nome, ':o' => $ordem]);
                $ok = "Tabulação \"$nome\" adicionada.";
                break;
            }

            $antigo = $db->querySingle("SELECT nome FROM status_opcoes WHERE id = $id");
            if ($antigo === null) { $erro = 'Tabulação não encontrada.'; break; }
            if ($antigo === $nome) break;

            // Renomeia também nos leads que já estão com essa tabulação
            $db->exec('BEGIN TRANSACTION;');
            executar($db, 'UPDATE status_opcoes SET nome = :n WHERE id = :id', [':n' => $nome, ':id' => $id]);
            $leads = executar($db, 'UPDATE leads SET status = :n WHERE status = :antigo', [':n' => $nome, ':antigo' => $antigo]);
            $db->exec('COMMIT;');
            $ok = "Tabulação \"$antigo\" renomeada para \"$nome\"" . ($leads ? " ($leads lead(s) atualizados)." : ".");
            break;

        case 'status_remover':
            $nome = $db->querySingle("SELECT nome FROM status_opcoes WHERE id = $id");
            if ($nome === null) { $erro = 'Tabulação não encontrada.'; break; }
            $uso = contar($db, 'SELECT COUNT(*) FROM leads WHERE status = :n', [':n' => $nome]);
            if ($uso > 0) {
                $erro = "Não é possível remover \"$nome\": $uso lead(s) estão com essa tabulação. Renomeie-a ou redistribua esses leads antes.";
                break;
            }
            executar($db, 'DELETE FROM status_opcoes WHERE id = :id', [':id' => $id]);
            $ok = "Tabulação \"$nome\" removida.";
            break;

        case 'status_mover':
            $ids = [];
            $res = $db->query('SELECT id FROM status_opcoes ORDER BY ordem, id');
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) $ids[] = (int)$row['id'];
            $pos  = array_search($id, $ids, true);
            $alvo = ($_POST['direcao'] ?? '') === 'cima' ? $pos - 1 : $pos + 1;
            if ($pos === false || $alvo < 0 || $alvo >= count($ids)) break;

            [$ids[$pos], $ids[$alvo]] = [$ids[$alvo], $ids[$pos]];
            $db->exec('BEGIN TRANSACTION;');
            foreach ($ids as $i => $sid) {
                executar($db, 'UPDATE status_opcoes SET ordem = :o WHERE id = :id', [':o' => $i + 1, ':id' => $sid]);
            }
            $db->exec('COMMIT;');
            break;

        // ===== EQUIPES =====
        case 'equipe_adicionar':
        case 'equipe_renomear':
            [$nome, $erro] = validar_nome($_POST['nome'] ?? '', 'equipe');
            if ($erro) break;
            if (contar($db, 'SELECT COUNT(*) FROM equipes WHERE nome = :n AND id != :id', [':n' => $nome, ':id' => $id]) > 0) {
                $erro = "Já existe a equipe \"$nome\".";
                break;
            }

            if ($acao === 'equipe_adicionar') {
                executar($db, 'INSERT INTO equipes (nome) VALUES (:n)', [':n' => $nome]);
                $ok = "Equipe \"$nome\" adicionada.";
                break;
            }

            $antigo = $db->querySingle("SELECT nome FROM equipes WHERE id = $id");
            if ($antigo === null) { $erro = 'Equipe não encontrada.'; break; }
            if ($antigo === $nome) break;

            // Renomeia também nos usuários (supervisores e consultores) dessa equipe
            $db->exec('BEGIN TRANSACTION;');
            executar($db, 'UPDATE equipes SET nome = :n WHERE id = :id', [':n' => $nome, ':id' => $id]);
            $usuarios = executar($db, 'UPDATE usuarios SET equipe = :n WHERE equipe = :antigo COLLATE NOCASE', [':n' => $nome, ':antigo' => $antigo]);
            $db->exec('COMMIT;');
            $ok = "Equipe \"$antigo\" renomeada para \"$nome\"" . ($usuarios ? " ($usuarios usuário(s) atualizados)." : ".");
            break;

        case 'equipe_remover':
            $nome = $db->querySingle("SELECT nome FROM equipes WHERE id = $id");
            if ($nome === null) { $erro = 'Equipe não encontrada.'; break; }
            $uso = contar($db, 'SELECT COUNT(*) FROM usuarios WHERE equipe = :n COLLATE NOCASE', [':n' => $nome]);
            if ($uso > 0) {
                $erro = "Não é possível remover \"$nome\": $uso usuário(s) estão nessa equipe. Mude a equipe deles em Usuários antes.";
                break;
            }
            executar($db, 'DELETE FROM equipes WHERE id = :id', [':id' => $id]);
            $ok = "Equipe \"$nome\" removida.";
            break;
    }

    $_SESSION['config_msg'] = $erro ? ['erro', $erro] : ($ok ? ['ok', $ok] : null);
    header('Location: configuracoes.php#' . $secao);
    exit;
}

include 'menu.php';

$msg = $_SESSION['config_msg'] ?? null;
unset($_SESSION['config_msg']);

// Tabulações + quantos leads usam cada uma
$status = [];
$res = $db->query('SELECT id, nome FROM status_opcoes ORDER BY ordem, id');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $status[] = $row;

$uso_status = [];
$res = $db->query('SELECT status, COUNT(*) AS total FROM leads GROUP BY status');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $uso_status[(string)$row['status']] = (int)$row['total'];

// Equipes + quantos usuários há em cada uma
$equipes = [];
$res = $db->query('SELECT id, nome FROM equipes ORDER BY nome COLLATE NOCASE');
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $equipes[] = $row;

$uso_equipes = [];
$res = $db->query("SELECT equipe, COUNT(*) AS total FROM usuarios WHERE equipe IS NOT NULL GROUP BY equipe COLLATE NOCASE");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $uso_equipes[strtolower($row['equipe'])] = (int)$row['total'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Configurações | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .alert-ok  {background:#f6ffed;border:1px solid #b7eb8f;color:#135200;padding:10px;border-radius:6px;margin:10px 0;}
        .alert-err {background:#fff1f0;border:1px solid #ffa39e;color:#a8071a;padding:10px;border-radius:6px;margin:10px 0;}
        .secao { margin-top: 28px; }
        .hint { font-size:13px; color:#666; text-align:center; margin:0 0 10px; }

        /* Formulários em linha (anula o width:100% geral do style.css) */
        .cfg-form { display:flex; gap:8px; align-items:center; margin:0; }
        .cfg-form input[type="text"] { flex:1; width:auto; min-width:0; padding:6px 8px; }
        .cfg-form button, .form-btn button { width:auto; }
        .form-btn { display:inline-block; margin:0; }
        .add-form { max-width:560px; margin:0 auto 6px; }

        .btn-mini { padding:6px 12px; border-radius:6px; background:#6f5dff; color:#fff; border:none; cursor:pointer; white-space:nowrap; }
        .btn-mini:hover { background:#5142ad; }
        .btn-mini:disabled { background:#c9c4f5; cursor:default; }
        .btn-remover { background:#d4380d; }
        .btn-remover:hover { background:#a8290a; }
        .btn-seta { padding:4px 9px; }

        /* Larguras próprias (anula as larguras de coluna das tabelas de leads) */
        .table-destaque.tabela-config th,
        .table-destaque.tabela-config td { width:auto; max-width:none; }
        .table-destaque.tabela-config td.col-ordem,
        .table-destaque.tabela-config th.col-ordem { width:90px; white-space:nowrap; }
        .table-destaque.tabela-config td.col-uso,
        .table-destaque.tabela-config th.col-uso { width:90px; }
        .table-destaque.tabela-config td.col-acao,
        .table-destaque.tabela-config th.col-acao { width:110px; white-space:nowrap; }
        .em-uso { font-size:12px; color:#888; }
    </style>
</head>
<body>
<div class="container" style="max-width:1000px;">
    <h2>Configurações</h2>

    <?php if ($msg): ?>
        <div class="<?= $msg[0] === 'ok' ? 'alert-ok' : 'alert-err' ?>"><?= htmlspecialchars($msg[1]) ?></div>
    <?php endif; ?>

    <!-- ===== Tabulações ===== -->
    <div class="secao" id="tabulacoes">
        <h3>Tabulações</h3>
        <p class="hint">Opções de status que o consultor escolhe ao trabalhar um lead, na ordem em que aparecem.
            Renomear atualiza também os leads que já estão com essa tabulação.</p>

        <form method="POST" class="cfg-form add-form">
            <input type="hidden" name="acao" value="status_adicionar">
            <input type="text" name="nome" placeholder="Nova tabulação" maxlength="120" required>
            <button type="submit" class="btn-mini">Adicionar</button>
        </form>

        <div class="tabela-responsive">
            <table class="table-destaque tabela-config">
                <tr>
                    <th class="col-ordem">Ordem</th>
                    <th>Tabulação</th>
                    <th class="col-uso">Leads</th>
                    <th class="col-acao">Remover</th>
                </tr>
                <?php foreach ($status as $i => $s):
                    $uso = $uso_status[$s['nome']] ?? 0;
                ?>
                <tr>
                    <td class="col-ordem">
                        <form method="POST" class="form-btn">
                            <input type="hidden" name="acao" value="status_mover">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="direcao" value="cima">
                            <button type="submit" class="btn-mini btn-seta" title="Subir" <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
                        </form>
                        <form method="POST" class="form-btn">
                            <input type="hidden" name="acao" value="status_mover">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="direcao" value="baixo">
                            <button type="submit" class="btn-mini btn-seta" title="Descer" <?= $i === count($status) - 1 ? 'disabled' : '' ?>>&darr;</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" class="cfg-form">
                            <input type="hidden" name="acao" value="status_renomear">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <input type="text" name="nome" value="<?= htmlspecialchars($s['nome']) ?>" maxlength="120" required>
                            <button type="submit" class="btn-mini">Salvar</button>
                        </form>
                    </td>
                    <td class="col-uso"><?= $uso ?></td>
                    <td class="col-acao">
                        <?php if ($uso === 0): ?>
                            <form method="POST" class="form-btn" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Remover a tabulação "' . $s['nome'] . '"?', JSON_UNESCAPED_UNICODE)) ?>)">
                                <input type="hidden" name="acao" value="status_remover">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="btn-mini btn-remover">Remover</button>
                            </form>
                        <?php else: ?>
                            <span class="em-uso" title="Há leads com essa tabulação">Em uso</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($status)): ?>
                    <tr><td colspan="4" style="color:#aaa;">Nenhuma tabulação cadastrada.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <!-- ===== Equipes ===== -->
    <div class="secao" id="equipes">
        <h3>Equipes</h3>
        <p class="hint">Equipes disponíveis para supervisores e consultores. A equipe de cada pessoa é escolhida em
            <a href="usuarios.php">Usuários</a>. Renomear atualiza também os usuários da equipe.</p>

        <form method="POST" class="cfg-form add-form">
            <input type="hidden" name="acao" value="equipe_adicionar">
            <input type="text" name="nome" placeholder="Nova equipe" maxlength="120" required>
            <button type="submit" class="btn-mini">Adicionar</button>
        </form>

        <div class="tabela-responsive">
            <table class="table-destaque tabela-config">
                <tr>
                    <th>Equipe</th>
                    <th class="col-uso">Usuários</th>
                    <th class="col-acao">Remover</th>
                </tr>
                <?php foreach ($equipes as $e):
                    $uso = $uso_equipes[strtolower($e['nome'])] ?? 0;
                ?>
                <tr>
                    <td>
                        <form method="POST" class="cfg-form">
                            <input type="hidden" name="acao" value="equipe_renomear">
                            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                            <input type="text" name="nome" value="<?= htmlspecialchars($e['nome']) ?>" maxlength="120" required>
                            <button type="submit" class="btn-mini">Salvar</button>
                        </form>
                    </td>
                    <td class="col-uso"><?= $uso ?></td>
                    <td class="col-acao">
                        <?php if ($uso === 0): ?>
                            <form method="POST" class="form-btn" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Remover a equipe "' . $e['nome'] . '"?', JSON_UNESCAPED_UNICODE)) ?>)">
                                <input type="hidden" name="acao" value="equipe_remover">
                                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                <button type="submit" class="btn-mini btn-remover">Remover</button>
                            </form>
                        <?php else: ?>
                            <span class="em-uso" title="Há usuários nessa equipe">Em uso</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($equipes)): ?>
                    <tr><td colspan="3" style="color:#aaa;">Nenhuma equipe cadastrada.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
</body>
</html>
