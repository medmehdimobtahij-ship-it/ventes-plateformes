/* Ventes plateformes : export de caisse Uber Eats / Deliveroo -> écriture de ventes, dans Pennylane ou en fichier.
   Étapes : 1. dossier ; 2. mois ; 3. import de l'export (cherché dans le SharePoint du client, ou déposé) ; 4. écriture ;
   5. envoi (Pennylane, ou fichier CSV à importer dans n'importe quel logiciel). Les contrôles se font côté serveur : seule une
   vraie anomalie (bloquante) s'affiche et empêche d'avancer. */
"use strict";

const ETAPES_PF = ["Dossier", "Mois", "Import", "Écriture", "Envoi"];
const PF = { d: null, etape: 0, dossier: null, mois: null, listeMois: null, source: null, ecriture: null, occupe: false, fini: null };

function pagePlateformes(zone) {
  zone.classList.add("page-outil");
  zone.innerHTML = `<div class="assistant">
      <div class="etapes-assistant">${ETAPES_PF.map((n, i) => `<span data-e="${i}"><b>${i + 1}</b>${n}</span>`).join('<i class="trait"></i>')}</div>
      <div class="carte-etape"><div class="tete-etape"><h2></h2><div class="sous"></div></div>
        <div class="alerte rouge cache" id="alerte-pf"></div><div class="contenu-etape"></div></div>
      <div class="bas-assistant"><span class="info"></span><button class="btn" data-a="precedent">Précédent</button>
        <button class="btn principal" data-a="suivant">Suivant</button></div></div>`;
  $('[data-a="precedent"]', zone).onclick = () => etapePf(Math.max(0, PF.etape - 1));
  $('[data-a="suivant"]', zone).onclick = () => suivantPf();
  api("GET", "/api/plateformes").then((d) => { PF.d = d; etapePf(PF.etape === 4 ? 0 : Math.min(PF.etape, PF.dossier ? 2 : 0)); })
    .catch((e) => alertePf([e.message]));
}

const nomDossierPf = () => PF.d?.dossiers[PF.dossier] || "";
const moisPf = () => PF.listeMois?.mois.find((m) => m.cle === PF.mois) || PF.d?.mois.find((m) => m.cle === PF.mois);
const infoPf = (t) => { const i = S.page === "plateformes" && $(".assistant .info"); if (i) i.innerHTML = t; };
/** L'écriture part dans Pennylane (relié, ou simulé en démo) ; sinon elle se télécharge en fichier. */
const versPennylanePf = () => !!PF.ecriture?.pennylane;
const pennylaneRelie = () => PF.d?.pennylane === "connecte";

function etapePf(e) {
  PF.etape = e;
  if (S.page !== "plateformes") return;  // réponse arrivée après un changement de page
  const z = $(".assistant");
  if (!z) return;
  alertePf(null);
  const nom = nomDossierPf(), periode = moisPf()?.nom || "";
  const [titre, sous] = [["Quel dossier ?", ""], ["Quel mois ?", nom], ["Importer l'export de caisse", `${nom} · ${periode}`],
    ["Écriture à passer", `${nom} · ${periode} · journal ${PF.d?.journal || ""}`], [versPennylanePf() ? "Écriture importée" : "Écriture prête", nom]][e];
  $(".tete-etape h2", z).textContent = titre;
  $(".tete-etape .sous", z).textContent = sous;
  $$(".etapes-assistant span", z).forEach((s, i) => { s.classList.toggle("courante", i === e); s.classList.toggle("faite", i < e); });
  const ancien = $(".contenu-etape", z), c = ancien.cloneNode(false);  // neuf à chaque étape : aucun écouteur ne s'accumule
  ancien.replaceWith(c);
  [etapeDossierPf, etapeMoisPf, etapeImportPf, etapeEcriturePf, etapeFinPf][e](c);
  if (MOUVEMENT) c.animate([{ opacity: 0, transform: "translateY(8px)" }, { opacity: 1, transform: "none" }], { duration: 240, easing: "cubic-bezier(.33,1,.68,1)" });
  majBoutonsPf();
}

