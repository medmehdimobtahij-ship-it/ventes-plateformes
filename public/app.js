/* Ventes plateformes : l'interface (sans bibliothèque). Ce fichier : outils communs, coque (en-tête), connexion et
   Paramètres ; plateformes.js : l'assistant d'écriture. Données, calculs et contrôles : côté serveur (src/). */
"use strict";

// ---------- Outils ----------
const ICONES = {
  // Tracés Lucide (lucide.dev, licence ISC)
  retour: '<path d="M15 6l-6 6 6 6"/>',
  parametres: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
  lune: '<path d="M19.5 14.6A7.6 7.6 0 0 1 9.4 4.5a7.6 7.6 0 1 0 10.1 10.1z"/>',
  soleil: '<circle cx="12" cy="12" r="3.8"/><path d="M12 2.8v2M12 19.2v2M2.8 12h2M19.2 12h2M5.5 5.5l1.4 1.4M17.1 17.1l1.4 1.4M5.5 18.5l1.4-1.4M17.1 6.9l1.4-1.4"/>',
  recherche: '<circle cx="10.8" cy="10.8" r="6.3"/><path d="M15.5 15.5l4.5 4.5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  trombone: '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
  telecharger: '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
  envoyer: '<path d="M14.5 21.5 22 2 2.5 9.5l8 3z"/><path d="M22 2 10.5 12.5"/>',
  copier: '<rect x="8" y="8" width="13" height="13" rx="2.5"/><path d="M16 8V5.5A2.5 2.5 0 0 0 13.5 3h-8A2.5 2.5 0 0 0 3 5.5v8A2.5 2.5 0 0 0 5.5 16H8"/>',
  fermer: '<path d="M6 6l12 12M18 6 6 18"/>',
  coche: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  dossier: '<path d="M3.5 7.5a2 2 0 0 1 2-2h3.7l2 2h7.3a2 2 0 0 1 2 2v7.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
  lien: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
};
const icone = (nom, cls = "") => `<svg class="ic ${cls}" viewBox="0 0 24 24" aria-hidden="true">${ICONES[nom]}</svg>`;

function el(html) { const t = document.createElement("template"); t.innerHTML = html.trim(); return t.content.firstElementChild; }
const echap = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const $ = (sel, racine = document) => racine.querySelector(sel);
const $$ = (sel, racine = document) => [...racine.querySelectorAll(sel)];
const attendre = (ms) => new Promise((r) => setTimeout(r, ms));
const MOUVEMENT = !matchMedia("(prefers-reduced-motion: reduce)").matches;
const pluriel = (n, mot, motPluriel = mot + "s") => `${n} ${n > 1 ? motPluriel : mot}`;

function initiales(nom) {
  const mots = (nom || "").replace(/-/g, " ").split(/\s+/).filter((m) => /[\p{L}\p{N}]/u.test(m[0] || ""));
  if (!mots.length) return "?";
  return (mots[0][0] + (mots[1] ? mots[1][0] : mots[0].slice(1, 2))).toUpperCase();
}

/** Fichier choisi -> base64 (sans l'en-tête « data:… »). */
function enBase64(fichier) {
  return new Promise((ok, ko) => {
    const r = new FileReader();
    r.onload = () => ok(String(r.result).split(",")[1] || "");
    r.onerror = () => ko(new Error(`${fichier.name} illisible.`));
    r.readAsDataURL(fichier);
  });
}
/** Fichier préparé par le serveur ({ nom, contenu en base64 }) -> téléchargé par le navigateur. */
function telechargerFichier({ nom, contenu }, type = "text/csv;charset=utf-8") {
  const octets = Uint8Array.from(atob(contenu), (c) => c.charCodeAt(0));
  const lien = el("<a></a>");
  lien.href = URL.createObjectURL(new Blob([octets], { type }));
  lien.download = nom;
  document.body.append(lien); lien.click(); lien.remove();
  setTimeout(() => URL.revokeObjectURL(lien.href), 2000);
}
async function copierTexte(texte, message = "Copié dans le presse-papiers.") {
  try { await navigator.clipboard.writeText(texte); toast(message); }
  catch {  // navigateur sans accès au presse-papiers : copie à l'ancienne
    const z = el("<textarea></textarea>"); z.value = texte; document.body.append(z); z.select();
    document.execCommand("copy"); z.remove(); toast(message);
  }
}

