<?php
/**
 * Écrit dans exemples/ des exports de caisse fictifs (mêmes colonnes que la vraie caisse) pour essayer l'import à la main :
 *   php outils/generer_exemples.php 2026-09 2026-10
 * Le premier mois en CSV, les suivants en Excel (.xlsx). Toujours les mêmes lignes pour un mois donné.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
foreach (['noyau', 'textes', 'plateformes', 'demo'] as $m) {
    require dirname(__DIR__) . "/src/$m.php";
}
$mois = array_slice($argv, 1) ?: ['2026-09', '2026-10'];
foreach ($mois as $i => $m) {
    [$a, $n] = array_map('intval', explode('-', $m));
    $lignes = demo_export('Trois Lunes - Centre', $a, $n);
    $chemin = dirname(__DIR__) . sprintf('/exemples/export-caisse-%04d-%02d.%s', $a, $n, $i === 0 ? 'csv' : 'xlsx');
    file_put_contents($chemin, $i === 0 ? demo_csv($lignes) : demo_xlsx($lignes));
    echo basename($chemin) . ' : ' . count($lignes) . " lignes\n";
}
