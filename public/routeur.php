<?php
// Lancement local (sans Apache) : php -S 0.0.0.0:8080 -t public public/routeur.php
$chemin = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$fichier = realpath(__DIR__ . $chemin);
if ($chemin !== '/' && $fichier && str_starts_with($fichier, __DIR__) && is_file($fichier) && !str_ends_with($fichier, '.php')) {
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    return false;  // fichier de l'interface : servi tel quel
}
require __DIR__ . '/index.php';
