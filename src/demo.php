<?php
/**
 * Mode démo (variable DEMO=1) : l'application tourne hors ligne avec des données FICTIVES.
 *
 *   - data-demo/ est rempli au premier lancement : compte « demo » / « demo », trois supérettes inventées avec leurs comptes,
 *     et des exports de caisse Uber Eats / Deliveroo des deux derniers mois rangés dans un faux SharePoint (data-demo/sharepoint) ;
 *   - Microsoft 365 et Pennylane ne sont jamais appelés : les routes concernées répondent par un message clair, et http() /
 *     la classe Pennylane refusent toute sortie réseau par sécurité ;
 *   - l'envoi dans Pennylane est simulé (data-demo/plateformes/demo-pennylane.json) : le mois apparaît ensuite comme passé.
 * Toutes les sociétés, tous les produits et tous les codes ci-dessous sont inventés.
 */
declare(strict_types=1);

const DEMO_IDENTIFIANT = 'demo';
const DEMO_NOM = 'Camille Lefèvre';

/** « Mode démo : Pennylane désactivé ici … » ($quoi : « Pennylane désactivé », « connexion Microsoft 365 désactivée »…). */
function demo_message(string $quoi): string
{
    return "Mode démo : $quoi ici (aucun compte externe, aucune requête ne sort). "
        . 'Le code d\'intégration complet est dans src/microsoft.php, src/sharepoint.php et src/pennylane.php.';
}

/** Supérettes inventées : identifiant (façon Pennylane) -> [nom, nom du magasin dans l'export]. */
function demo_magasins(): array
{
    return [
        '900101' => ['SUPÉRETTE DES TROIS LUNES', 'Trois Lunes - Centre'],
        '900102' => ['ÉPICERIE PETIT PHARE', 'Petit Phare - Port'],
        '900103' => ['MINI MARCHÉ DES EXEMPLES', 'Mini Marché - Gare'],
    ];
}

/** Comptes de l'écriture de chaque supérette (le 3e n'a pas de compte de ventes à 10 % : le contrôle le signale). */
function demo_schemas(): array
{
    $s = fn(string $source, array $clients, array $ventes, array $tva) => ['journal' => PF_JOURNAL, 'source' => $source,
        'plateformes' => ['Uber Eats' => ['client' => $clients[0]], 'Deliveroo' => ['client' => $clients[1]]],
        'ventes' => array_combine(PF_TAUX_SCHEMA, $ventes), 'tva' => array_combine(PF_TAUX_SCHEMA, $tva)];
    return [
        '900101' => $s('écritures passées (exemple)', ['411UBER', '411DELIV'], ['707200', '707100', '707055'], ['4457120', '4457110', '4457105']),
        '900102' => $s('écritures passées (exemple)', ['41100010', '41100011'], ['7070200', '7070100', '7070055'], ['44571200', '44571100', '44571055']),
        '900103' => $s("proposé d'après le plan comptable (exemple) : à vérifier", ['411UBER', '411DELIV'], ['707200', '', '707055'],
            ['4457120', '4457110', '4457105']),
    ];
}

/** Produits inventés : [code interne (préfixe 2, réservé à l'usage interne des magasins), nom, prix TTC en centimes, taux]. */
const DEMO_PRODUITS = [
    ['2000000000011', 'Lait demi-écrémé 1 L', 145, 5.5], ['2000000000028', 'Pâtes coquillettes 500 g', 129, 5.5],
    ['2000000000035', 'Eau minérale 1,5 L', 89, 5.5], ['2000000000042', 'Céréales au chocolat 375 g', 389, 5.5],
    ['2000000000059', 'Tablette chocolat noir 100 g', 219, 5.5], ['2000000000066', 'Pain de mie complet', 249, 5.5],
    ['2000000000073', 'Yaourts nature x4', 199, 5.5], ['2000000000080', 'Bananes 1 kg', 239, 5.5],
    ['2000000000097', 'Riz basmati 1 kg', 329, 5.5], ['2000000000103', 'Soda cola 33 cl', 135, 5.5],
    ['2000000000110', "Jus d'orange 1 L", 259, 5.5], ['2000000000127', 'Chips nature 150 g', 189, 5.5],
    ['2000000000134', 'Sandwich jambon-beurre', 450, 10], ['2000000000141', 'Salade composée', 520, 10],
    ['2000000000158', 'Wrap poulet', 490, 10], ['2000000000165', 'Lasagnes à réchauffer', 590, 10],
    ['2000000000172', 'Bière blonde 50 cl', 245, 20], ['2000000000189', 'Lessive liquide 1,5 L', 799, 20],
    ['2000000000196', 'Piles AA x4', 599, 20], ['2000000000202', 'Papier toilette x6', 349, 20],
    ['2000000000219', 'Dentifrice 75 ml', 229, 20],
    ['2000000000226', 'Liquide vaisselle 500 ml', 189, 5.5],  // mal paramétré en caisse : le contrôle « non alimentaire » le relève
];

