<?php
/**
 * Banc d'essai des fonctions de calcul (lancé par les tests Python, sans serveur ni réseau) : lit sur l'entrée standard une
 * liste JSON d'appels [[fonction, [arguments…]], …] et écrit la liste JSON des résultats. Seules les fonctions pf_*,
 * demo_* et lire_montant sont permises. Un argument {"base64": "…"} est décodé (contenu d'un fichier).
 */
declare(strict_types=1);

foreach (['noyau', 'textes', 'plateformes', 'demo'] as $m) {
    require dirname(__DIR__) . "/src/$m.php";
}

$appels = json_decode((string)stream_get_contents(STDIN), true) ?? [];
$sortie = [];
foreach ($appels as [$fonction, $arguments]) {
    if (!preg_match('/^(pf_|demo_(export|csv|xlsx)$|lire_montant$)/', $fonction) || !function_exists($fonction)) {
        $sortie[] = ['erreur' => "fonction refusée : $fonction"];
        continue;
    }
    $arguments = array_map(fn($a) => is_array($a) && array_keys($a) === ['base64'] ? base64_decode($a['base64']) : $a, $arguments);
    try {
        $r = $fonction(...$arguments);
        $sortie[] = is_string($r) && !mb_check_encoding($r, 'UTF-8') ? ['base64' => base64_encode($r)] : $r;
    } catch (Throwable $e) {
        $sortie[] = ['erreur' => $e->getMessage()];
    }
}
echo json_encode($sortie, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
