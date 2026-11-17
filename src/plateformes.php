<?php
/**
 * Ventes Uber Eats / Deliveroo des supérettes : export de caisse -> écriture de ventes contrôlée -> Pennylane.
 *
 * Méthode : par plateforme et par taux, TTC = somme des (Product Price - Product Discount) ; la remise de commande non
 * affectée aux produits n'est pas déduite ; HT = TTC / (1 + taux) arrondi au centime ; TVA = TTC - HT. Une écriture par
 * plateforme et par mois, au journal des ventes, datée du dernier jour du mois : débit client plateforme (TTC) / crédit
 * ventes HT et TVA collectée, par taux. Montants en centimes, taux en centièmes de % (5,5 % = 550) : calcul exact, sans
 * flottant.
 *
 * Exports : dossier « …PLATEFORMES… » du client dans le SharePoint du cabinet (chemin réglable : sharepoint.php), lus par
 * Microsoft Graph ; ou fichier déposé à la main. Comptes de l'écriture : lus dans les écritures déjà passées du dossier,
 * sinon proposés d'après son plan comptable, modifiables à la main (data/plateformes/schemas.json).
 */
declare(strict_types=1);

const PF_TAUX_FRANCE = [550, 1000, 2000];
const PF_PLATEFORMES = ['uber eats' => ['Uber Eats', 'UBER'], 'deliveroo' => ['Deliveroo', 'DELIV']];
const PF_COLONNES = ['Date', 'Status', 'Platform', 'Order ID', 'Order Price', 'Product Price', 'Product VAT'];
const PF_NE_SONT_PAS_DES_EXPORTS = ['pennylane_import', 'tva_ventes'];  // fichiers produits par le cabinet, rangés au même endroit
// Produits non alimentaires (ou alcool) vendus à taux réduit : 20 % en principe, signalés au contrôle
const PF_NON_ALIMENTAIRE = ['désodorisant', 'desodorisant', "destructeur d'odeur", 'litière', 'litiere', 'adoucissant', 'lessive',
    'changes bébé', 'changes bebe', 'couche', 'liquide vaisselle', 'papier toilette', 'essuie-tout', 'mouchoir', 'coton-tige', 'shampo',
    'gel douche', 'dentifrice', 'brosse à dents', 'rasoir', 'déodorant', 'deodorant', 'pile', 'briquet', 'sac poubelle', 'éponge', 'eponge',
    'javel', 'nettoyant', 'insecticide', 'préservatif', 'serviette hygiénique', 'tampon', 'bière', 'biere', 'vin ', 'whisky', 'vodka', 'rhum',
    'champagne', 'cidre'];
const PF_TAUX_SCHEMA = ['20', '10', '5,5'];
// Comptes proposés tant qu'un dossier n'a pas les siens (plan comptable général) : réglables dans Paramètres ou config.json
const PF_COMPTES_PCG = ['client_uber_eats' => '411UBER', 'client_deliveroo' => '411DELIV',
    'ventes_20' => '707200', 'ventes_10' => '707100', 'ventes_5,5' => '707055', 'tva_20' => '4457120', 'tva_10' => '4457110', 'tva_5,5' => '4457105'];

/** Réglages du cabinet modifiés dans Paramètres (data/parametres.json) : code journal, comptes par défaut. */
function pf_parametres(): array
{
    return lire_json(chemin_data('parametres.json'), []);
}

// Code du journal des ventes (Paramètres, sinon réglage « journal », sinon VT)
define('PF_JOURNAL', strtoupper(trim((string)(pf_parametres()['journal'] ?? '')) ?: reglage('journal', 'VT')));

function pf_chemin(string ...$p): string
{
    return chemin_data('plateformes', ...$p);
}

function pf_dossiers(): array
{
    return lire_json(pf_chemin('dossiers.json'), []);
}

function pf_schemas(): array
{
    return lire_json(pf_chemin('schemas.json'), []);
}

/** Identifiant d'un dossier : celui de Pennylane (chiffres) ou « local-… » pour un dossier saisi à la main. */
function pf_id($brut): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', (string)$brut) ?? '';
}

/** Comptes par défaut (dossier sans schéma à lui) : Paramètres, sinon config.json (« compte_ventes_20 »…), sinon le PCG. */
function pf_schema_defaut(): array
{
    $p = pf_parametres()['comptes'] ?? [];
    $c = fn(string $k) => trim((string)($p[$k] ?? '')) ?: reglage('compte_' . str_replace(',', '_', $k), PF_COMPTES_PCG[$k]);
    return ['journal' => PF_JOURNAL, 'source' => 'comptes par défaut du cabinet (Paramètres)',
        'plateformes' => ['Uber Eats' => ['client' => $c('client_uber_eats')], 'Deliveroo' => ['client' => $c('client_deliveroo')]],
        'ventes' => array_combine(PF_TAUX_SCHEMA, array_map(fn($t) => $c("ventes_$t"), PF_TAUX_SCHEMA)),
        'tva' => array_combine(PF_TAUX_SCHEMA, array_map(fn($t) => $c("tva_$t"), PF_TAUX_SCHEMA))];
}

/** Schéma d'un dossier : le sien (lu dans Pennylane ou corrigé à la main), sinon les comptes par défaut. */
function pf_schema(string $dossier): array
{
    return pf_schemas()[$dossier] ?? pf_schema_defaut();
}

