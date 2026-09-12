/* Interactions et animations réservées à la variante. Aucune dépendance ajoutée. */
(() => {
  'use strict';
  const root = document.querySelector('.ueb-v2');
  if (!root) return;
  const $ = (selector) => root.querySelector(selector);
  const $$ = (selector) => [...root.querySelectorAll(selector)];
  root.classList.add('has-v2-js');

  const menu = $('.v2-menu');
  const nav = $('#v2-navigation');
  const mobile = matchMedia('(max-width: 850px)');
  const closeMenu = (restoreFocus = false) => {
    nav.classList.remove('is-open');
    menu.setAttribute('aria-expanded', 'false');
    menu.querySelector('.sr').textContent = 'Ouvrir le menu';
    if (restoreFocus) menu.focus();
  };
  menu.hidden = false;
  menu.addEventListener('click', () => {
    const open = menu.getAttribute('aria-expanded') !== 'true';
    nav.classList.toggle('is-open', open);
    menu.setAttribute('aria-expanded', String(open));
    menu.querySelector('.sr').textContent = open ? 'Fermer le menu' : 'Ouvrir le menu';
  });
  nav.addEventListener('click', (event) => {
    const link = event.target.closest('a');
    if (!link) return;
    closeMenu();
    if (mobile.matches && link.hash && link.origin === location.origin && link.pathname === location.pathname) {
      const section = document.getElementById(link.hash.slice(1));
      if (section) {
        section.setAttribute('tabindex', '-1');
        section.focus({ preventScroll: true });
        section.addEventListener('blur', () => section.removeAttribute('tabindex'), { once: true });
      }
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && menu.getAttribute('aria-expanded') === 'true') closeMenu(true);
  });
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.v2-nav')) closeMenu();
  });
  mobile.addEventListener('change', () => closeMenu());

  // La recherche et la ville se combinent ; accents et casse sont indifférents.
  const normalize = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const schools = $$('[data-school]');
  const search = $('#v2-search');
  const cities = $$('[data-city]');
  const count = $('[data-results]');
  let city = '';
  schools.forEach((school) => { school.searchText = normalize(school.dataset.search); });
  const filter = () => {
    const words = normalize(search.value).split(/\s+/).filter(Boolean);
    let visible = 0;
    schools.forEach((school) => {
      const show = (!city || school.dataset.ville === city) && words.every((word) => school.searchText.includes(word));
      school.hidden = !show;
      if (show) visible++;
    });
    count.textContent = `${visible} établissement${visible === 1 ? '' : 's'} ${visible === 1 ? 'affiché' : 'affichés'} sur ${schools.length}.`;
    $('[data-empty]').hidden = visible !== 0;
  };
  $('[data-directory-tools]').hidden = false;
  search.addEventListener('input', filter);
  cities.forEach((button) => button.addEventListener('click', () => {
    city = button.dataset.city;
    cities.forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
    filter();
  }));
  $('[data-reset]').addEventListener('click', () => {
    search.value = '';
    city = '';
    cities.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.city === '')));
    filter();
    search.focus();
  });

  $$('[data-copy]').forEach((button) => {
    button.hidden = false;
    let resetTimer;
    button.addEventListener('click', async () => {
      const label = button.firstChild;
      const status = $('[data-copy-status]');
      clearTimeout(resetTimer);
      try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(button.dataset.copy);
        label.textContent = 'RIB copié';
        status.textContent = `Le RIB de ${button.closest('[data-school]').querySelector('h3').textContent} a été copié.`;
      } catch {
        const number = document.getElementById(button.dataset.ribId);
        const range = document.createRange();
        range.selectNodeContents(number);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        label.textContent = 'RIB sélectionné';
        status.textContent = 'La copie automatique est indisponible. Le RIB est sélectionné : utilise la commande Copier de ton appareil.';
      }
      resetTimer = setTimeout(() => { label.textContent = 'Copier le RIB'; }, 2800);
    });
  });

  // Les éléments sont visibles par défaut : aucun contenu ne dépend des animations.
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  if (!Element.prototype.animate || !('IntersectionObserver' in window)) return;
  const animations = new Set();
  const animate = (element, delay = 0, intro = false) => {
    if (reducedMotion.matches) return;
    const animation = element.animate([
      { opacity: 0, transform: `translateY(${intro ? 24 : 20}px)` },
      { opacity: 1, transform: 'translateY(0)' }
    ], { duration: intro ? 850 : 650, delay, easing: 'cubic-bezier(.22, 1, .36, 1)', fill: 'backwards' });
    animations.add(animation);
    animation.finished.then(() => animations.delete(animation)).catch(() => animations.delete(animation));
  };
  if (!reducedMotion.matches) $$('[data-enter]').forEach((element, i) => animate(element, Math.min(i * 85, 350), true));
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      animate(entry.target);
      observer.unobserve(entry.target);
    });
  }, { threshold: .12, rootMargin: '0px 0px -25px 0px' });
  $$('[data-reveal]').forEach((element) => observer.observe(element));
  reducedMotion.addEventListener('change', () => {
    if (!reducedMotion.matches) return;
    animations.forEach((animation) => animation.cancel());
    observer.disconnect();
    // Le lecteur existant applique aussi ce choix au prochain chargement ;
    // en cours de visite, on utilise son contrôle public de pause.
    const pause = $('[data-remotion-pause]');
    if (pause && pause.getAttribute('aria-pressed') !== 'true') pause.click();
  });
})();
