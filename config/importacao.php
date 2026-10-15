<?php
// config/importacao.php
// Leitura das planilhas de base (Upload Base e database/reparar_lista.php).

// Colunas aceitas por campo do lead. Maiúsculas, acentos, espaços e pontuação no nome não importam.
const COLUNAS_LEAD = [
    'empresa'       => ['Empresa/Cliente', 'Empresa'],
    'tel_comercial' => ['Telefone', 'Telefone Comercial', 'tel_comercial'],
    'tel_celular'   => ['Telefone Celular', 'tel_celular'],
    'email'         => ['Email'],
    'cidade'        => ['Cidade'],
    'estado'        => ['Estado'],
    'cep'           => ['CEP'],
    'codigolead'    => ['CodigoLead', 'codigo_lead'],
    'descricao'     => ['Descrição'],
    'cpf_cnpj'      => ['CPF/CNPJ', 'cpf_cnpj', 'CNPJ', 'CPF'],
    'qtd_linhas'    => ['Linhas', 'Quantidade de Linhas', 'Qtd Linhas'],
];

function normalizar_nome_coluna($nome) {
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT', trim((string)$nome))));
}

/** Índice da primeira coluna do cabeçalho com um dos nomes, ou null se não houver. */
function procurar_coluna($header, $possiveis) {
    foreach ($header as $i => $col) {
        $colNorm = normalizar_nome_coluna($col);
        foreach ($possiveis as $nome) {
            if ($colNorm !== '' && $colNorm === normalizar_nome_coluna($nome)) return $i;
        }
    }
    return null; // coluna ausente fica vazia (antes caía na 1ª coluna e copiava o CNPJ para todos os campos)
}

function mapear_colunas($header) {
    $idx = [];
    foreach (COLUNAS_LEAD as $campo => $nomes) $idx[$campo] = procurar_coluna($header, $nomes);
    return $idx;
}

function extrair_lead($linha, $idx) {
    $lead = [];
    foreach ($idx as $campo => $i) {
        $lead[$campo] = ($i !== null && isset($linha[$i])) ? trim((string)$linha[$i]) : '';
    }
    return $lead;
}

/** Linha sem nenhum identificador (CNPJ, empresa, código ou telefone) é ignorada. */
function lead_vazio($lead) {
    return $lead['cpf_cnpj'] === '' && $lead['empresa'] === '' && $lead['codigolead'] === '' && $lead['tel_comercial'] === '';
}

/** Lê CSV (ponto e vírgula) ou XLSX e devolve [cabeçalho, linhas]. */
function ler_planilha($caminho, $ext) {
    if ($ext === 'csv') {
        $dados = [];
        $handle = fopen($caminho, 'r');
        while (($linha = fgetcsv($handle, 0, ';', '"', '\\')) !== false) $dados[] = $linha;
        fclose($handle);
    } else {
        $dados = \PhpOffice\PhpSpreadsheet\IOFactory::load($caminho)->getActiveSheet()->toArray();
    }
    $header = array_shift($dados) ?: [];
    return [$header, $dados];
}

/** Garante a coluna qtd_linhas em leads (bancos criados antes dela). */
function garantir_coluna_qtd_linhas($db) {
    $res = $db->query("PRAGMA table_info(leads)");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (strtolower($row['name']) === 'qtd_linhas') return;
    }
    $db->exec("ALTER TABLE leads ADD COLUMN qtd_linhas TEXT");
}