/** 2000 -> « 20 » ; 550 -> « 5,5 ». */
function taux_txt(int $t): string
{
    return rtrim(rtrim(number_format($t / 100, 2, ',', ''), '0'), ',');
}

/** Centimes -> « 1 589,45 » (format français, espaces insécables). */
function eur(int $c): string
{
    $s = $c < 0 ? '-' : '';
    $c = abs($c);
    $entier = (string)intdiv($c, 100);
    $groupes = [];
    while ($entier !== '') {
        array_unshift($groupes, substr($entier, -3));
        $entier = strlen($entier) > 3 ? substr($entier, 0, -3) : '';
    }
    return $s . implode(NBSP, $groupes) . ',' . str_pad((string)($c % 100), 2, '0', STR_PAD_LEFT);
}

// ---------- Lecture de l'export ----------

/** Lignes d'un classeur Excel (.xlsx / .xlsm), première feuille : [[valeurs]]. Sans bibliothèque (ZipArchive + XML). */
function pf_lire_xlsx(string $contenu): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'pf');
    file_put_contents($tmp, $contenu);
    $z = new ZipArchive();
    if ($z->open($tmp) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Classeur Excel illisible.');
    }
    $partages = [];
    if (($xml = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml);
        foreach ($sx->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            }
            foreach ($si->r as $r) {
                $t .= (string)$r->t;
            }
            $partages[] = $t;
        }
    }
    // Première feuille d'après le classeur (workbook.xml + relations), sinon sheet1.xml
    $feuille = 'xl/worksheets/sheet1.xml';
    $wb = $z->getFromName('xl/workbook.xml');
    $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $w = simplexml_load_string($wb);
        $w->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $premiere = $w->xpath('//m:sheets/m:sheet')[0] ?? null;
        $rid = $premiere ? (string)$premiere->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        foreach (simplexml_load_string($rels)->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $cible = ltrim((string)$rel['Target'], '/');
                $feuille = str_starts_with($cible, 'xl/') ? $cible : 'xl/' . $cible;
            }
        }
    }
    $xml = $z->getFromName($feuille);
    $z->close();
    @unlink($tmp);
    if ($xml === false) {
        throw new RuntimeException('Feuille du classeur introuvable.');
    }
    $sx = simplexml_load_string($xml);
    $lignes = [];
    foreach ($sx->sheetData->row as $row) {
        $l = [];
        $suivante = 1;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = 0;
            foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $col = $ref === '' ? $suivante : $col;  // cellule sans référence (certains exports) : juste après la précédente
            $suivante = $col + 1;
            $type = (string)$c['t'];
            $v = isset($c->v) ? (string)$c->v : null;
            if ($type === 's') {
                $v = $partages[(int)$v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string)$c->is->t;
            } elseif ($type === 'b') {
                $v = $v === '1';
            }
            $l[$col - 1] = $v;
        }
        if ($l) {
            $max = max(array_keys($l));
            $lignes[] = array_map(fn($i) => $l[$i] ?? null, range(0, $max));
        }
    }
    return $lignes;
}

/** Lignes de l'export (liste de [colonne => valeur]), xlsx ou csv. */
function pf_lire_export(string $nom, string $contenu): array
{
    if (preg_match('/\.(xlsx|xlsm)$/i', $nom)) {
        $lignes = pf_lire_xlsx($contenu);
    } else {
        $texte = en_utf8($contenu);  // export en Windows-1252 (Excel) : accepté
        $premiere = strtok(trim($texte), "\r\n") ?: '';
        $lignes = lire_csv($texte, substr_count($premiere, ';') > substr_count($premiere, ',') ? ';' : ',');
    }
    if (!$lignes) {
        return [];
    }
    $entetes = array_map(fn($h) => trim((string)($h ?? '')), array_shift($lignes));
    $out = [];
    foreach ($lignes as $l) {
        if (!array_filter($l, fn($v) => $v !== null && $v !== '')) {
            continue;
        }
        $ligne = [];
        foreach ($entetes as $i => $h) {
            $ligne[$h] = $l[$i] ?? null;
        }
        $out[] = $ligne;
    }
    return $out;
}

/** Liste de dossiers (supérettes) importée d'un fichier CSV ou Excel, une ligne par dossier. Colonnes reconnues (en-têtes
 *  sans tenir compte des accents ni des majuscules) : Nom (obligatoire), Identifiant Pennylane, Client Uber Eats, Client
 *  Deliveroo, Ventes 20, Ventes 10, Ventes 5,5, TVA 20, TVA 10, TVA 5,5. Retourne [[id, nom, schéma ou null]] et les erreurs. */
