/* Registre des quitus, espace scolarité : toute la ligne d'un dossier ouvre
 * sa fiche (le nom reste le lien, pour le clavier et sans JavaScript), les
 * filtres s'appliquent dès qu'on les change, « Valider » fait frapper le
 * tampon de la scolarité sur la ligne avant l'envoi (la fenêtre de
 * confirmation d'app.js passe d'abord), et le mouvement accompagne
 * l'arrivée, les filtres, l'ouverture d'un dossier et la validation. */
(() => {
	"use strict";
	const registre = document.querySelector("[data-registre-quitus]");
	if (!registre) return;
	const reduit = window.matchMedia("(prefers-reduced-motion: reduce)");
	const fenetre = document.getElementById("fenetre-confirmation");
	const interactif = "a, button, input, select, textarea, label, summary, form";

	/* Clic n'importe où sur le dossier ; Ctrl, ⌘ ou clic du milieu : nouvel onglet. */
	const ouvrir = (ev) => {
		const dossier = ev.target.closest("[data-href]");
		if (!dossier || ev.target.closest(interactif)) return;
		if (String(window.getSelection?.() ?? "").trim()) return; /* l'agent sélectionne un numéro */
		if (ev.button === 1 || ev.ctrlKey || ev.metaKey) {
			window.open(dossier.dataset.href, "_blank", "noopener");
		} else if (ev.button === 0) {
			window.location.assign(dossier.dataset.href);
		}
	};
	registre.addEventListener("click", ouvrir);
	registre.addEventListener("auxclick", (ev) => { if (ev.button === 1) ouvrir(ev); });

	/* Filtres : une liste changée s'applique aussitôt (le bouton « Filtrer »
	   ne sert que sans JavaScript ; la recherche part avec Entrée). */
	const filtres = registre.querySelector("[data-filtres-registre]");
	if (filtres) {
		filtres.querySelector(".registre__filtrer")?.remove();
		filtres.querySelectorAll("select").forEach((liste) => liste.addEventListener("change", () => filtres.requestSubmit()));
	}

	/* ---------- Mouvement ----------
	   L'arrivée est préparée avant le premier affichage par le script en ligne
	   du gabarit (data-arrivee, est-entree, data-depart). */
	const memoire = {
		lire: (cle) => { try { return window.sessionStorage.getItem(cle); } catch (e) { return null; } },
		ecrire: (cle, valeur) => { try { window.sessionStorage.setItem(cle, valeur); } catch (e) { /* stockage indisponible */ } },
	};
	const interne = registre.dataset.arrivee === "interne";

	/* Les compteurs défilent jusqu'à leur valeur : depuis zéro à l'arrivée,
	   depuis l'ancienne valeur après un filtre ou une validation. */
	const comptes = {};
	registre.querySelectorAll("[data-compte]").forEach((el, rang) => {
		const fin = Number(el.dataset.compte);
		comptes[el.dataset.cle] = fin;
		if (el.dataset.depart === undefined || reduit.matches) {
			el.textContent = fin;
			return;
		}
		const debut = Number(el.dataset.depart);
		const duree = interne ? 520 : 820;
		const t0 = performance.now() + (interne ? 120 : 260 + rang * 70);
		const pas = (t) => {
			const p = Math.min(1, Math.max(0, (t - t0) / duree));
			el.textContent = Math.round(debut + (fin - debut) * (1 - Math.pow(1 - p, 3)));
			if (p < 1) window.requestAnimationFrame(pas);
		};
		window.requestAnimationFrame(pas);
	});
	memoire.ecrire("ueb-registre-comptes", JSON.stringify(comptes));

	/* L'anneau de répartition (Remotion) se dessine à l'arrivée ; après un
	   filtre, il s'affiche directement dans son état final. */
	const anneau = registre.querySelector('[data-remotion-differe="donut"]');
	if (anneau && typeof window.uebMonterAnimation === "function") {
		if (interne) anneau.classList.add("animation--fige");
		anneau.dataset.remotion = anneau.dataset.remotionDiffere;
		window.uebMonterAnimation(anneau);
	}

	/* Après une validation, la ligne du dossier s'éclaire puis se pose. */
	const retrouve = location.hash.startsWith("#dossier-") && document.getElementById(location.hash.slice(1));
	if (retrouve && retrouve.matches(".registre__dossier")) {
		retrouve.classList.add("est-retrouve");
		const zone = retrouve.getBoundingClientRect();
		if (zone.top < 80 || zone.bottom > window.innerHeight) retrouve.scrollIntoView({ block: "center", behavior: reduit.matches ? "auto" : "smooth" });
		history.replaceState(null, "", location.pathname + location.search);
	}

	/* Transition de vue vers la fiche : l'avatar de la ligne rejoint l'en-tête
	   de la fiche (même nom de transition que .qf-avatar), et au retour la fiche
	   le rend à sa ligne. */
	const avatarDe = (id) => id && registre.querySelector(`#dossier-${CSS.escape(id)} .bo-avatar`);
	window.addEventListener("pageswap", (ev) => {
		const cible = ev.activation?.entry?.url;
		if (!ev.viewTransition || !cible) return;
		const url = new URL(cible);
		const id = url.searchParams.get("quitus");
		const avatar = !url.searchParams.has("pdf") && avatarDe(id);
		if (!avatar) return;
		avatar.style.viewTransitionName = "qf-avatar";
		memoire.ecrire("ueb-registre-dossier", id);
	});
	window.addEventListener("pagereveal", (ev) => {
		if (!ev.viewTransition) return;
		const avatar = avatarDe(memoire.lire("ueb-registre-dossier"));
		if (!avatar) return;
		avatar.style.viewTransitionName = "qf-avatar";
		ev.viewTransition.finished.finally(() => { avatar.style.viewTransitionName = ""; });
	});

	/* Le tampon frappe la ligne, puis la validation s'enregistre. */
	registre.querySelectorAll("form[data-registre-valider]").forEach((form) => {
		form.addEventListener("submit", (ev) => {
			if (ev.defaultPrevented || form.dataset.tamponne === "1") return;
			if (form.dataset.confirmer && form.dataset.confirme !== "1" && fenetre?.showModal) return;
			const hote = form.querySelector("[data-registre-tampon]");
			const animation = hote?.querySelector("[data-remotion-differe]");
			if (!animation || reduit.matches || typeof window.uebMonterAnimation !== "function") return;
			ev.preventDefault();
			form.dataset.tamponne = "1";
			form.querySelector("button[type=submit]")?.setAttribute("aria-busy", "true");
			form.closest("[data-href]")?.classList.add("est-tamponne");
			hote.hidden = false;
			animation.dataset.remotion = animation.dataset.remotionDiffere;
			window.uebMonterAnimation(animation);
			window.setTimeout(() => form.requestSubmit(), 1150);
		});
	});
})();
