"""Le cœur de l'outil : lecture de l'export, totaux TTC par plateforme et par taux, ventilation HT / TVA au centime,
écriture équilibrée, contrôles. Fonctions PHP appelées directement (tests/outil.php) : ni serveur ni réseau.

Référence indépendante en Python (Decimal) : HT = TTC / (1 + taux) arrondi au centime le plus proche (à mi-chemin : vers
l'extérieur), TVA = TTC - HT.
"""
import csv
import io
import os
import random
from decimal import ROUND_HALF_UP, Decimal

import pytest

from commun import RACINE, b64, php, php_appels

NBSP = " "


def ventiler_ref(ttc, taux):
    """TTC en centimes, taux en centièmes de % -> [HT, TVA] en centimes."""
    ht = (Decimal(ttc) * 10000 / (10000 + taux)).quantize(Decimal(1), rounding=ROUND_HALF_UP)
    return [int(ht), ttc - int(ht)]


def ligne(plateforme="Uber Eats", prix="10.00", taux="5.5", remise="", statut="COMPLETED", commande="C1", total=None, nom="Pâtes 500 g",
          date="15/09/2026 12:00", magasin="Magasin A", ean=""):
    return {"Date": date, "Status": statut, "Platform": plateforme, "Store": magasin, "Order ID": commande,
            "Order Price": total if total is not None else prix, "Product EAN": ean, "Product Name": nom,
            "Product Price": prix, "Product Discount": remise, "Product VAT": taux}


SCHEMA = {"journal": "VT", "plateformes": {"Uber Eats": {"client": "411UBER"}, "Deliveroo": {"client": "411DELIV"}},
          "ventes": {"20": "707200", "10": "707100", "5,5": "707055"}, "tva": {"20": "4457120", "10": "4457110", "5,5": "4457105"}}


def controles(analyse, niveau=None):
    return [c["message"] for c in analyse["controles"] if niveau is None or c["niveau"] == niveau]


# ---------- Ventilation HT / TVA ----------
@pytest.mark.parametrize("ttc,taux,attendu", [
    (10550, 550, [10000, 550]),       # 105,50 TTC à 5,5 % : tombe juste
    (11000, 1000, [10000, 1000]),
    (12000, 2000, [10000, 2000]),
    (100, 550, [95, 5]),              # 1,00 / 1,055 = 0,9478… -> 0,95
    (1, 2000, [1, 0]),                # 0,01 / 1,2 = 0,0083 -> 0,01 : la TVA ne devient jamais négative
    (0, 2000, [0, 0]),
    (123456, 550, [117020, 6436]),    # 1 234,56 / 1,055 = 1 170,1990… -> 1 170,20
    (31240, 1000, [28400, 2840]),
    (8990, 2000, [7492, 1498]),       # 89,90 / 1,2 = 74,9166… -> 74,92
    (-8990, 2000, [-7492, -1498]),    # avoir (remboursement) : même arrondi, de l'autre côté de zéro
    (633, 550, [600, 33]),            # 6,33 / 1,055 = 6,0000 : exact à 10^-4 près
    (66, 1000, [60, 6]),              # 0,66 / 1,1 = 0,6 exactement
    (3, 2000, [3, 0]),                # 0,03 / 1,2 = 0,025 : à mi-chemin -> vers l'extérieur (0,03)
    (-3, 2000, [-3, 0]),
    (9, 2000, [8, 1]),                # 0,09 / 1,2 = 0,075 : à mi-chemin -> 0,08
])
def test_ventilation_cas_choisis(ttc, taux, attendu):
    assert php("pf_ventiler", ttc, taux) == attendu
    assert ventiler_ref(ttc, taux) == attendu


def test_ventilation_aleatoire_contre_reference_decimale():
    """4 500 montants tirés au hasard (petits, grands, négatifs) sur les trois taux : identique à Decimal, au centime."""
    alea = random.Random(20260930)
    cas = [(alea.choice([alea.randint(-5000, 5000), alea.randint(0, 10 ** 7), alea.randint(-10 ** 9, 10 ** 9)]), t)
           for t in (550, 1000, 2000) for _ in range(1500)]
    resultats = php_appels(*[["pf_ventiler", [ttc, t]] for ttc, t in cas])
    for (ttc, t), r in zip(cas, resultats):
        assert r == ventiler_ref(ttc, t), (ttc, t, r)
        ht, tva = r
        assert ht + tva == ttc
        assert abs(ht * t - tva * 10000) <= 10000  # TVA = HT x taux, à un centime près


