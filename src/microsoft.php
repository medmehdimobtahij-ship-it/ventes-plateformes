<?php
/**
 * Compte Microsoft de chaque utilisateur (Microsoft Graph) : il sert à lire les exports des clients dans le SharePoint du
 * cabinet (sharepoint.php). Lecture seule : l'application ne demande que Files.Read.All.
 *
 * Connexion par la page Microsoft (code d'autorisation + PKCE + nonce, client public, application Microsoft Entra du
 * cabinet : réglage « microsoft_client_id »). Retour sur l'application elle-même quand c'est possible (lancée sur ce PC,
 * en localhost, ou adresse du site déclarée dans l'application Entra : réglage « adresse_site ») ; sinon retour sur
 * http://localhost et la personne colle l'adresse de la page de retour. Le jeton d'identité est vérifié (signature
 * Microsoft, application, organisation, dates, nonce). Jetons chiffrés (AES-256-GCM) dans data/microsoft/<identifiant>.json,
 * renouvelés automatiquement.
 */
declare(strict_types=1);

define('GRAPH', getenv('VP_GRAPH') ?: 'https://graph.microsoft.com/v1.0');  // remplaçables pour les tests (faux Microsoft local)
define('AUTORITE', getenv('VP_AUTORITE') ?: 'https://login.microsoftonline.com/' . rawurlencode(reglage('microsoft_tenant', 'organizations')) . '/oauth2/v2.0');
define('EMETTEUR', getenv('VP_EMETTEUR') ?: 'https://login.microsoftonline.com');  // « iss » des jetons d'identité : EMETTEUR/<tenant>/v2.0
const PORTEES = 'offline_access openid profile User.Read Files.Read.All';
const RETOUR_MICROSOFT = 'http://localhost';
const TENANT_PERSONNEL = '9188040d-6c67-4c5b-b112-36a304b66dad';  // comptes Microsoft personnels (outlook.com, hotmail…), fixe chez Microsoft
const LIEN_CONSENTEMENT = 'https://login.microsoftonline.com/organizations/v2.0/adminconsent?client_id=';

final class ErreurMicrosoft extends Exception
{
    public function __construct(string $message, public ?array $action = null) { parent::__construct($message); }
}

/** Application Microsoft Entra du cabinet (config.json ou VP_MICROSOFT_CLIENT_ID) ; vide tant qu'elle n'est pas déclarée. */
function microsoft_client_id(): string
{
    return trim(reglage('microsoft_client_id'));
}

/** Erreur Microsoft -> [message clair, action] : la mise en place doit pouvoir se faire sans aide. */
function expliquer_microsoft(string $t, string $client_id): array
{
    if (str_contains($t, 'AADSTS65001') || str_contains($t, 'AADSTS90094') || stripos($t, 'consent') !== false) {
        return ['Le cabinet doit autoriser une fois l\'application Microsoft (approbation administrateur).',
            ['type' => 'consentement', 'lien' => lien_consentement($client_id),
                'etapes' => ['Un administrateur Microsoft du cabinet ouvre le lien et accepte.', 'Ensuite, chacun peut connecter son compte.']]];
    }
    if (str_contains($t, 'AADSTS700016')) {
        return ['L\'identifiant d\'application Microsoft est inconnu : vérifiez « microsoft_client_id » dans config.json.', ['type' => 'client_id']];
    }
    if (str_contains($t, 'AADSTS50076') || str_contains($t, 'AADSTS50079')) {  // le cabinet exige la double authentification
        return ['Double authentification exigée par le cabinet : validez dans Microsoft Authenticator, puis recommencez.', ['type' => 'mfa']];
    }
    if (str_contains($t, 'invalid_grant') || str_contains($t, 'AADSTS70008') || str_contains($t, 'AADSTS54005')) {
        return ['Connexion trop ancienne ou déjà utilisée : recommencez « Connecter mon compte Microsoft ».', null];
    }
    if (stripos($t, 'access_denied') !== false || str_contains($t, 'AADSTS65004')) {
        return ['Connexion refusée sur la page Microsoft.', null];
    }
    return [mb_substr(strtok($t, "\n") ?: 'Erreur Microsoft.', 0, 300), null];
}