function majBoutonsPf() {
  if (S.page !== "plateformes") return;
  const z = $(".assistant");
  if (!z) return;
  const e = PF.etape, s = $('[data-a="suivant"]', z);
  $('[data-a="precedent"]', z).classList.toggle("cache", e === 0 || e === 4);
  s.innerHTML = { 3: versPennylanePf() ? `${icone("envoyer")} Importer dans Pennylane` : `${icone("telecharger")} Télécharger l'écriture`, 4: "Nouvelle écriture" }[e] || "Suivant";
  const pret = [!!PF.dossier, !!PF.mois && !!PF.listeMois, !!PF.source, !!PF.ecriture?.ecritures?.length, true][e];
  s.disabled = PF.occupe || !pret;
}

function alertePf(lignes, comptes = false) {
  const a = $("#alerte-pf");
  if (!a) return;
  a.classList.toggle("cache", !lignes);
  if (!lignes) return;
  a.innerHTML = `<b>Anomalie : impossible d'aller plus loin.</b><ul>${lignes.map((l) => `<li>${echap(l)}</li>`).join("")}</ul>
    ${comptes ? '<button class="btn petit" data-a="comptes">Modifier les comptes</button>' : ""}`;
  $('[data-a="comptes"]', a)?.addEventListener("click", () => modifierComptesPf());
}

async function suivantPf() {
  const e = PF.etape;
  if (e === 0) { PF.mois = null; PF.listeMois = null; PF.source = null; PF.ecriture = null; return etapePf(1); }
  if (e === 1) { PF.source = null; return etapePf(2); }
  if (e === 2) return importerPf();
  if (e === 3) return versPennylanePf() ? envoyerPf() : telechargerPf();
  PF.ecriture = null; PF.source = null; PF.fini = null;
  etapePf(0);
}

// --- 1. Dossier : supérettes suivies (ajoutées depuis Pennylane, saisies à la main ou importées d'un fichier) ---
function etapeDossierPf(c) {
  const d = PF.d;
  c.innerHTML = `<div class="liste-etape"></div>
    <div class="ligne-boutons"><button class="btn petit" data-a="ajouter">${icone("plus")} Ajouter un dossier</button>
      <label class="btn petit fantome">${icone("dossier")} Importer une liste (CSV, Excel)<input type="file" accept=".csv,.xlsx,.xlsm" class="cache" name="liste"></label></div>`;
  const zone = $(".liste-etape", c);
  const ids = Object.keys(d.dossiers);
  zone.innerHTML = ids.length ? ids.map((id) => `<div class="item-etape ${id === PF.dossier ? "choisi" : ""}" data-id="${echap(id)}" tabindex="0">
      <span>${echap(d.dossiers[id])}</span>${d.schemas[id] ? "" : '<span class="pastille p-orange" title="Comptes par défaut du cabinet (Paramètres), modifiables à l\'étape Écriture">comptes par défaut</span>'}
      <button class="rond petit-rond" data-a="renommer" title="Renommer">${icone("parametres")}</button>
      <button class="rond petit-rond" data-a="retirer" title="Retirer ${echap(d.dossiers[id])} de la liste">${icone("fermer")}</button></div>`).join("")
    : '<div class="vide-liste"><b>Aucun dossier pour l\'instant.</b><span>Ajoutez une supérette (depuis Pennylane ou à la main), ou importez une liste.</span></div>';
  zone.onclick = async (ev) => {
    const it = ev.target.closest(".item-etape"); if (!it) return;
    const id = it.dataset.id;
    if (ev.target.closest('[data-a="retirer"]')) {
      if (!await confirmer({ titre: `Retirer ${echap(d.dossiers[id])} ?`, texte: "Le dossier sort de cette liste ; rien n'est modifié dans Pennylane.", oui: "Retirer" })) return;
      PF.d = await api("POST", "/api/plateformes/dossiers", { id, action: "retirer" }).catch((e2) => (signalerErreur(e2), PF.d));
      if (PF.dossier === id) PF.dossier = null;
      return etapePf(0);
    }
    if (ev.target.closest('[data-a="renommer"]')) return renommerDossierPf(id);
    PF.dossier = id;
    $$(".item-etape", zone).forEach((x) => x.classList.toggle("choisi", x === it));
    majBoutonsPf();
  };
  zone.ondblclick = (ev) => { if (ev.target.closest(".item-etape") && !ev.target.closest("button")) suivantPf(); };
  $('[data-a="ajouter"]', c).onclick = () => ajouterDossierPf();
  $('[name="liste"]', c).onchange = (ev) => { if (ev.target.files[0]) importerDossiersPf(ev.target.files[0]); ev.target.value = ""; };
}