def test_ventilation_cas_limites_a_mi_chemin():
    """Tous les TTC de 0 à 2 000 centimes : aucun écart avec la référence (couvre tous les arrondis à mi-chemin possibles)."""
    cas = [(ttc, t) for t in (550, 1000, 2000) for ttc in range(0, 2001)]
    for (ttc, t), r in zip(cas, php_appels(*[["pf_ventiler", [ttc, t]] for ttc, t in cas])):
        assert r == ventiler_ref(ttc, t), (ttc, t)


# ---------- Lecture des montants et des dates ----------
@pytest.mark.parametrize("texte,centimes", [("12.8", 1280), ("12,80", 1280), ("1 234,56", 123456), ("1,234.56", 123456),
                                            ("1.234,56", 123456), ("5.5", 550), ("20", 2000), ("0.005", 1), ("-3,10", -310),
                                            ("", 0), (None, 0), ("abc", None)])
def test_montants_lus_au_centime(texte, centimes):
    assert php("pf_montant", texte) == centimes


def test_montant_nombre_excel_sans_erreur_de_flottant():
    assert php("pf_montant", 0.1 + 0.2) == 30
    assert php("pf_montant", 2.675) == 268  # 2,675 en binaire vaut 2,67499… : lu « 2.675000 » puis arrondi au centime


@pytest.mark.parametrize("valeur,iso", [("15/09/2026 12:03", "2026-09-15"), ("15/09/2026", "2026-09-15"), ("2026-09-15T23:59:00", "2026-09-15"),
                                        ("2026-09-15", "2026-09-15"), (46295, "2026-09-30"), (46295 + 23.5 / 24, "2026-09-30"),
                                        ("31/02/2026", None), ("", None)])
def test_dates(valeur, iso):
    assert php("pf_lire_date", valeur) == iso


# ---------- Totaux par plateforme et par taux ----------
def test_totaux_par_plateforme_et_taux_avec_remises():
    lignes = [
        ligne(prix="10.00", taux="5.5", remise="1.00", commande="U1", total="22.00"),
        ligne(prix="12.00", taux="20", commande="U1", total="22.00", nom="Lessive 1 L"),
        ligne(prix="4.50", taux="10", commande="U2", nom="Sandwich"),
        ligne(plateforme="Deliveroo", prix="3,30", taux="5,5", remise="0,30", commande="D1"),
        ligne(plateforme="DELIVEROO", prix="2.00", taux="20", commande="D2", nom="Soda"),
    ]
    a = php("pf_analyser", lignes, [2026, 9])
    assert a["totaux"] == {"Uber Eats": {"2000": 1200, "1000": 450, "550": 900}, "Deliveroo": {"2000": 200, "550": 300}}
    assert a["mois"] == [2026, 9]
    assert a["nb_commandes"] == 4
    assert not controles(a, "bloquant")
    assert any("Remises produits déduites : 1,30" in m for m in controles(a))


def test_commandes_non_terminees_et_articles_sans_prix_ignores():
    lignes = [ligne(prix="10.00"), ligne(prix="99.00", statut="CANCELLED", commande="X"), ligne(prix="", commande="C1", total="10.00"),
              ligne(prix="5.00", statut="", commande="Y")]
    a = php("pf_analyser", lignes, [2026, 9])
    assert a["totaux"] == {"Uber Eats": {"550": 1000}}
    infos = controles(a, "info")
    assert any("CANCELLED" in m for m in infos) and any("(vide)" in m for m in infos)
    assert any("1 article(s) sans prix" in m for m in infos)


def test_ventes_d_un_autre_mois_ignorees_et_signalees():
    lignes = [ligne(prix="10.00", date="30/09/2026 23:59"), ligne(prix="7.00", date="01/10/2026 00:01", commande="C2")]
    a = php("pf_analyser", lignes, [2026, 9])
    assert a["totaux"] == {"Uber Eats": {"550": 1000}}
    assert any("1 ligne(s) d'autres mois" in m for m in controles(a, "info"))
    vide = php("pf_analyser", lignes, [2026, 8])
    assert any("Aucune vente de août 2026" in m for m in controles(vide, "bloquant"))
    sans_mois = php("pf_analyser", lignes)
    assert any("plusieurs mois" in m for m in controles(sans_mois, "bloquant"))


