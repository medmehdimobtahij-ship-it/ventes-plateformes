<?php
/**
 * Comptes de connexion des collaborateurs : data/comptes.json, mots de passe hachés (password_hash), jamais en clair.
 *
 * Au premier lancement, les comptes du réglage « comptes » sont créés avec un mot de passe provisoire affiché une seule
 * fois (outils/initialiser.php), à changer dans Paramètres. En mode démo : « demo » / « demo ».
 * Session : cookie HttpOnly, SameSite=Strict (et __Host- en HTTPS), liée au navigateur, fermée après 2 h sans activité.
 */
declare(strict_types=1);

/** Comptes créés au premier lancement : réglage « comptes » (« identifiant:Nom affiché,… »), noms d'exemple par défaut. */
function comptes_initiaux(): array
{
    $out = [];
    foreach (explode(',', reglage('comptes', 'claire:Claire Moreau,paul:Paul Bernard')) as $morceau) {
        [$id, $nom] = array_pad(explode(':', $morceau, 2), 2, '');
        if (trim($id) !== '') {
            $out[strtolower(trim($id))] = trim($nom) ?: trim($id);
        }
    }
    return $out;
}

const LONGUEUR_MINI = 12;
const SESSION_INACTIVITE = 2 * 3600;   // secondes sans rien faire : reconnexion demandée
const SESSION_DUREE_MAX = 12 * 3600;   // une journée de travail au plus, même active

function comptes_lire(): array
{
    return lire_json(chemin_data('comptes.json'), []);
}

function comptes_ecrire(array $comptes): void
{
    ecrire_json(chemin_data('comptes.json'), $comptes);
}

function mot_de_passe_provisoire(): string
{
    $b = fn() => rtrim(strtr(base64_encode(random_bytes(4)), '+/', '-_'), '=');
    return $b() . '-' . $b() . '-' . $b();
}

/** Crée les comptes manquants. Retourne [identifiant => mot de passe provisoire] des comptes créés. */
function comptes_initialiser(): array
{
    $comptes = comptes_lire();
    $crees = [];
    foreach (comptes_initiaux() as $id => $nom) {
        if (!isset($comptes[$id])) {
            $mdp = mot_de_passe_provisoire();
            $comptes[$id] = ['nom' => $nom, 'hash' => password_hash($mdp, PASSWORD_DEFAULT)];
            $crees[$id] = $mdp;
        }
    }
    if ($crees) {
        comptes_ecrire($comptes);
    }
    return $crees;
}

/** Le compte [identifiant, nom] si le mot de passe est bon, sinon null. */
function comptes_verifier(?string $identifiant, ?string $mdp): ?array
{
    $id = strtolower(trim((string)$identifiant));
    $c = comptes_lire()[$id] ?? null;
    if ($c && ($c['hash'] ?? '') !== '' && $mdp !== null && $mdp !== '' && password_verify($mdp, $c['hash'])) {
        return ['identifiant' => $id, 'nom' => $c['nom']];
    }
    return null;
}

/** Règle de la CNIL (délibération 2022-100) : 12 caractères avec majuscules, minuscules, chiffres et caractères spéciaux,
 *  ou 14 caractères avec au moins trois de ces sortes, ou une phrase de passe de 7 mots. Ni l'identifiant ni le nom dedans. */
function mot_de_passe_refuse(string $mdp, string $identifiant = '', string $nom = ''): ?string
{
    $sortes = (int)preg_match('/\p{Lu}/u', $mdp) + (int)preg_match('/\p{Ll}/u', $mdp) + (int)preg_match('/\d/', $mdp)
        + (int)preg_match('/[^\p{L}\d]/u', $mdp);
    $mots = count(preg_split('/[\s\-_.]+/u', trim($mdp), -1, PREG_SPLIT_NO_EMPTY));
    $l = mb_strlen($mdp);
    if (!(($l >= LONGUEUR_MINI && $sortes === 4) || ($l >= 14 && $sortes >= 3) || ($mots >= 7 && $l >= 20))) {
        return 'Au moins 12 caractères avec majuscule, minuscule, chiffre et caractère spécial (ou 14 caractères sans caractère spécial).';
    }
    $bas = mb_strtolower(sans_accents($mdp));
    foreach (array_merge([$identifiant], preg_split('/\s+/', $nom)) as $morceau) {
        $m = mb_strtolower(sans_accents((string)$morceau));
        if (mb_strlen($m) >= 4 && str_contains($bas, $m)) {
            return 'Le mot de passe ne doit pas contenir votre nom ni votre identifiant.';
        }
    }
    return null;
}