/** Nom du dossier : c'est aussi le nom de son dossier dans le SharePoint du cabinet (recherche des exports). */
function renommerDossierPf(id) {
  const f = fenetre({ titre: "Renommer le dossier", classe: "petite",
    corps: champ("nom", "Nom (comme le dossier du client dans SharePoint)", saisie("nom", PF.d.dossiers[id])),
    pied: '<button class="btn" data-a="non">Annuler</button><button class="btn principal" data-a="ok">Enregistrer</button>' });
  $('[data-a="non"]', f).onclick = () => f.fermer();
  $('[data-a="ok"]', f).onclick = async () => {
    const nom = $('[name="nom"]', f).value.trim();
    if (!nom) return;
    try { PF.d = await api("POST", "/api/plateformes/dossiers", { id, nom }); f.fermer(); etapePf(0); } catch (e) { signalerErreur(e); }
  };
}

async function importerDossiersPf(fichier) {
  try {
    const r = await api("POST", "/api/plateformes/dossiers/import", { fichier: { nom: fichier.name, contenu: await enBase64(fichier) } });
    PF.d = r;
    toast(`${pluriel(r.importes, "dossier importé", "dossiers importés")}${r.erreurs.length ? ` · ${pluriel(r.erreurs.length, "ligne ignorée", "lignes ignorées")}` : ""}.`);
    etapePf(0);
  } catch (e) { signalerErreur(e); }
}

