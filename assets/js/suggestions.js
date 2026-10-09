/*
 * Recherche intelligente (inc/suggestions.php) : dès la première lettre, une
 * liste de suggestions sous le champ de recherche, les noms, matricules et
 * numéros qui commencent par la saisie, au début d'un de leurs mots, sans
 * tenir compte des accents ni des majuscules. Les lettres tapées ressortent.
 *
 * Sources, au choix sur le champ :
 *   data-suggestions-url    la base (admin-ajax.php), droits de la page ;
 *   data-suggestions-liste  une liste JSON fournie par la page ;
 *   data-suggestions-lignes un sélecteur de lignes déjà affichées
 *                           (data-pay-ligne : JSON nom/detail ; sinon data-recherche).
 *
 * Clavier : flèches haut et bas pour parcourir, Entrée pour choisir, Échap
 * pour fermer. Choisir une suggestion met sa valeur dans le champ et lance la
 * recherche : l'événement « input » pour les filtres en direct, sinon l'envoi
 * du formulaire. Entrée sans suggestion choisie cherche le texte tapé.
 * Sans JavaScript, le champ reste une recherche ordinaire.
 */
(() => {
	"use strict";

	const MAX = 8;
	const normaliser = (t) => String(t ?? "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
	const mots = (t) => normaliser(t).split(/[^a-z0-9]+/).filter(Boolean);
	/* Chaque mot tapé commence un mot du texte. */
	const correspond = (texte, saisie) => {
		const m = mots(texte);
		return mots(saisie).every((s) => m.some((x) => x.startsWith(s)));
	};
	let rang = 0;

	const brancher = (champ) => {
		const id = `suggestions-${++rang}`;
		const hote = champ.parentElement;
		const liste = document.createElement("ul");
		const statut = document.createElement("p");
		liste.id = id;
		liste.className = "suggestions";
		liste.setAttribute("role", "listbox");
		liste.setAttribute("aria-label", "Suggestions");
		liste.hidden = true;
		statut.className = "sr";
		statut.setAttribute("role", "status");
		hote.classList.add("suggestions-hote");
		hote.append(liste, statut);
		champ.setAttribute("role", "combobox");
		champ.setAttribute("aria-autocomplete", "list");
		champ.setAttribute("aria-expanded", "false");
		champ.setAttribute("aria-controls", id);
		champ.setAttribute("autocomplete", "off");

		const form = champ.form;
		const enDirect = Boolean(form?.hasAttribute("data-filtres-direct")) || Boolean(champ.dataset.suggestionsLignes) || !form;
		const cache = new Map();
		let local = null;
		try {
			local = champ.dataset.suggestionsListe ? JSON.parse(champ.dataset.suggestionsListe) : null;
		} catch (e) {
			local = null;
		}
		let options = [];
		let actif = -1;
		let controle = null;
		let minuterie = null;
		let ignorer = false;

		/* Candidats des lignes affichées : une fois par nom. */
		const candidatsLignes = () => {
			const vus = new Map();
			document.querySelectorAll(champ.dataset.suggestionsLignes).forEach((el) => {
				let l = "";
				let d = "";
				if (el.dataset.payLigne) {
					try {
						const p = JSON.parse(el.dataset.payLigne);
						l = p.nom;
						d = p.detail || "";
					} catch (e) { /* ligne illisible : ignorée */ }
				} else {
					l = el.dataset.recherche || el.textContent;
				}
				l = String(l || "").trim();
				if (l && !vus.has(l)) vus.set(l, { l, d, v: l });
			});
			return [...vus.values()];
		};
		/* Rang : le nom commence par la saisie, puis un mot du nom, puis le détail seul. */
		const filtrer = (candidats, saisie) => {
			const debut = normaliser(saisie);
			const rangDe = (c) => (normaliser(c.l).startsWith(debut) ? 0 : correspond(c.l, saisie) ? 1 : 2);
			return candidats
				.filter((c) => correspond(`${c.l} ${c.d} ${c.v}`, saisie))
				.map((c) => ({ c, r: rangDe(c) }))
				.sort((a, b) => a.r - b.r || a.c.l.localeCompare(b.c.l, "fr"))
				.slice(0, MAX)
				.map(({ c }) => c);
		};
		const chercher = async (saisie) => {
			if (local) return filtrer(local, saisie);
			if (champ.dataset.suggestionsLignes) return filtrer(candidatsLignes(), saisie);
			const cle = normaliser(saisie);
			if (cache.has(cle)) return cache.get(cle);
			controle?.abort();
			controle = new AbortController();
			const url = new URL(champ.dataset.suggestionsUrl, location.href);
			url.searchParams.set("q", saisie);
			const reponse = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" }, signal: controle.signal });
			if (!reponse.ok) throw new Error(`Réponse ${reponse.status}`);
			const json = await reponse.json();
			const resultats = json.success && Array.isArray(json.data) ? json.data : [];
			cache.set(cle, resultats);
			return resultats;
		};

		/* Libellé avec les lettres tapées en gras, construit en nœuds texte (jamais en HTML). */
		const surligner = (texte, saisie) => {
			const fragment = document.createDocumentFragment();
			const tapes = mots(saisie);
			String(texte).split(/(\s+)/).forEach((morceau) => {
				const n = normaliser(morceau);
				const prefixe = tapes.filter((t) => n.startsWith(t)).sort((a, b) => b.length - a.length)[0];
				if (!prefixe || /^\s+$/.test(morceau)) {
					fragment.append(morceau);
					return;
				}
				/* Les accents ne changent pas la longueur visible : on coupe au même nombre de lettres. */
				let i = 0;
				let lettres = 0;
				while (i < morceau.length && lettres < prefixe.length) {
					if (/[a-z0-9]/.test(normaliser(morceau[i]))) lettres++;
					i++;
				}
				const marque = document.createElement("mark");
				marque.textContent = morceau.slice(0, i);
				fragment.append(marque, morceau.slice(i));
			});
			return fragment;
		};

		const fermer = () => {
			liste.hidden = true;
			champ.setAttribute("aria-expanded", "false");
			champ.removeAttribute("aria-activedescendant");
			actif = -1;
		};
		const marquer = (i) => {
			actif = i;
			[...liste.children].forEach((li, k) => li.setAttribute("aria-selected", String(k === i)));
			if (i >= 0 && options[i]) {
				champ.setAttribute("aria-activedescendant", `${id}-${i}`);
				liste.children[i].scrollIntoView({ block: "nearest" });
			} else {
				champ.removeAttribute("aria-activedescendant");
			}
		};
		const afficher = (resultats, saisie) => {
			options = resultats;
			liste.replaceChildren();
			if (!resultats.length) {
				const vide = document.createElement("li");
				vide.className = "suggestions__vide";
				vide.setAttribute("role", "presentation");
				vide.textContent = `Aucun résultat ne commence par « ${saisie} ». Entrée pour chercher partout.`;
				liste.append(vide);
			}
			resultats.forEach((s, i) => {
				const li = document.createElement("li");
				li.id = `${id}-${i}`;
				li.className = "suggestions__option";
				li.setAttribute("role", "option");
				li.setAttribute("aria-selected", "false");
				const libelle = document.createElement("span");
				libelle.className = "suggestions__libelle";
				libelle.append(surligner(s.l, saisie));
				li.append(libelle);
				if (s.d) {
					const detail = document.createElement("span");
					detail.className = "suggestions__detail";
					detail.append(surligner(s.d, saisie));
					li.append(detail);
				}
				li.addEventListener("click", () => choisir(i));
				liste.append(li);
			});
			liste.hidden = false;
			champ.setAttribute("aria-expanded", "true");
			actif = -1;
			statut.textContent = resultats.length ? `${resultats.length} suggestion${resultats.length > 1 ? "s" : ""}, flèches pour parcourir.` : "Aucune suggestion.";
		};
		const lancer = () => {
			const saisie = champ.value.trim();
			clearTimeout(minuterie);
			if (!saisie) {
				fermer();
				return;
			}
			minuterie = setTimeout(async () => {
				try {
					const resultats = await chercher(saisie);
					if (champ.value.trim() === saisie && document.activeElement === champ) afficher(resultats, saisie);
				} catch (erreur) {
					if (erreur.name !== "AbortError") fermer(); // la recherche ordinaire reste
				}
			}, local || champ.dataset.suggestionsLignes ? 0 : 130);
		};
		const choisir = (i) => {
			const s = options[i];
			if (!s) return;
			champ.value = s.v;
			fermer();
			if (enDirect) {
				ignorer = true;
				champ.dispatchEvent(new Event("input", { bubbles: true }));
				ignorer = false;
			} else {
				form.requestSubmit();
			}
		};

		champ.addEventListener("input", () => {
			if (!ignorer) lancer();
		});
		champ.addEventListener("focus", () => {
			if (champ.value.trim() && liste.hidden) lancer();
		});
		champ.addEventListener("keydown", (ev) => {
			const ouvert = !liste.hidden && options.length > 0;
			if (ev.key === "ArrowDown" || ev.key === "ArrowUp") {
				if (!ouvert) {
					lancer();
					return;
				}
				ev.preventDefault();
				const suivant = actif + (ev.key === "ArrowDown" ? 1 : -1);
				marquer(suivant >= options.length ? 0 : suivant < 0 ? options.length - 1 : suivant);
			} else if (ev.key === "Enter" && ouvert && actif >= 0) {
				ev.preventDefault();
				choisir(actif);
			} else if (ev.key === "Escape" && !liste.hidden) {
				ev.preventDefault();
				fermer();
			} else if (ev.key === "Tab") {
				fermer();
			}
		});
		champ.addEventListener("blur", () => setTimeout(fermer, 120));
		/* Un clic dans la liste ne fait pas perdre le focus au champ. */
		liste.addEventListener("mousedown", (ev) => ev.preventDefault());
		document.addEventListener("click", (ev) => {
			if (!hote.contains(ev.target)) fermer();
		});
	};

	document.querySelectorAll("input[data-suggestions-url], input[data-suggestions-liste], input[data-suggestions-lignes]").forEach(brancher);
})();