/** Approbation administrateur, une fois pour tout le cabinet, des autorisations demandées (lecture des fichiers). */
function lien_consentement(string $client_id): string
{
    $portees = implode(' ', array_map(fn($p) => in_array($p, ['offline_access', 'openid', 'profile'], true) ? $p : "https://graph.microsoft.com/$p",
        explode(' ', PORTEES)));
    return LIEN_CONSENTEMENT . rawurlencode($client_id) . '&' . http_build_query(['scope' => $portees, 'redirect_uri' => RETOUR_MICROSOFT], '', '&', PHP_QUERY_RFC3986);
}

/** Adresse où Microsoft renvoie après la connexion : l'application elle-même si elle est sur ce PC (localhost, toujours
 *  accepté par Microsoft) ou si son adresse est déclarée (réglage « adresse_site ») ; sinon http://localhost (adresse à coller). */
function adresse_retour_microsoft(): string
{
    $hote = strtolower((string)parse_url(origine(), PHP_URL_HOST));
    $port = parse_url(origine(), PHP_URL_PORT);
    if (in_array($hote, ['localhost', '127.0.0.1', '[::1]'], true)) {
        return 'http://localhost' . ($port ? ":$port" : '');
    }
    return rtrim(reglage('adresse_site'), '/') ?: RETOUR_MICROSOFT;
}

/** Requête HTTP brute : [code, corps, en-têtes]. $suivre : suit les redirections (téléchargement d'un fichier). */
function http(string $methode, string $url, array $entetes = [], $corps = null, int $delai = 60, bool $suivre = false): array
{
    if (MODE_DEMO) {  // démo hors ligne : aucune requête ne sort, quoi qu'il arrive
        throw new ErreurMicrosoft(demo_message('Microsoft 365 désactivé'));
    }
    $c = curl_init($url);
    $recus = [];
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $delai,
        CURLOPT_HTTPHEADER => $entetes, CURLOPT_USERAGENT => 'VentesPlateformes/1.0', CURLOPT_FOLLOWLOCATION => $suivre, CURLOPT_MAXREDIRS => 5,
        CURLOPT_UNRESTRICTED_AUTH => false,  // le jeton n'est jamais renvoyé à l'adresse de téléchargement
        CURLOPT_HEADERFUNCTION => function ($c, $ligne) use (&$recus) {
            if (str_contains($ligne, ':')) {
                [$k, $v] = explode(':', $ligne, 2);
                $recus[strtolower(trim($k))] = trim($v);
            }
            return strlen($ligne);
        }]);
    if ($corps !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $corps);
    }
    $r = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    curl_close($c);
    if ($r === false) {
        throw new ErreurMicrosoft("Microsoft injoignable ($err).");
    }
    return [$code, (string)$r, $recus];
}

/** base64url -> octets (jetons Microsoft). */
function b64url(string $t): string
{
    return (string)base64_decode(strtr($t, '-_', '+/') . str_repeat('=', (4 - strlen($t) % 4) % 4));
}