function pf_lire_dossiers(string $nom_fichier, string $contenu): array
{
    $cle = fn(string $h) => preg_replace('/[^a-z0-9]/', '', strtolower(sans_accents($h)));
    $colonnes = ['nom' => 'nom', 'identifiantpennylane' => 'id', 'idpennylane' => 'id', 'pennylane' => 'id',
        'clientubereats' => 'p:Uber Eats', 'clientdeliveroo' => 'p:Deliveroo'];
    foreach (PF_TAUX_SCHEMA as $t) {
        $colonnes[$cle("ventes $t")] = "ventes:$t";
        $colonnes[$cle("tva $t")] = "tva:$t";
    }
    $out = $erreurs = [];
    foreach (pf_lire_export($nom_fichier, $contenu) as $n => $l) {
        $v = [];
        foreach ($l as $h => $x) {
            if (isset($colonnes[$cle((string)$h)])) {
                $v[$colonnes[$cle((string)$h)]] = trim((string)($x ?? ''));
            }
        }
        $nom = $v['nom'] ?? '';
        if ($nom === '') {
            $erreurs[] = 'Ligne ' . ($n + 2) . ' : nom absent, ligne ignorée.';
            continue;
        }
        $id = pf_id(preg_replace('/\.0+$/', '', $v['id'] ?? ''));  // identifiant lu comme nombre dans Excel : « 123.0 »
        $schema = null;
        if (array_filter($v, fn($x, $k) => str_contains($k, ':') && $x !== '', ARRAY_FILTER_USE_BOTH)) {
            $schema = pf_schema_defaut();
            $schema['source'] = "importé (fichier $nom_fichier)";
            foreach ($v as $k => $x) {
                if ($x !== '' && str_contains($k, ':')) {
                    [$section, $t] = explode(':', $k, 2);
                    if ($section === 'p') {
                        $schema['plateformes'][$t]['client'] = $x;
                    } else {
                        $schema[$section][$t] = $x;
                    }
                }
            }
        }
        $out[] = [$id, $nom, $schema];
    }
    return [$out, $erreurs];
}

/** Date d'une cellule : date Excel (nombre de jours) ou texte ; [année, mois, jour] ou null. */
function pf_lire_date($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {  // date Excel : jours depuis le 30/12/1899
        return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400));  // heure « murale » d'Excel : jamais décalée en heure de Paris
    }
    $s = substr(trim((string)$v), 0, 19);
    foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $s);
        if ($d && $d->format($fmt) === $s) {
            return $d->format('Y-m-d');
        }
    }
    foreach (['d/m/Y', 'Y-m-d'] as $fmt) {  // « 12/09/2026 14:03 » : partie date seule
        $d = DateTime::createFromFormat('!' . $fmt, substr($s, 0, 10));
        if ($d && $d->format($fmt) === substr($s, 0, 10)) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

/** « 12,8 » / 12.8 / '' -> centimes (0 si vide) ; illisible -> null. */
function pf_montant($v): ?int
{
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return 0;
    }
    return lire_montant(is_float($v) ? sprintf('%.6F', $v) : (string)$v);
}

/** Mois couverts par l'export : [[année, mois]] triés. */
function pf_couverture(array $lignes): array
{
    $mois = [];
    foreach ($lignes as $l) {
        $d = pf_lire_date($l['Date'] ?? null);
        if ($d) {
            $mois[substr($d, 0, 7)] = [(int)substr($d, 0, 4), (int)substr($d, 5, 2)];
        }
    }
    ksort($mois);
    return array_values($mois);
}

function pf_mois_nom(array $m): string
{
    return MOIS[$m[1] - 1] . ' ' . $m[0];
}

