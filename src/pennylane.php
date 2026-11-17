<?php
/**
 * API Pennylane du cabinet (jeton « cabinet », gardé chiffré dans data/pennylane.json) : liste des dossiers du cabinet,
 * plan comptable, journaux, écritures passées et création des écritures de ventes.
 * Routes : firm/v1/companies ; v2/ledger_accounts, journals, ledger_entries (pagination cursor / limit), me.
 * VP_PENNYLANE : autre adresse (tests, faux Pennylane local).
 */
declare(strict_types=1);

define('PENNYLANE', rtrim(getenv('VP_PENNYLANE') ?: 'https://app.pennylane.com/api/external', '/'));
define('FIRM_V1', PENNYLANE . '/firm/v1');
define('PL_V2', PENNYLANE . '/v2');

class ErreurPennylane extends Exception
{
}

function jeton_pennylane(): string
{
    $p = lire_json(chemin_data('pennylane.json'), []);
    return isset($p['jeton']) ? (dechiffrer($p['jeton'], 'pennylane') ?? '') : '';
}

/** Jeton collé dans Paramètres (vide : retiré). */
function enregistrer_jeton_pennylane(string $jeton): void
{
    sous_verrou(function () use ($jeton) {
        $jeton = trim($jeton);
        ecrire_json(chemin_data('pennylane.json'), $jeton === '' ? [] : ['jeton' => chiffrer($jeton, 'pennylane')]);
        @unlink(chemin_data('pennylane-dossiers.json'));
    });
}

final class Pennylane
{
    private string $jeton;

    public function __construct(?string $jeton = null, private float $pause = 0.25)
    {
        if (MODE_DEMO) {  // démo hors ligne : Pennylane n'est jamais appelé
            throw new ErreurPennylane(demo_message('Pennylane désactivé'));
        }
        $this->jeton = $jeton ?? jeton_pennylane();
        if ($this->jeton === '') {
            throw new ErreurPennylane('Jeton API Pennylane absent : le coller dans Paramètres.');
        }
    }

    /** GET / POST JSON ; X-Company-Id pour les routes v2. Réessaie sur 429 / 5xx (lectures seulement). */
    public function requete(string $methode, string $url, ?string $dossier = null, $corps = null)
    {
        for ($essai = 0; $essai < 4; $essai++) {
            if ($this->pause > 0) {
                usleep((int)($this->pause * 1e6));  // limite de débit de l'API
            }
            $entetes = ['Authorization: Bearer ' . $this->jeton, 'Accept: application/json'];
            if ($dossier) {
                $entetes[] = 'X-Company-Id: ' . $dossier;
            }
            if ($corps !== null) {
                $entetes[] = 'Content-Type: application/json';
            }
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => $entetes, CURLOPT_USERAGENT => 'VentesPlateformes/1.0', CURLOPT_HEADER => true]);
            if ($corps !== null) {
                curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($corps, JSON_UNESCAPED_UNICODE));
            }
            $r = curl_exec($c);
            $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
            $taille_entetes = (int)curl_getinfo($c, CURLINFO_HEADER_SIZE);
            $err = curl_error($c);
            curl_close($c);
            $relisible = $methode === 'GET';  // une écriture n'est jamais rejouée : Pennylane a pu l'enregistrer (doublon)
            if ($r === false) {
                if ($essai < 3 && $relisible) {
                    sleep(2 ** $essai);
                    continue;
                }
                throw new ErreurPennylane($relisible ? "API Pennylane injoignable ($err)."
                    : "Pas de réponse de Pennylane ($err) : vérifiez dans Pennylane si l'écriture a été enregistrée avant de recommencer.");
            }
            $entete = substr($r, 0, $taille_entetes);
            $texte = substr($r, $taille_entetes);
            if (($code === 429 || ($relisible && in_array($code, [500, 502, 503, 504], true))) && $essai < 3) {
                $attente = preg_match('/retry-after:\s*(\d+)/i', $entete, $m) ? (int)$m[1] : 2 ** $essai;
                sleep(min(max($attente, 1), 20));
                continue;
            }
            if ($code === 401) {
                throw new ErreurPennylane('Jeton API Pennylane refusé (401) : vérifier le jeton dans Paramètres.');
            }
            if ($code === 404) {
                return null;
            }
            if ($code >= 400) {
                $detail = json_decode($texte, true);
                $detail = is_array($detail) ? ($detail['error'] ?? $detail['message'] ?? json_encode($detail, JSON_UNESCAPED_UNICODE)) : mb_substr($texte, 0, 200);
                throw new ErreurPennylane("API Pennylane : erreur $code sur " . parse_url($url, PHP_URL_PATH) . ' — '
                    . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)));
            }
            return $texte === '' ? [] : json_decode($texte, true);
        }
        throw new ErreurPennylane('API Pennylane saturée : réessayer dans un instant.');
    }

    public function get(string $url, ?string $dossier = null)
    {
        return $this->requete('GET', $url, $dossier);
    }

    /** Toutes les lignes d'une route v2 paginée par curseur. */
    public function liste(string $chemin, string $dossier, array $params = []): array
    {
        $lignes = [];
        $curseur = null;
        do {
            $q = $params + ['limit' => 100] + ($curseur ? ['cursor' => $curseur] : []);
            $r = $this->get(PL_V2 . "/$chemin?" . http_build_query($q), $dossier) ?? [];
            $lignes = array_merge($lignes, $r['items'] ?? []);
            $curseur = !empty($r['has_more']) ? ($r['next_cursor'] ?? null) : null;
        } while ($curseur);
        return $lignes;
    }

    /** Tous les dossiers du cabinet (firm/v1/companies, toutes les pages) : [{id, name, siren, …}]. */
    public function dossiers_du_cabinet(): array
    {
        $out = [];
        for ($page = 1; $page < 100; $page++) {
            $r = $this->get(FIRM_V1 . "/companies?page=$page&per_page=100") ?? [];
            $out = array_merge($out, $r['items'] ?? []);
            if ($page >= (int)($r['total_pages'] ?? 1)) {
                break;
            }
        }
        return $out;
    }
}

/** Dossiers du cabinet gardés 30 minutes (data/pennylane-dossiers.json) : la liste s'affiche tout de suite. */
function dossiers_cabinet(bool $frais = false): array
{
    $cache = lire_json(chemin_data('pennylane-dossiers.json'), []);
    if (!$frais && isset($cache['t'], $cache['dossiers']) && time() - $cache['t'] < 1800) {
        return $cache['dossiers'];
    }
    try {
        $liste = array_map(fn($c) => ['id' => (string)$c['id'], 'nom' => (string)($c['name'] ?? '')], (new Pennylane(null, 0))->dossiers_du_cabinet());
    } catch (ErreurPennylane $e) {
        if (isset($cache['dossiers']) && !MODE_DEMO) {
            return $cache['dossiers'];  // hors ligne : la dernière liste connue suffit
        }
        throw $e;
    }
    usort($liste, fn($a, $b) => strcasecmp($a['nom'], $b['nom']));
    ecrire_json(chemin_data('pennylane-dossiers.json'), ['t' => time(), 'dossiers' => $liste], false);
    return $liste;
}