/** Colonnes d'un export de caisse (une ligne par produit vendu, Uber Eats et Deliveroo ensemble). */
const DEMO_COLONNES = ['Date', 'Status', 'Platform', 'Store', 'Order ID', 'Order Price', 'Product EAN', 'Product Name', 'Quantity',
    'Product Price', 'Product Discount', 'Product VAT'];

/** Lignes d'un export fictif pour un mois (toujours les mêmes pour un magasin et un mois donnés) : [[colonne => valeur]].
 *  Dates en « jj/mm/aaaa hh:mm », montants en euros (nombres). Remises, commandes annulées, article non servi compris. */
function demo_export(string $magasin, int $annee, int $mois): array
{
    mt_srand(crc32("$magasin|$annee-$mois"));
    $jours = (int)date('t', mktime(12, 0, 0, $mois, 1, $annee));
    $lignes = [];
    foreach (['Uber Eats' => [170, 240], 'Deliveroo' => [110, 160]] as $plateforme => [$min, $max]) {
        $n = mt_rand($min, $max);  // commandes du mois
        for ($k = 0; $k < $n; $k++) {
            $id = $plateforme === 'Uber Eats' ? sprintf('UE-%06X', mt_rand(0, 0xFFFFFF)) : sprintf('DR-%07d', mt_rand(1000000, 9999999));
            $quand = sprintf('%02d/%02d/%04d %02d:%02d', mt_rand(1, $jours), $mois, $annee, mt_rand(10, 23), mt_rand(0, 59));
            $statut = mt_rand(1, 100) <= 5 ? 'CANCELLED' : 'COMPLETED';
            $produits = [];
            for ($p = mt_rand(1, 5); $p > 0; $p--) {
                $produits[] = DEMO_PRODUITS[mt_rand(0, count(DEMO_PRODUITS) - 2)];
            }
            if (mt_rand(1, 12) === 1) {
                $produits[] = DEMO_PRODUITS[count(DEMO_PRODUITS) - 1];
            }
            $commande = [];
            foreach ($produits as [$ean, $nom, $prix, $taux]) {
                $qte = mt_rand(1, 10) <= 8 ? 1 : 2;
                $brut = $prix * $qte;
                $remise = mt_rand(1, 100) <= 15 ? (mt_rand(0, 1) ? intdiv($brut * 20 + 50, 100) : min(100, $brut)) : 0;  // -20 % ou -1 €
                $non_servi = mt_rand(1, 120) === 1;  // article remplacé ou non servi : pas de prix
                $commande[] = ['Date' => $quand, 'Status' => $statut, 'Platform' => $plateforme, 'Store' => $magasin, 'Order ID' => $id,
                    'Order Price' => 0, 'Product EAN' => $ean, 'Product Name' => $nom, 'Quantity' => $qte,
                    'Product Price' => $non_servi ? '' : $brut / 100, 'Product Discount' => $non_servi || !$remise ? '' : $remise / 100,
                    'Product VAT' => $taux];
            }
            $total = array_sum(array_map(fn($l) => (int)round((float)$l['Product Price'] * 100), $commande));  // prix brut de la commande
            foreach ($commande as $l) {
                $lignes[] = ['Order Price' => $total / 100] + $l;
            }
        }
    }
    usort($lignes, fn($a, $b) => strcmp(substr($a['Date'], 6, 4) . substr($a['Date'], 3, 2) . substr($a['Date'], 0, 2) . substr($a['Date'], 11),
        substr($b['Date'], 6, 4) . substr($b['Date'], 3, 2) . substr($b['Date'], 0, 2) . substr($b['Date'], 11)));
    return array_map(fn($l) => array_merge(array_fill_keys(DEMO_COLONNES, ''), $l), $lignes);
}