def test_produit_non_alimentaire_a_taux_reduit_signale():
    lignes = [ligne(prix="1.89", taux="5.5", nom="Liquide vaisselle 500 ml"), ligne(prix="2.45", taux="20", nom="Bière blonde 50 cl", commande="C2"),
              ligne(prix="1.45", taux="5.5", nom="Lait 1 L", commande="C3")]
    a = php("pf_analyser", lignes, [2026, 9])
    attention = controles(a, "attention")
    assert any("Produit non alimentaire à 5,5 %" in m and "Liquide vaisselle 500 ml" in m and "1,89" in m for m in attention)
    assert not any("Bière" in m for m in attention)  # à 20 % : rien à dire
    assert not controles(a, "bloquant")  # une remarque, pas un blocage


def test_meme_produit_a_deux_taux_et_ecart_de_commande():
    lignes = [ligne(prix="2.00", taux="5.5", ean="2000000000011", nom="Lait", commande="A", total="2.00"),
              ligne(prix="2.00", taux="20", ean="2000000000011", nom="Lait", commande="B", total="9.99")]
    attention = controles(php("pf_analyser", lignes, [2026, 9]), "attention")
    assert any("Même produit à plusieurs taux" in m for m in attention)
    assert any("Commande Uber Eats B" in m and "écart" in m for m in attention)


@pytest.mark.parametrize("modif,message", [
    ({"Product VAT": "7"}, "Taux de TVA inattendu 7 %"),
    ({"Product Price": "douze"}, "prix ou un taux illisible"),
])
def test_anomalies_bloquantes(modif, message):
    a = php("pf_analyser", [{**ligne(), **modif}], [2026, 9])
    assert any(message in m for m in controles(a, "bloquant"))


def test_colonnes_manquantes_et_plusieurs_magasins_bloquent():
    sans_tva = {k: v for k, v in ligne().items() if k != "Product VAT"}
    assert any("Colonnes absentes" in m and "Product VAT" in m for m in controles(php("pf_analyser", [sans_tva], [2026, 9])))
    deux = [ligne(), ligne(magasin="Magasin B", commande="C2")]
    assert any("Plusieurs magasins" in m for m in controles(php("pf_analyser", deux, [2026, 9]), "bloquant"))
    autre = [ligne(plateforme="Just Eat")]
    a = php("pf_analyser", autre, [2026, 9])
    assert any("plateforme non prévue" in m for m in controles(a, "attention"))
    assert controles(a, "bloquant") and not a["totaux"]  # rien à comptabiliser : on s'arrête


# ---------- L'écriture ----------
def test_exemple_du_readme():
    """Uber Eats, mars 2026 : 1 234,56 à 5,5 %, 312,40 à 10 %, 89,90 à 20 % (TTC)."""
    analyse = {"mois": [2026, 3], "totaux": {"Uber Eats": {"2000": 8990, "1000": 31240, "550": 123456}}}
    [e], ctr = php("pf_construire_ecritures", analyse, SCHEMA)
    assert ctr == []
    assert (e["date"], e["piece"], e["libelle"], e["journal"]) == ("2026-03-31", "VT-UBER-2026-03", "Ventes Uber Eats mars 2026", "VT")
    assert [(l["compte"], l["debit"], l["credit"]) for l in e["lignes"]] == [
        ("411UBER", 163686, 0),
        ("4457120", 0, 1498), ("707200", 0, 7492),
        ("4457110", 0, 2840), ("707100", 0, 28400),
        ("4457105", 0, 6436), ("707055", 0, 117020)]
    assert e["lignes"][1]["libelle"] == f"Ventes Uber Eats mars 2026 - TVA 20{NBSP}%"
    assert e["lignes"][2]["libelle"] == f"Ventes Uber Eats mars 2026 - HT TVA 20{NBSP}%"


