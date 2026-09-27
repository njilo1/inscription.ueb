/* Registre financier : filtres locaux, tri, pagination et export des lignes filtrées. */
(() => {
 'use strict';
 const racine = document.querySelector('[data-pay]');
 if (!racine) return;
 const section = racine.querySelector('[data-pay-registre]');
 const chercher = (nom) => section.querySelector(`[data-pay-${nom}]`);
 const normaliser = (s) => String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr').trim();
 const nombre = new Intl.NumberFormat('fr-FR');
 const lignes = [...section.querySelectorAll('[data-pay-ligne]')].map((el, index) => {
  const d = JSON.parse(el.dataset.payLigne);
  return { el, d, index, texte: normaliser(`${d.nom} ${d.detail}`) };
 });
 const recherche = chercher('recherche');
 const situation = chercher('situation');
 const tri = chercher('tri');
 const tbody = section.querySelector('tbody');
 const types = [...section.querySelectorAll('[data-pay-type]')];
 let type = 'tous', page = 1, visibles = [], delai, pret = false;
 const reduit = matchMedia('(prefers-reduced-motion: reduce)');
 const parPage = 10;
 const afficher = () => {
  const nbPages = Math.max(1, Math.ceil(visibles.length / parPage));
  page = Math.min(nbPages, Math.max(1, page));
  lignes.forEach((l) => { l.el.hidden = true; });
  visibles.slice((page - 1) * parPage, page * parPage).forEach((l) => { l.el.hidden = false; });
  chercher('vide').hidden = visibles.length !== 0;
  section.querySelector('.pay-table-cadre').hidden = visibles.length === 0;
  chercher('pagination').hidden = visibles.length <= parPage;
  chercher('precedent').disabled = page <= 1;
  chercher('suivant').disabled = page >= nbPages;
  chercher('page').textContent = `${page} / ${nbPages}`;
  chercher('resultats').textContent = `${visibles.length} ligne${visibles.length > 1 ? 's' : ''} sur ${lignes.length}. Le bilan en haut porte sur tout le périmètre.`;
  const somme = (cle) => visibles.reduce((s,l) => s + Number(l.d[cle]), 0);
  const gras = (texte) => Object.assign(document.createElement('b'), { textContent: texte });
  chercher('total').replaceChildren('Total filtré : ', gras(`${nombre.format(somme('encaisse'))} FCFA`), ' encaissés, ', gras(`${nombre.format(somme('reste'))} FCFA`), ' restants');
  chercher('export').disabled = visibles.length === 0;
 };
 const filtrer = () => {
  clearTimeout(delai);
  const mots = normaliser(recherche.value).split(/\s+/).filter(Boolean);
  visibles = lignes.filter(({d,texte}) => (type === 'tous' || d.type === type) && mots.every((m) => texte.includes(m)) && (situation.value === 'tous' || (situation.value === 'reste' ? d.reste > 0 : situation.value === 'verification' ? d.verification > 0 : d.situation === situation.value)));
  visibles.sort((a,b) => tri.value === 'nom' ? a.d.nom.localeCompare(b.d.nom,'fr') || a.index-b.index : b.d[tri.value]-a.d[tri.value] || a.index-b.index);
  visibles.forEach((l) => tbody.appendChild(l.el));
  page = 1;
  afficher();
  /* Après un filtre ou un tri, les lignes entrent brièvement : le geste a un effet visible. */
  if (pret && !reduit.matches) {
   visibles.slice(0, parPage).forEach((l, i) => l.el.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 260, delay: Math.min(i, 8) * 28, easing: 'cubic-bezier(.16,1,.3,1)', fill: 'backwards' }));
  }
 };
 /* Indicateur glissant : il suit le type choisi, le mouvement dit ce qui a changé. */
 const groupe = section.querySelector('.pay-types');
 const indicateur = document.createElement('span');
 indicateur.className = 'pay-types__indicateur';
 indicateur.setAttribute('aria-hidden', 'true');
 groupe.prepend(indicateur);
 const placer = () => {
  const actif = types.find((t) => t.getAttribute('aria-pressed') === 'true');
  if (!actif) return;
  indicateur.style.width = actif.offsetWidth + 'px';
  indicateur.style.height = actif.offsetHeight + 'px';
  indicateur.style.transform = `translate(${actif.offsetLeft}px, ${actif.offsetTop}px)`;
 };
 types.forEach((b) => b.addEventListener('click', () => {
  type = b.dataset.payType;
  types.forEach((t) => t.setAttribute('aria-pressed', String(t === b)));
  placer();
  filtrer();
 }));
 new ResizeObserver(placer).observe(groupe);
 recherche.addEventListener('input', () => { clearTimeout(delai); delai = setTimeout(filtrer, 120); });
 situation.addEventListener('change', filtrer);
 tri.addEventListener('change', filtrer);
 chercher('reset').addEventListener('click', () => {
  type = 'tous'; recherche.value = ''; situation.value = 'tous'; tri.value = 'attendu';
  types.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.payType === 'tous')));
  placer(); filtrer(); recherche.focus();
 });
 chercher('precedent').addEventListener('click', () => { page--; afficher(); });
 chercher('suivant').addEventListener('click', () => { page++; afficher(); });
 chercher('export').addEventListener('click', () => {
  // Neutralise les formules de tableur, y compris après des espaces ou tabulations.
  const cellule = (v) => {
   let s = String(v ?? '');
   if (/^[\s]*[=+\-@]/.test(s) || /^[\t\r\n]/.test(s)) s = "'" + s;
   return '"' + s.replace(/"/g, '""') + '"';
  };
  const donnees = [['Année académique','Établissement / filière','Type','Effectif','Unité','Attendu FCFA','Encaissé FCFA','À vérifier FCFA','Reste FCFA','Recouvrement %']];
  visibles.forEach(({d}) => donnees.push([racine.dataset.annee,d.nom,d.type === 'droits' ? 'Droits universitaires' : 'Frais médicaux',d.effectif,d.unite,d.attendu,d.encaisse,d.verification,d.reste,d.attendu ? d.taux.toFixed(2).replace('.',',') : '']));
  const url = URL.createObjectURL(new Blob(['\ufeff' + donnees.map((r) => r.map(cellule).join(';')).join('\r\n')], {type:'text/csv;charset=utf-8;'}));
  const lien = document.createElement('a'); lien.href = url; lien.download = `paiements-ueb-${racine.dataset.annee}-${type}.csv`;
  document.body.appendChild(lien); lien.click(); lien.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
 });
 chercher('outils').hidden = false;
 chercher('export').hidden = false;
 filtrer();
 placer();
 requestAnimationFrame(() => groupe.classList.add('est-pret')); // pas de glissement au premier placement
 pret = true;
 /* Les révélations de données au défilement sont dans administration-mouvement.js. */
})();