/** Totaux TTC par plateforme / taux et contrôles. $mois [année, mois] : seules les ventes de ce mois sont comptées. */
function pf_analyser(array $lignes, ?array $mois = null): array
{
    $a = ['totaux' => [], 'controles' => [], 'mois' => null, 'magasins' => [], 'nb_lignes' => count($lignes), 'nb_commandes' => 0];
    $ajout = function (string $niveau, string $msg) use (&$a) {
        $a['controles'][] = ['niveau' => $niveau, 'message' => $msg];
    };
    if (!$lignes) {
        $ajout('bloquant', 'Le fichier ne contient aucune ligne.');
        return $a;
    }
    $manquantes = array_values(array_filter(PF_COLONNES, fn($c) => !array_key_exists($c, $lignes[0])));
    if ($manquantes) {
        $ajout('bloquant', "Colonnes absentes de l'export : " . implode(', ', $manquantes) . '.');
        return $a;
    }
    $totaux = $commandes = $statuts = $taux_inconnus = $inconnues = $taux_par_ean = $taux_douteux = $mois_fichier = $magasins = [];
    $dates = [];
    $illisibles = $non_facturees = $hors_mois = $remises = 0;
    foreach ($lignes as $l) {
        $d = pf_lire_date($l['Date'] ?? null);
        if ($d) {
            $mois_fichier[substr($d, 0, 7)] = [(int)substr($d, 0, 4), (int)substr($d, 5, 2)];
        }
        if ($mois && $d && [(int)substr($d, 0, 4), (int)substr($d, 5, 2)] !== $mois) {
            $hors_mois++;
            continue;
        }
        if (trim((string)($l['Store'] ?? '')) !== '') {
            $magasins[trim((string)$l['Store'])] = true;
        }
        $statut = strtoupper(trim((string)($l['Status'] ?? '')));
        if ($statut !== 'COMPLETED') {
            $statuts[$statut ?: '(vide)'] = ($statuts[$statut ?: '(vide)'] ?? 0) + 1;
            continue;
        }
        $plateforme = trim((string)($l['Platform'] ?? ''));
        if (!isset(PF_PLATEFORMES[strtolower($plateforme)])) {
            $inconnues[$plateforme ?: '(vide)'] = ($inconnues[$plateforme ?: '(vide)'] ?? 0) + 1;
            continue;
        }
        $prix = pf_montant($l['Product Price'] ?? null);
        $taux = pf_montant($l['Product VAT'] ?? null);
        if ($prix === null || $taux === null) {
            $illisibles++;
            continue;
        }
        if ($d) {
            $dates[] = $d;
        }
        if ($prix === 0 && trim((string)($l['Product Price'] ?? '')) === '') {
            $non_facturees++;  // article non servi / remplacé : pas de prix
            continue;
        }
        if (!in_array($taux, PF_TAUX_FRANCE, true)) {
            $taux_inconnus[$taux] = ($taux_inconnus[$taux] ?? 0) + $prix;
        }
        $remise = pf_montant($l['Product Discount'] ?? null) ?? 0;  // remise produit : déduite du CA (prix réellement payé)
        $prix -= $remise;
        $remises += $remise;
        $nom_pf = PF_PLATEFORMES[strtolower($plateforme)][0];
        $totaux[$nom_pf][$taux] = ($totaux[$nom_pf][$taux] ?? 0) + $prix;
        $nom = ' ' . mb_strtolower((string)($l['Product Name'] ?? '')) . ' ';
        if ($taux < 2000) {
            foreach (PF_NON_ALIMENTAIRE as $m) {
                if (str_contains($nom, $m)) {
                    $k = trim((string)($l['Product Name'] ?? '')) . '|' . $taux;
                    $taux_douteux[$k] = ($taux_douteux[$k] ?? 0) + $prix;
                    break;
                }
            }
        }
        if (trim((string)($l['Product EAN'] ?? '')) !== '') {
            $taux_par_ean[trim((string)$l['Product EAN']) . '|' . trim((string)($l['Product Name'] ?? ''))][$taux] = true;
        }
        $cle = $plateforme . '|' . (string)($l['Order ID'] ?? '');
        $commandes[$cle] ??= [pf_montant($l['Order Price'] ?? null) ?? 0, 0];
        $commandes[$cle][1] += $prix + $remise;  // le prix de la commande est brut : comparé aux lignes avant remise
    }
    foreach ($totaux as $p => $t) {
        krsort($t);
        $a['totaux'][$p] = $t;
    }
    $a['nb_commandes'] = count($commandes);
    $a['magasins'] = array_keys($magasins);
    sort($a['magasins']);
    ksort($mois_fichier);
    if ($mois) {
        if ($dates) {
            $a['mois'] = $mois;
        } else {
            $couverts = implode(', ', array_map('pf_mois_nom', $mois_fichier)) ?: 'aucune date lisible';
            $ajout('bloquant', 'Aucune vente de ' . pf_mois_nom($mois) . " dans ce fichier (il couvre : $couverts).");
        }
        if ($hors_mois) {
            $ajout('info', "$hors_mois ligne(s) d'autres mois ignorée(s) : seul " . pf_mois_nom($mois) . ' est comptabilisé.');
        }
    } else {
        $mf = [];
        foreach ($dates as $d) {
            $mf[substr($d, 0, 7)] = [(int)substr($d, 0, 4), (int)substr($d, 5, 2)];
        }
        ksort($mf);
        if (!$mf) {
            $ajout('bloquant', "Aucune date lisible dans l'export.");
        } elseif (count($mf) > 1) {
            $ajout('bloquant', "L'export couvre plusieurs mois (" . implode(', ', array_map('pf_mois_nom', $mf)) . ') : une écriture se fait mois par mois.');
        } else {
            $a['mois'] = reset($mf);
        }
    }
    if (count($a['magasins']) > 1) {
        $ajout('bloquant', 'Plusieurs magasins dans le même export : ' . implode(', ', $a['magasins']) . '.');
    }
    foreach ($taux_inconnus as $t => $total) {
        $ajout('bloquant', 'Taux de TVA inattendu ' . taux_txt((int)$t) . ' % (' . eur($total) . ' € TTC) : seuls 5,5 %, 10 % et 20 % sont prévus.');
    }
    if ($illisibles) {
        $ajout('bloquant', "$illisibles ligne(s) avec un prix ou un taux illisible.");
    }
    foreach ($statuts as $s => $n) {
        $ajout('info', "$n ligne(s) au statut « $s » ignorée(s) (seules les commandes COMPLETED sont comptées).");
    }
    foreach ($inconnues as $p => $n) {
        $ajout('attention', "$n ligne(s) d'une plateforme non prévue « $p » ignorée(s).");
    }
    if ($remises) {
        $ajout('info', 'Remises produits déduites : ' . eur($remises) . ' € TTC.');
    }
    if ($non_facturees) {
        $ajout('info', "$non_facturees article(s) sans prix (non servis ou remplacés) : non comptés.");
    }
    $ecarts = [];
    foreach ($commandes as $k => [$prix, $somme]) {
        if (abs($prix - $somme) > 1) {
            $ecarts[] = [$k, $prix, $somme];
        }
    }
    foreach (array_slice($ecarts, 0, 10) as [$k, $prix, $somme]) {
        [$p, $n] = explode('|', $k, 2);
        $ajout('attention', "Commande $p $n : prix de la commande " . eur($prix) . ' € mais lignes produits ' . eur($somme)
            . ' € (écart ' . eur($somme - $prix) . ' € ; ligne en double ?). Le total des lignes est retenu.');
    }
    arsort($taux_douteux);
    foreach (array_slice($taux_douteux, 0, 10, true) as $k => $total) {
        [$nom, $t] = explode('|', $k);
        $ajout('attention', 'Produit non alimentaire à ' . taux_txt((int)$t) . " % (20 % en principe) : $nom, " . eur($total) . ' € TTC.');
    }
    if (count($ecarts) > 10) {
        $ajout('attention', '… et ' . (count($ecarts) - 10) . ' autre(s) commande(s) avec un écart.');
    }
    foreach ($taux_par_ean as $k => $ts) {
        if (count($ts) > 1) {
            [$ean, $nom] = explode('|', $k, 2);
            $t = array_keys($ts);
            sort($t);
            $ajout('attention', 'Même produit à plusieurs taux de TVA (' . implode(', ', array_map(fn($x) => taux_txt($x) . ' %', $t)) . ') : ' . ($nom ?: $ean) . '.');
        }
    }
    if (!$a['totaux'] && !array_filter($a['controles'], fn($c) => $c['niveau'] === 'bloquant')) {
        $ajout('bloquant', 'Aucune vente Uber Eats ou Deliveroo à comptabiliser.');
    }
    return $a;
}

