<?php
/**
 * Premier lancement : crée les comptes du réglage « comptes » (config.json) s'ils n'existent pas et affiche, une seule fois,
 * leur mot de passe provisoire (à changer dans Paramètres). Ne fait rien si les comptes existent déjà.
 *   php outils/initialiser.php          (affichage lisible)
 *   php outils/initialiser.php --json   (tests automatiques)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/src/noyau.php';
require dirname(__DIR__) . '/src/comptes.php';

$crees = sous_verrou(fn() => comptes_initialiser());
cle_secrete();
if (in_array('--json', $argv, true)) {
    echo json_encode($crees), "\n";
    exit;
}
if ($crees) {
    echo "\n  Comptes créés. Mots de passe provisoires (à changer dans Paramètres) :\n";
    foreach ($crees as $id => $mdp) {
        echo "    $id : $mdp\n";
    }
    echo "\n";
} else {
    echo "Les comptes existent déjà (" . DATA . "/comptes.json) : rien à faire.\n";
}
