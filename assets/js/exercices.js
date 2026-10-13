/*
 * Onglet Exercice de l'administration (templates/composants/exercices-admin.php).
 *
 *   - À l'arrivée, un seul moment orchestré, joué avec la frise Remotion de
 *     l'avancement : les lignes de la liste se posent l'une après l'autre, la
 *     sélection paraît sur l'exercice consulté, les chiffres et les jours
 *     comptent jusqu'à leur valeur.
 *   - Choisir un autre exercice : la sélection glisse jusqu'à sa ligne, le
 *     détail s'estompe, puis la page se recharge sur cet exercice. Haut / bas
 *     au clavier déplacent le focus d'une ligne à l'autre ; Entrée la choisit.
 *   - Activer par l'interrupteur « Exercice actif » : une fois la fenêtre de
 *     confirmation acceptée (app.js), le curseur glisse, puis la décision part.
 *   - Clôturer : une fois la fenêtre de confirmation acceptée, le sceau
 *     « Exercice clôturé » frappe le panneau du statut, puis la décision part.
 *
 * « Réduire les animations » : tout est à sa place d'emblée, sans délai d'envoi.
 * Sans script : chaque ligne est un formulaire qui fonctionne seul.
 */
(() => {
	"use strict";

	const liste = document.querySelector("[data-exo-liste]");
	if (!liste) return;

	const reduit = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
	const gsap = window.gsap;
	const anime = Boolean(gsap) && !reduit;
	const corps = liste.querySelector(".exo-liste__corps");
	const selection = liste.querySelector("[data-exo-selection]");
	const detail = document.querySelector("[data-exo-detail]");
	const lignes = [...liste.querySelectorAll(".exo-ligne")];
	let parti = false;

	/* ---------- Sélection : une pièce blanche posée sur la ligne consultée ---------- */

	const consultee = () => lignes.find((li) => li.classList.contains("est-consulte"));
	const cible = (li) => {
		const c = corps.getBoundingClientRect();
		const r = li.getBoundingClientRect();
		return { x: r.left - c.left, y: r.top - c.top, width: r.width, height: r.height };
	};
	const poser = (li) => {
		if (!selection || !li) return;
		const c = cible(li);
		if (gsap) {
			gsap.set(selection, c);
		} else {
			Object.assign(selection.style, { transform: `translate(${c.x}px, ${c.y}px)`, width: `${c.width}px`, height: `${c.height}px` });
		}
	};
	if (selection && consultee()) {
		liste.classList.add("a-selection");
		poser(consultee());
		new ResizeObserver(() => { if (!parti) poser(consultee()); }).observe(corps);
	}

	/* ---------- Choisir un exercice ---------- */

	lignes.forEach((li) => {
		const form = li.querySelector("form");
		form.addEventListener("submit", (ev) => {
			if (form.dataset.parti === "1") return; // renvoyé après la glissade : il part
			ev.preventDefault();
			if (parti || li.classList.contains("est-consulte")) return;
			parti = true;
			consultee()?.classList.remove("est-consulte");
			li.classList.add("est-consulte");
			form.querySelector("button").setAttribute("aria-busy", "true");
			detail?.classList.add("est-en-attente");
			const envoyer = () => {
				form.dataset.parti = "1";
				form.requestSubmit();
			};
			if (!anime || !selection) {
				envoyer();
				return;
			}
			gsap.to(selection, { ...cible(li), duration: 0.42, ease: "expo.out", onComplete: envoyer });
		});
	});
	liste.addEventListener("keydown", (ev) => {
		if (ev.key !== "ArrowUp" && ev.key !== "ArrowDown") return;
		const actuelle = lignes.findIndex((li) => li.contains(document.activeElement));
		const voisine = lignes[actuelle + (ev.key === "ArrowUp" ? -1 : 1)];
		if (actuelle < 0 || !voisine) return;
		ev.preventDefault();
		voisine.querySelector("button").focus();
	});

	/* ---------- Arrivée ---------- */

	if (anime) {
		const ligne = gsap.timeline({ defaults: { ease: "expo.out" } });
		ligne.from(lignes.map((li) => li.querySelector(".exo-ligne__bouton")), { opacity: 0, x: -12, duration: 0.6, stagger: 0.05, clearProps: "opacity,transform" }, 0.05);
		if (selection && consultee()) {
			ligne.from(selection, { opacity: 0, scale: 0.97, duration: 0.6 }, 0.3);
		}

		/* Chiffres du détail : du zéro à leur valeur, puis le texte exact du serveur. */
		const format = new Intl.NumberFormat("fr-FR");
		document.querySelectorAll("[data-exo-detail] [data-compter]").forEach((el, i) => {
			const fin = Number(el.dataset.compter) || 0;
			const texte = el.textContent;
			if (!fin) return;
			const etat = { v: 0 };
			el.textContent = "0";
			gsap.to(etat, {
				v: fin,
				duration: 1.2,
				delay: 0.35 + i * 0.08,
				ease: "expo.out",
				onUpdate: () => { el.textContent = format.format(Math.round(etat.v)); },
				onComplete: () => { el.textContent = texte; },
			});
		});
	}

	/* ---------- Activer par l'interrupteur : le curseur glisse ---------- */

	const allumer = document.querySelector("form[data-exo-allumer]");
	allumer?.addEventListener("submit", (ev) => {
		/* La fenêtre de confirmation passe d'abord (app.js) ; refusée, rien ne part. */
		if (ev.defaultPrevented || allumer.dataset.allume === "1") return;
		const interrupteur = allumer.querySelector("[role='switch']");
		interrupteur?.setAttribute("aria-checked", "true");
		interrupteur?.setAttribute("aria-busy", "true");
		if (reduit) return;
		ev.preventDefault();
		allumer.dataset.allume = "1";
		window.setTimeout(() => allumer.requestSubmit(), 320);
	});

	/* ---------- Clôturer : le sceau frappe le panneau du statut ---------- */

	const cloture = document.querySelector("form[data-exo-sceller]");
	const sceau = document.querySelector("[data-exo-sceau]");
	cloture?.addEventListener("submit", (ev) => {
		/* La fenêtre de confirmation passe d'abord (app.js) ; refusée, rien ne part. */
		if (ev.defaultPrevented || cloture.dataset.scelle === "1") return;
		const animation = sceau && sceau.querySelector("[data-remotion-differe]");
		if (!animation || reduit || typeof window.uebMonterAnimation !== "function") return;
		ev.preventDefault();
		cloture.dataset.scelle = "1";
		cloture.querySelector("button")?.setAttribute("aria-busy", "true");

		const panneau = sceau.closest(".exo-etat");
		const horsEcran = panneau.getBoundingClientRect().top < 0;
		if (horsEcran) panneau.scrollIntoView({ behavior: "smooth", block: "center" });
		window.setTimeout(() => {
			sceau.hidden = false;
			animation.dataset.remotion = animation.dataset.remotionDiffere;
			window.uebMonterAnimation(animation);
			window.setTimeout(() => cloture.requestSubmit(), 1350);
		}, horsEcran ? 450 : 0);
	});
})();
