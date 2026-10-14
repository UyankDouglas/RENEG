<?php
// config/database.php
date_default_timezone_set('America/Recife'); // mesmo fuso em todas as páginas, independente do servidor

$db = new SQLite3(__DIR__ . '/../database/database.db');
$db->busyTimeout(5000); // espera até 5s se outro usuário estiver gravando, em vez de "database is locked"
