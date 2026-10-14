<?php
session_start();
$timeout = 30 * 60; // 30 min

if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        session_unset(); session_destroy();
        header('Location: login.php?msg=Sessão expirada por inatividade.'); exit;
    }
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/opcoes.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php'); exit;
}
include 'menu.php';

$usuario_id   = (int)$_SESSION['usuario_id'];
$usuario_nivel= $_SESSION['usuario_nivel'] ?? '';

// —— Opções de Status (editáveis em Configurações) ——
$status_options = listar_status($db);

// —— Filtro de Status via GET ——
$status_padrao = in_array('VENDA', $status_options, true) ? 'VENDA' : 'Todos'; // padrão "VENDA"
$status_sel = trim($_GET['status'] ?? $status_padrao);
$validos = array_merge(['Todos'], $status_options);
if (!in_array($status_sel, $validos, true)) {
    $status_sel = $status_padrao;
}
$titulo_status = ($status_sel === 'Todos') ? 'TODOS' : $status_sel;
$esc_status = SQLite3::escapeString($status_sel);

// Arrays base (evita undefined)
$ufs = $ufs_qtd = $vendedores = $vendedores_qtd = [];

// —— ADM/Supervisor: visão geral —— //
if (in_array($usuario_nivel, ['ADM', 'Supervisor'], true)) {

    // Por UF
    $whereUF = ($status_sel === 'Todos') ? '' : "WHERE status = '$esc_status'";
    $res = $db->query("
        SELECT estado, COUNT(*) AS total
          FROM leads
          $whereUF
      GROUP BY estado
      ORDER BY total DESC
    ");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $ufs[] = $row['estado'] ?: 'N/D';
        $ufs_qtd[] = (int)$row['total'];
    }

    // Por Consultor
    $whereCons = ($status_sel === 'Todos') ? '' : "WHERE leads.status = '$esc_status'";
    $res = $db->query("
        SELECT COALESCE(usuarios.nome,'N/D') AS consultor, COUNT(*) AS total
          FROM leads
     LEFT JOIN usuarios ON leads.consultor_id = usuarios.id
          $whereCons
      GROUP BY leads.consultor_id
      ORDER BY total DESC
    ");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $vendedores[] = $row['consultor'];
        $vendedores_qtd[] = (int)$row['total'];
    }

// —— Consultor: visão pessoal —— //
} elseif ($usuario_nivel === 'Consultor') {
    $whereUfCons = "WHERE consultor_id = $usuario_id";
    if ($status_sel !== 'Todos') {
        $whereUfCons .= " AND status = '$esc_status'";
    }
    $res = $db->query("
        SELECT estado, COUNT(*) AS total
          FROM leads
          $whereUfCons
      GROUP BY estado
      ORDER BY total DESC
    ");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $ufs[] = $row['estado'] ?: 'N/D';
        $ufs_qtd[] = (int)$row['total'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="/Logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard-cards { display:flex; gap:32px; margin-bottom:24px; flex-wrap: wrap; }
        .dashboard-card { background:#f7f8fa; border-radius:12px; padding:16px 20px; box-shadow:0 2px 8px rgba(100,100,150,0.07); min-width:240px; }
        .dashboard-card h3 { margin:0 0 6px; font-size:1.05em; }
        .chart-container { max-width: 920px; margin: 24px auto 48px auto; }
        .filtro-status { display:flex; gap:12px; align-items:center; margin:10px 0 18px 0; }
        .filtro-status select { padding:7px 10px; border-radius:8px; border:1px solid #bbb; }
    </style>
</head>
<body>
<div class="container">
    <h2>Dashboard de Vendas/Atendimentos</h2>

    <!-- Filtro por Status -->
    <form method="GET" class="filtro-status">
        <label for="status"><b>Status:</b></label>
        <select name="status" id="status" onchange="this.form.submit()">
            <option value="Todos" <?= ($status_sel==='Todos'?'selected':'') ?>>Todos</option>
            <?php foreach ($status_options as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= ($status_sel===$opt?'selected':'') ?>><?= htmlspecialchars($opt) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Aplicar</button></noscript>
    </form>

    <?php if (in_array($usuario_nivel, ['ADM', 'Supervisor'], true)): ?>
        <div class="dashboard-cards">
            <div class="dashboard-card">
                <h3>Total <?= htmlspecialchars($titulo_status) ?> (todas UFs)</h3>
                <div style="font-size:1.4em;font-weight:700;"><?= array_sum($ufs_qtd) ?></div>
            </div>
            <div class="dashboard-card">
                <h3>Top Consultor (<?= htmlspecialchars($titulo_status) ?>)</h3>
                <div>
                    <b><?= htmlspecialchars($vendedores[0] ?? 'N/D') ?></b>
                    (<?= (int)($vendedores_qtd[0] ?? 0) ?>)
                </div>
            </div>
        </div>

        <div class="chart-container">
            <canvas id="graficoUf"></canvas>
        </div>
        <div class="chart-container">
            <canvas id="graficoConsultor"></canvas>
        </div>

    <?php else: ?>
        <div class="dashboard-card" style="max-width:380px;margin:auto;">
            <h3>Suas ocorrências — <?= htmlspecialchars($titulo_status) ?></h3>
            <div style="font-size:1.4em;font-weight:700;"><?= array_sum($ufs_qtd) ?></div>
        </div>
        <div class="chart-container">
            <canvas id="graficoUf"></canvas>
        </div>
    <?php endif; ?>
</div>

<script>
const ufs    = <?= json_encode($ufs) ?>;
const ufsQtd = <?= json_encode($ufs_qtd) ?>;
const tituloStatus = <?= json_encode($titulo_status) ?>;

<?php if (in_array($usuario_nivel, ['ADM', 'Supervisor'], true)): ?>
const vendedores    = <?= json_encode($vendedores) ?>;
const vendedoresQtd = <?= json_encode($vendedores_qtd) ?>;

new Chart(document.getElementById('graficoUf').getContext('2d'), {
    type: 'bar',
    data: {
        labels: ufs,
        datasets: [{
            label: `Ocorrências (${tituloStatus}) por UF`,
            data: ufsQtd,
            backgroundColor: '#6f5dff',
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            title: { display: true, text: `Ocorrências (${tituloStatus}) por Região (UF)` }
        },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});

new Chart(document.getElementById('graficoConsultor').getContext('2d'), {
    type: 'bar',
    data: {
        labels: vendedores,
        datasets: [{
            label: `Ocorrências (${tituloStatus}) por Consultor`,
            data: vendedoresQtd,
            backgroundColor: '#48c774',
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            title: { display: true, text: `Ocorrências (${tituloStatus}) por Consultor` }
        },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
<?php else: ?>
new Chart(document.getElementById('graficoUf').getContext('2d'), {
    type: 'bar',
    data: {
        labels: ufs,
        datasets: [{
            label: `Suas ocorrências (${tituloStatus}) por UF`,
            data: ufsQtd,
            backgroundColor: '#6f5dff',
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            title: { display: true, text: `Suas ocorrências (${tituloStatus}) por Região (UF)` }
        },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
<?php endif; ?>
</script>
</body>
</html>
