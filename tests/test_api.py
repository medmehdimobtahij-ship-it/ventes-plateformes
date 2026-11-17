"""L'application en vrai (sans DEMO) : vrai serveur PHP, données temporaires, faux Microsoft / SharePoint / Pennylane locaux.

Deux parcours complets : sans aucune intégration (export déposé à la main -> fichier CSV d'écritures), puis avec Pennylane
et SharePoint (export trouvé tout seul -> écriture créée dans Pennylane, jamais deux fois), et les contrôles qui bloquent.
"""
import base64
import csv
import io
import json
import os
import shutil
import subprocess
import tempfile
import time
from decimal import Decimal
from urllib.parse import parse_qs, urlparse

import pytest
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import padding, rsa

from commun import APPELS, H, MS, PHP, PL, RACINE, SP, Client, curl_disponible, lancer_php, pl_reinitialiser, sp_ajouter, sp_reinitialiser

pytestmark = pytest.mark.skipif(not curl_disponible(), reason="extension curl absente de ce PHP (tests lancés en CI)")

CLIENT_ID = "00000000-0000-4000-8000-00000000c0de"  # application Entra fictive
TENANT = "11111111-2222-3333-4444-555555555555"
CLE = rsa.generate_private_key(public_exponent=65537, key_size=2048)
EXEMPLE = os.path.join(RACINE, "exemples", "export-caisse-2026-09.csv")


def b64u(o):
    return base64.urlsafe_b64encode(o).decode().rstrip("=")