/** Ajout d'un dossier : choisi dans les dossiers Pennylane du cabinet (comptes lus tout seuls), ou saisi à la main. */
async function ajouterDossierPf() {
  const f = fenetre({ titre: "Ajouter un dossier", sous: "Depuis Pennylane, ou à la main", classe: "petite",
    corps: `<label class="recherche">${icone("recherche")}<input class="saisie" placeholder="Rechercher…"></label>
      <div class="liste-etape courte"><div class="chargement">Lecture des dossiers Pennylane…</div></div>
      <details class="avance" ${pennylaneRelie() ? "" : "open"}><summary>Saisir à la main</summary>
        <div class="grille2">${champ("nom", "Nom du dossier", saisie("nom"))}${champ("pennylane", "Identifiant Pennylane (facultatif)", saisie("pennylane", "", 'inputmode="numeric"'))}</div>
        <div><button class="btn petit" data-a="manuel">Ajouter ce dossier</button></div></details>`,
    pied: '<button class="btn" data-a="non">Annuler</button><button class="btn principal" data-a="ok" disabled>Ajouter</button>' });
  $('[data-a="non"]', f).onclick = () => f.fermer();
  const ajouterVraiment = async (corps, bouton) => {
    bouton.disabled = true; const avant = bouton.textContent; bouton.textContent = "Ajout…";
    try { PF.d = await api("POST", "/api/plateformes/dossiers", corps); PF.dossier = Object.keys(PF.d.dossiers).find((k) => PF.d.dossiers[k] === corps.nom) || corps.id || null; f.fermer(); etapePf(0); }
    catch (e) { signalerErreur(e); bouton.disabled = false; bouton.textContent = avant; }
  };
  $('[data-a="manuel"]', f).onclick = (ev) => {
    const nom = $('[name="nom"]', f).value.trim();
    if (!nom) return toast("Nom du dossier à saisir.", { erreur: true });
    ajouterVraiment({ nom, id: $('[name="pennylane"]', f).value.replace(/\D/g, "") }, ev.currentTarget);
  };
  let dossiers;
  try { dossiers = (await api("GET", "/api/plateformes/cabinet")).dossiers.filter((x) => !(x.id in PF.d.dossiers)); }
  catch (e) { $(".liste-etape", f).innerHTML = `<div class="aide">${echap(e.message)} Saisissez le dossier à la main ci-dessous.</div>`; $(".recherche", f).classList.add("cache"); return; }
  let choisi = null;
  const zone = $(".liste-etape", f), champRecherche = $(".recherche input", f);
  const remplir = () => {
    const q = champRecherche.value.trim().toLowerCase();
    zone.innerHTML = dossiers.filter((x) => !q || x.nom.toLowerCase().includes(q)).map((x) =>
      `<button class="item-etape ${x.id === choisi?.id ? "choisi" : ""}" data-id="${echap(x.id)}"><span>${echap(x.nom)}</span></button>`).join("") || '<div class="aide">Aucun dossier.</div>';
  };
  remplir();
  champRecherche.oninput = remplir;
  const ajouter = () => { if (choisi) ajouterVraiment({ id: choisi.id, nom: choisi.nom }, $('[data-a="ok"]', f)); };
  zone.onclick = (ev) => {
    const b = ev.target.closest(".item-etape"); if (!b) return;
    choisi = dossiers.find((x) => x.id === b.dataset.id);
    $$(".item-etape", zone).forEach((x) => x.classList.toggle("choisi", x === b));
    $('[data-a="ok"]', f).disabled = false;
  };
  zone.ondblclick = (ev) => { if (ev.target.closest(".item-etape")) ajouter(); };
  $('[data-a="ok"]', f).onclick = ajouter;
}

// --- 2. Mois : ce qui est déjà passé est relu dans Pennylane à chaque fois (jamais de mémoire du logiciel) ---
async function etapeMoisPf(c) {
  c.innerHTML = `<div class="liste-etape"><div class="chargement">Lecture des mois déjà passés…</div></div><div class="date-ecriture"></div>`;
  if (!PF.listeMois || PF.listeMois.dossier !== PF.dossier) {
    PF.occupe = true; majBoutonsPf();
    try { PF.listeMois = { ...(await api("POST", "/api/plateformes/mois", { dossier: PF.dossier })), dossier: PF.dossier }; }
    catch (e) { PF.occupe = false; alertePf([e.message]); return; }
    PF.occupe = false;
  }
  if (PF.etape !== 1) return;
  const l = PF.listeMois;
  if (!PF.mois || !l.mois.some((m) => m.cle === PF.mois && !m.fait)) PF.mois = l.propose;
  const zone = $(".liste-etape", c);
  zone.innerHTML = `${l.erreur ? `<div class="alerte">${echap(l.erreur)}</div>` : ""}${l.info ? `<div class="aide">${echap(l.info)}</div>` : ""}
    ${l.mois.map((m) => `<button class="item-etape ${m.cle === PF.mois ? "choisi" : ""} ${m.fait ? "fait" : ""}" data-cle="${m.cle}" ${m.fait ? "disabled" : ""}>
      <span>${echap(m.nom)}</span>${m.etat ? `<span class="etat-mois ${m.etat.startsWith("✓") ? "vert" : ""}">${echap(m.etat)}</span>` : ""}</button>`).join("")}`;
  const date = () => { $(".date-ecriture", c).innerHTML = moisPf() ? `Date de l'écriture : <b>${echap(moisPf().date)}</b>` : ""; };
  date();
  zone.onclick = (ev) => {
    const b = ev.target.closest(".item-etape"); if (!b || b.disabled) return;
    PF.mois = b.dataset.cle;
    $$(".item-etape", zone).forEach((x) => x.classList.toggle("choisi", x === b));
    date(); majBoutonsPf();
  };
  zone.ondblclick = (ev) => { const b = ev.target.closest(".item-etape"); if (b && !b.disabled) suivantPf(); };
  majBoutonsPf();
}

