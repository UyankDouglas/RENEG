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

// Somente ADM pode acessar
$nivelSess = strtoupper(trim($_SESSION['usuario_nivel'] ?? ''));
if (!isset($_SESSION['usuario_id']) || $nivelSess !== 'ADM') {
    header('Location: login.php');
    exit;
}

include 'menu.php';

$msg = '';
$minha_id = (int)($_SESSION['usuario_id'] ?? 0);

// Opções de equipe (ajuste livremente)
$equipes = ['Tallyta', 'Marcela'];

/**
 * CADASTRAR NOVO USUÁRIO
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar') {
    $nome  = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $nivel = trim($_POST['nivel'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    // Normaliza nível
    $validNiveis = ['ADM', 'Supervisor', 'Consultor'];
    if (!in_array($nivel, $validNiveis, true)) {
        $msg = "Nível inválido.";
    } elseif ($nome === '' || $email === '' || $senha === '') {
        $msg = "Preencha todos os campos obrigatórios.";
    } else {
        // Equipe obrigatória exceto para ADM
        $equipe = ($nivel === 'ADM') ? null : trim($_POST['equipe'] ?? '');
        if ($nivel !== 'ADM' && $equipe === '') {
            $msg = "Equipe obrigatória para Supervisor e Consultor!";
        } else {
            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $db->prepare('INSERT INTO usuarios (nome, email, senha_hash, nivel, equipe) VALUES (:n, :e, :s, :nv, :eq)');
            $stmt->bindValue(':n',  $nome, SQLITE3_TEXT);
            $stmt->bindValue(':e',  $email, SQLITE3_TEXT);
            $stmt->bindValue(':s',  $senha_hash, SQLITE3_TEXT);
            $stmt->bindValue(':nv', $nivel, SQLITE3_TEXT);
            if ($equipe === null) {
                $stmt->bindValue(':eq', null, SQLITE3_NULL);
            } else {
                $stmt->bindValue(':eq', $equipe, SQLITE3_TEXT);
            }

            if ($stmt->execute()) {
                $msg = "Usuário cadastrado!";
            } else {
                $msg = "Erro ao cadastrar usuário! Verifique se o e-mail já existe.";
            }
        }
    }
}

/**
 * REMOVER USUÁRIO (nunca remove ADM)
 */
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $user_nivel = $db->querySingle("SELECT nivel FROM usuarios WHERE id = $id");
    if ($user_nivel !== 'ADM') {
        $db->exec("DELETE FROM usuarios WHERE id = $id");
    }
    header('Location: usuarios.php');
    exit;
}

