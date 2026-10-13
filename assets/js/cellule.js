/**
 * Tableau de bord de la cellule informatique : l'anneau de l'état des comptes
 * se balaie (composition Remotion « donut », lecteur assets/js/remotion-ueb.js)
 * par-dessus le SVG serveur, qui reste l'image finale et le repli.
 *
 * html.adm-anime (posé avant l'affichage, jamais en mouvement réduit) cache
 * les parts du SVG ; sans lecteur, ou s'il n'est pas prêt à temps, on les
 * montre aussitôt.
 */
(() => {
	"use strict";
	const racine = document.documentElement;
	const anneau = document.querySelector(".cel-anneau[data-props]");
	if (!anneau || !racine.classList.contains("adm-anime")) return;
	if (typeof window.uebMonterAnimation !== "function" || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
		racine.classList.remove("adm-anime");
		return;
	}
	racine.classList.add("adm-anime-pret");
	const hote = document.createElement("div");
	hote.className = "animation animation--donut";
	hote.dataset.remotion = "donut";
	hote.dataset.props = anneau.dataset.props;
	hote.setAttribute("aria-hidden", "true");
	hote.innerHTML = '<div class="animation__scene" data-remotion-scene></div>';
	anneau.prepend(hote);
	window.uebMonterAnimation(hote);
	window.setTimeout(() => {
		if (!hote.classList.contains("est-pret")) racine.classList.remove("adm-anime");
	}, 2500);
})();
