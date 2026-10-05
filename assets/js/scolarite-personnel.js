/* Le registre et le formulaire restent utilisables sans JavaScript : le volet
   de création est alors dans la page, sous le registre. */
(() => {
	"use strict";
	const personnel = document.querySelector("[data-personnel]");
	if (!personnel) return;
	const sansAccent = (texte) => texte.normalize("NFD").replace(/\p{M}/gu, "");
	const calme = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;

	/* ---------- Tiroir de création ---------- */
	const creation = personnel.querySelector("[data-personnel-creation]");
	if (creation && typeof HTMLDialogElement === "function") {
		const tiroir = document.createElement("dialog");
		tiroir.className = "personnel-tiroir";
		tiroir.setAttribute("aria-labelledby", "titre-cellule");
		creation.before(tiroir);
		tiroir.append(creation);
		const fermer = creation.querySelector("[data-personnel-fermer]");
		fermer.hidden = false;
		let declencheur = null;

		const ouvrir = (cible) => {
			if (tiroir.open) return;
			declencheur = cible || document.activeElement;
			tiroir.classList.remove("est-fermeture");
			tiroir.showModal();
			const champ = creation.querySelector(".champ--invalide input") || creation.querySelector("[data-resume-erreurs]") || creation.querySelector('[name="login"]');
			champ?.focus();
		};
		const refermer = () => {
			if (!tiroir.open || tiroir.classList.contains("est-fermeture")) return;
			const finir = () => tiroir.close();
			if (calme()) return finir();
			tiroir.classList.add("est-fermeture");
			tiroir.addEventListener("animationend", finir, { once: true });
		};

		document.querySelectorAll("[data-personnel-ouvrir]").forEach((lien) => {
			lien.addEventListener("click", (event) => {
				event.preventDefault();
				ouvrir(lien);
			});
		});
		fermer.addEventListener("click", refermer);
		/* Quelle que soit la façon de fermer, le focus revient au bouton d'ouverture. */
		tiroir.addEventListener("close", () => {
			tiroir.classList.remove("est-fermeture");
			declencheur?.focus?.();
		});
		/* Échap et clic sur le voile referment avec le même mouvement. */
		tiroir.addEventListener("cancel", (event) => {
			event.preventDefault();
			refermer();
		});
		tiroir.addEventListener("click", (event) => {
			if (event.target === tiroir) refermer();
		});
		/* Après un échec de création, le tiroir se rouvre sur le champ en erreur. */
		if (creation.hasAttribute("data-personnel-erreur")) ouvrir(personnel.querySelector("[data-personnel-ouvrir]"));
	}

	/* L'identifiant s'écrit comme il servira à la connexion : minuscules,
	   sans accent, les espaces deviennent des points. */
	const login = personnel.querySelector("[data-personnel-login]");
	login?.addEventListener("input", () => {
		const avant = login.value;
		const propre = sansAccent(avant).toLowerCase().replace(/\s+/g, ".").replace(/[^a-z0-9._@-]/g, "");
		if (propre === avant) return;
		const position = Math.max(0, (login.selectionStart ?? avant.length) - (avant.length - propre.length));
		login.value = propre;
		login.setSelectionRange(position, position);
	});

	/* ---------- Recherche et filtres (à partir de 4 comptes) ---------- */
	const recherche = personnel.querySelector("[data-personnel-recherche]");
	if (!recherche) return;
	const normaliser = (texte) => sansAccent(texte).toLocaleLowerCase("fr");
	const lignes = [...personnel.querySelectorAll("[data-personnel-ligne]")].map((element) => ({
		element,
		texte: normaliser(element.textContent),
		statut: element.dataset.statut,
	}));
	const filtres = [...personnel.querySelectorAll("[data-personnel-statut]")];
	const compteur = personnel.querySelector("[data-personnel-compte]");
	const vide = personnel.querySelector("[data-personnel-sans-resultat]");
	const tableau = personnel.querySelector(".personnel-table-conteneur");
	let statut = "tous";
	let delai;
	const filtrer = () => {
		const termes = normaliser(recherche.value.trim()).split(/\s+/).filter(Boolean);
		let nombre = 0;
		lignes.forEach((ligne) => {
			const visible = (statut === "tous" || ligne.statut === statut) && termes.every((terme) => ligne.texte.includes(terme));
			ligne.element.hidden = !visible;
			if (visible) nombre += 1;
		});
		compteur.textContent = nombre === lignes.length
			? `${nombre} compte${nombre === 1 ? "" : "s"}`
			: `${nombre} compte${nombre === 1 ? "" : "s"} sur ${lignes.length}`;
		vide.hidden = nombre > 0;
		tableau.hidden = nombre === 0;
	};
	recherche.addEventListener("input", () => {
		clearTimeout(delai);
		delai = setTimeout(filtrer, 150);
	});
	filtres.forEach((bouton) => {
		bouton.addEventListener("click", () => {
			clearTimeout(delai);
			statut = bouton.dataset.personnelStatut;
			filtres.forEach((filtre) => filtre.setAttribute("aria-pressed", String(filtre === bouton)));
			filtrer();
		});
	});
	personnel.querySelector("[data-personnel-effacer]").addEventListener("click", () => {
		clearTimeout(delai);
		recherche.value = "";
		statut = "tous";
		filtres.forEach((filtre) => filtre.setAttribute("aria-pressed", String(filtre.dataset.personnelStatut === statut)));
		filtrer();
		recherche.focus();
	});
	personnel.querySelector("[data-personnel-outils]").hidden = false;
	window.addEventListener("pageshow", filtrer);
	filtrer();
})();
