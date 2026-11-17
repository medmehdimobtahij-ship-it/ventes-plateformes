<?php
/**
 * Ventes plateformes (PHP) : le socle. Réglages, chemins, réponses JSON, verrou, fichiers JSON écrits sans risque, dates,
 * chiffrement. PHP repart de zéro à chaque requête : tout ce qui doit durer vit sur disque, dans data/.
 */
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

define('RACINE', dirname(__DIR__));

/**
 * Réglage du cabinet : variable d'environnement VP_<CLE> (en majuscules), sinon config.json à la racine du projet
 * (voir config.exemple.json), sinon la valeur par défaut. Aucune valeur réelle n'est écrite dans le code.
 */
function reglage(string $cle, string $defaut = ''): string
{
    static $fichier = null;
    $env = getenv('VP_' . strtoupper($cle));
    if ($env !== false && $env !== '') {
        return $env;
    }
    if ($fichier === null) {
        $c = is_file(RACINE . '/config.json') ? json_decode((string)file_get_contents(RACINE . '/config.json'), true) : null;
        $fichier = is_array($c) ? $c : [];
    }
    $v = $fichier[$cle] ?? null;
    return is_bool($v) ? ($v ? '1' : '0') : (is_scalar($v) && (string)$v !== '' ? (string)$v : $defaut);
}

/** Mode démo (variable DEMO=1, ou « demo » dans config.json) : données fictives, aucun appel à Microsoft ni à Pennylane. */
define('MODE_DEMO', in_array(strtolower((string)(getenv('DEMO') ?: reglage('demo'))), ['1', 'true', 'oui'], true));
define('DATA', getenv('VP_DATA') ?: RACINE . (MODE_DEMO ? '/data-demo' : '/data'));  // hors du dossier public : jamais servi par le web

final class Arret extends Exception
{
    /** Réponse HTTP immédiate (ex. 404, 401) levée n'importe où dans le traitement. */
    public function __construct(public int $statut, public array $corps) { parent::__construct((string)($corps['erreur'] ?? '')); }
}

/** Fichier de data/ impossible à écrire (disque plein, droits, fichier tenu par un antivirus) : message montré tel quel. */
final class ErreurEnregistrement extends RuntimeException
{
}

final class ErreurSaisie extends Exception
{
    /** Champs à corriger : [clé du champ => message] (répondu en 422, affiché sous le champ). */
    public function __construct(public array $champs) { parent::__construct(implode(' ; ', $champs)); }
}

function json_reponse(int $statut, $corps): never
{
    http_response_code($statut);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE)
        ?: '{"erreur":"Réponse illisible."}';
    exit;
}

function arret(int $statut, string $erreur, array $plus = []): never
{
    throw new Arret($statut, ['erreur' => $erreur] + $plus);
}

/** Corps JSON de la requête (tableau associatif ; [] si vide ou illisible). */
function corps(): array
{
    static $c = null;
    if ($c === null) {
        $c = json_decode((string)file_get_contents('php://input'), true);
        $c = is_array($c) ? $c : [];
    }
    return $c;
}

function chemin_data(string ...$parties): string
{
    return DATA . '/' . implode('/', $parties);
}

/** Lit un fichier JSON (tableau associatif) ; $defaut s'il manque ou est illisible. */
function lire_json(string $chemin, $defaut = [])
{
    if (!is_file($chemin)) {
        return $defaut;
    }
    $brut = (string)file_get_contents($chemin);
    $brut = str_starts_with($brut, "\xEF\xBB\xBF") ? substr($brut, 3) : $brut;  // BOM ajouté par le Bloc-notes
    $v = json_decode($brut, true);
    if ($v === null && trim($brut) !== '' && trim($brut) !== 'null') {
        // Fichier abîmé : gardé de côté avant que la prochaine écriture ne l'écrase (rien n'est perdu sans trace)
        $copie = $chemin . '.illisible-' . date('Ymd-His');
        if (!glob($chemin . '.illisible-*')) {
            @copy($chemin, $copie);
        }
        error_log("Ventes plateformes : JSON illisible, copie gardée : $copie");
    }
    return $v === null ? $defaut : $v;
}

/** Écrit un fichier d'un coup (fichier temporaire puis renommage) : jamais de fichier à moitié écrit. */
function ecrire_fichier(string $chemin, string $contenu): void
{
    $dossier = dirname($chemin);
    if (!is_dir($dossier)) {
        mkdir($dossier, 0770, true);
    }
    $tmp = $chemin . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $contenu, LOCK_EX) !== strlen($contenu)) {
        @unlink($tmp);
        throw new ErreurEnregistrement("Écriture impossible (disque plein ou droits) : " . basename($chemin));
    }
    // Windows : un antivirus ou OneDrive peut tenir le fichier un instant ; on réessaie avant de remplacer
    for ($essai = 0; $essai < 5; $essai++) {
        if (@rename($tmp, $chemin)) {
            return;
        }
        usleep(100000);
    }
    if (!@unlink($chemin) || !@rename($tmp, $chemin)) {
        @unlink($tmp);
        throw new ErreurEnregistrement("Enregistrement impossible : " . basename($chemin) . ' est utilisé par un autre programme.');
    }
}