// ---------- Échanges avec le serveur ----------
let RELECTURE_AUTO = false;
/** Relectures automatiques (minuteurs) : le serveur ne les compte pas comme une activité (session fermée après 2 h sans rien faire). */
function relectureAuto(f) { RELECTURE_AUTO = true; try { return f(); } finally { RELECTURE_AUTO = false; } }
async function api(methode, url, corps) {
  let r;
  try {
    r = await fetch(url, { method: methode, credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Interface": "1", ...(RELECTURE_AUTO ? { "X-Interface-Auto": "1" } : {}) },
      body: corps === undefined ? undefined : JSON.stringify(corps) });
  } catch { throw new Error("Connexion au serveur impossible : vérifiez que l'application est lancée."); }  // serveur arrêté, réseau coupé
  if (r.status === 401 && url !== "/api/connexion") { pageConnexion(url === "/api/moi" ? "" : "Session expirée : reconnectez-vous."); throw new Error("401"); }
  const d = r.headers.get("content-type")?.includes("json") ? await r.json() : {};
  if (!r.ok) {
    const e = new Error(d.erreur || (d.champs ? Object.values(d.champs).join(" ") : "Erreur du serveur."));
    e.champs = d.champs; e.statut = r.status; e.action = d.action; throw e;
  }
  return d;
}

function toast(texte, options = {}) {
  const t = el(`<div class="toast ${options.erreur ? "erreur" : ""}"><span>${echap(texte)}</span></div>`);
  $("#toasts").append(t);
  const partir = () => { t.classList.add("part"); setTimeout(() => t.remove(), 260); };
  setTimeout(partir, options.duree || 3600);
}
function signalerErreur(e) { if (e.message !== "401") toast(e.message, { erreur: true, duree: 6000 }); }

// ---------- État ----------
const S = {
  moi: null, page: "plateformes",  // on arrive directement sur l'assistant
  theme: (() => { try { return localStorage.getItem("vp-theme") || "clair"; } catch { return "clair"; } })(),
};
document.documentElement.dataset.theme = S.theme;

// ---------- Connexion ----------
function pageConnexion(message = "") {
  S.moi = null;
  arreterSuivis();
  const deja = $("#racine .connexion");
  if (deja) { if (message) $(".msg-erreur", deja).textContent = message; return; }  // déjà affichée : la saisie en cours reste
  $("#couche-modales").innerHTML = "";
  $("#racine").innerHTML = "";
  const f = el(`<div class="connexion"><form autocomplete="on">
      <div class="logo"><img src="logo.svg" alt=""></div>
      <div><h1>Ventes plateformes</h1><div class="doux">Uber Eats et Deliveroo : de l'export de caisse à l'écriture comptable.</div></div>
      <div class="champ"><label for="id">Identifiant</label><input id="id" class="saisie" name="username" autocomplete="username" required></div>
      <div class="champ"><label for="mdp">Mot de passe</label><input id="mdp" class="saisie" type="password" name="password" autocomplete="current-password" required></div>
      <button class="btn principal" type="submit">Se connecter</button>
      <div class="msg-erreur">${echap(message)}</div>
      <div class="bandeau-demo cache"><b>Mode démo</b> · données fictives, aucun service externe appelé.<br>
        Identifiant <code>demo</code> · mot de passe <code>demo</code></div>
      <div class="pied-connexion">Session fermée après 2 h sans activité</div>
    </form></div>`);
  $("#racine").append(f);
  $("#id", f).focus();
  const entrer = async () => {
    try { S.moi = await api("POST", "/api/connexion", { identifiant: $("#id", f).value, mot_de_passe: $("#mdp", f).value }); await demarrer(); }
    catch (e) { $(".msg-erreur", f).textContent = e.message; }
  };
  $("form", f).onsubmit = (ev) => { ev.preventDefault(); entrer(); };
  fetch("/api/connexion/options", { credentials: "same-origin" }).then((r) => r.json()).then((o) => {
    if (!o.demo) return;
    $(".bandeau-demo", f).classList.remove("cache");  // démo : identifiants affichés, pré-remplis, connexion lancée toute seule
    if (!$("#id", f).value) { $("#id", f).value = "demo"; $("#mdp", f).value = "demo"; }
    if (!message && !pageConnexion.auto) { pageConnexion.auto = true; setTimeout(entrer, 700); }
  }).catch(() => { /* options illisibles : connexion classique */ });
}

