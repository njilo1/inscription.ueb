/*
 * Espace Administration : bascule clair / sombre.
 *
 * Le choix est mémorisé (localStorage « ueb-theme-bo ») et relu avant tout
 * affichage par le court script que ueb_page_debut() place en tête de page :
 * pas d'éclair blanc au chargement. Clair par défaut, quel que soit le réglage
 * du système : le sombre ne vient que d'un choix explicite.
 *
 * Le nouveau thème se révèle en cercle depuis le bouton quand le navigateur
 * sait le faire (View Transitions) ; sinon, ou en mouvement réduit, il
 * s'applique d'un coup. Le bouton est un bouton à deux états (aria-pressed).
 */
(() => {
	"use strict";

	const racine = document.documentElement;
	const boutons = document.querySelectorAll("[data-bascule-theme]");
	if (!boutons.length) return;

	const reduit = window.matchMedia("(prefers-reduced-motion: reduce)");
	/* État voulu, tenu ici et non relu dans la page : deux clics rapprochés
	   pendant une transition doivent bien s'annuler. */
	let voulu = racine.dataset.theme === "sombre";
	let enCours = null;

	const synchroniser = () => {
		boutons.forEach((b) => b.setAttribute("aria-pressed", String(voulu)));
	};

	/* Applique toujours l'état voulu au moment où elle s'exécute : les rappels
	   de transitions écourtées peuvent arriver dans le désordre. */
	const appliquer = () => {
		const sombre = voulu;
		if (sombre) {
			racine.dataset.theme = "sombre";
		} else {
			delete racine.dataset.theme;
		}
		try {
			localStorage.setItem("ueb-theme-bo", sombre ? "sombre" : "clair");
		} catch (e) {
			/* Stockage refusé (navigation privée) : le choix vaut pour cette page. */
		}
		synchroniser();
	};

	boutons.forEach((bouton) => {
		bouton.addEventListener("click", () => {
			voulu = !voulu;
			synchroniser(); // l'état du bouton change tout de suite, la page suit
			enCours?.skipTransition();
			bouton.classList.remove("est-bascule");
			void bouton.offsetWidth; // relance l'animation de l'icône
			bouton.classList.add("est-bascule");

			if (!document.startViewTransition || reduit.matches) {
				appliquer();
				return;
			}
			const r = bouton.getBoundingClientRect();
			const x = r.left + r.width / 2;
			const y = r.top + r.height / 2;
			const rayon = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
			const transition = document.startViewTransition(appliquer);
			enCours = transition;
			transition.finished.finally(() => {
				if (enCours === transition) enCours = null;
			});
			transition.ready
				.then(() => {
					racine.animate(
						{ clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${rayon}px at ${x}px ${y}px)`] },
						{ duration: 520, easing: "cubic-bezier(.16, 1, .3, 1)", pseudoElement: "::view-transition-new(root)" },
					);
				})
				.catch(() => {});
		});
	});

	synchroniser();
})();

/*
 * Registre des établissements : chaque ligne s'ouvre sur son tiroir (niveaux,
 * quitus par statut, montants). Toute la ligne est cliquable, hors liens ; le
 * bouton rond porte l'état (aria-expanded). Le tiroir se déplie en hauteur,
 * d'un coup en mouvement réduit, et redevient caché une fois refermé.
 */
(() => {
	"use strict";
	const reduit = window.matchMedia("(prefers-reduced-motion: reduce)");
	document.querySelectorAll(".adm-registre__ligne[data-detail]").forEach((ligne) => {
		const bouton = ligne.querySelector(".adm-registre__bouton");
		const detail = document.getElementById(ligne.dataset.detail);
		if (!bouton || !detail) return;
		let fermeture = 0;
		const basculer = () => {
			const ouvrir = bouton.getAttribute("aria-expanded") !== "true";
			bouton.setAttribute("aria-expanded", String(ouvrir));
			ligne.classList.toggle("est-ouvert", ouvrir);
			clearTimeout(fermeture);
			if (ouvrir) {
				detail.hidden = false;
				void detail.offsetHeight; /* part de la hauteur nulle : la transition joue */
				detail.classList.add("est-ouvert");
			} else {
				detail.classList.remove("est-ouvert");
				fermeture = setTimeout(() => { detail.hidden = true; }, reduit.matches ? 0 : 400);
			}
		};
		bouton.addEventListener("click", (e) => {
			e.stopPropagation();
			basculer();
		});
		ligne.addEventListener("click", (e) => {
			if (e.target.closest("a, button") || String(window.getSelection() || "")) return;
			basculer();
		});
	});
})();

/* Bouton « Imprimer » des écrans de consultation (ueb_bouton_imprimer()). */
document.addEventListener("click", (e) => {
	if (e.target.closest("[data-imprimer]")) window.print();
});
