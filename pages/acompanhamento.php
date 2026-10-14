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

$usuario_nivel = $_SESSION['usuario_nivel'];
$usuario_equipe = $_SESSION['usuario_equipe'] ?? null;

if ($usuario_nivel == 'ADM') {
    $sql = "SELECT id, nome FROM usuarios WHERE nivel = 'Consultor'";
} elseif ($usuario_nivel == 'Supervisor') {
    $sql = "SELECT id, nome FROM usuarios WHERE nivel = 'Consultor' AND equipe = '".$db->escapeString($usuario_equipe)."'";
}
$consultores = [];
$res = $db->query($sql);
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $consultores[] = $row;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Acompanhamento | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .progress-bar-bg {
            width: 100px;
            background: #e6e9f7;
            border-radius: 6px;
            height: 15px;
            margin: 0 auto;
            overflow: hidden;
        }
        .progress-bar {
            background: #6f5dff;
            height: 100%;
        }
        .baixar-link {
            font-size:12px;
            color:#3a68c1;
            text-decoration:underline;
            display:inline-block;
            margin-top:3px;
        }
        .baixar-todos-lotes {
            display:inline-block;
            background:#3a68c1;
            color:#fff !important;
            font-size:15px;
            font-weight:bold;
            padding:10px 18px;
            border-radius:7px;
            text-decoration:none;
            margin-bottom:20px;
            margin-top:10px;
        }
        .baixar-todos-lotes:hover {
            background:#2838af;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Acompanhamento dos Consultores</h2>
    <div style="margin-bottom:22px;">
        <a href="baixar_todos_lotes.php" class="baixar-todos-lotes">
            &#128229; Baixar Todos os Lotes (Excel)
        </a>
    </div>
    <div class="tabela-responsive">
        <table class="table-destaque">
            <tr>
                <th>Consultor</th>
                <th>Lote Atual</th>
                <th>Data Importação</th>
                <th>Total Leads</th>
                <th>Tabulados</th>
                <th>% Trabalhado</th>
                <th>Baixar do Consultor</th>
            </tr>
            <?php foreach ($consultores as $c):
                // Busca todos os IDs dos lotes/listas atribuídos ao consultor
                $lotes = [];
                $resLotes = $db->query("SELECT DISTINCT lista_id FROM leads WHERE consultor_id = {$c['id']} AND lista_id IS NOT NULL");
                while ($lot = $resLotes->fetchArray(SQLITE3_ASSOC)) $lotes[] = $lot['lista_id'];

                // Mostra o lote mais recente primeiro (último atribuído)
                $lote_atual = null;
                if (count($lotes) > 0) {
                    $lote_id = max($lotes); // Último lote
                    $lote = $db->query("SELECT * FROM listas WHERE id = $lote_id")->fetchArray(SQLITE3_ASSOC);

                    // Totais desse lote para esse consultor
                    $total = $db->querySingle("SELECT COUNT(*) FROM leads WHERE consultor_id = {$c['id']} AND lista_id = $lote_id");
                    $tabulados = $db->querySingle("SELECT COUNT(*) FROM leads WHERE consultor_id = {$c['id']} AND lista_id = $lote_id AND status != 'Novo' AND status != ''");

                    $percent = $total > 0 ? round($tabulados / $total * 100, 1) : 0;
                } else {
                    $lote = null;
                    $lote_id = 0;
                    $total = $tabulados = $percent = 0;
                }
            ?>
            <tr>
                <td><?=htmlspecialchars($c['nome'])?></td>
                <td><?= $lote ? htmlspecialchars($lote['nome_arquivo']) : '-' ?></td>
                <td><?= $lote ? date('d/m/Y H:i', strtotime($lote['data_importacao'])) : '-' ?></td>
                <td><?= $total ?></td>
                <td><?= $tabulados ?></td>
                <td>
                    <div class="progress-bar-bg">
                        <div class="progress-bar" style="width:<?=$percent?>%"></div>
                    </div>
                    <?=$percent?>%
                </td>
                <td>
                    <?php if ($lote): ?>
                        <a href="baixar_lista_consultor.php?consultor=<?=$c['id']?>&lote=<?=$lote_id?>" class="baixar-link">Baixar Excel</a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
</body>
</html>
