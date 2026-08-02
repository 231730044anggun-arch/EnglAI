<?php
require 'config/koneksi.php';
$pdo = db();
$q = $pdo->query('SHOW COLUMNS FROM classroom_members');
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo $c['Field'] . PHP_EOL;
}
