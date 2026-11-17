"""Commun aux tests : PHP, banc d'essai des fonctions de calcul, vrai serveur PHP (php -S) sur des données temporaires,
faux Microsoft (connexion, SharePoint) et faux Pennylane (API v2 + firm), sans jamais toucher à un vrai service."""
import base64
import json
import os
import shutil
import socket
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, unquote, urlparse

import pytest
import requests

RACINE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get("VP_PHP") or shutil.which("php") or r"C:\dev\outils\php83\php.exe"
H = {"X-Interface": "1"}


def php_appels(*appels):
    """Appelle des fonctions PHP (tests/outil.php) : [(fonction, [arguments])] -> résultats."""
    r = subprocess.run([PHP, "tests/outil.php"], cwd=RACINE, input=json.dumps(list(appels)), capture_output=True, text=True,
                       env=dict(os.environ, VP_DATA=tempfile.mkdtemp(prefix="vp-calcul-")),
                       encoding="utf-8", timeout=120)
    assert r.returncode == 0, r.stdout + r.stderr
    return json.loads(r.stdout)


def php(fonction, *arguments):
    resultat = php_appels([fonction, list(arguments)])[0]
    assert not (isinstance(resultat, dict) and "erreur" in resultat and len(resultat) == 1), resultat
    return resultat


def b64(octets):
    return {"base64": base64.b64encode(octets).decode()}


def curl_disponible():
    r = subprocess.run([PHP, "-r", "echo extension_loaded('curl') ? 'oui' : 'non';"], capture_output=True, text=True, timeout=30)
    return r.stdout.strip() == "oui"


