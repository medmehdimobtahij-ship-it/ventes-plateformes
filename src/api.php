<?php
/**
 * L'API JSON (interface : public/app.js et public/plateformes.js). Toute l'API exige une session, sauf la connexion.
 *
 * Les exports sont lus dans le SharePoint du cabinet avec le compte Microsoft de la personne connectée (ou déposés à la
 * main) ; les écritures partent dans Pennylane avec le jeton API du cabinet. En mode démo, ni Microsoft ni Pennylane ne
 * sont appelés : le SharePoint et l'envoi sont simulés dans data-demo/ (demo.php).
 */
declare(strict_types=1);

final class Api
{
    private array $moi = [];

    public function traiter(string $methode, string $chemin): void
    {
        if ($chemin === '/api/connexion/options' && $methode === 'GET') {
            json_reponse(200, ['demo' => MODE_DEMO]);
        }
        if (in_array($chemin, ['/api/connexion', '/api/deconnexion'], true) && ($_SERVER['HTTP_X_INTERFACE'] ?? '') !== '1') {
            arret(403, 'Requête refusée.');  // seulement depuis l'interface (aucun autre site ne peut connecter ou déconnecter)
        }
        if ($chemin === '/api/connexion' && $methode === 'POST') {
            json_reponse(...$this->connexion());
        }
        if ($chemin === '/api/deconnexion' && $methode === 'POST') {
            session_demarrer();
            $_SESSION = [];
            session_destroy();
            json_reponse(200, ['ok' => true]);
        }
        $this->moi = exiger_connexion(($_SERVER['HTTP_X_INTERFACE_AUTO'] ?? '') !== '1');  // relectures automatiques : pas une activité
        $routes = [
            ['GET', '/api/moi', 'moi'], ['POST', '/api/mot-de-passe', 'mot_de_passe'],
            ['GET', '/api/parametres', 'parametres'], ['POST', '/api/parametres/comptes', 'parametres_comptes'],
            ['POST', '/api/pennylane/jeton', 'pennylane_jeton'],
            ['GET', '/api/microsoft', 'microsoft_etat'], ['POST', '/api/microsoft/connexion', 'microsoft_connexion'],
            ['POST', '/api/microsoft/retour', 'microsoft_retour'], ['POST', '/api/microsoft/deconnexion', 'microsoft_deconnexion'],
            ['GET', '/api/plateformes', 'plateformes'], ['GET', '/api/plateformes/cabinet', 'plateformes_cabinet'],
            ['POST', '/api/plateformes/dossiers', 'plateformes_dossiers'], ['POST', '/api/plateformes/dossiers/import', 'plateformes_dossiers_import'], ['POST', '/api/plateformes/mois', 'plateformes_mois'],
            ['POST', '/api/plateformes/exports', 'plateformes_exports'], ['POST', '/api/plateformes/importer', 'plateformes_importer'],
            ['POST', '/api/plateformes/schema/lire', 'plateformes_schema_lire'], ['POST', '/api/plateformes/schema', 'plateformes_schema'],
            ['POST', '/api/plateformes/envoyer', 'plateformes_envoyer'],
        ];
        $connue = false;
        foreach ($routes as [$verbe, $motif, $nom]) {
            if ($motif === $chemin) {
                $connue = true;
                if ($verbe === $methode) {
                    $r = $this->$nom();
                    json_reponse(...(is_array($r) && isset($r[0], $r[1]) && is_int($r[0]) && count($r) === 2 ? $r : [200, $r]));
                }
            }
        }
        arret($connue ? 405 : 404, $connue ? 'Méthode non permise.' : 'Introuvable.');
    }

    /** Réponse des fonctions qui appelleraient Microsoft ou Pennylane, en mode démo. */
    private function refus_demo(string $quoi): array
    {
        return [409, ['erreur' => demo_message($quoi), 'demo' => true]];
    }