// ---------- Coque : en-tête, pages ----------
const PAGES = { plateformes: ["Ventes plateformes", () => pagePlateformes], parametres: ["Paramètres", () => pageParametres] };

function construireCoque() {
  $("#racine").innerHTML = "";
  const c = el(`<div id="coque">
    <main id="droite">
      <header class="entete">
        <button class="rond retour cache" id="retour" title="Retour">${icone("retour")}</button>
        <span class="logo logo-entete"><img src="logo.svg" alt=""></span>
        <div style="min-width:0"><h1 id="titre"></h1><div class="sous" id="sous"></div></div>
        <div class="outils">
          ${S.moi.demo ? '<span class="pastille p-violet" title="Données fictives : Microsoft et Pennylane ne sont jamais appelés">Mode démo</span>' : ""}
          <button class="rond" id="bouton-parametres" title="Paramètres">${icone("parametres")}</button>
          <button class="rond bouton-theme" title="Changer de thème">${icone(S.theme === "sombre" ? "soleil" : "lune")}</button>
          <button class="avatar" id="moi" title="${echap(S.moi.nom)}">${echap(initiales(S.moi.nom))}</button>
        </div>
      </header>
      <section id="page"></section>
    </main>
  </div>`);
  $("#racine").append(c);
  $("#retour", c).onclick = () => aller("plateformes");
  $("#bouton-parametres", c).onclick = () => aller("parametres");
  $(".bouton-theme", c).onclick = basculerTheme;
  $("#moi", c).onclick = (ev) => menu(ev.currentTarget, [
    [`Connecté : ${S.moi.nom}`, null],
    ["Paramètres", () => aller("parametres")],
    "-",
    ["Se déconnecter", async () => {
      try { await api("POST", "/api/deconnexion"); } catch (e) { if (e.message !== "401") return signalerErreur(e); }
      location.replace("/");  // page rechargée : rien de la session précédente ne reste
    }],
  ]);
}

function basculerTheme() {
  S.theme = S.theme === "sombre" ? "clair" : "sombre";
  try { localStorage.setItem("vp-theme", S.theme); } catch { /* navigateur privé : thème non retenu */ }
  document.documentElement.dataset.theme = S.theme;
  $(".bouton-theme").innerHTML = icone(S.theme === "sombre" ? "soleil" : "lune");
}

/** Changement de page : l'ancienne s'efface, PUIS la nouvelle arrive (jamais les deux l'une sur l'autre). */
async function aller(code) {
  fermerMenus();
  const change = code !== S.page;
  S.page = code;
  const zone = $("#page");
  if (change && MOUVEMENT) { zone.classList.remove("entree"); zone.classList.add("sortie"); await attendre(140); }
  rendre();
  if (MOUVEMENT) { zone.classList.remove("sortie", "entree"); void zone.offsetWidth; zone.classList.add("entree"); }
}

function rendre() {
  const [nom, fabrique] = PAGES[S.page] || PAGES.plateformes;
  $("#titre").textContent = nom;
  $("#sous").innerHTML = "";
  $("#retour").classList.toggle("cache", S.page === "plateformes");
  $(".logo-entete").classList.toggle("cache", S.page !== "plateformes");
  $("#bouton-parametres").classList.toggle("cache", S.page === "parametres");
  const zone = $("#page");
  zone.className = "";
  zone.innerHTML = "";
  fabrique()(zone);
}