/*
 * Menu « Exporter » : rapport PDF ou Word au format institutionnel, tableur
 * Excel sans en-tête. Le fichier est préparé par le serveur ; le choix cliqué
 * annonce la préparation, puis le menu se referme.
 */
(() => {
 const bloc = document.querySelector('[data-export]');
 if (!bloc) return;
 const bouton = bloc.querySelector('.adm-export__bouton');
 const menu = bloc.querySelector('.adm-export__menu');
 const liens = [...menu.querySelectorAll('a')];
 /* Le menu reste dans l'écran, à 16 px des bords, quelle que soit la place du bouton. */
 const caler = () => {
  menu.style.translate = '';
  const r = menu.getBoundingClientRect();
  const decalage = Math.max(0, 16 - r.left) - Math.max(0, r.right - (document.documentElement.clientWidth - 16));
  if (decalage) menu.style.translate = `${decalage}px 0`;
 };
 const ouvrir = (etat, focus = false) => {
  bouton.setAttribute('aria-expanded', String(etat));
  menu.hidden = !etat;
  if (etat) caler();
  if (etat && focus) liens[0].focus();
 };
 bouton.addEventListener('click', () => ouvrir(menu.hidden));
 bouton.addEventListener('keydown', (e) => { if (e.key === 'ArrowDown') { e.preventDefault(); ouvrir(true, true); } });
 menu.addEventListener('keydown', (e) => {
  const i = liens.indexOf(document.activeElement);
  if (e.key === 'ArrowDown') { e.preventDefault(); liens[(i + 1) % liens.length].focus(); }
  if (e.key === 'ArrowUp') { e.preventDefault(); liens[(i - 1 + liens.length) % liens.length].focus(); }
  if (e.key === 'Escape') { ouvrir(false); bouton.focus(); }
 });
 document.addEventListener('click', (e) => { if (!menu.hidden && !bloc.contains(e.target)) ouvrir(false); });
 bloc.addEventListener('focusout', (e) => { if (!menu.hidden && !bloc.contains(e.relatedTarget)) ouvrir(false); });
 liens.forEach((a) => a.addEventListener('click', () => {
  const petit = a.querySelector('small');
  const texte = petit.textContent;
  a.classList.add('est-en-cours');
  petit.textContent = 'Préparation du fichier…';
  setTimeout(() => { a.classList.remove('est-en-cours'); petit.textContent = texte; ouvrir(false); }, 2200);
 }));
})();