/** Export au format CSV (séparateur « , », point décimal : comme l'export anglais de la caisse). */
function demo_csv(array $lignes): string
{
    $f = fopen('php://temp', 'r+');
    fputcsv($f, DEMO_COLONNES, ',', '"', '');
    foreach ($lignes as $l) {
        fputcsv($f, array_map(fn($v) => is_float($v) ? rtrim(rtrim(sprintf('%.2F', $v), '0'), '.') : (string)$v, array_values($l)), ',', '"', '');
    }
    rewind($f);
    $csv = (string)stream_get_contents($f);
    fclose($f);
    return $csv;
}

/** Export au format Excel (.xlsx minimal, sans bibliothèque) : dates en vraies dates Excel, montants en nombres. */
function demo_xlsx(array $lignes): string
{
    $col = fn(int $i) => chr(65 + $i);
    $cellule = function ($v, string $ref, bool $date = false): string {
        if ($date) {
            $d = DateTime::createFromFormat('d/m/Y H:i', (string)$v, new DateTimeZone('UTC'));
            return "<c r=\"$ref\" s=\"1\"><v>" . round($d->getTimestamp() / 86400 + 25569, 6) . '</v></c>';
        }
        if (is_int($v) || is_float($v)) {
            return "<c r=\"$ref\"><v>$v</v></c>";
        }
        return $v === '' ? '' : "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars((string)$v, ENT_XML1) . '</t></is></c>';
    };
    $rangs = ['<row r="1">' . implode('', array_map(fn($i, $h) => $cellule($h, $col($i) . '1'), array_keys(DEMO_COLONNES), DEMO_COLONNES)) . '</row>'];
    foreach (array_values($lignes) as $n => $l) {
        $r = $n + 2;
        $rangs[] = "<row r=\"$r\">" . implode('', array_map(fn($i, $k) => $cellule($l[$k], $col($i) . $r, $k === 'Date'),
            array_keys(DEMO_COLONNES), DEMO_COLONNES)) . '</row>';
    }
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $fichiers = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook $ns xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">"
            . '<sheets><sheet name="Ventes" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><styleSheet $ns><fonts count=\"1\"><font><sz val=\"11\"/><name val=\"Calibri\"/></font></fonts>"
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf numFmtId="22" applyNumberFormat="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet $ns><sheetData>" . implode('', $rangs) . '</sheetData></worksheet>',
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'vpx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    foreach ($fichiers as $nom => $contenu) {
        $z->addFromString($nom, $contenu);
    }
    $z->close();
    $octets = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $octets;
}

/** [année, mois] du mois précédent de $n mois. */
function demo_mois(int $n): array
{
    $t = mktime(12, 0, 0, (int)date('n') - $n, 1, (int)date('Y'));
    return [(int)date('Y', $t), (int)date('n', $t)];
}

function demo_sharepoint(string ...$p): string
{
    return chemin_data('sharepoint', ...$p);
}