/** [HT, TVA] d'un total TTC (centimes) : HT arrondi au centime (au plus proche, à mi-chemin vers l'extérieur), TVA = TTC - HT. */
function pf_ventiler(int $ttc, int $taux): array
{
    $num = $ttc * 10000;
    $den = 10000 + $taux;
    $ht = $num >= 0 ? intdiv(2 * $num + $den, 2 * $den) : -intdiv(2 * (-$num) + $den, 2 * $den);
    return [$ht, $ttc - $ht];
}

function pf_dernier_jour(int $annee, int $mois): string
{
    return sprintf('%04d-%02d-%02d', $annee, $mois, (int)date('t', mktime(12, 0, 0, $mois, 1, $annee)));
}

/** [écritures, contrôles] (une écriture par plateforme) à partir des totaux et du schéma de comptes du dossier. */
function pf_construire_ecritures(array $analyse, array $schema): array
{
    $controles = $ecritures = [];
    if (!$analyse['mois']) {
        return [$ecritures, $controles];
    }
    [$annee, $mois] = $analyse['mois'];
    foreach ($analyse['totaux'] as $plateforme => $par_taux) {
        $code = '';
        foreach (PF_PLATEFORMES as [$nom, $c]) {
            if ($nom === $plateforme) {
                $code = $c;
            }
        }
        $compte_client = (string)($schema['plateformes'][$plateforme]['client'] ?? '');
        $libelle = "Ventes $plateforme " . MOIS[$mois - 1] . " $annee";
        $credits = [];
        $total = 0;
        foreach ($par_taux as $taux => $ttc) {
            if ($ttc === 0) {
                continue;
            }
            [$ht, $tva] = pf_ventiler($ttc, (int)$taux);
            $t = taux_txt((int)$taux);
            $vente = (string)($schema['ventes'][$t] ?? '');
            $collectee = (string)($schema['tva'][$t] ?? '');
            if ($vente === '' || $collectee === '') {
                $controles[] = ['niveau' => 'bloquant', 'message' => "$plateforme : compte de " . ($vente === '' ? 'ventes' : 'TVA')
                    . " à $t % non défini pour ce dossier (" . eur($ttc) . ' € TTC à ce taux).'];
            }
            if (abs($ht * (int)$taux - $tva * 10000) > 10000) {  // la TVA doit valoir HT × taux, à un centime près
                $controles[] = ['niveau' => 'bloquant', 'message' => "$plateforme $t % : TVA " . eur($tva) . ' € incohérente avec HT ' . eur($ht) . ' €.'];
            }
            $credits[] = ['compte' => $collectee, 'libelle' => "$libelle - TVA $t" . NBSP . '%', 'debit' => 0, 'credit' => $tva];
            $credits[] = ['compte' => $vente, 'libelle' => "$libelle - HT TVA $t" . NBSP . '%', 'debit' => 0, 'credit' => $ht];
            $total += $ttc;
        }
        if ($compte_client === '') {
            $controles[] = ['niveau' => 'bloquant', 'message' => "Compte client $plateforme non défini pour ce dossier."];
        }
        $lignes = array_merge([['compte' => $compte_client, 'libelle' => $libelle, 'debit' => $total, 'credit' => 0]], $credits);
        $debit = array_sum(array_column($lignes, 'debit'));
        $credit = array_sum(array_column($lignes, 'credit'));
        if ($debit !== $credit) {
            $controles[] = ['niveau' => 'bloquant', 'message' => "$plateforme : écriture déséquilibrée (" . eur($debit) . ' ≠ ' . eur($credit) . ').'];
        }
        $ecritures[] = ['plateforme' => $plateforme, 'date' => pf_dernier_jour($annee, $mois), 'piece' => sprintf('%s-%s-%04d-%02d', PF_JOURNAL, $code, $annee, $mois),
            'libelle' => $libelle, 'journal' => PF_JOURNAL, 'lignes' => $lignes];
    }
    return [$ecritures, $controles];
}

