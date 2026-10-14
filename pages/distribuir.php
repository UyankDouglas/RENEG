<?php
session_start();
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

date_default_timezone_set('America/Recife');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_nivel'], ['ADM', 'Supervisor'])) {
    header('Location: login.php');
    exit;
}

// ====== AJAX (contador de restantes) ======
if (isset($_GET['ajax']) && $_GET['ajax'] === 'restantes') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $count = (int)$db->querySingle("SELECT COUNT(*) FROM leads WHERE consultor_id IS NULL");
    echo json_encode([
        'restantes' => $count,
        'ts' => date('H:i:s'),
    ]);
    exit;
}
// ==========================================

include 'menu.php';

$msg = '';
$usuario_nivel  = $_SESSION['usuario_nivel'];
$usuario_equipe = $_SESSION['usuario_equipe'] ?? null;

/**
 * Segurança: se for Supervisor, força a equipe a partir do banco.
 */
if ($usuario_nivel === 'Supervisor') {
    $stmtSup = $db->prepare("SELECT equipe FROM usuarios WHERE id = :id AND nivel = 'Supervisor'");
    $stmtSup->bindValue(':id', $_SESSION['usuario_id'], SQLITE3_INTEGER);
    $resSup  = $stmtSup->execute();
    $rowSup  = $resSup ? $resSup->fetchArray(SQLITE3_ASSOC) : null;

    if (!$rowSup || empty($rowSup['equipe'])) {
        header('Location: dashboard.php?msg=Supervisor sem equipe definida. Contate o ADM.');
        exit;
    }

    $usuario_equipe = $rowSup['equipe'];
    $_SESSION['usuario_equipe'] = $usuario_equipe;
}

// Contagem inicial de leads restantes (não atribuídos)
$leads_restantes = (int)$db->querySingle("SELECT COUNT(*) FROM leads WHERE consultor_id IS NULL");

// Busca UFs disponíveis (apenas não atribuídos)
$ufs_disponiveis = [];
$sql_ufs = "SELECT DISTINCT estado FROM leads WHERE consultor_id IS NULL AND estado IS NOT NULL AND estado != '' ORDER BY estado";
$res_ufs = $db->query($sql_ufs);
while ($row = $res_ufs->fetchArray(SQLITE3_ASSOC)) {
    $ufs_disponiveis[] = $row['estado'];
}

/**
 * Lista de consultores aptos:
 * - ADM: todos consultores
 * - Supervisor: apenas consultores da sua equipe
 * - Aptos = nunca receberam OU finalizaram a lista anterior (sem pendentes)
 */
if ($usuario_nivel === 'ADM') {
    $stmt = $db->prepare("SELECT id, nome, equipe FROM usuarios WHERE nivel = 'Consultor'");
} else {
    $stmt = $db->prepare("
        SELECT id, nome, equipe
        FROM usuarios
        WHERE nivel = 'Consultor' AND UPPER(equipe) = UPPER(:equipe)
    ");
    $stmt->bindValue(':equipe', $usuario_equipe, SQLITE3_TEXT);
}

$consultores = [];
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $consultorId = (int)$row['id'];
    $total = (int)$db->querySingle("SELECT COUNT(*) FROM leads WHERE consultor_id = {$consultorId}");
    $pendentes = (int)$db->querySingle(
        "SELECT COUNT(*) FROM leads WHERE consultor_id = {$consultorId} AND (status IS NULL OR status = '' OR status = 'Novo')"
    );
    if ($total == 0 || ($total > 0 && $pendentes == 0)) {
        $consultores[] = $row;
    }
}

// Cria um mapa id=>dados p/ validação posterior
$consultores_map = [];
foreach ($consultores as $c) { $consultores_map[(int)$c['id']] = $c; }

