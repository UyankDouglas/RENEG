<?php
session_start();
$timeout = 30 * 60; // 30 minutos em segundos

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    header('Location: login.php?msg=Sessão expirada por inatividade.');
    exit;
}
$_SESSION['last_activity'] = time(); // Atualiza sempre que há requisição

require_once __DIR__ . '/../config/database.php';
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
include 'menu.php';
$nivel = $_SESSION['usuario_nivel'];

// Excluir toda a lista + seus leads
if ($nivel == 'ADM' && isset($_GET['del'])) {
    $lista_id = (int)$_GET['del'];
    $db->exec("DELETE FROM leads WHERE lista_id = $lista_id");
    $db->exec("DELETE FROM listas WHERE id = $lista_id");
    header('Location: leads.php');
    exit;
}

// Buscar listas
$listas = [];
$res = $db->query("SELECT * FROM listas ORDER BY data_importacao DESC");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $listas[] = $row;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Listas Importadas | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../Logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="container" style="max-width:520px;">
    <h2>Listas de Leads Importadas</h2>
    <div class="tabela-responsive">
        <table class="table-destaque">
            <tr>
                <th>Nome do Arquivo</th>
                <th>Data de Importação</th>
                <th>Total de Leads</th>
                <?php if ($nivel == 'ADM'): ?><th>Ações</th><?php endif; ?>
            </tr>
            <?php foreach ($listas as $lista): ?>
            <tr>
                <td><?=htmlspecialchars($lista['nome_arquivo'])?></td>
                <td><?=date('d/m/Y H:i', strtotime($lista['data_importacao']))?></td>
                <td><?=intval($lista['total_leads'])?></td>
                <?php if ($nivel == 'ADM'): ?>
                <td>
                    <a href="leads.php?del=<?=$lista['id']?>" onclick="return confirm('Excluir TODA a lista e seus leads?')" style="color:#e55039; font-weight:bold;">Excluir</a>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($listas)): ?>
            <tr><td colspan="<?= $nivel=='ADM' ? 4 : 3 ?>" style="text-align:center; color:#aaa;">Nenhuma lista importada.</td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>
</body>
</html>