    // ---------- Connexion et compte ----------
    private function connexion(): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $d = corps();
        $id = strtolower(trim((string)($d['identifiant'] ?? '')));
        if (essais_trop_nombreux($ip, $id)) {
            return [429, ['erreur' => 'Trop d\'essais : réessayez dans 15 minutes.']];
        }
        $compte = comptes_verifier($id, isset($d['mot_de_passe']) ? (string)$d['mot_de_passe'] : null);
        if (!$compte) {
            noter_essai_rate($ip, $id);
            return [401, ['erreur' => 'Identifiant ou mot de passe incorrect.']];
        }
        ouvrir_session($compte);
        return [200, $compte + ['demo' => MODE_DEMO]];
    }

    private function moi(): array
    {
        return $this->moi + ['demo' => MODE_DEMO];
    }

    private function mot_de_passe(): array
    {
        if (MODE_DEMO) {
            return [409, ['erreur' => 'Mode démo : le mot de passe du compte de démonstration reste « demo ».']];
        }
        $d = corps();
        if (!comptes_verifier($this->moi['identifiant'], isset($d['ancien']) ? (string)$d['ancien'] : null)) {
            throw new ErreurSaisie(['ancien' => 'Mot de passe actuel incorrect.']);
        }
        try {
            sous_verrou(fn() => comptes_changer($this->moi['identifiant'], isset($d['nouveau']) ? (string)$d['nouveau'] : null));
        } catch (InvalidArgumentException $e) {
            throw new ErreurSaisie(['nouveau' => $e->getMessage()]);
        }
        session_demarrer();  // les autres sessions sont fermées (nouvelle génération) ; celle-ci reste ouverte
        $_SESSION['generation'] = comptes_lire()[$this->moi['identifiant']]['generation'];
        session_write_close();
        return ['ok' => true];
    }

    /** Pennylane relié : « demo » (simulé), « connecte » (jeton enregistré) ou « » (non configuré : fichier d'écritures). */
    private function etat_pennylane(): string
    {
        return MODE_DEMO ? 'demo' : (jeton_pennylane() !== '' ? 'connecte' : '');
    }

    /** Réglages visibles dans Paramètres : état des intégrations (jeton masqué), où sont cherchés les exports, journal et
     *  comptes par défaut. */
    private function parametres(): array
    {
        $j = MODE_DEMO ? '' : jeton_pennylane();
        $defaut = pf_schema_defaut();
        $comptes = ['client_uber_eats' => $defaut['plateformes']['Uber Eats']['client'], 'client_deliveroo' => $defaut['plateformes']['Deliveroo']['client']];
        foreach (PF_TAUX_SCHEMA as $t) {
            $comptes["ventes_$t"] = $defaut['ventes'][$t];
            $comptes["tva_$t"] = $defaut['tva'][$t];
        }
        return ['demo' => MODE_DEMO, 'pennylane' => $this->etat_pennylane(), 'jeton' => $j === '' ? '' : substr($j, 0, 4) . '…' . substr($j, -4),
            'microsoft' => microsoft_client_id() !== '' || MODE_DEMO, 'sharepoint' => SP_BIBLIOTHEQUE,
            'exports' => str_replace('/', ' › ', SP_CLIENTS . '/' . SP_EXPORTS) . ' › …PLATEFORMES…',
            'journal' => PF_JOURNAL, 'comptes' => $comptes, 'pcg' => PF_COMPTES_PCG];
    }

    /** Code journal et comptes par défaut, modifiés dans Paramètres (vide : retour à config.json ou au plan comptable général). */
    private function parametres_comptes(): array
    {
        $d = corps();
        sous_verrou(function () use ($d) {
            $p = pf_parametres();
            if (array_key_exists('journal', $d)) {
                $j = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$d['journal']));
                if ($j === '') {
                    unset($p['journal']);
                } else {
                    $p['journal'] = $j;
                }
            }
            foreach (array_keys(PF_COMPTES_PCG) as $k) {
                if (array_key_exists($k, (array)($d['comptes'] ?? []))) {
                    $p['comptes'][$k] = preg_replace('/[^A-Za-z0-9]/', '', (string)$d['comptes'][$k]);
                }
            }
            ecrire_json(chemin_data('parametres.json'), $p);
        });
        return ['ok' => true];
    }

    private function pennylane_jeton(): array
    {
        if (MODE_DEMO) {
            return $this->refus_demo('Pennylane désactivé');
        }
        enregistrer_jeton_pennylane((string)(corps()['jeton'] ?? ''));
        return ['ok' => true];
    }

    // ---------- Compte Microsoft (lecture du SharePoint) ----------
    private function compte(): CompteMicrosoft
    {
        return new CompteMicrosoft($this->moi['identifiant']);
    }

    private function microsoft_etat(): array
    {
        $b = $this->compte();
        $f = $b->flux();
        $cx = $f && ($f['expire'] ?? 0) > time() - 3600 ? ['etat' => $f['statut'] ?? 'attente', 'message' => $f['message'] ?? null,
            'action' => $f['action'] ?? null] : null;
        return ['adresse' => $b->adresse(), 'connexion' => $cx, 'configure' => $b->client_id !== '', 'demo' => MODE_DEMO];
    }

    private function microsoft_connexion(): array
    {
        if (MODE_DEMO) {
            return $this->refus_demo('connexion à Microsoft 365 désactivée');
        }
        $retour = adresse_retour_microsoft();
        try {
            $cx = $this->compte()->demarrer($retour);
        } catch (ErreurMicrosoft $e) {
            return [409, ['erreur' => $e->getMessage(), 'action' => $e->action]];
        }
        return ['url' => $cx['url'], 'automatique' => $retour !== RETOUR_MICROSOFT];
    }

    /** Connexion terminée : retour Microsoft sur le site, ou adresse collée par la personne (page de retour Microsoft). */
    private function microsoft_retour(): array
    {
        if (MODE_DEMO) {
            return $this->refus_demo('connexion à Microsoft 365 désactivée');
        }
        try {
            return ['ok' => true, 'adresse' => $this->compte()->terminer((string)(corps()['adresse'] ?? ''))];
        } catch (ErreurMicrosoft $e) {
            return [409, ['erreur' => $e->getMessage(), 'action' => $e->action]];
        }
    }

    private function microsoft_deconnexion(): array
    {
        $this->compte()->deconnecter();
        return ['ok' => true];
    }

    // ---------- Ventes plateformes ----------
    private function plateformes(): array
    {
        return ['dossiers' => pf_dossiers(), 'schemas' => pf_schemas(), 'defaut' => pf_schema_defaut(), 'journal' => PF_JOURNAL, 'demo' => MODE_DEMO,
            'pennylane' => $this->etat_pennylane(),
            'mois' => array_map(fn($m) => ['cle' => sprintf('%04d-%02d', ...$m), 'nom' => majuscule_initiale(pf_mois_nom($m)),
                'date' => jjmmaaaa(pf_dernier_jour(...$m))], pf_liste_mois())];
    }

    private function plateformes_cabinet(): array
    {
        if (MODE_DEMO) {
            return $this->refus_demo('liste des dossiers Pennylane désactivée');
        }
        try {
            return ['dossiers' => dossiers_cabinet()];
        } catch (ErreurPennylane $e) {
            return [409, ['erreur' => $e->getMessage()]];
        }
    }

    private function plateformes_dossiers(): array
    {
        $d = corps();
        $id = pf_id($d['id'] ?? '') ?: (trim((string)($d['nom'] ?? '')) !== '' ? 'local-' . bin2hex(random_bytes(4)) : '');
        if ($id === '') {
            arret(422, 'Dossier à choisir.');
        }
        $retirer = ($d['action'] ?? '') === 'retirer';
        sous_verrou(function () use ($d, $id, $retirer) {
            $dossiers = pf_dossiers();
            if ($retirer) {
                unset($dossiers[$id]);
            } else {
                $dossiers[$id] = trim((string)($d['nom'] ?? '')) ?: "Dossier $id";
            }
            ecrire_json(pf_chemin('dossiers.json'), $dossiers);
        });
        if (!$retirer && !isset(pf_schemas()[$id]) && ctype_digit($id) && $this->etat_pennylane() === 'connecte') {
            try {  // nouveau dossier : schéma lu tout seul dans Pennylane
                $this->schema_enregistrer($id, pf_schema_automatique(new Pennylane(), $id));
            } catch (ErreurPennylane $e) {
                // lu plus tard (« Modifier les comptes › Relire dans Pennylane »)
            }
        }
        return $this->plateformes();
    }

    /** Liste de dossiers importée d'un fichier (CSV ou Excel) : ajoutés, ou renommés s'ils existent déjà (même identifiant
     *  Pennylane, ou même nom) ; comptes du fichier gardés comme schéma du dossier. */
    private function plateformes_dossiers_import(): array
    {
        $f = (array)(corps()['fichier'] ?? []);
        $contenu = base64_decode((string)($f['contenu'] ?? ''), true);
        try {
            [$lignes, $erreurs] = pf_lire_dossiers((string)($f['nom'] ?? ''), $contenu === false ? '' : $contenu);
        } catch (Throwable $e) {
            return [422, ['erreur' => 'Fichier illisible : ' . $e->getMessage()]];
        }
        if (!$lignes) {
            return [422, ['erreur' => 'Aucun dossier dans ce fichier (colonne « Nom » attendue).' . ($erreurs ? ' ' . implode(' ', $erreurs) : '')]];
        }
        $n = sous_verrou(function () use ($lignes) {
            $dossiers = pf_dossiers();
            $schemas = pf_schemas();
            $par_nom = array_flip(array_map(fn($x) => mb_strtolower($x), $dossiers));
            foreach ($lignes as [$id, $nom, $schema]) {
                $id = $id ?: ($par_nom[mb_strtolower($nom)] ?? 'local-' . bin2hex(random_bytes(4)));
                $dossiers[$id] = $nom;
                if ($schema) {
                    $schemas[$id] = $schema;
                }
            }
            ecrire_json(pf_chemin('dossiers.json'), $dossiers);
            ecrire_json(pf_chemin('schemas.json'), $schemas);
            return count($lignes);
        });
        return $this->plateformes() + ['importes' => $n, 'erreurs' => $erreurs];
    }

    private function schema_enregistrer(string $id, array $schema): void
    {
        if ($id === '') {
            arret(422, 'Dossier à choisir.');
        }
        sous_verrou(function () use ($id, $schema) {
            $s = pf_schemas();
            $s[$id] = $schema;
            ecrire_json(pf_chemin('schemas.json'), $s);
        });
    }

    /** Mois du dossier avec ce qui est déjà passé dans Pennylane (relu à chaque fois : jamais de mémoire du logiciel). */
    private function plateformes_mois(): array
    {
        $dossier = pf_id(corps()['dossier'] ?? '');
        $mois = pf_liste_mois();
        $erreur = $info = '';
        $passes = [];
        try {
            if (MODE_DEMO) {
                $passes = demo_mois_passes($dossier);
            } elseif ($this->etat_pennylane() === '' || !ctype_digit($dossier)) {  // sans Pennylane : rien à relire, tous les mois proposés
                $info = 'Pennylane non relié : les mois déjà passés ne sont pas relus, à vous de choisir le bon.';
            } else {
                $passes = pf_mois_passes(new Pennylane(null, 0.1), $dossier, sprintf('%04d-%02d-01', ...end($mois)));
            }
        } catch (ErreurPennylane $e) {
            $erreur = 'Mois déjà passés non lus : ' . $e->getMessage();
        }
        $connu = !$erreur && !$info;
        $prochain = $connu ? pf_mois_propose($mois, $passes) : ($mois[1] ?? $mois[0]);
        if ($connu) {  // rien après le prochain mois à passer
            $mois = array_slice($mois, array_search($prochain, $mois, true));
        }
        $toutes = ['Uber Eats', 'Deliveroo'];
        $liste = [];
        foreach ($mois as $m) {
            $cle = sprintf('%04d-%02d', ...$m);
            $fait = $passes[$cle] ?? [];
            $reste = array_values(array_diff($toutes, $fait));
            $etat = !$connu ? '' : (!$reste ? '✓ passée' : ($fait ? '✓ ' . implode(', ', $fait) . ' passée · ' . implode(', ', $reste) . ' à passer' : 'à passer'));
            if ($m === pf_liste_mois()[0]) {
                $etat = ($etat !== '' ? "$etat · " : '') . 'mois en cours';
            }
            $liste[] = ['cle' => $cle, 'nom' => majuscule_initiale(pf_mois_nom($m)), 'etat' => $etat, 'fait' => !$reste && $connu,
                'date' => jjmmaaaa(pf_dernier_jour(...$m))];
        }
        return ['mois' => $liste, 'propose' => sprintf('%04d-%02d', ...$prochain), 'erreur' => $erreur, 'info' => $info];
    }

    /** Export du mois : cherché dans le dossier « …PLATEFORMES… » du client (SharePoint du cabinet). */
    private function plateformes_exports(): array
    {
        @set_time_limit(180);
        $d = corps();
        $dossier = pf_id($d['dossier'] ?? '');
        [$annee, $mois] = array_map('intval', explode('-', (string)($d['mois'] ?? '0-0')) + [0, 0]);
        if ($mois < 1 || $mois > 12 || $annee < 2000) {
            return [422, ['erreur' => 'Mois à choisir.']];
        }
        $nom = pf_dossiers()[$dossier] ?? '';
        $candidats = [];
        try {
            if (MODE_DEMO) {
                $candidats = demo_exports($nom, $annee);
            } else {
                $b = $this->compte();
                if (SP_BIBLIOTHEQUE === '') {
                    return ['trouve' => null, 'message' => "SharePoint non configuré (réglage « sharepoint ») : déposez l'export à la main."];
                }
                if ($b->adresse() === '') {
                    return ['trouve' => null, 'message' => 'Compte Microsoft non connecté : déposez l\'export à la main (ou connectez votre compte dans Paramètres).'];
                }
                sp_verifier_compte($b);
                $candidats = pf_exports_sharepoint($b, $nom, $annee);
            }
            foreach ($candidats as $f) {
                $couverts = pf_couverture(pf_lire_export($f['nom'], $this->telecharger($f)));
                if (in_array([$annee, $mois], $couverts, true)) {
                    $periode = count($couverts) > 1 ? pf_mois_nom($couverts[0]) . ' → ' . pf_mois_nom(end($couverts)) : pf_mois_nom($couverts[0]);
                    return ['trouve' => $f + ['periode' => $periode, 'ou' => 'SharePoint › ' . $nom . ' › … › ' . basename(dirname($f['chemin']))], 'message' => ''];
                }
            }
        } catch (ErreurMicrosoft | RuntimeException $e) {
            return ['trouve' => null, 'message' => 'Recherche dans le SharePoint impossible : ' . $e->getMessage()];
        }
        return ['trouve' => null, 'message' => 'Aucun export ' . (preg_match('/^[aeiouyâéèê]/u', MOIS[$mois - 1]) ? "d'" : 'de ') . MOIS[$mois - 1] . " $annee trouvé dans le dossier "
            . "plateformes de $nom" . ($candidats ? '' : ' (dossier du client introuvable dans SharePoint)') . '. Déposez le fichier à la main.'];
    }

    /** Contenu d'un export du SharePoint (simulé en mode démo). */
    private function telecharger(array $f): string
    {
        return MODE_DEMO ? demo_telecharger($f) : pf_telecharger($this->compte(), $f);
    }

    private function etat_plateformes_fichier(): string
    {
        return pf_chemin('en-cours', $this->moi['identifiant'] . '.json');
    }

    /** Lit l'export, construit l'écriture, fait les contrôles (dans Pennylane aussi). Anomalie bloquante : on s'arrête. */
    private function plateformes_importer(): array
    {
        @set_time_limit(180);
        $d = corps();
        $dossier = pf_id($d['dossier'] ?? '');
        $mois = array_map('intval', explode('-', (string)($d['mois'] ?? '0-0')));
        try {
            if (!empty($d['fichier'])) {
                $nom = (string)($d['fichier']['nom'] ?? '');
                $contenu = base64_decode((string)($d['fichier']['contenu'] ?? ''), true);
                if ($contenu === false) {
                    throw new RuntimeException('fichier illisible');
                }
            } else {
                $f = (array)($d['sharepoint'] ?? []);
                $nom = (string)($f['nom'] ?? '');
                $contenu = $this->telecharger($f);
            }
            $analyse = pf_analyser(pf_lire_export($nom, $contenu), $mois);
        } catch (Throwable $e) {
            return ['bloquants' => ['Lecture du fichier impossible : ' . $e->getMessage()]];
        }
        $bloquants = array_column(array_filter($analyse['controles'], fn($c) => $c['niveau'] === 'bloquant'), 'message');
        if ($bloquants) {
            return ['bloquants' => $bloquants];
        }
        [$ecritures, $ctr] = pf_construire_ecritures($analyse, pf_schema($dossier));
        $bloquants = array_column(array_filter($ctr, fn($c) => $c['niveau'] === 'bloquant'), 'message');
        if ($bloquants) {
            return ['bloquants' => $bloquants, 'comptes' => true];
        }
        $remarques = array_column(array_filter($analyse['controles'], fn($c) => $c['niveau'] === 'attention'), 'message');
        $pennylane = $this->etat_pennylane();
        $comptes = $absents = $deja = [];
        $journal = null;
        $scopes = ['*'];
        if ($pennylane === 'demo') {  // Pennylane simulé : seul « déjà passée » est vérifié
            $passes = demo_mois_passes($dossier)[sprintf('%04d-%02d', ...$mois)] ?? [];
            $deja = array_values(array_map(fn($e) => $e['piece'], array_filter($ecritures, fn($e) => in_array($e['plateforme'], $passes, true))));
            $journal = 'demo';
            $remarques[] = 'Mode démo : vérifications dans Pennylane (plan comptable, journal, droits du jeton) désactivées ici.';
        } elseif ($pennylane === '' || !ctype_digit($dossier)) {  // sans Pennylane : l'écriture part en fichier, à importer à la main
            $pennylane = '';
            $journal = PF_JOURNAL;
        } else {
            try {  // vérifications dans Pennylane : comptes du plan, écriture déjà passée, journal des ventes, droit d'écrire
                $pl = new Pennylane(null, 0.15);
                $comptes = pf_comptes_du_dossier($pl, $dossier);
                $deja = array_values(array_map(fn($e) => $e['piece'], array_filter($ecritures, fn($e) => pf_piece_existante($pl, $dossier, $e['piece'], $e['date']))));
                $journal = null;
                foreach ($pl->liste('journals', $dossier) as $j) {
                    if (strtoupper((string)($j['code'] ?? '')) === PF_JOURNAL) {
                        $journal = $j['id'];
                    }
                }
                $scopes = ($pl->get(PL_V2 . '/me', $dossier) ?? [])['scopes'] ?? [];
            } catch (ErreurPennylane $e) {
                return ['bloquants' => ['Vérification dans Pennylane impossible : ' . $e->getMessage()]];
            }
            $utilises = array_unique(array_merge(...array_map(fn($e) => array_column($e['lignes'], 'compte'), $ecritures)));
            $absents = array_values(array_filter($utilises, fn($c) => !isset($comptes[$c])));
            sort($absents);
        }
        $bloquants = [];
        if ($absents) {
            $bloquants[] = 'Compte(s) absent(s) du plan comptable du dossier : ' . implode(', ', $absents) . '.';
        }
        if ($deja && count($deja) === count($ecritures)) {
            $bloquants[] = 'Déjà passée dans Pennylane : ' . implode(', ', $deja) . '.';
        } elseif ($deja) {  // une plateforme déjà passée ce mois-ci : seule l'autre est importée
            $ecritures = array_values(array_filter($ecritures, fn($e) => !in_array($e['piece'], $deja, true)));
            $remarques[] = 'Déjà passée, non réimportée : ' . implode(', ', $deja) . '.';
        }
        if (!$journal) {
            $bloquants[] = 'Journal ' . PF_JOURNAL . ' introuvable dans ce dossier.';
        }
        if (!pf_peut_ecrire($scopes)) {
            $bloquants[] = "Le jeton API ne permet pas d'écrire les écritures : en mettre un autre dans Paramètres.";
        }
        if ($bloquants) {
            return ['bloquants' => $bloquants, 'comptes' => (bool)$absents];
        }
        // L'écriture vérifiée est gardée sur le serveur : l'import envoie exactement celle-ci
        $csv = pf_fichier_csv($ecritures);
        ecrire_json($this->etat_plateformes_fichier(), ['dossier' => $dossier, 'ecritures' => $ecritures, 'comptes' => $comptes, 'journal' => $journal,
            'le' => time()]);
        return ['ecritures' => array_map(fn($e) => $e + ['lignes_texte' => array_map(fn($l) => $l + ['debit_texte' => $l['debit'] ? eur($l['debit']) : '',
            'credit_texte' => $l['credit'] ? eur($l['credit']) : ''], $e['lignes']), 'ttc' => eur($e['lignes'][0]['debit'])], $ecritures),
            'remarques' => $remarques, 'tableur' => pf_presse_papiers($ecritures), 'pennylane' => $pennylane,
            'fichier' => ['nom' => sprintf('ecritures-%s-%04d-%02d.csv', PF_JOURNAL, ...$mois), 'contenu' => base64_encode($csv)]];
    }

    private function plateformes_schema_lire(): array
    {
        if (MODE_DEMO) {
            return $this->refus_demo('lecture des comptes dans Pennylane désactivée');
        }
        $dossier = pf_id(corps()['dossier'] ?? '');
        try {
            $schema = pf_schema_automatique(new Pennylane(), $dossier);
        } catch (ErreurPennylane $e) {
            return [409, ['erreur' => $e->getMessage()]];
        }
        $this->schema_enregistrer($dossier, $schema);
        return ['schema' => $schema];
    }

    /** Comptes corrigés à la main. */
    private function plateformes_schema(): array
    {
        $d = corps();
        $dossier = pf_id($d['dossier'] ?? '');
        $actuel = pf_schema($dossier);
        $nouveau = $actuel;
        foreach (['Uber Eats', 'Deliveroo'] as $pl) {
            $nouveau['plateformes'][$pl]['client'] = trim((string)($d['schema']['plateformes'][$pl]['client'] ?? ($actuel['plateformes'][$pl]['client'] ?? '')));
        }
        foreach (['ventes', 'tva'] as $section) {
            foreach (PF_TAUX_SCHEMA as $t) {
                $nouveau[$section][$t] = trim((string)($d['schema'][$section][$t] ?? ($actuel[$section][$t] ?? '')));
            }
        }
        if ($nouveau !== $actuel) {
            $nouveau['source'] = explode(' ; corrigé', (string)($actuel['source'] ?? ''))[0] . ' ; corrigé à la main';
            $this->schema_enregistrer($dossier, $nouveau);
        }
        return ['schema' => $nouveau];
    }

    private function plateformes_envoyer(): array
    {
        $etat = lire_json($this->etat_plateformes_fichier(), null);
        if (!$etat || time() - ($etat['le'] ?? 0) > 3600 || $etat['dossier'] !== pf_id(corps()['dossier'] ?? '')) {
            return [409, ['erreur' => "Écriture à revérifier : refaire l'import de l'export."]];
        }
        // Sous le verrou (un double clic n'envoie jamais deux fois), écriture par écriture : chacune est revérifiée dans Pennylane
        // juste avant, puis retirée de l'état dès qu'elle est passée (si la suivante est refusée, un nouvel essai ne renvoie que
        // ce qui reste).
        return sous_verrou(function () {
            $fichier = $this->etat_plateformes_fichier();
            $etat = lire_json($fichier, null);
            if (!$etat || !$etat['ecritures']) {
                return [409, ['erreur' => 'Écritures déjà envoyées.']];
            }
            $passees = [];
            try {
                $pl = MODE_DEMO ? null : new Pennylane();
                while ($etat['ecritures']) {
                    $e = $etat['ecritures'][0];
                    if (MODE_DEMO) {
                        demo_envoyer($etat['dossier'], $e);
                    } elseif (!pf_piece_existante($pl, $etat['dossier'], $e['piece'], $e['date'])) {
                        pf_envoyer($pl, $etat['dossier'], $e, $etat['comptes'], $etat['journal']);
                    }
                    $passees[] = array_shift($etat['ecritures']);
                    $etat['envoyees'][] = $e['piece'];
                    ecrire_json($fichier, $etat);
                }
            } catch (ErreurPennylane $e) {
                $faites = $passees ? ' Déjà passée(s) : ' . implode(', ', array_column($passees, 'piece')) . ' (non renvoyée(s) au prochain essai).' : '';
                return [409, ['erreur' => "Pennylane a refusé l'écriture : " . $e->getMessage() . $faites]];
            }
            @unlink($fichier);
            return ['ok' => true, 'simule' => MODE_DEMO,
                'ecritures' => array_map(fn($e) => ['piece' => $e['piece'], 'libelle' => $e['libelle'], 'ttc' => eur($e['lignes'][0]['debit'])], $passees)];
        });
    }
}
