<?php
/**
 * Ventes plateformes (PHP) : point d'entrée unique. Sert la page de l'interface et l'API (/api/…) ; les fichiers de
 * l'interface (app.js, styles.css, logo, police) sont servis directement par le serveur web. Données dans ../data, jamais
 * publiques.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('expose_php', '0');
header_remove('X-Powered-By');
$demo = in_array(strtolower((string)getenv('DEMO')), ['1', 'true', 'oui'], true);  // la démo n'appelle aucun service : curl inutile
$manquantes = array_values(array_filter($demo ? ['openssl', 'zip', 'mbstring', 'json', 'simplexml'] : ['curl', 'openssl', 'zip', 'mbstring', 'json', 'simplexml'],
    fn($e) => !extension_loaded($e)));
if ($manquantes || PHP_VERSION_ID < 80200) {  // hébergement mal réglé : on le dit clairement plutôt qu'une page blanche
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Ventes plateformes : PHP 8.2 ou plus récent est nécessaire (ici ' . PHP_VERSION . ')'
        . ($manquantes ? ', avec les extensions : ' . implode(', ', $manquantes) . ' (php.ini : lignes extension=…).' : '.'));
}
$src = dirname(__DIR__) . '/src';
foreach (['noyau', 'textes', 'comptes', 'microsoft', 'sharepoint', 'pennylane', 'plateformes', 'demo', 'api'] as $m) {
    require "$src/$m.php";
}
demo_initialiser();  // mode démo : données fictives créées au premier lancement (rien sinon)

// En-têtes de sécurité (OWASP) : rien d'extérieur ne s'exécute, la page ne s'affiche dans aucun cadre, rien ne fuit vers
// d'autres sites.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; "
    . "connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
if (en_https()) {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
}

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$chemin = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (!str_starts_with($chemin, '/api/')) {
    if ($chemin === '/' || $chemin === '/index.html') {
        $hote = strtolower((string)parse_url(origine(), PHP_URL_HOST));
        if (PHP_SAPI === 'cli-server' && in_array($hote, ['127.0.0.1', '[::1]'], true) && isset($_GET['state'])) {  // Microsoft revient toujours sur
            $port = parse_url(origine(), PHP_URL_PORT);                                                             // localhost : même adresse que la
            header('Location: http://localhost' . ($port ? ":$port" : '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 302);  // page, sinon pas de cookie
            exit;
        }
        if (MODE_DEMO && isset($_GET['demo'])) {  // démo seulement : connexion directe au compte de démonstration
            ouvrir_session(['identifiant' => DEMO_IDENTIFIANT, 'nom' => DEMO_NOM]);
            header('Location: /', true, 302);
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile("$src/page.html");
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Introuvable.');
}

// Erreurs fatales (temps maximal dépassé, mémoire) : jamais une page vide, toujours un message lisible par l'interface
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true) && !headers_sent()) {
        error_log('Ventes plateformes (fatale) : ' . $e['message'] . ' ' . $e['file'] . ':' . $e['line']);
        $temps = str_contains($e['message'], 'Maximum execution time');
        http_response_code($temps ? 504 : 500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erreur' => $temps ? 'Microsoft ou Pennylane met trop de temps à répondre : réessayez dans un instant.'
            : 'Erreur du serveur.'], JSON_UNESCAPED_UNICODE);
    }
});
@set_time_limit(120);

try {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ($chemin === '/api/plateformes/importer' ? 12 : 2) * 1024 * 1024) {
        arret(413, 'Envoi trop volumineux.');
    }
    (new Api())->traiter($methode, $chemin);
} catch (Arret $e) {
    json_reponse($e->statut, $e->corps);
} catch (ErreurSaisie $e) {
    json_reponse(422, ['champs' => $e->champs]);
} catch (ErreurEnregistrement $e) {
    error_log('Ventes plateformes : ' . $e);
    json_reponse(500, ['erreur' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Ventes plateformes : ' . $e);
    json_reponse(500, ['erreur' => 'Erreur du serveur.']);
}