// ---------- Menus ----------
function fermerMenus() { $$(".menu-flottant").forEach((m) => m.remove()); }
/** items : [libellé, fonction] ; [libellé, null] = titre ; "-" = séparateur. */
function menu(ancre, items) {
  fermerMenus();
  const m = el(`<div class="menu-flottant" role="menu"></div>`);
  for (const it of items) {
    if (it === "-") { m.append(el("<hr>")); continue; }
    const [libelle, fn] = it;
    if (!fn) { m.append(el(`<div class="titre-menu">${echap(libelle)}</div>`)); continue; }
    const b = el(`<button role="menuitem">${echap(libelle)}</button>`);
    b.onclick = () => { fermerMenus(); fn(); };
    m.append(b);
  }
  document.body.append(m);
  const r = ancre.getBoundingClientRect(), mr = m.getBoundingClientRect();
  m.style.left = Math.max(10, Math.min(r.left, innerWidth - mr.width - 10)) + "px";
  m.style.top = Math.max(10, r.bottom + mr.height + 6 > innerHeight ? r.top - mr.height - 6 : r.bottom + 6) + "px";
  setTimeout(() => addEventListener("pointerdown", function dehors(e) {
    if (!m.contains(e.target)) { m.remove(); removeEventListener("pointerdown", dehors); }
  }), 0);
}

// ---------- Fenêtres ----------
function fenetre({ titre, sous = "", corps = "", pied = "", classe = "" }) {
  const v = el(`<div class="voile"><div class="fenetre ${classe}" role="dialog" aria-modal="true">
      <div class="f-tete"><div style="min-width:0"><h2>${titre}</h2>${sous ? `<div class="sous">${sous}</div>` : ""}</div>
        <button class="rond fermer" title="Fermer (Échap)">${icone("fermer")}</button></div>
      <div class="f-corps">${corps}</div>${pied ? `<div class="f-pied">${pied}</div>` : ""}</div></div>`);
  const f = $(".fenetre", v);
  const fermer = () => {
    if (!v.isConnected) return;
    removeEventListener("keydown", echap_);
    v.classList.add("part"); setTimeout(() => v.remove(), MOUVEMENT ? 180 : 0);
    f.aLaFermeture?.();
  };
  const echap_ = (e) => { if (e.key === "Escape" && $("#couche-modales").lastElementChild === v) fermer(); };
  addEventListener("keydown", echap_);
  $(".fermer", f).onclick = fermer;
  v.addEventListener("pointerdown", (e) => { if (e.target === v) v.dataset.dehors = "1"; });
  v.addEventListener("pointerup", (e) => { if (e.target === v && v.dataset.dehors) fermer(); delete v.dataset.dehors; });
  $("#couche-modales").append(v);
  f.fermer = fermer;
  setTimeout(() => $("input:not([type=checkbox]):not([type=file]), select, textarea", f)?.focus(), 60);
  return f;
}
/** Question oui / non dans une fenêtre du site (jamais la boîte grise du navigateur). */
function confirmer({ titre, texte = "", oui = "Oui", non = "Annuler", danger = false }) {
  return new Promise((ok) => {
    const f = fenetre({ titre, classe: "petite", corps: texte ? `<div>${texte}</div>` : "",
      pied: `<button class="btn" data-a="non">${echap(non)}</button><button class="btn principal ${danger ? "danger-plein" : ""}" data-a="oui">${echap(oui)}</button>` });
    let reponse = false;
    f.aLaFermeture = () => ok(reponse);
    $('[data-a="non"]', f).onclick = () => f.fermer();
    $('[data-a="oui"]', f).onclick = () => { reponse = true; f.fermer(); };
    setTimeout(() => $('[data-a="oui"]', f).focus(), 80);
  });
}

function erreursChamps(f, champs = {}) {
  $$(".msg-erreur[data-pour]", f).forEach((m) => m.remove());
  $$(".saisie.erreur", f).forEach((x) => x.classList.remove("erreur"));
  for (const [cle, msg] of Object.entries(champs)) {
    const c = $(`[name="${cle}"]`, f);
    if (c) { c.classList.add("erreur"); c.closest(".champ")?.append(el(`<div class="msg-erreur" data-pour="${cle}">${echap(msg)}</div>`)); }
    else toast(msg, { erreur: true });
  }
}
const champ = (nom, libelle, controle, aide = "") =>
  `<div class="champ"><label for="c-${nom}">${libelle}</label>${controle}${aide ? `<small class="aide">${aide}</small>` : ""}</div>`;
const saisie = (nom, valeur = "", attrs = "") => `<input id="c-${nom}" name="${nom}" class="saisie" value="${echap(valeur)}" ${attrs}>`;

/** Boucles de suivi en cours (connexion Microsoft) : toutes arrêtées quand la session se ferme. */
const SUIVIS = new Set();
function arreterSuivis() { [...SUIVIS].forEach((arret) => arret()); }

