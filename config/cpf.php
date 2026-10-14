<?php
// config/cpf.php
// CPF do usuário (é o login): guardado só com os 11 dígitos e exibido como 000.000.000-00.

function cpf_normalizar($cpf) {
    return preg_replace('/\D+/', '', (string)$cpf);
}

/** Confere tamanho e dígitos verificadores (pega erro de digitação). */
function cpf_valido($cpf) {
    $cpf = cpf_normalizar($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) $soma += (int)$cpf[$i] * ($t + 1 - $i);
        if ((int)$cpf[$t] !== ((10 * $soma) % 11) % 10) return false;
    }
    return true;
}

function cpf_formatar($cpf) {
    $cpf = cpf_normalizar($cpf);
    if (strlen($cpf) !== 11) return $cpf;
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

/** Se outro usuário (diferente de $exceto_id) já usa esse CPF. */
function cpf_em_uso($db, $cpf, $exceto_id = 0) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE cpf = :c AND id != :id');
    $stmt->bindValue(':c', cpf_normalizar($cpf), SQLITE3_TEXT);
    $stmt->bindValue(':id', (int)$exceto_id, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
    return (int)($row[0] ?? 0) > 0;
}