// --- 3. Import : export du mois cherché dans le dossier « …PLATEFORMES… » du client (SharePoint, lecture seule), ou déposé ---
async function etapeImportPf(c) {
  c.innerHTML = `<div class="zone-fichier"><div class="fichier-trouve"><span class="point-attente"></span> Recherche de l'export dans le SharePoint du client…</div>
      <label class="btn">${icone("telecharger")} Choisir un fichier…<input type="file" accept=".xlsx,.xlsm,.csv" class="cache" name="export"></label></div>
    <div class="aide">Export de caisse du mois (Excel ou CSV), une ligne par produit vendu, Uber Eats et Deliveroo ensemble. Le fichier peut aussi être glissé ici.</div>`;
  const zone = c;
  zone.addEventListener("dragover", (ev) => { ev.preventDefault(); zone.classList.add("survol-fichier"); });
  zone.addEventListener("dragleave", () => zone.classList.remove("survol-fichier"));
  zone.addEventListener("drop", (ev) => { ev.preventDefault(); zone.classList.remove("survol-fichier"); if (ev.dataTransfer.files[0]) fichierPf(c, ev.dataTransfer.files[0]); });
  $('[name="export"]', c).onchange = (ev) => { if (ev.target.files[0]) fichierPf(c, ev.target.files[0]); };
  if (PF.source) return afficherSourcePf(c);
  PF.occupe = true; majBoutonsPf();
  let r;
  try { r = await api("POST", "/api/plateformes/exports", { dossier: PF.dossier, mois: PF.mois }); }
  catch (e) { r = { trouve: null, message: e.message }; }
  PF.occupe = false;
  if (PF.etape !== 2 || PF.source) return;
  if (r.trouve) PF.source = { sharepoint: r.trouve, nom: r.trouve.nom, ou: `${r.trouve.ou} · ventes de ${r.trouve.periode}` };
  afficherSourcePf(c, r.message);
}
function afficherSourcePf(c, message = "") {
  const z = $(".fichier-trouve", c);
  z.innerHTML = PF.source ? `${icone("trombone")}<div><b>${echap(PF.source.nom)}</b><br><span class="aide">${echap(PF.source.ou)}</span></div>`
    : `<div>${echap(message || "Aucun export trouvé : choisissez le fichier.")}</div>`;
  majBoutonsPf();
}
async function fichierPf(c, fichier) {
  if (!/\.(xlsx|xlsm|csv)$/i.test(fichier.name)) return toast("Export Excel (.xlsx) ou CSV attendu.", { erreur: true });
  try { PF.source = { fichier: { nom: fichier.name, contenu: await enBase64(fichier) }, nom: fichier.name, ou: "Fichier déposé" }; }
  catch (e) { return signalerErreur(e); }
  alertePf(null);
  afficherSourcePf(c);
}

