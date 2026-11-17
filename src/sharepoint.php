<?php
/**
 * SharePoint du cabinet (la bibliothèque de documents où sont rangés les dossiers des clients), lu par Microsoft Graph avec
 * le compte Microsoft de la personne connectée : rien à installer sur les PC. Lecture seule.
 *
 * Réglages (config.json) : « sharepoint » = adresse web de la bibliothèque, « sharepoint_clients » = dossier des clients
 * dans la bibliothèque, « sharepoint_exports » = chemin des pièces d'un client ({client}, {annee}). Les exports sont
 * cherchés sous ce chemin, dans tout sous-dossier dont le nom contient « PLATEFORMES ».
 */
declare(strict_types=1);

define('SP_BIBLIOTHEQUE', rtrim(reglage('sharepoint'), '/'));  // vide : pas de SharePoint, les exports se déposent à la main
define('SP_CLIENTS', trim(reglage('sharepoint_clients', 'Dossiers/Comptabilite'), '/'));
define('SP_EXPORTS', trim(reglage('sharepoint_exports', '{client}/{annee}/Comptable'), '/'));
const SP_REFUS = "Votre compte Microsoft n'a pas accès aux fichiers du cabinet : reconnectez-le (Paramètres) avec votre adresse du cabinet "
    . "et acceptez l'accès aux fichiers.";

/** Compte qui peut ouvrir le SharePoint du cabinet : sinon un message qui dit quoi faire. */
function sp_verifier_compte(CompteMicrosoft $b): void
{
    if ($b->adresse() === '') {
        throw new ErreurMicrosoft('Connectez votre compte Microsoft du cabinet (Paramètres) : les fichiers du cabinet (SharePoint) se lisent par lui.',
            ['type' => 'reconnecter']);
    }
    if ($b->personnel()) {
        throw new ErreurMicrosoft("Le compte connecté ({$b->adresse()}) est un compte Microsoft personnel : il n'a pas accès au SharePoint du cabinet. "
            . 'Dans Paramètres, déconnectez-le puis connectez votre adresse du cabinet.', ['type' => 'reconnecter']);
    }
}

/** Chemin des pièces d'un client pour une année, sous le dossier des clients. */
function sp_chemin_exports(string $client, int $annee): string
{
    return SP_CLIENTS . '/' . strtr(SP_EXPORTS, ['{client}' => $client, '{annee}' => (string)$annee]);
}

/** Adresse web d'un dossier de la bibliothèque (espaces encodés, comme dans le navigateur). */
function sp_adresse(string $chemin): string
{
    return str_replace('%3A', ':', implode('/', array_map('rawurlencode', explode('/', SP_BIBLIOTHEQUE . '/' . $chemin))));
}

/** Dossier SharePoint [drive, id] retrouvé par son adresse (API « shares »), gardé en mémoire dans data/sharepoint.json. */
function sp_dossier(CompteMicrosoft $b, string $chemin, bool $rafraichir = false): array
{
    $cache = lire_json(chemin_data('sharepoint.json'), []);
    if (!$rafraichir && isset($cache[$chemin]['drive'], $cache[$chemin]['id'])) {
        return $cache[$chemin];
    }
    foreach ([sp_adresse($chemin), SP_BIBLIOTHEQUE . '/' . $chemin] as $adresse) {
        $code = 'u!' . rtrim(strtr(base64_encode($adresse), '+/', '-_'), '=');
        $r = $b->appel('GET', "/shares/$code/driveItem", ['params' => ['$select' => 'id,name,folder,parentReference'], 'refus' => SP_REFUS,
            'service' => 'SharePoint']);
        if ($r && isset($r['folder'], $r['parentReference']['driveId'])) {
            $cache[$chemin] = ['drive' => $r['parentReference']['driveId'], 'id' => $r['id']];
            ecrire_json(chemin_data('sharepoint.json'), $cache);
            return $cache[$chemin];
        }
    }
    throw new ErreurMicrosoft('Dossier introuvable dans SharePoint : ' . str_replace('/', ' › ', $chemin) . '. Votre compte Microsoft y a-t-il accès ?');
}