/**
 * EDITAR HIERARQUIA / EQUIPE / SENHA (por linha)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar') {
    $uid          = (int)($_POST['user_id'] ?? 0);
    $novo_nivel   = trim($_POST['nivel'] ?? '');
    $nova_equipe  = trim($_POST['equipe'] ?? '');
    $nova_senha   = trim($_POST['nova_senha'] ?? '');

    $validNiveis  = ['ADM', 'Supervisor', 'Consultor'];
    if (!in_array($novo_nivel, $validNiveis, true)) {
        $msg = "Nível inválido.";
    } elseif ($uid <= 0) {
        $msg = "Usuário inválido.";
    } else {
        // Busca o nível atual do usuário
        $rowAtual = $db->querySingle("SELECT nivel FROM usuarios WHERE id = $uid", true);
        if (!$rowAtual) {
            $msg = "Usuário não encontrado.";
        } else {
            $nivelAtual = $rowAtual['nivel'];

            // Regra: não pode rebaixar o ÚNICO ADM
            if ($nivelAtual === 'ADM' && $novo_nivel !== 'ADM') {
                $qtdAdm = (int)$db->querySingle("SELECT COUNT(*) FROM usuarios WHERE nivel = 'ADM'");
                if ($qtdAdm <= 1) {
                    $msg = "Não é possível rebaixar o único Administrador.";
                }
            }

            // Para ADM, equipe deve ser NULL; para outros, equipe obrigatória
            $equipeFinal = null;
            if ($novo_nivel === 'ADM') {
                $equipeFinal = null;
            } else {
                if ($nova_equipe === '') {
                    $msg = "Equipe é obrigatória para Supervisor e Consultor.";
                } else {
                    $equipeFinal = $nova_equipe;
                }
            }

            if ($msg === '') {
                // Monta UPDATE conforme senha em branco ou não
                if ($nova_senha !== '') {
                    $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("
                        UPDATE usuarios
                           SET nivel = :nv,
                               equipe = :eq,
                               senha_hash = :sh
                         WHERE id = :id
                    ");
                    $stmt->bindValue(':sh', $hash, SQLITE3_TEXT);
                } else {
                    $stmt = $db->prepare("
                        UPDATE usuarios
                           SET nivel = :nv,
                               equipe = :eq
                         WHERE id = :id
                    ");
                }

                $stmt->bindValue(':nv', $novo_nivel, SQLITE3_TEXT);
                if ($equipeFinal === null) {
                    $stmt->bindValue(':eq', null, SQLITE3_NULL);
                } else {
                    $stmt->bindValue(':eq', $equipeFinal, SQLITE3_TEXT);
                }
                $stmt->bindValue(':id', $uid, SQLITE3_INTEGER);

                if ($stmt->execute()) {
                    $msg = "Usuário atualizado com sucesso!";

                    // Aviso: se o ADM rebaixar a si mesmo, pode perder acesso ao recarregar
                    if ($uid === $minha_id && $novo_nivel !== 'ADM') {
                        $msg .= " (Atenção: você alterou seu próprio nível; pode perder o acesso a esta página ao recarregar.)";
                    }
                } else {
                    $msg = "Falha ao atualizar o usuário.";
                }
            }
        }
    }
}

// Lista todos os usuários para exibição
$res = $db->query("SELECT id, nome, email, nivel, equipe FROM usuarios ORDER BY nome ASC");
$usuarios = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $usuarios[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Gestão de Usuários | Sistema de Leads</title>
    <link rel="icon" type="image/png" href="../css/Logo.png">
    <meta charset="UTF-8">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .alert {background:#f6ffed;border:1px solid #b7eb8f;color:#135200;padding:10px;border-radius:6px;margin:10px 0;}
        .warn  {background:#fffbe6;border:1px solid #ffe58f;color:#614700;padding:10px;border-radius:6px;margin:10px 0;}
        .row-form { display:flex; gap:8px; align-items:center; flex-wrap: wrap; }
        .row-form input[type="text"],
        .row-form input[type="email"],
        .row-form input[type="password"],
        .row-form select { padding:6px; }
        .btn-mini { padding:6px 10px; border-radius:6px; background:#6f5dff; color:#fff; border:none; cursor:pointer; }
        .btn-mini:hover { background:#5142ad; }
        .pill-adm { padding:2px 8px; border-radius:999px; background:#0050b3; color:#fff; font-size:12px; }
        .pill-sup { padding:2px 8px; border-radius:999px; background:#096dd9; color:#fff; font-size:12px; }
        .pill-con { padding:2px 8px; border-radius:999px; background:#389e0d; color:#fff; font-size:12px; }
        .tabela-responsive { overflow-x:auto; }
        .table-destaque th, .table-destaque td { vertical-align: middle; }
        .hint { font-size:12px; color:#666; }
    </style>
</head>
<body>
<div class="container">
    <h2>Gestão de Usuários</h2>

    <div class="alert">
        Você está logado como <b>Administrador</b>. Aqui você pode <b>cadastrar</b>, <b>alterar hierarquia</b>, <b>definir equipe</b> e <b>alterar senha</b> dos usuários.
    </div>

    <?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- Cadastro -->
    <h3>Cadastrar novo usuário</h3>
    <form method="POST" id="form-cadastro" class="row-form">
        <input type="hidden" name="acao" value="cadastrar">
        <input type="text" name="nome" placeholder="Nome" required>
        <input type="email" name="email" placeholder="Email" required>
        <select name="nivel" id="nivel-novo" required>
            <option value="ADM">Administrador</option>
            <option value="Supervisor">Supervisor</option>
            <option value="Consultor">Consultor</option>
        </select>
        <select name="equipe" id="equipe-novo" required>
            <option value="">Equipe</option>
            <?php foreach($equipes as $eq): ?>
                <option value="<?=htmlspecialchars($eq)?>"><?=htmlspecialchars($eq)?></option>
            <?php endforeach; ?>
        </select>
        <input type="password" name="senha" placeholder="Senha" required>
        <button type="submit" class="btn-mini">Cadastrar</button>
        <span class="hint">Para ADM, a equipe será ignorada.</span>
    </form>
    <script>
    // Desabilita/limpa equipe no cadastro quando ADM for escolhido
    (function(){
        const nivel = document.getElementById('nivel-novo');
        const equipe = document.getElementById('equipe-novo');
        function sync() {
            if (nivel.value === 'ADM') {
                equipe.required = false;
                equipe.value = '';
                equipe.disabled = true;
            } else {
                equipe.required = true;
                equipe.disabled = false;
            }
        }
        nivel.addEventListener('change', sync);
        sync();
    })();
    </script>

    <!-- Lista / Edição -->
    <h3 style="margin-top:24px;">Usuários cadastrados</h3>
    <div class="tabela-responsive">
        <table class="table-destaque">
            <tr>
                <th>Nome</th>
                <th>Email</th>
                <th>Nível</th>
                <th>Equipe</th>
                <th>Nova senha</th>
                <th>Ações</th>
            </tr>

            <?php foreach ($usuarios as $u): 
                $pill = $u['nivel']==='ADM' ? 'pill-adm' : ($u['nivel']==='Supervisor' ? 'pill-sup' : 'pill-con');
            ?>
            <tr>
                <form method="POST" class="row-form" id="form-user-<?= (int)$u['id'] ?>">
                    <input type="hidden" name="acao" value="editar">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <td><?= htmlspecialchars($u['nome']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <select name="nivel" class="nivel-select" data-id="<?= (int)$u['id'] ?>">
                            <option value="ADM"        <?= $u['nivel']==='ADM'        ? 'selected' : '' ?>>Administrador</option>
                            <option value="Supervisor" <?= $u['nivel']==='Supervisor' ? 'selected' : '' ?>>Supervisor</option>
                            <option value="Consultor"  <?= $u['nivel']==='Consultor'  ? 'selected' : '' ?>>Consultor</option>
                        </select>
                        <span class="<?= $pill ?>" style="margin-left:6px;"><?= htmlspecialchars($u['nivel']) ?></span>
                    </td>
                    <td>
                        <select name="equipe" class="equipe-select" id="eq-<?= (int)$u['id'] ?>">
                            <option value="">Equipe</option>
                            <?php foreach($equipes as $eq): ?>
                                <option value="<?= htmlspecialchars($eq) ?>" <?= ($u['equipe'] ?? '')===$eq ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($eq) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($u['nivel']==='ADM'): ?>
                            <div class="hint">ADM: todas as equipes</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="password" name="nova_senha" placeholder="(opcional)">
                        <div class="hint">Deixe vazio para manter</div>
                    </td>
                    <td>
                        <button type="submit" class="btn-mini">Salvar</button>
                        <?php if ($u['nivel'] !== 'ADM'): ?>
                            <a href="usuarios.php?del=<?= (int)$u['id'] ?>" class="btn-mini" style="background:#d4380d" onclick="return confirm('Remover este usuário?')">Remover</a>
                        <?php else: ?>
                            <span class="hint">ADM não pode ser removido</span>
                        <?php endif; ?>
                    </td>
                </form>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<script>
// Habilita/desabilita campo equipe por linha conforme nível
(function(){
    function syncRow(rowId){
        const nivel = document.querySelector('#form-user-'+rowId+' select[name="nivel"]');
        const equipe = document.getElementById('eq-'+rowId);
        if (!nivel || !equipe) return;
        if (nivel.value === 'ADM') {
            equipe.disabled = true;
            equipe.value = '';
        } else {
            equipe.disabled = false;
        }
    }

    document.querySelectorAll('.nivel-select').forEach(sel => {
        sel.addEventListener('change', () => syncRow(sel.dataset.id));
        // Sync inicial
        syncRow(sel.dataset.id);
    });
})();
</script>
</body>
</html>