def test_ecritures_toujours_equilibrees_et_datees_du_dernier_jour():
    alea = random.Random(7)
    for annee, mois, jour in [(2026, 2, 28), (2028, 2, 29), (2026, 4, 30), (2026, 12, 31)]:
        totaux = {p: {str(t): alea.randint(1, 10 ** 7) for t in (2000, 1000, 550)} for p in ("Uber Eats", "Deliveroo")}
        ecritures, ctr = php("pf_construire_ecritures", {"mois": [annee, mois], "totaux": totaux}, SCHEMA)
        assert ctr == [] and len(ecritures) == 2
        for e in ecritures:
            assert e["date"] == f"{annee}-{mois:02d}-{jour}"
            assert sum(l["debit"] for l in e["lignes"]) == sum(l["credit"] for l in e["lignes"])
            assert e["lignes"][0]["debit"] == sum(totaux[e["plateforme"]].values())
        assert {e["piece"] for e in ecritures} == {f"VT-UBER-{annee}-{mois:02d}", f"VT-DELIV-{annee}-{mois:02d}"}


def test_taux_sans_vente_omis_et_comptes_manquants_bloquants():
    analyse = {"mois": [2026, 9], "totaux": {"Deliveroo": {"2000": 0, "1000": 1100}}}
    [e], ctr = php("pf_construire_ecritures", analyse, SCHEMA)
    assert [l["compte"] for l in e["lignes"]] == ["411DELIV", "4457110", "707100"]
    incomplet = {**SCHEMA, "ventes": {**SCHEMA["ventes"], "10": ""}, "plateformes": {"Uber Eats": {"client": "411UBER"}}}
    _, ctr = php("pf_construire_ecritures", analyse, incomplet)
    assert any("compte de ventes à 10 % non défini" in c["message"] for c in ctr)
    assert any("Compte client Deliveroo non défini" in c["message"] for c in ctr)


def test_schema_par_defaut_du_plan_comptable_general():
    d = php("pf_schema_defaut")
    assert d["plateformes"] == {"Uber Eats": {"client": "411UBER"}, "Deliveroo": {"client": "411DELIV"}}
    assert d["ventes"] == {"20": "707200", "10": "707100", "5,5": "707055"}
    assert d["tva"] == {"20": "4457120", "10": "4457110", "5,5": "4457105"}


def test_presse_papiers_et_fichier_csv():
    analyse = {"mois": [2026, 3], "totaux": {"Uber Eats": {"550": 123456}}}
    ecritures, _ = php("pf_construire_ecritures", analyse, SCHEMA)
    texte = php("pf_presse_papiers", ecritures).split("\r\n")
    assert texte[0] == "Date\tJournal\tPièce\tCompte\tLibellé\tDébit\tCrédit"
    assert texte[1] == "31/03/2026\tVT\tVT-UBER-2026-03\t411UBER\tVentes Uber Eats mars 2026\t1234,56\t"
    fichier = php("pf_fichier_csv", ecritures)
    assert fichier.startswith("﻿")
    lignes = list(csv.reader(io.StringIO(fichier.lstrip("﻿")), delimiter=";"))
    assert lignes[0] == ["Date", "Journal", "Pièce", "Compte", "Libellé", "Débit", "Crédit"]
    assert lignes[1] == ["31/03/2026", "VT", "VT-UBER-2026-03", "411UBER", "Ventes Uber Eats mars 2026", "1234,56", ""]
    assert [l[3] for l in lignes[1:]] == ["411UBER", "4457105", "707055"]
    assert sum(Decimal(l[5].replace(",", ".") or 0) for l in lignes[1:]) == sum(Decimal(l[6].replace(",", ".") or 0) for l in lignes[1:])


# ---------- Fichiers d'export : CSV (« , » ou « ; », Windows-1252), Excel ----------
def test_lecture_csv_point_virgule_windows_1252():
    texte = ("Date;Status;Platform;Order ID;Order Price;Product Name;Product Price;Product Discount;Product VAT\r\n"
             "03/09/2026 11:00;COMPLETED;Uber Eats;A1;3,50;Café crème;3,50;;10\r\n")
    lignes = php("pf_lire_export", "export.csv", b64(texte.encode("cp1252")))
    assert lignes[0]["Product Name"] == "Café crème" and lignes[0]["Product Price"] == "3,50"
    assert php("pf_analyser", lignes, [2026, 9])["totaux"] == {"Uber Eats": {"1000": 350}}