function comptes_changer(string $identifiant, ?string $nouveau): void
{
    $comptes = comptes_lire();
    if ($refus = mot_de_passe_refuse((string)$nouveau, $identifiant, (string)($comptes[$identifiant]['nom'] ?? ''))) {
        throw new InvalidArgumentException($refus);
    }
    $comptes[$identifiant]['hash'] = password_hash((string)$nouveau, PASSWORD_DEFAULT);
    $comptes[$identifiant]['generation'] = ($comptes[$identifiant]['generation'] ?? 0) + 1;  // ses autres sessions sont fermées
    comptes_ecrire($comptes);
}

// ---------- Session (cookie protégé : HttpOnly, SameSite=Strict, Secure et préfixe __Host- en HTTPS) ----------
function nom_session(): string
{
    return en_https() ? '__Host-ventes_plateformes' : 'ventes_plateformes';
}

function session_demarrer(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dossier = chemin_data('sessions');
    if (!is_dir($dossier)) {
        mkdir($dossier, 0770, true);
    }
    session_save_path($dossier);
    session_name(nom_session());
    ini_set('session.gc_maxlifetime', (string)SESSION_DUREE_MAX);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    session_set_cookie_params(['lifetime' => SESSION_DUREE_MAX, 'path' => '/', 'secure' => en_https(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

/** Empreinte du navigateur : un cookie de session volé ne sert pas depuis un autre navigateur. */
function empreinte_navigateur(): string
{
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . cle_secrete());
}

/** Ouvre la session d'un compte (nouvel identifiant de session : pas de fixation). */
function ouvrir_session(array $compte): void
{
    session_demarrer();
    session_regenerate_id(true);
    $t = time();
    $_SESSION = ['identifiant' => $compte['identifiant'], 'nom' => $compte['nom'], 'debut' => $t, 'vu' => $t,
        'empreinte' => empreinte_navigateur(), 'generation' => comptes_lire()[$compte['identifiant']]['generation'] ?? 0];
    session_write_close();
}

/** Personne connectée, ou 401. Les POST doivent venir de l'interface (en-tête X-Interface : aucun autre site ne peut
 *  l'envoyer sans y être autorisé). $activite : la requête compte comme une action de la personne. */
function exiger_connexion(bool $activite = true): array
{
    session_demarrer();
    $s = $_SESSION;
    $c = comptes_lire()[$s['identifiant'] ?? ''] ?? null;
    $t = time();
    if (!$c || empty($s['debut']) || $t - $s['debut'] > SESSION_DUREE_MAX || $t - ($s['vu'] ?? 0) > SESSION_INACTIVITE
        || !hash_equals($s['empreinte'] ?? '', empreinte_navigateur()) || ($s['generation'] ?? 0) !== ($c['generation'] ?? 0)) {
        $_SESSION = [];
        session_destroy();
        arret(401, 'Session expirée : reconnectez-vous.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_INTERFACE'] ?? '') !== '1') {
        arret(403, 'Requête refusée.');
    }
    if ($activite && $t - $s['vu'] > 60) {
        $_SESSION['vu'] = $t;
    }
    $moi = ['identifiant' => $s['identifiant'], 'nom' => $s['nom']];
    session_write_close();  // session lue : libérée aussitôt, les requêtes d'une même personne ne s'attendent pas
    return $moi;
}

/** Anti-devinette : 5 connexions ratées en 5 minutes depuis une même adresse bloquent 5 minutes ; 10 ratées sur un même
 *  compte en 15 minutes (quelle que soit l'adresse) bloquent ce compte 15 minutes. */
function essais_trop_nombreux(string $ip, string $compte = ''): bool
{
    $essais = lire_json(chemin_data('essais.json'), []);
    $recents = fn(string $k, int $duree) => count(array_filter($essais[$k] ?? [], fn($t) => time() - $t < $duree));
    return $recents($ip, 300) >= 5 || ($compte !== '' && $recents("compte:$compte", 900) >= 10);
}

function noter_essai_rate(string $ip, string $compte = ''): void
{
    sous_verrou(function () use ($ip, $compte) {
        $essais = lire_json(chemin_data('essais.json'), []);
        foreach ($essais as $k => $ts) {  // ménage : on ne garde que les 15 dernières minutes
            $essais[$k] = array_values(array_filter($ts, fn($t) => time() - $t < 900));
            if (!$essais[$k]) {
                unset($essais[$k]);
            }
        }
        $essais[$ip][] = time();
        if ($compte !== '' && isset(comptes_lire()[$compte])) {
            $essais["compte:$compte"][] = time();
        }
        ecrire_json(chemin_data('essais.json'), $essais, false);
    });
}