/** Lit l'export, construit l'écriture, fait les contrôles (dans Pennylane aussi s'il est relié) ; alerte si anomalie. */
async function importerPf() {
  PF.occupe = true; majBoutonsPf(); infoPf('<span class="point-attente"></span> Lecture de l\'export et vérifications…');
  let r;
  try {
    r = await api("POST", "/api/plateformes/importer", { dossier: PF.dossier, mois: PF.mois,
      ...(PF.source.fichier ? { fichier: PF.source.fichier } : { sharepoint: PF.source.sharepoint }) });
  } catch (e) { r = { bloquants: [e.message] }; }
  PF.occupe = false; infoPf("");
  if (r.bloquants) { majBoutonsPf(); return alertePf(r.bloquants, !!r.comptes); }
  PF.ecriture = r;
  etapePf(3);
}

// --- 4. Écriture ---
function etapeEcriturePf(c) {
  const r = PF.ecriture;
  const resume = r.ecritures.map((e) => `${echap(e.libelle)} : ${echap(e.ttc)} € TTC`).join(" · ");
  c.innerHTML = `<div class="tableau outil ecriture"><table><thead><tr><th>Pièce</th><th>Compte</th><th>Libellé</th><th class="droite">Débit</th><th class="droite">Crédit</th></tr></thead>
      <tbody>${r.ecritures.map((e) => e.lignes_texte.map((l) => `<tr><td>${echap(e.piece)}</td><td>${echap(l.compte)}</td><td>${echap(l.libelle)}</td>
        <td class="droite">${echap(l.debit_texte)}</td><td class="droite">${echap(l.credit_texte)}</td></tr>`).join("")).join("")}</tbody></table></div>
    <div class="ligne-boutons"><div class="resume-ecriture"><b>${resume}</b>
      ${r.remarques.length ? `<span class="aide">${pluriel(r.remarques.length, "remarque", "remarques")} ci-dessous, sans effet sur l'écriture</span>` : ""}
      ${r.pennylane ? "" : '<span class="aide">Pennylane non relié : l\'écriture se télécharge en fichier CSV, à importer dans votre logiciel comptable.</span>'}</div>
      <span class="espace"></span><button class="btn petit" data-a="comptes">Modifier les comptes</button>
      <button class="btn petit" data-a="copier">${icone("copier")} Copier</button>
      ${r.pennylane ? `<button class="btn petit" data-a="csv">${icone("telecharger")} CSV</button>` : ""}</div>
    ${r.remarques.length ? `<ul class="remarques">${r.remarques.map((x) => `<li>${echap(x)}</li>`).join("")}</ul>` : ""}`;
  $('[data-a="comptes"]', c).onclick = () => modifierComptesPf();
  $('[data-a="copier"]', c).onclick = () => copierTexte(r.tableur, "Écriture copiée (à coller dans un tableur).");
  $('[data-a="csv"]', c)?.addEventListener("click", () => telechargerFichier(r.fichier));
}

// --- 5. Envoi : Pennylane, ou fichier ---
async function envoyerPf() {
  const r = PF.ecriture, nom = nomDossierPf();
  const ok = await confirmer({ titre: "Importer dans Pennylane ?", oui: "Créer l'écriture",
    texte: `Créer dans <b>${echap(nom)}</b>, journal ${echap(PF.d.journal)} :<ul>${r.ecritures.map((e) => `<li>${echap(e.piece)} : ${echap(e.libelle)} (${echap(e.ttc)} € TTC)</li>`).join("")}</ul>` });
  if (!ok) return;
  PF.occupe = true; majBoutonsPf(); infoPf('<span class="point-attente"></span> Import dans Pennylane…');
  try { PF.fini = await api("POST", "/api/plateformes/envoyer", { dossier: PF.dossier }); PF.listeMois = null; etapePf(4); }
  catch (e) { alertePf([e.message]); }
  PF.occupe = false; infoPf(""); majBoutonsPf();
}
function telechargerPf() {
  telechargerFichier(PF.ecriture.fichier);
  PF.fini = { fichier: PF.ecriture.fichier.nom, ecritures: PF.ecriture.ecritures };
  etapePf(4);
}
function etapeFinPf(c) {
  const f = PF.fini;
  c.innerHTML = `<div class="pdf-pret">${icone("coche")}<div>${f.ecritures.map((e) => `<div><b>${echap(e.piece)}</b> : ${echap(e.libelle)}, ${echap(e.ttc)} € TTC</div>`).join("")}
    <span>${f.fichier ? `Fichier ${echap(f.fichier)} téléchargé : importez-le dans votre logiciel comptable (journal ${echap(PF.d.journal)}).`
      : f.simule ? "Mode démo : envoi simulé, rien n'est parti. Le mois apparaît maintenant comme passé."
      : `Visible dans Pennylane, ${echap(nomDossierPf())}, journal ${echap(PF.d.journal)}.`}</span></div></div>
    ${f.fichier ? `<div><button class="btn petit" data-a="encore">${icone("telecharger")} Télécharger à nouveau</button></div>` : ""}`;
  $('[data-a="encore"]', c)?.addEventListener("click", () => telechargerFichier(PF.ecriture.fichier));
}

// --- Schéma de comptes du dossier (lu tout seul dans Pennylane, sinon comptes par défaut ; modifiable si besoin) ---
const POSTES_PF = [["Client Uber Eats", "plateformes", "Uber Eats"], ["Client Deliveroo", "plateformes", "Deliveroo"],
  ...["20", "10", "5,5"].flatMap((t) => [[`Ventes HT ${t} %`, "ventes", t], [`TVA ${t} %`, "tva", t]])];
function modifierComptesPf() {
  const s = () => PF.d.schemas[PF.dossier] || PF.d.defaut;
  const valeur = (sc, section, cle) => (section === "plateformes" ? sc.plateformes?.[cle]?.client : sc[section]?.[cle]) || "";
  const relire = pennylaneRelie() && /^\d+$/.test(PF.dossier || "");
  const f = fenetre({ titre: "Comptes de l'écriture", sous: echap(nomDossierPf()), classe: "petite",
    corps: `<div class="aide source"></div><div class="lignes-comptes"></div>`,
    pied: `${relire ? '<button class="btn gauche" data-a="relire">Relire dans Pennylane</button>' : ""}<button class="btn" data-a="non">Annuler</button><button class="btn principal" data-a="ok">Enregistrer</button>` });
  const remplir = () => {
    const sc = s();
    $(".source", f).textContent = `Source : ${sc.source || "—"}`;
    $(".lignes-comptes", f).innerHTML = POSTES_PF.map(([l, section, cle]) => `<label class="ligne-compte"><span>${echap(l)}</span>
      <input class="saisie" data-section="${section}" data-cle="${cle}" value="${echap(valeur(sc, section, cle))}" placeholder="À définir"></label>`).join("");
  };
  remplir();
  $('[data-a="non"]', f).onclick = () => f.fermer();
  $('[data-a="relire"]', f)?.addEventListener("click", async (ev) => {
    ev.target.disabled = true;
    try { PF.d.schemas[PF.dossier] = (await api("POST", "/api/plateformes/schema/lire", { dossier: PF.dossier })).schema; remplir(); toast("Comptes relus dans Pennylane."); }
    catch (e) { signalerErreur(e); }
    ev.target.disabled = false;
  });
  $('[data-a="ok"]', f).onclick = async () => {
    const schema = { plateformes: {}, ventes: {}, tva: {} };
    for (const x of $$("input[data-section]", f)) {
      if (x.dataset.section === "plateformes") schema.plateformes[x.dataset.cle] = { client: x.value.trim() };
      else schema[x.dataset.section][x.dataset.cle] = x.value.trim();
    }
    try { PF.d.schemas[PF.dossier] = (await api("POST", "/api/plateformes/schema", { dossier: PF.dossier, schema })).schema; }
    catch (e) { return signalerErreur(e); }
    f.fermer();
    if ((PF.etape === 2 || PF.etape === 3) && PF.source) importerPf();  // recalcul avec les comptes corrigés
  };
}