/** Clé publique RSA (module n, exposant e d'une clé Microsoft) au format PEM, lisible par openssl. */
function cle_rsa_pem(string $n, string $e): string
{
    $longueur = function (int $l): string {
        $o = ltrim(pack('N', $l), "\0");
        return $l < 128 ? chr($l) : chr(0x80 | strlen($o)) . $o;
    };
    $der = fn(int $balise, string $v) => chr($balise) . $longueur(strlen($v)) . $v;
    $entier = fn(string $o) => $der(0x02, (ord($o[0] ?? "\0") > 0x7f ? "\0" : '') . $o);
    $cle = $der(0x30, $der(0x30, hex2bin('06092a864886f70d0101010500')) . $der(0x03, "\0" . $der(0x30, $entier(b64url($n)) . $entier(b64url($e)))));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($cle), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Clés publiques de Microsoft (signature des jetons d'identité), gardées 24 h ; relues si une clé inconnue apparaît. */
function microsoft_cles(bool $relire = false): array
{
    $chemin = chemin_data('microsoft-cles.json');
    $cache = lire_json($chemin, []);
    if (!$relire && ($cache['lu'] ?? 0) > time() - 86400 && !empty($cache['keys'])) {
        return $cache['keys'];
    }
    [$code, $corps] = http('GET', preg_replace('#/oauth2/v2\.0$#', '', AUTORITE) . '/discovery/v2.0/keys', [], null, 15);
    $cles = json_decode($corps, true)['keys'] ?? null;
    if ($code !== 200 || !is_array($cles)) {
        throw new ErreurMicrosoft('Microsoft injoignable (clés de signature) : réessayez dans un instant.');
    }
    ecrire_json($chemin, ['lu' => time(), 'keys' => $cles], false);
    return $cles;
}

/** Jeton d'identité Microsoft vérifié : signé (RS256) par une clé Microsoft, émis pour l'application par l'organisation du
 *  compte, encore valable, et pour CETTE connexion (nonce). Retourne ses informations ; sinon la connexion est refusée. */
function verifier_id_token(string $jwt, string $client_id, ?string $nonce): array
{
    $p = explode('.', $jwt);
    $entete = count($p) === 3 ? (json_decode(b64url($p[0]), true) ?: []) : [];
    $infos = count($p) === 3 ? (json_decode(b64url($p[1]), true) ?: []) : [];
    if (($entete['alg'] ?? '') !== 'RS256' || empty($entete['kid'])) {
        throw new ErreurMicrosoft('Réponse de Microsoft non signée : connexion refusée.');
    }
    $trouver = fn(array $cles) => array_values(array_filter($cles, fn($k) => ($k['kid'] ?? '') === $entete['kid'] && ($k['kty'] ?? '') === 'RSA'))[0] ?? null;
    $cle = $trouver(microsoft_cles()) ?? $trouver(microsoft_cles(true));
    if (!$cle || openssl_verify("$p[0].$p[1]", b64url($p[2]), cle_rsa_pem((string)$cle['n'], (string)$cle['e']), OPENSSL_ALGO_SHA256) !== 1) {
        throw new ErreurMicrosoft('Signature Microsoft invalide : connexion refusée.');
    }
    $tid = (string)($infos['tid'] ?? '');
    $t = time();
    if (($infos['aud'] ?? '') !== $client_id || $tid === '' || ($infos['iss'] ?? '') !== EMETTEUR . "/$tid/v2.0"
        || (int)($infos['exp'] ?? 0) < $t - 300 || (int)($infos['nbf'] ?? $infos['iat'] ?? 0) > $t + 300
        || ($nonce !== null && !hash_equals($nonce, (string)($infos['nonce'] ?? '')))) {
        throw new ErreurMicrosoft('Jeton Microsoft non valable pour cette application : connexion refusée.');
    }
    return $infos;
}

/** Code d'autorisation (ou jeton de renouvellement) -> jetons Microsoft. */
function microsoft_jetons(array $champs, string $client_id): array
{
    [$code, $corps] = http('POST', AUTORITE . '/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query($champs + ['client_id' => $client_id, 'scope' => PORTEES]));
    $r = json_decode($corps, true) ?: [];
    if ($code >= 400 || empty($r['access_token'])) {
        error_log('Ventes plateformes, réponse Microsoft : ' . mb_substr((string)($r['error_description'] ?? $corps), 0, 300));
        [$m, $a] = expliquer_microsoft(($r['error_description'] ?? '') . ' ' . ($r['error'] ?? $corps), $client_id);
        throw new ErreurMicrosoft($m, $a);
    }
    return $r;
}

/** Page d'autorisation Microsoft (code + PKCE + nonce). Retourne [url, vérificateur, nonce]. */
function microsoft_autorisation(string $client_id, string $retour, string $etat): array
{
    $verificateur = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $nonce = bin2hex(random_bytes(16));
    $url = AUTORITE . '/authorize?' . http_build_query(['client_id' => $client_id, 'response_type' => 'code',
        'redirect_uri' => $retour, 'response_mode' => 'query', 'scope' => PORTEES, 'state' => $etat, 'nonce' => $nonce,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verificateur, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'prompt' => 'select_account']);
    return [$url, $verificateur, $nonce];
}

final class CompteMicrosoft
{
    private ?array $jetons = null;
    public string $client_id;

    public function __construct(public string $identifiant)
    {
        $this->client_id = microsoft_client_id();
        $paquet = lire_json($this->chemin(), null);
        if (is_array($paquet) && isset($paquet['chiffre'])) {
            $clair = dechiffrer($paquet['chiffre'], 'microsoft');
            $this->jetons = $clair ? json_decode($clair, true) : null;
        }
    }

    private function chemin(string $suffixe = ''): string
    {
        return chemin_data('microsoft', $this->identifiant . $suffixe . '.json');
    }

    public function adresse(): string
    {
        return strtolower((string)($this->jetons['adresse'] ?? ''));
    }

    /** Compte Microsoft personnel (outlook.com, hotmail…) : pas d'accès au SharePoint du cabinet. */
    public function personnel(): bool
    {
        $domaine = substr(strrchr($this->adresse(), '@') ?: '', 1);
        return ($this->jetons['tenant'] ?? '') === TENANT_PERSONNEL
            || in_array($domaine, ['outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'live.com', 'live.fr', 'msn.com', 'gmail.com'], true);
    }

    private function sauver(): void
    {
        ecrire_json($this->chemin(), ['chiffre' => chiffrer(json_encode($this->jetons), 'microsoft')]);
    }

    public function deconnecter(): void
    {
        @unlink($this->chemin());
        @unlink($this->chemin('.flux'));
        $this->jetons = null;
    }

    // --- Connexion : code d'autorisation + PKCE ---
    public function demarrer(string $retour = RETOUR_MICROSOFT): array
    {
        if ($this->client_id === '') {
            throw new ErreurMicrosoft('Application Microsoft non déclarée : renseignez « microsoft_client_id » dans config.json '
                . '(identifiant de l\'application Microsoft Entra du cabinet).', ['type' => 'client_id']);
        }
        $etat = bin2hex(random_bytes(16));
        [$url, $verificateur, $nonce] = microsoft_autorisation($this->client_id, $retour, $etat);
        ecrire_json($this->chemin('.flux'), ['etat' => $etat, 'verificateur' => $verificateur, 'nonce' => $nonce, 'expire' => time() + 900,
            'statut' => 'attente', 'retour' => $retour]);
        return ['url' => $url, 'etat' => $etat];
    }

    public function flux(): ?array
    {
        return lire_json($this->chemin('.flux'), null);
    }

    /** Termine la connexion à partir de l'adresse de retour Microsoft (…/?code=…&state=…). */
    public function terminer(string $adresse_retour): string
    {
        parse_str((string)parse_url(trim($adresse_retour), PHP_URL_QUERY), $q);
        $flux = $this->flux();
        if (!$flux || ($q['state'] ?? '') !== $flux['etat'] || $flux['expire'] < time() || ($flux['statut'] ?? '') !== 'attente') {
            throw new ErreurMicrosoft('Adresse non reconnue : recommencez « Connecter mon compte Microsoft » et collez l\'adresse de la page de retour.');
        }
        if (isset($q['error'])) {
            [$m, $a] = expliquer_microsoft(($q['error_description'] ?? '') . ' ' . $q['error'], $this->client_id);
            ecrire_json($this->chemin('.flux'), ['statut' => 'erreur', 'message' => $m, 'action' => $a] + $flux);
            throw new ErreurMicrosoft($m, $a);
        }
        try {
            $r = microsoft_jetons(['grant_type' => 'authorization_code', 'code' => (string)($q['code'] ?? ''),
                'redirect_uri' => $flux['retour'] ?? RETOUR_MICROSOFT, 'code_verifier' => $flux['verificateur']], $this->client_id);
            $infos = verifier_id_token((string)($r['id_token'] ?? ''), $this->client_id, (string)($flux['nonce'] ?? ''));
        } catch (ErreurMicrosoft $e) {  // affiché aussi dans la fenêtre « Connecter » restée ouverte
            ecrire_json($this->chemin('.flux'), ['statut' => 'erreur', 'message' => $e->getMessage(), 'action' => $e->action] + $flux);
            throw $e;
        }
        $this->jetons = ['acces' => $r['access_token'], 'renouvellement' => $r['refresh_token'] ?? '',
            'expire' => time() + (int)($r['expires_in'] ?? 3600) - 120,
            'adresse' => strtolower((string)($infos['preferred_username'] ?? $infos['email'] ?? '')), 'tenant' => (string)$infos['tid']];
        $this->sauver();
        $flux['statut'] = 'connecte';
        ecrire_json($this->chemin('.flux'), $flux);
        return $this->adresse();
    }

    private function renouveler(): void
    {
        $r = microsoft_jetons(['grant_type' => 'refresh_token', 'refresh_token' => $this->jetons['renouvellement']], $this->client_id);
        $this->jetons = ['acces' => $r['access_token'], 'renouvellement' => $r['refresh_token'] ?? $this->jetons['renouvellement'],
            'expire' => time() + (int)($r['expires_in'] ?? 3600) - 120] + $this->jetons;
        $this->sauver();
    }

    private function jeton(): string
    {
        if (!$this->jetons) {
            throw new ErreurMicrosoft('Compte Microsoft non connecté (Paramètres).', ['type' => 'reconnecter']);
        }
        if ($this->jetons['expire'] < time()) {
            $this->renouveler_ou_expliquer();
        }
        return $this->jetons['acces'];
    }

    /** Renouvelle le jeton ; « reconnectez votre compte » seulement si Microsoft refuse vraiment (jeton révoqué…), pas pour
     *  une coupure réseau ou une saturation passagère (le compte reste connecté, on réessaie plus tard). */
    private function renouveler_ou_expliquer(): void
    {
        try {
            $this->renouveler();
        } catch (ErreurMicrosoft $e) {
            $m = $e->getMessage();
            $definitif = str_starts_with($m, 'Connexion trop ancienne') || stripos($m, 'autoriser') !== false
                || stripos($m, "identifiant d'application") !== false || stripos($m, 'Double authentification') !== false;
            if ($definitif) {
                throw new ErreurMicrosoft('Connexion Microsoft expirée : reconnectez votre compte (Paramètres).', ['type' => 'reconnecter']);
            }
            throw new ErreurMicrosoft('Microsoft ne répond pas pour le moment : réessayez dans un instant.');
        }
    }

    // --- Appels Graph ---
    /** $options : params (requête), refus (message si 401/403), service (nom dans les erreurs), brut ([contenu, type]),
     *  suivre (suit la redirection d'un téléchargement). 404 -> null. */
    public function appel(string $methode, string $url, array $options = [])
    {
        $url = str_starts_with($url, 'http') ? $url : GRAPH . $url;
        if (!empty($options['params'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['params'], '', '&', PHP_QUERY_RFC3986);
        }
        $renouvele = false;
        for ($essai = 0; $essai < 4; $essai++) {
            [$code, $r, $h] = http($methode, $url, ['Authorization: Bearer ' . $this->jeton()], null, 120, !empty($options['suivre']));
            // Limitation Microsoft : on attend et on réessaie (lecture seule : rejouer ne change rien)
            if (in_array($code, [429, 503, 504], true) && $essai < 3) {
                sleep(min((int)($h['retry-after'] ?? 3) ?: 3, 10));
                continue;
            }
            if ($code === 401 && !$renouvele && !empty($this->jetons['renouvellement'])) {  // jeton refusé avant son expiration
                $renouvele = true;                                                          // (révoqué, mot de passe changé) : renouvelé une fois
                $this->renouveler_ou_expliquer();
                $essai--;
                continue;
            }
            if ($code === 404) {
                return null;
            }
            if ($code >= 400) {
                $detail = json_decode($r, true)['error']['message'] ?? mb_substr($r, 0, 200);
                if (in_array($code, [401, 403], true) && isset($options['refus'])) {
                    throw new ErreurMicrosoft($options['refus'], ['type' => 'reconnecter']);
                }
                throw new ErreurMicrosoft(($options['service'] ?? 'Microsoft') . " a refusé la demande ($code) : $detail");
            }
            if (!empty($options['brut'])) {
                return [$r, $h['content-type'] ?? 'application/octet-stream'];
            }
            return $r === '' ? [] : (json_decode($r, true) ?? []);
        }
        throw new ErreurMicrosoft('Microsoft est saturé : réessayez dans un instant.');
    }

    /** Toutes les pages d'une liste Graph (@odata.nextLink), au plus $maximum éléments. */
    public function pages(string $url, array $params, int $maximum = 300, array $options = []): array
    {
        $out = [];
        $r = $this->appel('GET', $url, ['params' => $params] + $options);
        while ($r) {
            foreach ($r['value'] ?? [] as $x) {
                $out[] = $x;
                if (count($out) >= $maximum) {
                    return $out;
                }
            }
            $r = !empty($r['@odata.nextLink']) ? $this->appel('GET', $r['@odata.nextLink'], $options) : null;
        }
        return $out;
    }
}
