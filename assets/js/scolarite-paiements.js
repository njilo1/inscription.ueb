/* Suivi des paiements de l'espace scolarité : la courbe des encaissements se
 * trace une fois, à son entrée à l'écran. Les autres mouvements (bilans qui
 * comptent, colonnes, anneau Remotion, jauges) viennent de
 * administration-mouvement.js. Sans JavaScript ou en mouvement réduit, la
 * courbe s'affiche entière. */
(() => {
	"use strict";
	const courbe = document.querySelector("[data-sco-courbe]");
	if (!courbe || !courbe.querySelector(".sco-courbe__trace")) return;
	if (window.matchMedia("(prefers-reduced-motion: reduce)").matches || !("IntersectionObserver" in window)) return;
	courbe.classList.add("est-a-tracer");
	const observateur = new IntersectionObserver((entrees) => {
		if (!entrees.some((e) => e.isIntersecting)) return;
		observateur.disconnect();
		window.requestAnimationFrame(() => courbe.classList.add("est-tracee"));
	}, { threshold: 0.35 });
	observateur.observe(courbe);
})();