function ecrire_json(string $chemin, $valeur, bool $indente = true): void
{
    $json = json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | ($indente ? JSON_PRETTY_PRINT : 0));
    if ($json === false) {
        throw new ErreurEnregistrement('Données impossibles à enregistrer : ' . basename($chemin) . ' (' . json_last_error_msg() . ')');
    }
    ecrire_fichier($chemin, $json);
}

/** Une écriture à la fois (plusieurs collaborateurs peuvent travailler en même temps) : verrou exclusif sur data/.verrou. */
function sous_verrou(callable $fonction)
{
    static $profondeur = 0, $poignee = null;
    if ($profondeur === 0) {
        if (!is_dir(DATA)) {
            mkdir(DATA, 0770, true);
        }
        $poignee = fopen(chemin_data('.verrou'), 'c');
        flock($poignee, LOCK_EX);
    }
    $profondeur++;
    try {
        return $fonction();
    } finally {
        $profondeur--;
        if ($profondeur === 0) {
            flock($poignee, LOCK_UN);
            fclose($poignee);
        }
    }
}

// ---------- Dates ----------
function aujourdhui(): string
{
    return date('Y-m-d');
}

function jjmmaaaa(?string $iso): string
{
    return $iso ? date('d/m/Y', strtotime($iso)) : '';
}

/** Clé du serveur (sessions, chiffrement des jetons), créée au premier lancement. */
function cle_secrete(): string
{
    $chemin = chemin_data('secret.key');
    if (!is_file($chemin)) {  // création exclusive : deux toutes premières requêtes ne fabriquent jamais deux clés
        if (!is_dir(DATA)) {
            @mkdir(DATA, 0770, true);
        }
        $f = @fopen($chemin, 'x');
        if ($f) {
            fwrite($f, bin2hex(random_bytes(32)));
            fclose($f);
        }
    }
    for ($i = 0; $i < 20 && strlen($cle = trim((string)@file_get_contents($chemin))) < 64; $i++) {
        usleep(50000);  // l'autre requête est en train de l'écrire
    }
    return $cle;
}

/** Chiffre / déchiffre (AES-256-GCM, clé dérivée de secret.key) : jetons Microsoft et Pennylane gardés sur disque. */
function chiffrer(string $clair, string $usage): string
{
    $cle = hash('sha256', $usage . ':' . cle_secrete(), true);
    $iv = random_bytes(12);
    $chiffre = openssl_encrypt($clair, 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $chiffre);
}

function dechiffrer(string $paquet, string $usage): ?string
{
    $brut = base64_decode($paquet, true);
    if ($brut === false || strlen($brut) < 29) {
        return null;
    }
    $cle = hash('sha256', $usage . ':' . cle_secrete(), true);
    $clair = openssl_decrypt(substr($brut, 28), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, substr($brut, 0, 12), substr($brut, 12, 16));
    return $clair === false ? null : $clair;
}

function en_https(): bool
{
    return getenv('VP_HTTPS') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Adresse de l'application vue par le navigateur (« https://outils.cabinet-exemple.fr »). */
function origine(): string
{
    $hote = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (en_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $hote);
}

/** Lancé sur ce PC (serveur intégré de PHP) et ouvert sur ce PC même (localhost). L'adresse IP (loopback) ne se falsifie
 *  pas, contrairement au nom d'hôte demandé : un autre PC du réseau, ou un site en ligne, n'est donc jamais « local ». */
function en_local(): bool
{
    $hote = strtolower((string)parse_url(origine(), PHP_URL_HOST));
    return PHP_SAPI === 'cli-server' && in_array($hote, ['localhost', '127.0.0.1', '[::1]'], true)
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** Texte d'un fichier reçu (CSV enregistré par Excel en Windows-1252, BOM…) -> UTF-8 propre. */
function en_utf8(string $texte): string
{
    $texte = str_starts_with($texte, "\xEF\xBB\xBF") ? substr($texte, 3) : $texte;
    if (str_starts_with($texte, "\xFF\xFE") || str_starts_with($texte, "\xFE\xFF")) {  // UTF-16 (« Texte Unicode » d'Excel)
        return (string)mb_convert_encoding(substr($texte, 2), 'UTF-8', str_starts_with($texte, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
    }
    return mb_check_encoding($texte, 'UTF-8') ? $texte : (string)mb_convert_encoding($texte, 'UTF-8', 'Windows-1252');
}

/** « Éléonore Gœtz » -> « Eleonore Goetz », sans l'extension intl (absente de certains hébergements). */
function sans_accents(string $texte): string
{
    static $table = null;
    $table ??= [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Æ' => 'AE', 'Ç' => 'C', 'È' => 'E', 'É' => 'E',
        'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O',
        'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O', 'Œ' => 'OE', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e',
        'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
        'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'ß' => 'ss', '’' => "'", '‘' => "'", '«' => '"', '»' => '"', '–' => '-', '—' => '-', "\u{a0}" => ' ', "\u{202f}" => ' ',
    ];
    $t = strtr($texte, $table);
    return preg_replace('/[^\x20-\x7E]/', '', $t) ?? $t;
}

function majuscule_initiale(string $mot): string
{
    return mb_strtoupper(mb_substr($mot, 0, 1)) . mb_substr($mot, 1);
}
