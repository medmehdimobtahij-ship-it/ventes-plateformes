"""Mode démo (DEMO=1) : démarre sans rien configurer, sert des données fictives, simule SharePoint et l'envoi dans Pennylane,
et ne fait AUCUNE requête vers l'extérieur. Les adresses Microsoft et Pennylane pointent ici vers le faux serveur des tests :
le moindre appel y serait noté (APPELS)."""
import os
import shutil
import tempfile

import pytest

from commun import APPELS, H, Client, lancer_php


@pytest.fixture(scope="module")
def demo(faux):
    data = tempfile.mkdtemp(prefix="vp-data-demo-")
    env = dict(os.environ, DEMO="1", VP_AUTORITE=f"{faux}/organizations/oauth2/v2.0", VP_GRAPH=faux, VP_PENNYLANE=f"{faux}/api/external",
               VP_MICROSOFT_CLIENT_ID="00000000-0000-4000-8000-00000000c0de")
    APPELS.clear()
    p, url = lancer_php(env, data)
    yield {"url": url, "data": data}
    p.terminate()
    shutil.rmtree(data, ignore_errors=True)


@pytest.fixture()
def c(demo):
    client = Client(demo["url"])
    client.connecter("demo", "demo")
    return client


def test_demarre_et_affiche_le_compte_de_demonstration(demo):
    visiteur = Client(demo["url"])
    page = visiteur.get("/")
    assert page.status_code == 200 and "<title>Ventes plateformes</title>" in page.text
    assert visiteur.get("/api/connexion/options").json() == {"demo": True}
    assert visiteur.post("/api/connexion", json={"identifiant": "demo", "mot_de_passe": "faux"}, headers=H).status_code == 401
    direct = Client(demo["url"])
    assert direct.get("/?demo", allow_redirects=False).status_code == 302  # connexion directe (captures d'écran)
    assert direct.get("/api/moi").json()["demo"] is True
    assert os.path.isfile(os.path.join(demo["data"], "comptes.json"))


def test_donnees_fictives(c):
    d = c.get("/api/plateformes").json()
    assert d["demo"] is True and d["pennylane"] == "demo"
    assert sorted(d["dossiers"].values()) == ["MINI MARCHÉ DES EXEMPLES", "SUPÉRETTE DES TROIS LUNES", "ÉPICERIE PETIT PHARE"]
    p = c.get("/api/parametres").json()
    assert p["demo"] is True and p["jeton"] == ""


def test_parcours_complet_simule(c):
    m = c.post("/api/plateformes/mois", json={"dossier": "900101"}, headers=H).json()
    trois_mois = [x for x in m["mois"] if x["fait"]]
    assert len(trois_mois) == 1  # mois déjà « passé » dans le Pennylane simulé
    mois = m["propose"]
    e = c.post("/api/plateformes/exports", json={"dossier": "900101", "mois": mois}, headers=H).json()
    assert e["trouve"] and e["trouve"]["drive"] == "demo", e
    r = c.post("/api/plateformes/importer", json={"dossier": "900101", "mois": mois, "sharepoint": e["trouve"]}, headers=H).json()
    assert "bloquants" not in r, r
    assert {x["plateforme"] for x in r["ecritures"]} == {"Uber Eats", "Deliveroo"}
    for x in r["ecritures"]:
        assert sum(l["debit"] for l in x["lignes"]) == sum(l["credit"] for l in x["lignes"]) > 0
    assert any(x.startswith("Mode démo") for x in r["remarques"])
    ok = c.post("/api/plateformes/envoyer", json={"dossier": "900101"}, headers=H).json()
    assert ok["simule"] is True and len(ok["ecritures"]) == 2
    apres = c.post("/api/plateformes/mois", json={"dossier": "900101"}, headers=H).json()
    assert apres["propose"] > mois
    encore = c.post("/api/plateformes/importer", json={"dossier": "900101", "mois": mois, "sharepoint": e["trouve"]}, headers=H).json()
    assert any("Déjà passée" in b for b in encore["bloquants"])


def test_export_excel_et_compte_manquant(c):
    m = c.post("/api/plateformes/mois", json={"dossier": "900102"}, headers=H).json()["propose"]
    e = c.post("/api/plateformes/exports", json={"dossier": "900102", "mois": m}, headers=H).json()
    assert e["trouve"]["nom"].endswith(".xlsx")
    assert "ecritures" in c.post("/api/plateformes/importer", json={"dossier": "900102", "mois": m, "sharepoint": e["trouve"]}, headers=H).json()
    m3 = c.post("/api/plateformes/mois", json={"dossier": "900103"}, headers=H).json()["propose"]
    e3 = c.post("/api/plateformes/exports", json={"dossier": "900103", "mois": m3}, headers=H).json()
    r3 = c.post("/api/plateformes/importer", json={"dossier": "900103", "mois": m3, "sharepoint": e3["trouve"]}, headers=H).json()
    assert r3["comptes"] is True and any("compte de ventes à 10 % non défini" in b for b in r3["bloquants"])


def test_fichier_hors_du_sharepoint_simule_refuse(c):
    r = c.post("/api/plateformes/importer", json={"dossier": "900101", "mois": "2026-09",
                                                 "sharepoint": {"nom": "x.csv", "id": "../comptes.json", "drive": "demo"}}, headers=H).json()
    assert "Lecture du fichier impossible" in r["bloquants"][0]


@pytest.mark.parametrize("methode,route,corps", [
    ("POST", "/api/microsoft/connexion", {}), ("POST", "/api/microsoft/retour", {"adresse": "http://localhost/?code=c1&state=x"}),
    ("POST", "/api/pennylane/jeton", {"jeton": "x"}), ("GET", "/api/plateformes/cabinet", None),
    ("POST", "/api/plateformes/schema/lire", {"dossier": "900101"}),
])
def test_services_externes_desactives_avec_message_clair(c, methode, route, corps):
    r = c.request(methode, route, json=corps, headers=H)
    assert r.status_code == 409 and r.json()["erreur"].startswith("Mode démo : ") and "désactivé" in r.json()["erreur"]


def test_aucune_requete_vers_l_exterieur(c):
    """Dernier test du module : après tout le parcours, le faux Microsoft / Pennylane n'a jamais été appelé."""
    assert APPELS == []