// ---------- Paramètres : chaque intégration est facultative, l'assistant marche sans elles ----------
function pageParametres(zone) {
  const z = el(`<div class="page-scroll"><div class="parametres">
      <div class="groupe" id="bloc-pennylane"></div>
      <div class="groupe" id="bloc-microsoft"></div>
      <div class="groupe" id="bloc-comptes"></div>
      <div class="groupe" id="bloc-mdp"></div></div></div>`);
  zone.append(z);
  api("GET", "/api/parametres").then((p) => {
    sectionPennylane($("#bloc-pennylane", z), p);
    sectionCompteMicrosoft($("#bloc-microsoft", z), p);
    sectionComptes($("#bloc-comptes", z), p);
    sectionMotDePasse($("#bloc-mdp", z), p);
  }).catch((e) => { $("#bloc-pennylane", z).innerHTML = `<div class="alerte">${echap(e.message)}</div>`; });
}

const etatIntegration = (ok, texte) => `<span class="pastille ${ok ? "p-vert" : "p-gris"}">${echap(texte)}</span>`;

/** Jeton API Pennylane du cabinet : jamais livré avec le site, gardé chiffré, masqué à l'écran une fois enregistré. */
function sectionPennylane(bloc, p) {
  const etat = { demo: etatIntegration(true, "simulé (démo)"), connecte: etatIntegration(true, "connecté") }[p.pennylane] || etatIntegration(false, "non configuré");
  bloc.innerHTML = `<div class="groupe-titre">PENNYLANE ${etat}</div>
    <div class="aide">Avec un jeton API, l'écriture est créée directement dans le dossier Pennylane (après vérification du plan comptable, du journal
      et des écritures déjà passées). Sans jeton, l'écriture se télécharge en fichier CSV (Date, Journal, Pièce, Compte, Libellé, Débit, Crédit)
      à importer dans votre logiciel comptable.</div>
    ${champ("jeton", "Jeton API du cabinet", saisie("jeton", "", `type="password" autocomplete="off" placeholder="${p.jeton ? `Enregistré : ${echap(p.jeton)}` : "À coller"}"`))}
    <div class="ligne-boutons"><button class="btn" data-a="jeton">Enregistrer le jeton</button>
      ${p.jeton ? '<button class="btn fantome danger" data-a="retirer">Retirer le jeton</button>' : ""}</div>`;
  const enregistrer = async (jeton) => {
    try { await api("POST", "/api/pennylane/jeton", { jeton }); toast(jeton ? "Jeton Pennylane enregistré (chiffré)." : "Jeton retiré."); rendre(); }
    catch (e) { signalerErreur(e); }
  };
  $('[data-a="jeton"]', bloc).onclick = () => { const v = $('[name="jeton"]', bloc).value.trim(); if (!v) return toast("Collez le jeton.", { erreur: true }); enregistrer(v); };
  $('[data-a="retirer"]', bloc)?.addEventListener("click", () => enregistrer(""));
}

/** Compte Microsoft de la personne : lecture des exports dans le SharePoint du cabinet (facultatif : dépôt à la main sinon). */
async function sectionCompteMicrosoft(bloc, p) {
  let e = {};
  try { e = await api("GET", "/api/microsoft"); } catch { /* état inconnu : affiché comme non connecté */ }
  const etat = p.demo ? etatIntegration(true, "simulé (démo)") : e.adresse ? etatIntegration(true, "connecté") : etatIntegration(false, "non configuré");
  bloc.innerHTML = `<div class="groupe-titre">MICROSOFT · SHAREPOINT ${etat}</div>
    <div class="aide">Facultatif : l'export du mois est cherché tout seul dans le SharePoint du cabinet (${echap(p.exports)}).
      Sans compte Microsoft, on dépose le fichier à la main.</div>
    ${p.demo ? '<div class="aide">Mode démo : un SharePoint simulé (fichiers fictifs dans data-demo/) remplace Microsoft.</div>'
      : !p.microsoft || !p.sharepoint ? `<div class="aide">Pour l'activer : déclarez une application dans Microsoft Entra, puis renseignez dans config.json son identifiant (« microsoft_client_id ») et l'adresse de la bibliothèque SharePoint (« sharepoint »).</div>`
      : e.adresse ? `<div class="ligne-boutons"><b>${echap(e.adresse)}</b><span class="espace"></span><button class="btn petit fantome danger" data-a="deconnecter">Déconnecter</button></div>`
      : '<div><button class="btn principal petit" data-a="connecter">Connecter mon compte Microsoft</button></div>'}`;
  $('[data-a="connecter"]', bloc)?.addEventListener("click", () => connecterMicrosoft(() => rendre()));
  $('[data-a="deconnecter"]', bloc)?.addEventListener("click", async () => {
    if (!await confirmer({ titre: "Déconnecter votre compte Microsoft ?", texte: "Les exports se déposeront à la main.", oui: "Déconnecter", danger: true })) return;
    try { await api("POST", "/api/microsoft/deconnexion"); toast("Compte Microsoft déconnecté."); rendre(); } catch (err) { signalerErreur(err); }
  });
}