def port_libre():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def lancer_php(env, data):
    """Serveur PHP intégré sur un port libre ; attend qu'il réponde. Retourne (processus, adresse)."""
    port = port_libre()
    p = subprocess.Popen([PHP, "-S", f"127.0.0.1:{port}", "-t", "public", "public/routeur.php"], cwd=RACINE,
                         env=dict(env, VP_DATA=data), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    url = f"http://127.0.0.1:{port}"
    for _ in range(100):
        try:
            requests.get(url + "/", timeout=1)
            break
        except requests.ConnectionError:
            time.sleep(0.1)
    return p, url


class Client(requests.Session):
    def __init__(self, base):
        super().__init__()
        self.base = base

    def request(self, methode, url, *a, **k):
        return super().request(methode, self.base + url, *a, **k)

    def connecter(self, identifiant, mot_de_passe):
        r = self.post("/api/connexion", json={"identifiant": identifiant, "mot_de_passe": mot_de_passe}, headers=H)
        assert r.status_code == 200, r.text
        return r.json()


# ---------- Faux services : un seul serveur, qui note tout ce qu'il reçoit ----------
APPELS = []          # (méthode, chemin, corps) de chaque requête reçue
PL = {}              # état du faux Pennylane
SP = {}              # faux SharePoint : id -> {nom, parent, dossier, contenu}
MS = {}              # faux Microsoft (connexion)


def pl_reinitialiser():
    PL.clear()
    PL.update({"jeton": "jeton-test", "dossiers": [{"id": 4242, "name": "SUPÉRETTE TEST"}],
               "journaux": [{"id": 7, "code": "VT"}, {"id": 8, "code": "HA"}],
               "comptes": [{"id": 100 + i, "number": n, "label": l} for i, (n, l) in enumerate([
                   ("411UBER", "Client Uber Eats"), ("411DELIV", "Client Deliveroo"), ("707200", "Ventes marchandises 20%"),
                   ("707100", "Ventes marchandises 10%"), ("707055", "Ventes marchandises alimentaires 5,5%"),
                   ("4457120", "TVA collectée à 20%"), ("4457110", "TVA collectée à 10%"), ("4457105", "TVA collectée à 5,5%")])],
               "ecritures": [], "lignes": {}, "scopes": ["ledger_entries:all", "journals:readonly"], "refus": False})


def sp_reinitialiser():
    SP.clear()
    SP["racine"] = {"nom": "Comptabilite", "parent": None, "dossier": True}


def sp_ajouter(parent, nom, dossier=False, contenu=b""):
    i = f"i{len(SP)}"
    SP[i] = {"nom": nom, "parent": parent, "dossier": dossier, "contenu": contenu}
    return i


class FauxServices(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def repondre(self, code, corps, brut=None):
        data = brut if brut is not None else json.dumps(corps).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/octet-stream" if brut is not None else "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def corps(self):
        n = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(n).decode() if n else ""

    def do_GET(self):
        self.traiter("GET", "")

    def do_POST(self):
        self.traiter("POST", self.corps())

    def traiter(self, methode, brut):
        u = urlparse(self.path)
        APPELS.append((methode, u.path, brut))
        q = {k: v[0] for k, v in parse_qs(u.query).items()}
        if u.path.startswith("/api/external/"):
            return self.pennylane(methode, u.path.removeprefix("/api/external"), q, brut)
        if u.path.endswith("/token"):
            champs = {k: v[0] for k, v in parse_qs(brut).items()}
            if champs.get("grant_type") == "authorization_code" and champs.get("code") == "c1" and champs.get("code_verifier"):
                return self.repondre(200, {"access_token": "t", "refresh_token": "r", "expires_in": 3600, "id_token": MS["id_token"]()})
            return self.repondre(400, {"error": "invalid_grant", "error_description": "AADSTS70008: expired"})
        if u.path.endswith("/discovery/v2.0/keys"):
            return self.repondre(200, MS["jwks"]())
        return self.graph(methode, u.path, q)

    # --- faux Graph (SharePoint) ---
    def graph(self, methode, chemin, q):
        if self.headers.get("Authorization") != "Bearer t":
            return self.repondre(401, {"error": {"message": "jeton"}})
        if chemin.startswith("/shares/"):  # adresse du dossier des pièces d'un client -> dossier du faux SharePoint
            code = chemin.split("/")[2][2:]
            adresse = base64.urlsafe_b64decode(code + "=" * (-len(code) % 4)).decode()
            fin = unquote(adresse).split("/Dossiers/Comptabilite/")[-1].split("/")
            noeud = "racine"
            for nom in fin:
                noeud = next((i for i, x in SP.items() if x["parent"] == noeud and x["nom"] == nom), None)
                if noeud is None:
                    return self.repondre(404, {"error": {"message": "introuvable"}})
            return self.repondre(200, {"id": noeud, "name": SP[noeud]["nom"], "folder": {}, "parentReference": {"driveId": "d1"}})
        morceaux = chemin.split("/")  # /drives/d1/items/<id>[/children|/content]
        if len(morceaux) >= 5 and morceaux[1] == "drives":
            i = morceaux[4]
            if i not in SP:
                return self.repondre(404, {"error": {"message": "introuvable"}})
            if chemin.endswith("/children"):
                return self.repondre(200, {"value": [{"id": k, "name": x["nom"], "lastModifiedDateTime": f"2026-10-0{1 + int(k[1:]) % 9}T10:00:00Z",
                                                      **({"folder": {}} if x["dossier"] else {"file": {}})}
                                                     for k, x in SP.items() if x["parent"] == i]})
            if chemin.endswith("/content"):
                return self.repondre(200, None, SP[i]["contenu"])
            return self.repondre(200, {"id": i})
        return self.repondre(404, {"error": {"message": "inconnu"}})

    # --- faux Pennylane ---
    def pennylane(self, methode, chemin, q, brut):
        if self.headers.get("Authorization") != f"Bearer {PL['jeton']}":
            return self.repondre(401, {"error": "Unauthorized"})
        if chemin == "/firm/v1/companies":
            return self.repondre(200, {"items": PL["dossiers"], "total_pages": 1})
        if self.headers.get("X-Company-Id") is None:
            return self.repondre(400, {"error": "X-Company-Id manquant"})
        page = lambda items: self.repondre(200, {"items": items, "has_more": False})
        if chemin == "/v2/journals":
            return page(PL["journaux"])
        if chemin == "/v2/ledger_accounts":
            return page(PL["comptes"])
        if chemin == "/v2/me":
            return self.repondre(200, {"scopes": PL["scopes"]})
        if chemin == "/v2/ledger_entries" and methode == "GET":
            filtres = json.loads(q.get("filter", "[]"))
            items = PL["ecritures"]
            for f in filtres:
                if f["field"] == "date" and f["operator"] == "eq":
                    items = [e for e in items if e["date"] == f["value"]]
                if f["field"] == "date" and f["operator"] == "gteq":
                    items = [e for e in items if e["date"] >= f["value"]]
                if f["field"] == "journal_id":
                    items = [e for e in items if e.get("journal_id") == f["value"]]
            return page(items)
        if chemin == "/v2/ledger_entries" and methode == "POST":
            if PL["refus"]:
                return self.repondre(422, {"error": "Écriture refusée par le faux Pennylane"})
            e = json.loads(brut)
            e["id"] = 9000 + len(PL["ecritures"])
            PL["ecritures"].append(e)
            return self.repondre(201, e)
        if chemin.startswith("/v2/ledger_entries/") and chemin.endswith("/ledger_entry_lines"):
            return page(PL["lignes"].get(int(chemin.split("/")[3]), []))
        return self.repondre(404, {"error": "inconnu"})


@pytest.fixture(scope="session")
def faux():
    s = ThreadingHTTPServer(("127.0.0.1", port_libre()), FauxServices)
    threading.Thread(target=s.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{s.server_port}"
    s.shutdown()
