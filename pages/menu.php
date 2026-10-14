<?php
$nivel = $_SESSION['usuario_nivel'] ?? '';
$nome = $_SESSION['usuario_nome'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Nome da Página | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
</head>
<nav class="top-menu">
    <div class="menu-left">
        <b>Sistema de Leads</b>
    </div>
    <div class="menu-center">
        <?php if ($nivel === 'ADM'): ?>
            <a href="dashboard.php">Dashboard</a>
            <a href="upload_base.php">Upload Base</a>
            <a href="distribuir.php">Distribuir</a>
            <a href="leads.php">Leads</a>
            <a href="redistribuir_status.php">Redistribuir por Status</a>
            <a href="acompanhamento.php">Acompanhamento</a>
            <a href="usuarios.php">Usuários</a>
        <?php elseif ($nivel === 'Supervisor'): ?>
            <a href="dashboard.php">Dashboard</a>
            <a href="upload_base.php">Upload Base</a>
            <a href="distribuir.php">Distribuir</a>
            <!-- <a href="redistribuir_status.php">Redistribuir por Status</a> -->
            <a href="acompanhamento.php">Acompanhamento</a>
        <?php elseif ($nivel === 'Consultor'): ?>
            <a href="dashboard.php">Dashboard</a>
            <a href="consultar.php">Meus Leads</a>
            <a href="leads_trabalhados.php">Leads Trabalhados</a>
        <?php endif; ?>
    </div>
    <div class="menu-right">
        <?php if ($nome): ?>
            <span class="usuario-logado">Olá, <?=htmlspecialchars($nome)?> (<?=htmlspecialchars($nivel)?>)</span>
            <a href="../logout.php" class="sair">Sair</a>
        <?php endif; ?>
    </div>
</nav>