/** Code journal et comptes proposés aux dossiers qui n'ont pas encore les leurs (plan comptable général par défaut). */
function sectionComptes(bloc, p) {
  const postes = [["client_uber_eats", "Client Uber Eats"], ["client_deliveroo", "Client Deliveroo"],
    ...["20", "10", "5,5"].flatMap((t) => [[`ventes_${t}`, `Ventes HT ${t} %`], [`tva_${t}`, `TVA collectée ${t} %`]])];
  bloc.innerHTML = `<div class="groupe-titre">JOURNAL ET COMPTES PAR DÉFAUT</div>
    <div class="aide">Utilisés pour un dossier tant qu'il n'a pas ses propres comptes (lus dans Pennylane ou corrigés dans l'assistant). Vide : valeur du plan comptable général.</div>
    <div class="lignes-comptes">
      <label class="ligne-compte"><span>Code du journal des ventes</span><input class="saisie" name="journal" value="${echap(p.journal)}" placeholder="VT"></label>
      ${postes.map(([k, l]) => `<label class="ligne-compte"><span>${echap(l)}</span><input class="saisie" data-cle="${k}" value="${echap(p.comptes[k])}" placeholder="${echap(p.pcg[k])}"></label>`).join("")}</div>
    <div><button class="btn" data-a="comptes">Enregistrer</button></div>`;
  $('[data-a="comptes"]', bloc).onclick = async () => {
    const comptes = Object.fromEntries($$("[data-cle]", bloc).map((x) => [x.dataset.cle, x.value.trim() === x.placeholder ? "" : x.value.trim()]));
    try { await api("POST", "/api/parametres/comptes", { journal: $('[name="journal"]', bloc).value, comptes }); toast("Comptes par défaut enregistrés."); }
    catch (e) { signalerErreur(e); }
  };
}

function sectionMotDePasse(bloc, p) {
  bloc.innerHTML = `<div class="groupe-titre">MON MOT DE PASSE · ${echap(S.moi.nom.toUpperCase())}</div>
    ${p.demo ? '<div class="aide">Mode démo : le mot de passe reste « demo ».</div>' : `<div class="grille2">
      ${champ("ancien", "Mot de passe actuel", saisie("ancien", "", 'type="password" autocomplete="current-password"'))}
      ${champ("nouveau", "Nouveau (12 caractères : majuscule, minuscule, chiffre, caractère spécial)", saisie("nouveau", "", 'type="password" autocomplete="new-password"'))}</div>
    <div><button class="btn petit" data-a="mdp">Changer mon mot de passe</button></div>`}`;
  $('[data-a="mdp"]', bloc)?.addEventListener("click", async () => {
    try {
      await api("POST", "/api/mot-de-passe", { ancien: $('[name="ancien"]', bloc).value, nouveau: $('[name="nouveau"]', bloc).value });
      $('[name="ancien"]', bloc).value = $('[name="nouveau"]', bloc).value = ""; erreursChamps(bloc, {}); toast("Mot de passe changé.");
    } catch (e) { if (e.champs) erreursChamps(bloc, e.champs); else signalerErreur(e); }
  });
}

/** Ce qu'il faut faire pour régler un souci de mise en place (lien direct + étapes). */
function htmlAction(action) {
  if (!action || !action.etapes) return "";
  return `<ol class="etapes-action">${action.etapes.map((e) => `<li>${echap(e)}</li>`).join("")}</ol>
    ${action.lien ? `<a class="btn principal petit" href="${echap(action.lien)}" target="_blank" rel="noopener">Ouvrir la page d'autorisation ↗</a>` : ""}`;
}