/** Texte tabulé (à coller dans Excel) : Date, Journal, Pièce, Compte, Libellé, Débit, Crédit. */
function pf_presse_papiers(array $ecritures): string
{
    $fr = fn($m) => $m ? str_replace('.', ',', sprintf('%.2f', $m / 100)) : '';
    $out = ["Date\tJournal\tPièce\tCompte\tLibellé\tDébit\tCrédit"];
    foreach ($ecritures as $e) {
        foreach ($e['lignes'] as $l) {
            $out[] = implode("\t", [jjmmaaaa($e['date']), $e['journal'], $e['piece'], $l['compte'], $l['libelle'], $fr($l['debit']), $fr($l['credit'])]);
        }
    }
    return implode("\r\n", $out);
}

/** Fichier d'écritures à importer dans n'importe quel logiciel comptable (sans Pennylane) : CSV « ; », UTF-8 avec BOM
 *  (Excel le lit tel quel), une ligne par ligne d'écriture : Date (jj/mm/aaaa) ; Journal ; Pièce ; Compte ; Libellé ;
 *  Débit ; Crédit (virgule décimale, case vide pour zéro). */
function pf_fichier_csv(array $ecritures): string
{
    $fr = fn($m) => $m ? str_replace('.', ',', sprintf('%.2f', $m / 100)) : '';
    $f = fopen('php://temp', 'r+');
    fputcsv($f, ['Date', 'Journal', 'Pièce', 'Compte', 'Libellé', 'Débit', 'Crédit'], ';', '"', '');
    foreach ($ecritures as $e) {
        foreach ($e['lignes'] as $l) {
            fputcsv($f, [jjmmaaaa($e['date']), $e['journal'], $e['piece'], $l['compte'], $l['libelle'], $fr($l['debit']), $fr($l['credit'])], ';', '"', '');
        }
    }
    rewind($f);
    $csv = (string)stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $csv);
}

/** [(année, mois)] du mois en cours en remontant sur $n mois. */
function pf_liste_mois(int $n = 13, ?string $aujourdhui = null): array
{
    $a = $aujourdhui ?? aujourdhui();
    $annee = (int)substr($a, 0, 4);
    $mois = (int)substr($a, 5, 2);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = [$annee, $mois];
        if (--$mois === 0) {
            $annee--;
            $mois = 12;
        }
    }
    return $out;
}

/** Mois présélectionné : celui qui suit le dernier mois passé ; aucun passé : le mois dernier. */
function pf_mois_propose(array $mois, array $passes): array
{
    foreach ($mois as $i => $m) {
        if (isset($passes[sprintf('%04d-%02d', ...$m)])) {
            return $i > 0 ? $mois[$i - 1] : $m;
        }
    }
    return $mois[1] ?? $mois[0];
}

// ---------- Pennylane ----------

/** Schéma lu dans les dernières écritures « Ventes Uber Eats / Deliveroo … » du journal des ventes ; null si aucune. */
function pf_extraire_schema(Pennylane $pl, string $dossier): ?array
{
    $vt = null;
    foreach ($pl->liste('journals', $dossier) as $j) {
        if (strtoupper((string)($j['code'] ?? '')) === PF_JOURNAL) {
            $vt = $j;
        }
    }
    if (!$vt) {
        return null;
    }
    $filtre = json_encode([['field' => 'journal_id', 'operator' => 'eq', 'value' => $vt['id']]]);
    $ecritures = array_values(array_filter($pl->liste('ledger_entries', $dossier, ['filter' => $filtre]),
        fn($e) => preg_match('/^Ventes (Uber Eats|Deliveroo)\b/i', (string)($e['label'] ?? ''))));
    if (!$ecritures) {
        return null;
    }
    usort($ecritures, fn($a, $b) => strcmp($b['date'], $a['date']));  // la plus récente d'abord
    $schema = ['journal' => PF_JOURNAL, 'plateformes' => [], 'ventes' => [], 'tva' => [], 'source' => ''];
    foreach ($ecritures as $e) {
        preg_match('/^Ventes (Uber Eats|Deliveroo)\b/i', $e['label'], $m);
        $plateforme = strtolower($m[1]) === 'uber eats' ? 'Uber Eats' : 'Deliveroo';
        $lignes = ($pl->get(PL_V2 . "/ledger_entries/{$e['id']}/ledger_entry_lines", $dossier) ?? [])['items'] ?? [];
        foreach ($lignes as $l) {
            $numero = (string)($l['ledger_account']['number'] ?? '');
            $libelle = trim((string)($l['label'] ?? ''));
            if (preg_match('/- TVA (\d+(?:[,.]\d+)?)\s*%\s*$/u', $libelle, $t)) {
                $schema['tva'][str_replace('.', ',', $t[1])] ??= $numero;
            } elseif (preg_match('/- HT TVA (\d+(?:[,.]\d+)?)\s*%\s*$/u', $libelle, $t)) {
                $schema['ventes'][str_replace('.', ',', $t[1])] ??= $numero;
            } elseif ((float)($l['debit'] ?? 0) > 0) {
                $schema['plateformes'][$plateforme] ??= ['client' => $numero];
            }
        }
        if ($schema['source'] === '') {
            $schema['source'] = 'écriture ' . (($e['piece_number'] ?? '') ?: $e['label']) . ' du ' . $e['date'];
        }
    }
    return $schema;
}

