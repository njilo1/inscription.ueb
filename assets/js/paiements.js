/* Filtrage local : les données et le total du périmètre restent inchangés.
   Sans JavaScript, le tableau complet est disponible. */
(() => {
	"use strict";
	const normaliser = (texte) => texte.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase("fr").trim();
	document.querySelectorAll("[data-paiements-liste]").forEach((section) => {
		const controles = section.querySelector("[data-paiements-filtres]");
		if (!controles) return;
		const recherche = section.querySelector("[data-paiements-recherche]");
		const situation = section.querySelector("[data-paiements-situation]");
		const effacer = section.querySelector("[data-paiements-effacer]");
		const resultat = section.querySelector("[data-paiements-resultats]");
		const vide = section.querySelector("[data-paiements-vide]");
		const tableau = section.querySelector(".suivi-table");
		const lignes = [...section.querySelectorAll("[data-paiement-ligne]")].map((ligne) => ({
			ligne, texte: normaliser(ligne.dataset.recherche), situation: ligne.dataset.situation,
		}));
		let delai;
		const filtrer = () => {
			clearTimeout(delai);
			const mots = normaliser(recherche.value).split(/\s+/).filter(Boolean);
			let total = 0;
			lignes.forEach((item) => {
				const visible = mots.every((mot) => item.texte.includes(mot)) && (situation.value === "tous" || situation.value === item.situation);
				item.ligne.hidden = !visible;
				if (visible) total++;
			});
			const pluriel = total > 1 ? "s" : "";
			resultat.textContent = `${total} ${section.dataset.unite}${pluriel} sur ${lignes.length} · Les indicateurs et le total portent sur tout le périmètre.`;
			vide.hidden = total !== 0;
			tableau.hidden = total === 0;
			effacer.disabled = !recherche.value && situation.value === "tous";
		};
		recherche.addEventListener("input", () => {
			clearTimeout(delai);
			delai = setTimeout(filtrer, 150);
		});
		situation.addEventListener("change", filtrer);
		effacer.addEventListener("click", () => {
			recherche.value = "";
			situation.value = "tous";
			filtrer();
			recherche.focus();
		});
		filtrer();
		controles.hidden = false;
	});
})();