/** « Connecter mon compte Microsoft » : page Microsoft dans un nouvel onglet, retour automatique (ou adresse collée). */
async function connecterMicrosoft(apres) {
  let r;
  try { r = await api("POST", "/api/microsoft/connexion"); }
  catch (e) {
    if (e.message === "401") return null;
    return fenetre({ titre: "Connexion Microsoft impossible pour l'instant", classe: "moyenne", corps: `<div class="alerte">${echap(e.message)}</div>${htmlAction(e.action)}` });
  }
  const f = fenetre({ titre: "Connecter mon compte Microsoft", classe: "moyenne",
    corps: `<div class="code-microsoft">
        <ol class="etapes-action"><li>« Se connecter chez Microsoft » : la page s'ouvre dans un nouvel onglet.</li>
          <li>Choisissez votre compte du cabinet, puis acceptez : l'application pourra lire les exports des clients (lecture seule).</li>
          ${r.automatique ? "<li>Vous revenez automatiquement ici.</li>" : `<li>La page de retour affiche « Ce site est inaccessible » : c'est normal.
            Copiez son adresse (elle commence par http://localhost) et collez-la ci-dessous.</li>`}</ol>
        <div style="display:flex;justify-content:center"><a class="btn principal" href="${echap(r.url)}" target="_blank" rel="noopener">Se connecter chez Microsoft ↗</a></div>
        <div class="coller ${r.automatique ? "cache" : ""}"><input class="saisie" name="retour" placeholder="Collez ici l'adresse http://localhost/?code=…"><button class="btn" data-a="valider">Valider</button></div>
        <div class="attente"><span class="point-attente"></span> En attente de votre connexion sur la page Microsoft…</div></div>` });
  const valider = async () => {
    const adresse = $('[name="retour"]', f).value.trim();
    if (adresse) try { await api("POST", "/api/microsoft/retour", { adresse }); } catch (e) { signalerErreur(e); }
  };
  $('[data-a="valider"]', f).onclick = valider;
  const suivre = setInterval(async () => {
    if (!f.isConnected) return arret();
    let e;
    try { e = await relectureAuto(() => api("GET", "/api/microsoft")); } catch (err) { if (err.message === "401") arret(); return; }
    const cx = e.connexion || {};
    if (cx.etat === "connecte") {
      arret();
      $(".f-corps", f).innerHTML = `<div class="succes-microsoft">${icone("coche")}<b>Compte connecté</b><span>${echap(e.adresse)}</span></div>`;
      setTimeout(() => { f.fermer(); apres?.(); }, 1400);
    } else if (cx.etat === "erreur") {
      arret();
      $(".attente", f).outerHTML = `<div class="alerte">${echap(cx.message)}</div>${htmlAction(cx.action)}`;
    }
  }, 2500);
  const arret = () => { clearInterval(suivre); SUIVIS.delete(arret); };
  SUIVIS.add(arret);
  return f;
}

// ---------- Démarrage : session, coque, retour de la page Microsoft ----------
const RETOUR_MICROSOFT = (() => {  // retour de « Connecter mon compte Microsoft » sur le site : gardé, puis l'adresse est nettoyée
  const q = new URLSearchParams(location.search);
  if (!q.get("state") || !(q.get("code") || q.get("error"))) return null;
  const adresse = location.href;
  history.replaceState(null, "", "/");
  return adresse;
})();

async function demarrer() {
  construireCoque();
  if (RETOUR_MICROSOFT && !demarrer.retourFait) {
    demarrer.retourFait = true;
    try { toast(`Compte Microsoft connecté : ${(await api("POST", "/api/microsoft/retour", { adresse: RETOUR_MICROSOFT })).adresse}`); }
    catch (e) { if (e.message !== "401") toast(e.message, { erreur: true, duree: 9000 }); }
    S.page = "parametres";
  }
  rendre();
}

addEventListener("DOMContentLoaded", async () => {
  try { S.moi = await api("GET", "/api/moi"); } catch (e) { if (e.message !== "401") pageConnexion(); return; }
  await demarrer();
});