def jwks():
    n = CLE.public_key().public_numbers()
    entier = lambda v: b64u(v.to_bytes((v.bit_length() + 7) // 8, "big"))
    return {"keys": [{"kty": "RSA", "kid": "k1", "n": entier(n.n), "e": entier(n.e)}]}


def id_token():
    infos = {"aud": CLIENT_ID, "iss": f"https://login.microsoftonline.com/{TENANT}/v2.0", "tid": TENANT, "oid": "o1",
             "preferred_username": "claire@cabinet-exemple.fr", "iat": int(time.time()), "nbf": int(time.time()),
             "exp": int(time.time()) + 3600, "nonce": MS.get("nonce", "")}
    debut = b64u(json.dumps({"alg": "RS256", "kid": "k1"}).encode()) + "." + b64u(json.dumps(infos).encode())
    return debut + "." + b64u(CLE.sign(debut.encode(), padding.PKCS1v15(), hashes.SHA256()))


@pytest.fixture(scope="module")
def serveur(faux):
    data = tempfile.mkdtemp(prefix="vp-api-")
    env = dict(os.environ, VP_AUTORITE=f"{faux}/organizations/oauth2/v2.0", VP_GRAPH=faux, VP_PENNYLANE=f"{faux}/api/external",
               VP_MICROSOFT_CLIENT_ID=CLIENT_ID, VP_COMPTES="claire:Claire Moreau",
               VP_SHAREPOINT="https://cabinet.invalid/sites/cabinet/Documents partages", VP_SHAREPOINT_CLIENTS="Dossiers/Comptabilite")
    env.pop("DEMO", None)
    p, url = lancer_php(env, data)
    yield {"url": url, "env": dict(env, VP_DATA=data), "data": data}
    p.terminate()
    shutil.rmtree(data, ignore_errors=True)


@pytest.fixture()
def c(serveur):
    """Installation neuve : data/ vide, comptes créés par outils/initialiser.php, Claire connectée."""
    for n in os.listdir(serveur["data"]):
        chemin = os.path.join(serveur["data"], n)
        shutil.rmtree(chemin) if os.path.isdir(chemin) else os.remove(chemin)
    mdp = json.loads(subprocess.run([PHP, "outils/initialiser.php", "--json"], cwd=RACINE, env=serveur["env"], capture_output=True,
                                    text=True, check=True, timeout=60).stdout)
    assert set(mdp) == {"claire"}
    pl_reinitialiser()
    sp_reinitialiser()
    MS.clear()
    MS.update({"id_token": id_token, "jwks": jwks})
    APPELS.clear()
    client = Client(serveur["url"])
    client.mdp = mdp["claire"]
    client.data = serveur["data"]
    client.connecter("claire", mdp["claire"])
    return client


def fichier(chemin):
    with open(chemin, "rb") as f:
        return {"nom": os.path.basename(chemin), "contenu": base64.b64encode(f.read()).decode()}


def lignes_csv(r):
    texte = base64.b64decode(r["fichier"]["contenu"]).decode("utf-8-sig")
    return list(csv.reader(io.StringIO(texte), delimiter=";"))


# ---------- Connexion ----------
def test_connexion_et_protections(serveur):
    visiteur = Client(serveur["url"])
    assert visiteur.get("/api/plateformes").status_code == 401
    assert visiteur.post("/api/connexion", json={"identifiant": "claire", "mot_de_passe": "faux"}).status_code == 403  # sans l'en-tête de l'interface
    assert visiteur.post("/api/connexion", json={"identifiant": "claire", "mot_de_passe": "faux"}, headers=H).status_code == 401
    assert visiteur.get("/").headers["Content-Security-Policy"].startswith("default-src 'self'")
    assert visiteur.get("/../config.exemple.json").status_code == 404


def test_mot_de_passe_change_et_regle(c):
    r = c.post("/api/mot-de-passe", json={"ancien": c.mdp, "nouveau": "court"}, headers=H)
    assert r.status_code == 422 and "nouveau" in r.json()["champs"]
    assert c.post("/api/mot-de-passe", json={"ancien": c.mdp, "nouveau": "Tres-Bon-Mot2Passe!"}, headers=H).status_code == 200
    assert c.post("/api/plateformes/mois", json={"dossier": "1"}).status_code == 403  # POST sans l'en-tête de l'interface


# ---------- Sans aucune intégration : dépôt à la main, fichier CSV ----------
def test_parcours_sans_pennylane_ni_microsoft(c):
    p = c.get("/api/parametres").json()
    assert p["pennylane"] == "" and p["journal"] == "VT" and p["comptes"]["ventes_5,5"] == "707055"
    # Dossier saisi à la main, comptes par défaut changés dans Paramètres
    d = c.post("/api/plateformes/dossiers", json={"nom": "SUPÉRETTE DES TROIS LUNES"}, headers=H).json()
    [dossier] = d["dossiers"]
    assert dossier.startswith("local-") and d["pennylane"] == ""
    assert c.post("/api/parametres/comptes", json={"journal": "ve", "comptes": {"ventes_5,5": "7071055"}}, headers=H).status_code == 200
    m = c.post("/api/plateformes/mois", json={"dossier": dossier}, headers=H).json()
    assert "Pennylane non relié" in m["info"] and m["erreur"] == ""
    e = c.post("/api/plateformes/exports", json={"dossier": dossier, "mois": "2026-09"}, headers=H).json()
    assert e["trouve"] is None and "Compte Microsoft non connecté" in e["message"]
    r = c.post("/api/plateformes/importer", json={"dossier": dossier, "mois": "2026-09", "fichier": fichier(EXEMPLE)}, headers=H).json()
    assert "bloquants" not in r, r
    assert r["pennylane"] == "" and [x["piece"] for x in r["ecritures"]] == ["VE-UBER-2026-09", "VE-DELIV-2026-09"]
    assert any("Liquide vaisselle" in x for x in r["remarques"])
    # Le fichier d'écritures : équilibré, mêmes montants que l'écriture affichée, comptes réglés
    lignes = lignes_csv(r)
    assert lignes[0] == ["Date", "Journal", "Pièce", "Compte", "Libellé", "Débit", "Crédit"]
    assert {l[0] for l in lignes[1:]} == {"30/09/2026"} and {l[1] for l in lignes[1:]} == {"VE"}
    assert "7071055" in {l[3] for l in lignes[1:]}
    somme = lambda k: sum(Decimal(l[k].replace(",", ".") or 0) for l in lignes[1:])
    assert somme(5) == somme(6) == Decimal(sum(e["lignes"][0]["debit"] for e in r["ecritures"])) / 100
    assert r["fichier"]["nom"] == "ecritures-VE-2026-09.csv"
    # Sans jeton, rien ne part vers Pennylane
    envoi = c.post("/api/plateformes/envoyer", json={"dossier": dossier}, headers=H)
    assert envoi.status_code == 409 and "Jeton API Pennylane absent" in envoi.json()["erreur"]
    assert not [a for a in APPELS if a[1].startswith("/api/external")]


def test_liste_de_dossiers_importee_et_renommee(c):
    with open(os.path.join(RACINE, "exemples", "dossiers.csv"), "rb") as f:
        r = c.post("/api/plateformes/dossiers/import", json={"fichier": {"nom": "dossiers.csv", "contenu": base64.b64encode(f.read()).decode()}},
                   headers=H).json()
    assert r["importes"] == 3 and sorted(r["dossiers"].values()) == ["MINI MARCHÉ DES EXEMPLES", "SUPÉRETTE DES TROIS LUNES", "ÉPICERIE PETIT PHARE"]
    phare = next(k for k, v in r["dossiers"].items() if v == "ÉPICERIE PETIT PHARE")
    assert r["schemas"][phare]["tva"]["20"] == "44571200"
    renomme = c.post("/api/plateformes/dossiers", json={"id": phare, "nom": "ÉPICERIE DU PETIT PHARE"}, headers=H).json()
    assert renomme["dossiers"][phare] == "ÉPICERIE DU PETIT PHARE" and len(renomme["dossiers"]) == 3
    # même fichier une 2e fois : les dossiers retrouvés par leur nom ne sont pas doublés
    with open(os.path.join(RACINE, "exemples", "dossiers.csv"), "rb") as f:
        r2 = c.post("/api/plateformes/dossiers/import", json={"fichier": {"nom": "dossiers.csv", "contenu": base64.b64encode(f.read()).decode()}},
                    headers=H).json()
    assert len(r2["dossiers"]) == 4  # le dossier renommé ne porte plus le même nom : ajouté de nouveau
    retire = c.post("/api/plateformes/dossiers", json={"id": phare, "action": "retirer"}, headers=H).json()
    assert phare not in retire["dossiers"]


# ---------- Avec Pennylane et SharePoint ----------
def connecter_microsoft(c):
    r = c.post("/api/microsoft/connexion", headers=H).json()
    q = parse_qs(urlparse(r["url"]).query)
    assert q["scope"][0] == "offline_access openid profile User.Read Files.Read.All" and q["code_challenge_method"] == ["S256"]
    MS["nonce"] = q["nonce"][0]
    retour = c.post("/api/microsoft/retour", json={"adresse": f"http://localhost/?code=c1&state={q['state'][0]}"}, headers=H)
    assert retour.status_code == 200, retour.text
    assert retour.json()["adresse"] == "claire@cabinet-exemple.fr"


def ranger_export(client, annee, nom, contenu):
    d = sp_ajouter("racine", client, True)
    d = sp_ajouter(d, str(annee), True)
    d = sp_ajouter(d, "Comptable", True)
    sp_ajouter(d, "pennylane_import_2026-08.csv", False, b"ne pas lire")  # fichier du cabinet rangé au même endroit
    d = sp_ajouter(d, "Ventes PLATEFORMES", True)
    sp_ajouter(d, nom, False, contenu)


def test_parcours_pennylane_et_sharepoint(c):
    assert c.post("/api/pennylane/jeton", json={"jeton": "jeton-test"}, headers=H).status_code == 200
    assert c.get("/api/parametres").json()["jeton"] == "jeto…test"
    assert "jeton-test" not in open(os.path.join(c.data, "pennylane.json"), encoding="utf-8").read()  # gardé chiffré
    # Dossier choisi dans Pennylane : comptes proposés d'après le plan comptable (aucune écriture passée)
    assert c.get("/api/plateformes/cabinet").json()["dossiers"] == [{"id": "4242", "nom": "SUPÉRETTE TEST"}]
    d = c.post("/api/plateformes/dossiers", json={"id": "4242", "nom": "SUPÉRETTE TEST"}, headers=H).json()
    s = d["schemas"]["4242"]
    assert s["plateformes"] == {"Uber Eats": {"client": "411UBER"}, "Deliveroo": {"client": "411DELIV"}}
    assert s["ventes"] == {"20": "707200", "10": "707100", "5,5": "707055"} and s["tva"] == {"20": "4457120", "10": "4457110", "5,5": "4457105"}
    # Export trouvé tout seul dans SharePoint
    connecter_microsoft(c)
    with open(EXEMPLE, "rb") as f:
        ranger_export("SUPÉRETTE TEST", 2026, "export-caisse-2026-09.csv", f.read())
    e = c.post("/api/plateformes/exports", json={"dossier": "4242", "mois": "2026-09"}, headers=H).json()
    assert e["trouve"]["nom"] == "export-caisse-2026-09.csv" and e["trouve"]["periode"] == "septembre 2026", e
    r = c.post("/api/plateformes/importer", json={"dossier": "4242", "mois": "2026-09", "sharepoint": e["trouve"]}, headers=H).json()
    assert "bloquants" not in r, r
    assert r["pennylane"] == "connecte"
    # Envoi : une écriture par plateforme, exactement celle qui a été vérifiée
    ok = c.post("/api/plateformes/envoyer", json={"dossier": "4242"}, headers=H).json()
    assert [x["piece"] for x in ok["ecritures"]] == ["VT-UBER-2026-09", "VT-DELIV-2026-09"]
    assert len(PL["ecritures"]) == 2
    uber = PL["ecritures"][0]
    assert (uber["date"], uber["label"], uber["journal_id"], uber["piece_number"]) == ("2026-09-30", "Ventes Uber Eats septembre 2026", 7, "VT-UBER-2026-09")
    ids = {a["number"]: a["id"] for a in PL["comptes"]}
    attendu = r["ecritures"][0]["lignes"]
    assert [(l["ledger_account_id"], l["debit"], l["credit"]) for l in uber["ledger_entry_lines"]] == [
        (ids[l["compte"]], f"{l['debit'] / 100:.2f}", f"{l['credit'] / 100:.2f}") for l in attendu]
    assert sum(Decimal(l["debit"]) for l in uber["ledger_entry_lines"]) == sum(Decimal(l["credit"]) for l in uber["ledger_entry_lines"])
    # Jamais deux fois : double clic, puis nouvel import du même mois
    assert c.post("/api/plateformes/envoyer", json={"dossier": "4242"}, headers=H).status_code == 409
    encore = c.post("/api/plateformes/importer", json={"dossier": "4242", "mois": "2026-09", "fichier": fichier(EXEMPLE)}, headers=H).json()
    assert any("Déjà passée dans Pennylane" in b for b in encore["bloquants"])
    m = c.post("/api/plateformes/mois", json={"dossier": "4242"}, headers=H).json()
    assert any(x["cle"] == "2026-09" and x["fait"] for x in m["mois"]) or m["propose"] > "2026-09"
    assert len(PL["ecritures"]) == 2


def test_schema_relu_dans_les_ecritures_passees(c):
    c.post("/api/pennylane/jeton", json={"jeton": "jeton-test"}, headers=H)
    PL["ecritures"].append({"id": 5, "date": "2026-08-31", "label": "Ventes Deliveroo août 2026", "piece_number": "VT-DELIV-2026-08", "journal_id": 7})
    PL["lignes"][5] = [{"ledger_account": {"number": "4110042"}, "label": "Ventes Deliveroo août 2026", "debit": "100.00"},
                       {"ledger_account": {"number": "44571055"}, "label": "Ventes Deliveroo août 2026 - TVA 5,5 %", "debit": "0"},
                       {"ledger_account": {"number": "7071055"}, "label": "Ventes Deliveroo août 2026 - HT TVA 5,5 %", "debit": "0"}]
    PL["comptes"] += [{"id": 300 + i, "number": n, "label": n} for i, n in enumerate(["4110042", "44571055", "7071055"])]
    d = c.post("/api/plateformes/dossiers", json={"id": "4242", "nom": "SUPÉRETTE TEST"}, headers=H).json()
    s = d["schemas"]["4242"]
    assert s["plateformes"]["Deliveroo"] == {"client": "4110042"} and s["ventes"]["5,5"] == "7071055" and s["tva"]["5,5"] == "44571055"
    assert s["ventes"]["20"] == "707200"  # taux jamais passé : complété d'après le plan comptable
    assert "VT-DELIV-2026-08" in s["source"]
    m = c.post("/api/plateformes/mois", json={"dossier": "4242"}, headers=H).json()
    aout = next(x for x in m["mois"] if x["cle"] == "2026-08")
    assert "Deliveroo passée" in aout["etat"] and "Uber Eats à passer" in aout["etat"] and not aout["fait"]
    # Uber Eats d'août seul est importé : Deliveroo est déjà passée
    with open(EXEMPLE, encoding="utf-8") as f:
        aout_csv = f.read().replace("/09/2026", "/08/2026")
    r = c.post("/api/plateformes/importer", json={"dossier": "4242", "mois": "2026-08", "fichier": {"nom": "aout.csv",
               "contenu": base64.b64encode(aout_csv.encode()).decode()}}, headers=H).json()
    assert "bloquants" not in r, r
    assert [e["piece"] for e in r["ecritures"]] == ["VT-UBER-2026-08"] and any("non réimportée" in x for x in r["remarques"])


@pytest.mark.parametrize("panne,message", [
    ("compte", "Compte(s) absent(s) du plan comptable du dossier : 707100."),
    ("journal", "Journal VT introuvable dans ce dossier."),
    ("droits", "Le jeton API ne permet pas d'écrire"),
])
def test_controles_dans_pennylane_bloquent(c, panne, message):
    c.post("/api/pennylane/jeton", json={"jeton": "jeton-test"}, headers=H)
    c.post("/api/plateformes/dossiers", json={"id": "4242", "nom": "SUPÉRETTE TEST"}, headers=H)
    if panne == "compte":
        PL["comptes"] = [a for a in PL["comptes"] if a["number"] != "707100"]
    if panne == "journal":
        PL["journaux"] = [{"id": 8, "code": "HA"}]
    if panne == "droits":
        PL["scopes"] = ["ledger_entries:readonly"]
    r = c.post("/api/plateformes/importer", json={"dossier": "4242", "mois": "2026-09", "fichier": fichier(EXEMPLE)}, headers=H).json()
    assert any(message in b for b in r["bloquants"]), r
    assert not PL["ecritures"]


def test_refus_de_pennylane_puis_comptes_corriges(c):
    c.post("/api/pennylane/jeton", json={"jeton": "jeton-test"}, headers=H)
    c.post("/api/plateformes/dossiers", json={"id": "4242", "nom": "SUPÉRETTE TEST"}, headers=H)
    # compte de ventes à 10 % effacé à la main : l'import s'arrête avant Pennylane
    s = c.post("/api/plateformes/schema", json={"dossier": "4242", "schema": {"ventes": {"10": ""}}}, headers=H).json()["schema"]
    assert s["ventes"]["10"] == "" and s["source"].endswith("corrigé à la main")
    r = c.post("/api/plateformes/importer", json={"dossier": "4242", "mois": "2026-09", "fichier": fichier(EXEMPLE)}, headers=H).json()
    assert r["comptes"] is True and any("compte de ventes à 10 % non défini" in b for b in r["bloquants"])
    c.post("/api/plateformes/schema", json={"dossier": "4242", "schema": {"ventes": {"10": "707100"}}}, headers=H)
    assert "ecritures" in c.post("/api/plateformes/importer", json={"dossier": "4242", "mois": "2026-09", "fichier": fichier(EXEMPLE)}, headers=H).json()
    PL["refus"] = True
    refus = c.post("/api/plateformes/envoyer", json={"dossier": "4242"}, headers=H)
    assert refus.status_code == 409 and "Pennylane a refusé" in refus.json()["erreur"]
    PL["refus"] = False  # nouvel essai : tout part, une seule fois
    assert len(c.post("/api/plateformes/envoyer", json={"dossier": "4242"}, headers=H).json()["ecritures"]) == 2
    assert len(PL["ecritures"]) == 2


def test_microsoft_deconnexion_et_retour_inconnu(c):
    connecter_microsoft(c)
    assert c.get("/api/microsoft").json()["adresse"] == "claire@cabinet-exemple.fr"
    assert c.post("/api/microsoft/deconnexion", headers=H).status_code == 200
    assert c.get("/api/microsoft").json()["adresse"] == ""
    mauvais = c.post("/api/microsoft/retour", json={"adresse": "http://localhost/?code=c1&state=pirate"}, headers=H)
    assert mauvais.status_code == 409
