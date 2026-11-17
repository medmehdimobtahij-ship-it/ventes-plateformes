<?php
/**
 * Textes et nombres à la française : mois, montants lus dans un export (« 1 234,56 », « 12.8 »), CSV.
 * Montants en CENTIMES (entiers) : aucun arrondi flottant.
 */
declare(strict_types=1);

const NBSP = "\u{a0}";
const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** « - 7 940,13 », « 22735 », « 1.234,5 », « 891 € » -> centimes ; vide ou illisible -> null. */
function lire_montant($texte): ?int
{
    $s = str_replace(['€', NBSP, "\u{202f}", ' '], '', trim((string)($texte ?? '')));
    if ($s === '') {
        return null;
    }
    if (str_contains($s, ',') && str_contains($s, '.')) {
        // 1.234,56 (milliers à la française) ; 1,234.56 (export à l'anglaise : la virgule vient avant le point)
        $s = strrpos($s, ',') < strrpos($s, '.') ? str_replace(',', '', $s) : str_replace('.', '', $s);
    }
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $s)) {
        return null;
    }
    $negatif = str_starts_with($s, '-');
    $s = ltrim($s, '+-');
    [$e, $d] = array_pad(explode('.', $s, 2), 2, '');
    $c = (int)($e === '' ? '0' : $e) * 100 + (int)str_pad(substr($d . '00', 0, 2), 2, '0');
    if (strlen($d) > 2 && (int)$d[2] >= 5) {  // arrondi au centime le plus proche
        $c++;
    }
    return $negatif ? -$c : $c;
}

/** Texte CSV -> lignes (tableaux de cellules). Séparateur « ; » ou « , » deviné sur la 1re ligne ; un retour à la ligne
 *  entre guillemets reste dans sa cellule (jamais une fausse ligne). Lignes vides ignorées. */
function lire_csv(string $texte, ?string $sep = null): array
{
    $texte = trim($texte);
    if ($texte === '') {
        return [];
    }
    $premiere = strtok($texte, "\r\n") ?: '';
    $sep ??= substr_count($premiere, ';') >= substr_count($premiere, ',') && str_contains($premiere, ';') ? ';' : ',';
    $f = fopen('php://temp', 'r+');
    fwrite($f, $texte);
    rewind($f);
    $out = [];
    while (($v = fgetcsv($f, null, $sep, '"', '')) !== false) {
        if ($v !== [null] && implode('', array_map('strval', $v)) !== '') {
            $out[] = array_map(fn($x) => (string)$x, $v);
        }
    }
    fclose($f);
    return $out;
}
