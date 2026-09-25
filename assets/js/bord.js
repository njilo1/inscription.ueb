/*
 * Tableau de bord de l’espace scolarité : infobulle des courbes de
 * progression, au survol (souris, doigt) et au clavier. Rien n’en dépend :
 * sans JavaScript, la phrase de résumé, les étiquettes de fin de courbe et le
 * tableau pour lecteurs d’écran portent déjà toutes les valeurs.
 */
(() => {
	"use strict";

	/* « 1 250 » : séparateur des milliers identique à ueb_formater_montant(). */
	const nombre = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
	const accord = (n, formes) => formes[n > 1 ? 1 : 0];
	const element = (balise, classe) => {
		const el = document.createElement(balise);
		if (classe) el.className = classe;
		return el;
	};

	const initialiser = (racine) => {
		let donnees;
		try {
			donnees = JSON.parse(racine.dataset.courbes || "");
		} catch (e) {
			return;
		}
		const zone = racine.querySelector("[data-courbes-zone]");
		const annonce = racine.querySelector("[data-courbes-annonce]");
		const figure = racine.closest("figure") || racine;
		const n = donnees?.dates?.length || 0;
		const haut = donnees?.haut || 0;
		if (!zone || n < 2 || !haut) return;

		zone.tabIndex = 0;
		zone.setAttribute("role", "group");
		zone.setAttribute("aria-label", "Courbes jour par jour. Flèches gauche et droite pour changer de jour, Début et Fin pour aller au premier ou au dernier.");

		/* Repère, points et infobulle, créés une fois puis déplacés. */
		const repere = element("span", "courbes__repere");
		const bulle = element("div", "courbes__bulle");
		const date = element("p", "courbes__bulle-date");
		const liste = element("ul");
		repere.setAttribute("aria-hidden", "true");
		bulle.setAttribute("aria-hidden", "true");
		const lignes = donnees.series.map((serie) => {
			const point = element("span", `courbes__point courbes__point--${serie.cle} courbes__point--survol`);
			const li = element("li");
			const cle = racine.querySelector(`.courbes__fin--${serie.cle} .courbes__cle`);
			const valeur = element("b");
			const libelle = element("span");
			if (cle) li.append(cle.cloneNode(true));
			li.append(valeur, libelle);
			liste.append(li);
			point.setAttribute("aria-hidden", "true");
			zone.append(point);
			return { serie, point, valeur, libelle };
		});
		bulle.append(date, liste);
		zone.append(repere, bulle);

		let courant = n - 1;

		/* L’infobulle se tient à droite du repère, bascule à gauche quand elle
		   sortirait du panneau, et ne dépasse jamais son bord gauche. */
		const placer = (x) => {
			const z = zone.getBoundingClientRect();
			const f = figure.getBoundingClientRect();
			const px = (x / 100) * z.width;
			const largeur = bulle.offsetWidth;
			const ecart = 14;
			let gauche = px + ecart;
			if (z.left + gauche + largeur > f.right - 12) gauche = px - ecart - largeur;
			gauche = Math.max(f.left - z.left + 12, gauche);
			bulle.style.left = `${Math.round(gauche)}px`;
		};

		const texte = (i) => `${donnees.dates[i]} : ` + lignes
			.map(({ serie }) => `${nombre(serie.valeurs[i])} ${accord(serie.valeurs[i], serie.formes)}`)
			.join(", ");

		const afficher = (i, auClavier) => {
			courant = Math.min(n - 1, Math.max(0, i));
			const x = (100 * courant) / (n - 1);
			repere.style.setProperty("--x", `${x}%`);
			date.textContent = donnees.dates[courant];
			lignes.forEach(({ serie, point, valeur, libelle }) => {
				const v = serie.valeurs[courant];
				point.style.setProperty("--x", `${x}%`);
				point.style.setProperty("--y", `${(100 * v) / haut}%`);
				valeur.textContent = nombre(v);
				libelle.textContent = accord(v, serie.formes);
			});
			zone.classList.add("courbes__zone--active");
			placer(x);
			if (auClavier && annonce) annonce.textContent = texte(courant);
		};

		const cacher = () => zone.classList.remove("courbes__zone--active");

		const depuisPointeur = (e) => {
			const z = zone.getBoundingClientRect();
			afficher(Math.round(((e.clientX - z.left) / z.width) * (n - 1)), false);
		};
		zone.addEventListener("pointermove", depuisPointeur);
		zone.addEventListener("pointerdown", depuisPointeur);
		zone.addEventListener("pointerleave", () => {
			if (document.activeElement === zone) afficher(courant, false);
			else cacher();
		});

		zone.addEventListener("focus", () => afficher(courant, true));
		zone.addEventListener("blur", cacher);
		zone.addEventListener("keydown", (e) => {
			const cibles = {
				ArrowLeft: courant - 1,
				ArrowDown: courant - 1,
				ArrowRight: courant + 1,
				ArrowUp: courant + 1,
				PageDown: courant - 7,
				PageUp: courant + 7,
				Home: 0,
				End: n - 1,
			};
			if (e.key === "Escape") {
				cacher();
				return;
			}
			if (!(e.key in cibles)) return;
			e.preventDefault();
			afficher(cibles[e.key], true);
		});

		addEventListener("resize", () => {
			if (zone.classList.contains("courbes__zone--active")) placer((100 * courant) / (n - 1));
		}, { passive: true });
	};

	document.querySelectorAll(".courbes[data-courbes]").forEach(initialiser);
})();
