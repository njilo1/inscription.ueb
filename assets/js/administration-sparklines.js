/* Tracés et valeurs existent côté serveur ; ce script ajoute leur exploration. */
(() => {
 "use strict";
 document.querySelectorAll('[data-mini-courbe]').forEach((figure) => {
  const data = JSON.parse(figure.dataset.miniCourbe);
  const zone = figure.querySelector('.adm-spark__zone');
  const output = figure.querySelector('output');
  const repere = figure.querySelector('.adm-spark__repere');
  const point = figure.querySelector('.adm-spark__point');
  let index = data.dates.length - 1;
  const afficher = (i) => {
   index = Math.max(0, Math.min(data.dates.length - 1, i));
   const [x, y] = data.points[index];
   repere.setAttribute('d', `M${x},4 V58`);
   point.setAttribute('cx', x);
   point.setAttribute('cy', y ?? 54);
   point.style.display = y === null ? 'none' : '';
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
 });
})();