/** Schéma proposé d'après le plan comptable [{number, label}] : à vérifier (aucune écriture passée). */
function pf_proposer_schema(array $comptes): array
{
    $schema = ['journal' => PF_JOURNAL, 'plateformes' => [], 'ventes' => [], 'tva' => [], 'source' => "proposé d'après le plan comptable (aucune écriture passée) : à vérifier"];
    usort($comptes, fn($a, $b) => [strlen((string)$a['number']), (string)$a['number']] <=> [strlen((string)$b['number']), (string)$b['number']]);
    foreach ($comptes as $a) {
        $numero = (string)$a['number'];
        $libelle = trim((string)($a['label'] ?? ''));
        $bas = mb_strtolower($libelle);
        if (str_starts_with($numero, '411')) {
            foreach (['Uber Eats' => 'uber', 'Deliveroo' => 'deliveroo'] as $pl => $mot) {
                if (str_contains($bas, $mot)) {
                    $schema['plateformes'][$pl] ??= ['client' => $numero];
                }
            }
        } elseif (str_starts_with($numero, '44571') && preg_match('/^TVA collectée à (\d+(?:[.,]\d+)?)\s*%$/iu', $libelle, $m)) {
            $taux = str_replace('.', ',', $m[1]);
            if (in_array($taux, PF_TAUX_SCHEMA, true)) {
                $schema['tva'][$taux] ??= $numero;
            }
        }
    }
    foreach (PF_TAUX_SCHEMA as $t) {
        $motifs = ["$t%", "$t %", "$t" . NBSP . '%', str_replace(',', '.', $t) . '%', str_replace(',', '.', $t) . ' %'];
        $candidats = array_values(array_filter($comptes, function ($a) use ($t, $motifs) {
            $l = (string)($a['label'] ?? '');
            if (!str_starts_with((string)$a['number'], '707') || str_contains(mb_strtolower($l), 'non alimentaire')) {
                return false;
            }
            if ($t === '20' && (str_contains($l, '5,5') || str_contains($l, '5.5'))) {
                return false;
            }
            foreach ($motifs as $m) {
                if (str_contains($l, $m)) {
                    return true;
                }
            }
            return false;
        }));
        usort($candidats, fn($a, $b) => [!str_contains(mb_strtolower((string)($a['label'] ?? '')), 'aliment'), strlen((string)$a['number']), (string)$a['number']]
            <=> [!str_contains(mb_strtolower((string)($b['label'] ?? '')), 'aliment'), strlen((string)$b['number']), (string)$b['number']]);
        if ($candidats) {
            $schema['ventes'][$t] = (string)$candidats[0]['number'];
        }
    }
    return $schema;
}

/** Schéma lu dans les écritures passées, complété (ou remplacé s'il n'y en a pas) par la proposition du plan. */
function pf_schema_automatique(Pennylane $pl, string $dossier): array
{
    $propose = pf_proposer_schema($pl->liste('ledger_accounts', $dossier));
    $lu = pf_extraire_schema($pl, $dossier);
    if (!$lu) {
        return $propose;
    }
    foreach (['ventes', 'tva'] as $section) {  // taux jamais utilisés dans une écriture : compte du plan proposé
        foreach ($propose[$section] as $t => $compte) {
            if (!isset($lu[$section][$t])) {
                $lu[$section][$t] = $compte;
                $lu['source'] .= " ; $section $t % d'après le plan";
            }
        }
    }
    $lu['plateformes'] += $propose['plateformes'];
    return $lu;
}

/** {« AAAA-MM »: [plateformes]} des ventes plateformes déjà passées au journal des ventes depuis $depuis (pièce
 *  VT-UBER-AAAA-MM / VT-DELIV-AAAA-MM, ou libellé « Ventes Uber Eats mars 2026 »). */
function pf_mois_passes(Pennylane $pl, string $dossier, string $depuis): array
{
    $vt = null;
    foreach ($pl->liste('journals', $dossier) as $j) {
        if (strtoupper((string)($j['code'] ?? '')) === PF_JOURNAL) {
            $vt = $j;
        }
    }
    if (!$vt) {
        return [];
    }
    $filtre = json_encode([['field' => 'journal_id', 'operator' => 'eq', 'value' => $vt['id']], ['field' => 'date', 'operator' => 'gteq', 'value' => $depuis]]);
    $passes = [];
    foreach ($pl->liste('ledger_entries', $dossier, ['filter' => $filtre]) as $e) {
        if (preg_match('/^[A-Z0-9]+-(UBER|DELIV)-(\d{4})-(\d{2})$/', (string)($e['piece_number'] ?? ''), $m)) {
            $plateforme = $m[1] === 'UBER' ? 'Uber Eats' : 'Deliveroo';
            $cle = "$m[2]-$m[3]";
        } elseif (preg_match('/^Ventes (Uber Eats|Deliveroo) (\p{L}+) (\d{4})/iu', (string)($e['label'] ?? ''), $m)
            && in_array(mb_strtolower($m[2]), MOIS, true)) {
            $plateforme = strtolower($m[1]) === 'uber eats' ? 'Uber Eats' : 'Deliveroo';
            $cle = sprintf('%s-%02d', $m[3], array_search(mb_strtolower($m[2]), MOIS, true) + 1);
        } else {
            continue;
        }
        $passes[$cle][$plateforme] = true;
    }
    return array_map('array_keys', $passes);
}

