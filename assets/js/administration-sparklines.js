/* Tracés et valeurs existent côté serveur ; ce script ajoute leur exploration.
   uebActiverMiniCourbe() sert aussi aux cartes redessinées en direct (attente-recus.js).
   Une mini-courbe à deux types (droits, frais médicaux) a un second point.
   Il bascule aussi les onglets par type de quitus (évolution des quitus). */
(() => {
 "use strict";
 const activer = (figure) => {
  const data = JSON.parse(figure.dataset.miniCourbe);
  const zone = figure.querySelector('.adm-spark__zone');
  const output = figure.querySelector('output');
  const repere = figure.querySelector('.adm-spark__repere');
  const point = figure.querySelector('.adm-spark__point');
  const point2 = figure.querySelector('.adm-spark__point--seconde');
  let index = data.dates.length - 1;
  const afficher = (i) => {
   index = Math.max(0, Math.min(data.dates.length - 1, i));
   const [x, y] = data.points[index];
   repere.setAttribute('d', `M${x},4 V58`);
   point.setAttribute('cx', x);
   point.setAttribute('cy', y ?? 54);
   point.style.display = y === null ? 'none' : '';
   if (point2 && data.points2) {
    const y2 = data.points2[index][1];
    point2.setAttribute('cx', x);
    point2.setAttribute('cy', y2 ?? 54);
    point2.style.display = y2 === null ? 'none' : '';
   }
   const text = `${data.dates[index]} · ${data.valeurs[index]}`;
   if (output.textContent !== text) output.textContent = text;
   figure.classList.add('est-active');
  };
  const parcourir = (event) => {
   const rect = zone.getBoundingClientRect();
   afficher(Math.round((((event.clientX - rect.left) / rect.width) * 240 - 4) / 232 * (data.dates.length - 1)));
  };
  zone.addEventListener('pointermove', parcourir);
  zone.addEventListener('pointerdown', parcourir);
  zone.addEventListener('pointerleave', () => {
   if (document.activeElement !== zone) figure.classList.remove('est-active');
  });
  zone.addEventListener('focus', () => afficher(index));
  zone.addEventListener('blur', () => figure.classList.remove('est-active'));
  zone.addEventListener('keydown', (event) => {
   if (event.key === 'Escape') { figure.classList.remove('est-active'); return; }
   if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
   event.preventDefault();
   afficher(event.key === 'Home' ? 0 : event.key === 'End' ? data.dates.length - 1 : index + (event.key === 'ArrowRight' ? 1 : -1));
  });
 };
 document.querySelectorAll('[data-mini-courbe]').forEach(activer);
 window.uebActiverMiniCourbe = activer;

 /* Onglets : clic, flèches gauche et droite, Début et Fin. */
 document.querySelectorAll('[data-onglets]').forEach((liste) => {
  const onglets = [...liste.querySelectorAll('[role="tab"]')];
  const choisir = (onglet, focus) => {
   onglets.forEach((o) => {
    const actif = o === onglet;
    o.setAttribute('aria-selected', String(actif));
    o.tabIndex = actif ? 0 : -1;
    document.getElementById(o.getAttribute('aria-controls')).hidden = !actif;
   });
   if (focus) onglet.focus();
  };
  onglets.forEach((o, i) => {
   o.addEventListener('click', () => choisir(o, false));
   o.addEventListener('keydown', (event) => {
    const cible = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: onglets.length - 1 }[event.key];
    if (cible === undefined) return;
    event.preventDefault();
    choisir(onglets[(cible + onglets.length) % onglets.length], true);
   });
  });
 });
})();