/** Remplit data-demo/ au premier lancement. Ne fait rien hors du mode démo. */
function demo_initialiser(): bool
{
    if (!MODE_DEMO || is_file(chemin_data('comptes.json'))) {
        return false;
    }
    sous_verrou(function () {
        cle_secrete();
        ecrire_json(pf_chemin('dossiers.json'), array_map(fn($m) => $m[0], demo_magasins()));
        ecrire_json(pf_chemin('schemas.json'), demo_schemas());
        // Faux SharePoint : exports des deux derniers mois, dans le dossier « Ventes PLATEFORMES » de chaque supérette
        foreach (demo_magasins() as $id => [$nom, $magasin]) {
            $id = (string)$id;  // clé numérique : PHP en fait un entier
            foreach ([1, 2] as $n) {
                [$a, $m] = demo_mois($n);
                $lignes = demo_export($magasin, $a, $m);
                $dossier = demo_sharepoint($nom, (string)$a, 'Ventes PLATEFORMES');
                $base = sprintf('export-caisse-%04d-%02d', $a, $m);
                ecrire_fichier("$dossier/$base" . ($id === '900102' && $n === 1 ? '.xlsx' : '.csv'),
                    $id === '900102' && $n === 1 ? demo_xlsx($lignes) : demo_csv($lignes));
            }
        }
        // Pennylane simulé : la première supérette a déjà passé le mois d'il y a trois mois
        [$a, $m] = demo_mois(3);
        ecrire_json(pf_chemin('demo-pennylane.json'), ['passes' => ['900101' => [sprintf('%04d-%02d', $a, $m) => ['Uber Eats', 'Deliveroo']]],
            'ecritures' => []]);
        ecrire_json(chemin_data('comptes.json'), [DEMO_IDENTIFIANT => ['nom' => DEMO_NOM, 'hash' => password_hash('demo', PASSWORD_DEFAULT)]]);
    });
    return true;
}

/** Exports trouvés dans le faux SharePoint (même forme que pf_exports_sharepoint), du plus récent au plus ancien. */
function demo_exports(string $nom, int $annee): array
{
    $racine = demo_sharepoint($nom, (string)$annee);
    if ($nom === '' || !is_dir($racine)) {
        return [];
    }
    $trouves = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $relatif = str_replace('\\', '/', substr($f->getPathname(), strlen(demo_sharepoint())));
        if ($f->isFile() && str_contains(mb_strtoupper(dirname($relatif)), 'PLATEFORMES') && preg_match('/\.(xlsx|xlsm|csv)$/i', $f->getFilename())) {
            $trouves[] = ['nom' => $f->getFilename(), 'id' => ltrim($relatif, '/'), 'drive' => 'demo', 'modifie' => date('c', $f->getMTime()),
                'chemin' => 'SharePoint (simulé)/' . ltrim($relatif, '/')];
        }
    }
    usort($trouves, fn($a, $b) => strcmp($b['nom'], $a['nom']));
    return $trouves;
}

/** Contenu d'un fichier du faux SharePoint (jamais en dehors de data-demo/sharepoint). */
function demo_telecharger(array $f): string
{
    $racine = realpath(demo_sharepoint());
    $chemin = realpath(demo_sharepoint((string)($f['id'] ?? '')));
    if (!$racine || !$chemin || !str_starts_with($chemin, $racine . DIRECTORY_SEPARATOR) || !is_file($chemin)) {
        throw new RuntimeException('fichier introuvable dans le SharePoint de démonstration');
    }
    return (string)file_get_contents($chemin);
}

/** Pennylane simulé : mois déjà passés {« AAAA-MM »: [plateformes]} d'un dossier. */
function demo_mois_passes(string $dossier): array
{
    return lire_json(pf_chemin('demo-pennylane.json'), [])['passes'][$dossier] ?? [];
}

/** Pennylane simulé : l'écriture est notée comme passée (rien ne sort de l'ordinateur). */
function demo_envoyer(string $dossier, array $e): void
{
    sous_verrou(function () use ($dossier, $e) {
        $p = lire_json(pf_chemin('demo-pennylane.json'), ['passes' => [], 'ecritures' => []]);
        $cle = substr($e['date'], 0, 7);
        $p['passes'][$dossier][$cle] = array_values(array_unique(array_merge($p['passes'][$dossier][$cle] ?? [], [$e['plateforme']])));
        $p['ecritures'][] = ['dossier' => $dossier, 'le' => date('c')] + $e;
        ecrire_json(pf_chemin('demo-pennylane.json'), $p);
    });
}
