<?php
// database/reparar_lista.php
// Corrige uma lista importada quando a importação ainda não reconhecia as colunas CNPJ/TELEFONE/LINHAS
// (todos os campos do lead receberam o CNPJ e o telefone e as linhas se perderam).
// Relê o arquivo original, preenche telefone e linhas e limpa os campos que só tinham o CNPJ copiado.
// Não mexe em distribuição (consultor) nem em tabulação (status, observação, telefone da ligação).
//
// Uso (somente no terminal):
//   php database/reparar_lista.php <lista_id> <arquivo.xlsx|csv>            -> só confere, não altera nada
//   php database/reparar_lista.php <lista_id> <arquivo.xlsx|csv> --aplicar  -> aplica a correção
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/importacao.php';

[$script, $lista_id, $arquivo] = array_pad($argv, 3, null);
$aplicar  = in_array('--aplicar', $argv, true);
$lista_id = (int)$lista_id;
if ($lista_id <= 0 || !$arquivo || !is_file($arquivo)) {
    echo "Uso: php database/reparar_lista.php <lista_id> <arquivo.xlsx|csv> [--aplicar]\n";
    exit(1);
}

$db = new SQLite3(__DIR__ . '/database.db');
$db->busyTimeout(5000);

// Linhas do arquivo, na mesma ordem e com o mesmo filtro da importação antiga
// (que só pulava linhas com a 1ª coluna vazia)
[$header, $dados] = ler_planilha($arquivo, strtolower(pathinfo($arquivo, PATHINFO_EXTENSION)));
$idx = mapear_colunas($header);
if ($idx['cpf_cnpj'] === null) {
    echo "ERRO: o arquivo não tem coluna CNPJ.\n";
    exit(1);
}
$linhas = [];
foreach ($dados as $linha) {
    if (!isset($linha[0]) || (string)$linha[0] === '') continue;
    $linhas[] = extrair_lead($linha, $idx);
}

// Leads da lista, na ordem em que foram inseridos
$leads = [];
$res = $db->query("SELECT id, cpf_cnpj FROM leads WHERE lista_id = $lista_id ORDER BY id");
while ($row = $res->fetchArray(SQLITE3_ASSOC)) $leads[] = $row;

echo "Lista $lista_id: " . count($leads) . " leads no banco | arquivo: " . count($linhas) . " linhas com CNPJ\n";
if (count($leads) !== count($linhas) || count($leads) === 0) {
    echo "ERRO: quantidades diferentes; o arquivo não corresponde a essa lista. Nada foi alterado.\n";
    exit(1);
}
foreach ($leads as $i => $lead) {
    if (trim((string)$lead['cpf_cnpj']) !== $linhas[$i]['cpf_cnpj']) {
        echo "ERRO: lead id {$lead['id']} tem CNPJ {$lead['cpf_cnpj']}, mas a linha " . ($i + 2) . " do arquivo tem {$linhas[$i]['cpf_cnpj']}. Nada foi alterado.\n";
        exit(1);
    }
}
echo "OK: todos os " . count($leads) . " leads batem com o arquivo, na mesma ordem.\n";

if (!$aplicar) {
    echo "Conferência apenas. Para corrigir, rode de novo com --aplicar.\n";
    exit;
}

garantir_coluna_qtd_linhas($db);

// Campos que receberam o CNPJ por engano voltam a ficar vazios (só se ainda forem iguais ao CNPJ)
$limpar = ['empresa', 'tel_celular', 'email', 'cidade', 'estado', 'cep', 'codigolead', 'descricao'];
$sets = [];
foreach ($limpar as $c) $sets[] = "$c = CASE WHEN $c = cpf_cnpj THEN '' ELSE $c END";
$stmt = $db->prepare('UPDATE leads SET tel_comercial = :tel, qtd_linhas = :qtd, ' . implode(', ', $sets) . '
                       WHERE id = :id AND cpf_cnpj = :cnpj');

$db->exec('BEGIN IMMEDIATE;');
$alterados = 0;
foreach ($leads as $i => $lead) {
    $stmt->bindValue(':tel',  $linhas[$i]['tel_comercial'], SQLITE3_TEXT);
    $stmt->bindValue(':qtd',  $linhas[$i]['qtd_linhas'], SQLITE3_TEXT);
    $stmt->bindValue(':id',   (int)$lead['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':cnpj', $lead['cpf_cnpj'], SQLITE3_TEXT);
    $stmt->execute();
    $alterados += $db->changes();
    $stmt->reset();
}
if ($alterados !== count($leads)) {
    $db->exec('ROLLBACK;');
    echo "ERRO: só $alterados de " . count($leads) . " leads seriam alterados. Nada foi alterado.\n";
    exit(1);
}
$db->exec('COMMIT;');
echo "Corrigidos $alterados leads.\n";