def test_lecture_xlsx_ecrit_par_un_tableur():
    openpyxl = pytest.importorskip("openpyxl")
    from datetime import datetime
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.append(["Date", "Status", "Platform", "Order ID", "Order Price", "Product Name", "Product Price", "Product Discount", "Product VAT"])
    ws.append([datetime(2026, 9, 30, 23, 30), "COMPLETED", "Deliveroo", "D9", 12.8, "Wrap poulet", 12.8, 0.8, 10])
    ws.append([datetime(2026, 9, 2, 9, 0), "COMPLETED", "Deliveroo", "D10", 1.45, "Lait", 1.45, None, 5.5])
    tampon = io.BytesIO()
    wb.save(tampon)
    lignes = php("pf_lire_export", "export.xlsx", b64(tampon.getvalue()))
    a = php("pf_analyser", lignes, [2026, 9])
    assert a["totaux"] == {"Deliveroo": {"1000": 1200, "550": 145}}  # la vente de 23 h 30 reste en septembre


def reference_depuis_csv(chemin, annee, mois):
    """Totaux recalculés en Python, sans le code PHP, depuis un export d'exemple."""
    totaux = {}
    with open(chemin, encoding="utf-8") as f:
        for l in csv.DictReader(f):
            if l["Status"] != "COMPLETED" or l["Product Price"] == "" or l["Date"][3:10] != f"{mois:02d}/{annee}":
                continue
            prix = Decimal(l["Product Price"]) - Decimal(l["Product Discount"] or 0)
            taux = int(Decimal(l["Product VAT"]) * 100)
            totaux.setdefault(l["Platform"], {}).setdefault(str(taux), 0)
            totaux[l["Platform"]][str(taux)] += int(prix * 100)
    return totaux


def test_fichiers_d_exemple_du_depot():
    """exemples/ : le CSV donne les mêmes totaux que le recalcul Python ; le xlsx et le CSV relus par le PHP sont cohérents."""
    chemin = os.path.join(RACINE, "exemples", "export-caisse-2026-09.csv")
    with open(chemin, "rb") as f:
        lignes = php("pf_lire_export", "export.csv", b64(f.read()))
    a = php("pf_analyser", lignes, [2026, 9])
    attendu = reference_depuis_csv(chemin, 2026, 9)
    assert {p: {t: v for t, v in d.items()} for p, d in a["totaux"].items()} == attendu
    assert not controles(a, "bloquant")
    assert any("Liquide vaisselle" in m for m in controles(a, "attention"))
    with open(os.path.join(RACINE, "exemples", "export-caisse-2026-10.xlsx"), "rb") as f:
        lignes_x = php("pf_lire_export", "export.xlsx", b64(f.read()))
    ax = php("pf_analyser", lignes_x, [2026, 10])
    assert set(ax["totaux"]) == {"Uber Eats", "Deliveroo"} and not controles(ax, "bloquant")
    regenere = php("pf_analyser", php("demo_export", "Trois Lunes - Centre", 2026, 10), [2026, 10])
    assert ax["totaux"] == regenere["totaux"]  # le xlsx relu = les lignes qui l'ont produit


def test_liste_de_dossiers_importee():
    with open(os.path.join(RACINE, "exemples", "dossiers.csv"), "rb") as f:
        lignes, erreurs = php("pf_lire_dossiers", "dossiers.csv", b64(f.read()))
    assert erreurs == []
    assert [l[1] for l in lignes] == ["SUPÉRETTE DES TROIS LUNES", "ÉPICERIE PETIT PHARE", "MINI MARCHÉ DES EXEMPLES"]
    assert lignes[1][2]["ventes"]["5,5"] == "7070055" and lignes[1][2]["plateformes"]["Deliveroo"]["client"] == "41100011"
    assert lignes[2][2] is None  # aucune colonne de compte remplie : comptes par défaut


def test_mois_propose():
    mois = php("pf_liste_mois", 13, "2026-11-17")
    assert mois[0] == [2026, 11] and mois[12] == [2025, 11]
    assert php("pf_mois_propose", mois, {}) == [2026, 10]  # rien de passé : le mois dernier
    assert php("pf_mois_propose", mois, {"2026-08": ["Uber Eats"]}) == [2026, 9]  # le mois qui suit le dernier passé