/** {numéro de compte: id Pennylane}. */
function pf_comptes_du_dossier(Pennylane $pl, string $dossier): array
{
    $out = [];
    foreach ($pl->liste('ledger_accounts', $dossier) as $a) {
        $out[(string)$a['number']] = $a['id'];
    }
    return $out;
}

function pf_piece_existante(Pennylane $pl, string $dossier, string $piece, string $date): bool
{
    $filtre = json_encode([['field' => 'date', 'operator' => 'eq', 'value' => $date]]);
    foreach ($pl->liste('ledger_entries', $dossier, ['filter' => $filtre]) as $e) {
        if (($e['piece_number'] ?? '') === $piece) {
            return true;
        }
    }
    return false;
}

function pf_peut_ecrire(array $scopes): bool
{
    foreach ($scopes as $s) {
        if ($s === '*' || (str_starts_with($s, 'ledger_entries') && !str_ends_with($s, ':readonly'))) {
            return true;
        }
    }
    return false;
}

/** Crée l'écriture dans Pennylane (POST v2/ledger_entries). */
function pf_envoyer(Pennylane $pl, string $dossier, array $e, array $ids, $journal_id)
{
    $corps = ['date' => $e['date'], 'label' => $e['libelle'], 'journal_id' => $journal_id, 'piece_number' => $e['piece'],
        'ledger_entry_lines' => array_map(fn($l) => ['ledger_account_id' => $ids[$l['compte']], 'label' => $l['libelle'],
            'debit' => sprintf('%.2f', $l['debit'] / 100), 'credit' => sprintf('%.2f', $l['credit'] / 100)], $e['lignes'])];
    return $pl->requete('POST', PL_V2 . '/ledger_entries', $dossier, $corps);
}

// ---------- Exports dans le SharePoint du cabinet ----------

/** Exports candidats du client pour l'année, du plus récent au plus ancien : [{nom, id, drive, modifie, chemin}]. */
function pf_exports_sharepoint(CompteMicrosoft $b, string $nom, int $annee): array
{
    $chemin = sp_chemin_exports($nom, $annee);
    try {
        $racine = sp_dossier($b, $chemin);
        if ($b->appel('GET', "/drives/{$racine['drive']}/items/{$racine['id']}", ['params' => ['$select' => 'id'], 'refus' => SP_REFUS,
            'service' => 'SharePoint']) === null) {
            $racine = sp_dossier($b, $chemin, true);  // dossier déplacé, renommé ou recréé depuis : adresse relue
        }
    } catch (ErreurMicrosoft $e) {
        return [];
    }
    $trouves = [];
    $explorer = function (array $d, string $ou, bool $dans_plateformes, int $profondeur) use (&$explorer, $b, &$trouves) {
        if ($profondeur > 4) {
            return;
        }
        $enfants = $b->pages("/drives/{$d['drive']}/items/{$d['id']}/children", ['$select' => 'id,name,folder,file,lastModifiedDateTime', '$top' => '999'], 2000,
            ['refus' => SP_REFUS, 'service' => 'SharePoint']);
        foreach ($enfants as $x) {
            $n = (string)$x['name'];
            if (isset($x['folder'])) {
                $explorer(['drive' => $d['drive'], 'id' => $x['id']], "$ou/$n", $dans_plateformes || str_contains(mb_strtoupper($n), 'PLATEFORMES'), $profondeur + 1);
            } elseif ($dans_plateformes && preg_match('/\.(xlsx|xlsm|csv)$/i', $n) && !str_starts_with($n, '~$')) {
                foreach (PF_NE_SONT_PAS_DES_EXPORTS as $p) {
                    if (str_starts_with(strtolower($n), $p)) {
                        continue 2;
                    }
                }
                $trouves[] = ['nom' => $n, 'id' => $x['id'], 'drive' => $d['drive'], 'modifie' => (string)($x['lastModifiedDateTime'] ?? ''),
                    'chemin' => "$ou/$n"];
            }
        }
    };
    $explorer($racine, $chemin, false, 0);
    usort($trouves, fn($a, $b) => strcmp($b['modifie'], $a['modifie']));
    return $trouves;
}

/** Contenu d'un fichier du SharePoint (gardé dans data/plateformes/cache tant qu'il ne change pas). */
function pf_telecharger(CompteMicrosoft $b, array $f): string
{
    $cache = pf_chemin('cache', hash('sha256', $f['drive'] . $f['id'] . $f['modifie']) . '.bin');
    if (is_file($cache)) {
        return (string)file_get_contents($cache);
    }
    $r = $b->appel('GET', "/drives/{$f['drive']}/items/{$f['id']}/content", ['brut' => true, 'suivre' => true, 'refus' => SP_REFUS, 'service' => 'SharePoint']);
    if (!is_array($r)) {
        throw new ErreurMicrosoft("« {$f['nom']} » est introuvable dans SharePoint (déplacé ou supprimé) : relancez la recherche des exports.");
    }
    $contenu = (string)$r[0];
    ecrire_fichier($cache, $contenu);
    return $contenu;
}