// Distribuição
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quantidade'])) {
    $quantidade = max(0, (int)$_POST['quantidade']);
    $ufs_selecionadas = $_POST['ufs'] ?? [];
    $ids_selecionados = array_map('intval', $_POST['consultores_ids'] ?? []);

    if ($quantidade <= 0) {
        $msg = "Informe uma quantidade válida.";
    } elseif (empty($ids_selecionados)) {
        $msg = "Selecione ao menos um consultor.";
    } else {
        // Mantém apenas consultores realmente aptos/visíveis para o usuário
        $consultores_escolhidos = [];
        foreach ($ids_selecionados as $cid) {
            if (isset($consultores_map[$cid])) {
                $consultores_escolhidos[] = $consultores_map[$cid];
            }
        }

        if (empty($consultores_escolhidos)) {
            $msg = "Nenhum consultor válido selecionado.";
        } else {
            // Filtro por UF (se houver)
            $where_uf = '';
            if (!empty($ufs_selecionadas)) {
                $ufs_sql = array_map(function($uf) use ($db) {
                    return "'" . $db->escapeString($uf) . "'";
                }, $ufs_selecionadas);
                $where_uf = " AND estado IN (" . implode(',', $ufs_sql) . ") ";
            }

            // Carrega IDs de leads elegíveis (não atribuídos)
            $leads = [];
            $sql_leads = "SELECT id FROM leads WHERE consultor_id IS NULL $where_uf";
            $resLeads = $db->query($sql_leads);
            while ($row = $resLeads->fetchArray(SQLITE3_ASSOC)) {
                $leads[] = (int)$row['id'];
            }
            shuffle($leads);

            $consultores_count = count($consultores_escolhidos);
            $total_leads_disponiveis = count($leads);

            if ($total_leads_disponiveis === 0) {
                $msg = "Nenhum lead disponível para distribuir com esse filtro.";
            } else {
                $db->exec('BEGIN TRANSACTION;');

                $lead_idx = 0;
                foreach ($consultores_escolhidos as $consultor) {
                    $consultor_id = (int)$consultor['id'];
                    $atribuido = 0;

                    while ($atribuido < $quantidade && $lead_idx < $total_leads_disponiveis) {
                        $lead_id = $leads[$lead_idx];

                        $stmtUp = $db->prepare("
                            UPDATE leads
                               SET consultor_id = :cid, data_atribuicao = :dt
                             WHERE id = :id AND consultor_id IS NULL
                        ");
                        $stmtUp->bindValue(':cid', $consultor_id, SQLITE3_INTEGER);
                        $stmtUp->bindValue(':dt', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                        $stmtUp->bindValue(':id', $lead_id, SQLITE3_INTEGER);
                        $stmtUp->execute();

                        $lead_idx++;
                        $atribuido++;
                    }

                    if ($lead_idx >= $total_leads_disponiveis) break;
                }

                $db->exec('COMMIT;');

                // Atualiza contagem após a distribuição
                $leads_restantes = (int)$db->querySingle("SELECT COUNT(*) FROM leads WHERE consultor_id IS NULL");
                $msg = "Distribuição realizada! {$lead_idx} lead(s) distribuído(s) entre ".count($consultores_escolhidos)." consultor(es).";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Distribuir Leads | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .checkbox-group {
            margin: 8px 0 16px 0;
            display: flex; flex-wrap: wrap; gap: 12px;
        }
        .checkbox-group label {
            display: flex; align-items: center; font-weight: 500;
            background: #f4f5ff; padding:4px 10px; border-radius:6px;
            box-shadow: 0 1px 3px rgba(100,100,150,0.06);
        }
        .checkbox-group input[type=checkbox] { margin-right: 6px; }
        .leads-restantes {
            background: #eef8ff; border: 1px solid #bfe3ff; color: #084d72;
            padding: 10px; border-radius: 6px; margin-bottom: 15px; font-weight: bold;
        }
        .tag-equipe {
            display:inline-block; margin: 6px 0 12px 0; padding: 6px 10px;
            background:#fff7e6; border:1px solid #ffd591; border-radius:6px; color:#ad6800; font-weight:600;
        }
        .alert {
            background:#f6ffed; border:1px solid #b7eb8f; color:#135200;
            padding:10px; border-radius:6px; margin:10px 0;
        }
        .section {
            margin: 14px 0; padding: 12px; border: 1px solid #eee; border-radius:8px; background:#fafbff;
        }
        .section h4 { margin: 0 0 8px 0; }
        .list-consultores {
            display:grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap:8px;
        }
        .pulse { animation: pulse 0.4s ease; display: inline-block; }
        @keyframes pulse { 0%{transform:scale(1)} 50%{transform:scale(1.06)} 100%{transform:scale(1)} }
        .ts { font-size: 0.85em; opacity: .7; margin-left: 8px; }
    </style>
</head>
<body>
<div class="container">
    <h2>Distribuir Leads</h2>

    <div class="leads-restantes">
        Leads restantes disponíveis:
        <span id="restantes"><?= (int)$leads_restantes ?></span>
        <span id="restantes-ts" class="ts">atualizado às <?= htmlspecialchars(date('H:i:s')) ?></span>
    </div>

    <?php if ($usuario_nivel === 'Supervisor'): ?>
        <div class="tag-equipe">
            Distribuição limitada à equipe: <b><?= htmlspecialchars($usuario_equipe) ?></b>
        </div>
    <?php endif; ?>

    <?php if (!empty($msg)): ?>
        <div class="alert"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="section">
            <h4>Quantidade de leads por consultor selecionado</h4>
            <input type="number" name="quantidade" min="1" required style="margin-bottom:10px;">
        </div>

        <div class="section">
            <h4>Distribuir por UF (opcional)</h4>
            <div class="checkbox-group">
                <?php foreach($ufs_disponiveis as $uf): ?>
                    <label>
                        <input type="checkbox" name="ufs[]" value="<?= htmlspecialchars($uf) ?>">
                        <?= htmlspecialchars($uf) ?>
                    </label>
                <?php endforeach; ?>
                <span style="font-size:0.94em; color:#888; margin-left:8px;">
                    (Deixe em branco para considerar todas as UFs)
                </span>
            </div>
        </div>

        <div class="section">
            <h4>Selecione os consultores que receberão leads</h4>
            <?php if (count($consultores) === 0): ?>
                <p style="color:#e55039; margin: 8px 0;">Nenhum consultor apto a receber nova lista no momento.</p>
            <?php else: ?>
                <div style="margin-bottom:8px;">
                    <label><input type="checkbox" id="marcarTodos"> Marcar/Desmarcar todos</label>
                </div>
                <div class="list-consultores">
                    <?php foreach ($consultores as $c): ?>
                        <label style="background:#f6f7ff; padding:6px 10px; border-radius:6px;">
                            <input type="checkbox" name="consultores_ids[]" value="<?= (int)$c['id'] ?>">
                            <?= htmlspecialchars($c['nome']) ?>
                            <?php if ($usuario_nivel === 'ADM' && !empty($c['equipe'])): ?>
                                <small style="color:#777;"> — <?= htmlspecialchars($c['equipe']) ?></small>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <button type="submit">Distribuir</button>
    </form>

    <p style="margin-top:6px;font-size:0.96em;color:#666;">
        <b>Aviso:</b> Só receberão leads os consultores que <u>nunca receberam</u> ou que <u>finalizaram a lista anterior</u>.<br>
        Um lead nunca será atribuído a mais de um consultor.
    </p>
</div>

<!-- Auto-refresh do contador de leads restantes + marcar todos -->
<script>
(function () {
    const span = document.getElementById('restantes');
    const ts = document.getElementById('restantes-ts');

    async function refreshRestantes() {
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('ajax', 'restantes');
            const resp = await fetch(url.toString(), { cache: 'no-store', credentials: 'same-origin' });
            if (!resp.ok) return;
            const data = await resp.json();
            if (typeof data.restantes !== 'undefined') {
                const novo = String(data.restantes);
                if (span.textContent !== novo) {
                    span.textContent = novo;
                    span.classList.add('pulse');
                    setTimeout(() => span.classList.remove('pulse'), 450);
                }
            }
            if (data.ts && ts) ts.textContent = 'atualizado às ' + data.ts;
        } catch (e) {}
    }
    refreshRestantes();
    setInterval(refreshRestantes, 12000);

    // Marcar/Desmarcar todos
    const toggleAll = document.getElementById('marcarTodos');
    if (toggleAll) {
        toggleAll.addEventListener('change', function() {
            document.querySelectorAll('input[name="consultores_ids[]"]').forEach(ch => {
                ch.checked = toggleAll.checked;
            });
        });
    }
})();
</script>
</body>
</html>
