/*
 * Espace Direction : assistant de rôle en trois étapes, aperçu en direct,
 * modèles, dépendances entre permissions, établissement demandé selon la
 * portée du rôle, dialogues de suppression.
 *
 * Amélioration progressive : sans JavaScript, les trois étapes restent
 * visibles l'une sous l'autre et le formulaire s'envoie normalement. Aucune
 * donnée n'est chargée ici : tout est déjà dans la page, et chaque envoi est
 * revérifié par le serveur (inc/direction.php).
 */
(() => {
	"use strict";
	const $ = (s, r = document) => r.querySelector(s);
	const $$ = (s, r = document) => [...r.querySelectorAll(s)];
	const reduit = window.matchMedia("(prefers-reduced-motion: reduce)");

	/* ---------- Dialogues (suppression d'un rôle) ---------- */
	$$("[data-ouvrir-dialogue]").forEach((bouton) => {
		const dialogue = document.getElementById(bouton.dataset.ouvrirDialogue);
		if (!dialogue?.showModal) return;
		bouton.addEventListener("click", () => {
			dialogue.showModal();
			$("select, input:not([type=hidden])", dialogue)?.focus();
		});
		$$("[data-fermer-dialogue]", dialogue).forEach((b) => b.addEventListener("click", () => dialogue.close()));
		dialogue.addEventListener("close", () => bouton.focus());
	});

	/* ---------- Établissement demandé seulement pour la portée « un » ---------- */
	$$("[data-compte-role]").forEach((form) => {
		const role = $("[data-choix-role]", form);
		const etab = $("[data-champ-etab]", form);
		if (!role || !etab) return;
		const maj = () => {
			const un = role.selectedOptions[0]?.dataset.portee === "un";
			etab.hidden = !un;
			$$("select", etab).forEach((s) => { s.disabled = !un; });
		};
		role.addEventListener("change", maj);
		maj();
	});

	/* ---------- Assistant de rôle ---------- */
	const form = $("[data-assistant]");
	if (!form) return;
	const donnees = JSON.parse($("#donnees-roles").textContent);
	const panneaux = $$("[data-etape]", form);
	const boutonsEtape = $$("[data-aller-etape]", form);
	const precedent = $("[data-etape-precedente]", form);
	const suivant = $("[data-etape-suivante]", form);
	const enregistrer = $("[data-enregistrer]", form);
	const compteur = $("[data-compteur-etape]", form);
	const nom = $("[data-champ-nom]", form);
	const choixEtabs = $("[data-etabs-choix]", form);
	let etape = 1;

	form.classList.add("est-pas-a-pas");

	/* Contrôle léger avant d'avancer ; le serveur refait toutes les vérifications. */
	const erreurEtape = (n) => {
		if (n === 1 && nom.value.trim().length < 2) return [nom, "Donne un nom au rôle (2 caractères au moins)."];
		if (n === 2) {
			const portee = $("[data-portee]:checked", form);
			if (!portee) return [$("[data-portee]", form), "Choisis la portée du rôle."];
			if (portee.value === "plusieurs" && $$("input[name='etablissements[]']:checked", form).length < 2) {
				return [$("input[name='etablissements[]']:not(:disabled)", form), "Coche au moins deux établissements."];
			}
		}
		if (n === 3 && !$$("[data-permission]:checked", form).length) return [$("[data-permission]:not(:disabled)", form), "Coche au moins une permission."];
		return null;
	};
	const signaler = (champ, message) => {
		let bulle = $(".assistant__erreur", form);
		if (!bulle) {
			bulle = document.createElement("p");
			bulle.className = "assistant__erreur";
			bulle.setAttribute("role", "alert");
			$(".assistant__nav", form).before(bulle);
		}
		bulle.textContent = message;
		bulle.hidden = !message;
		if (champ && message) champ.focus();
	};

	const aller = (n, sens = 1) => {
		etape = Math.min(3, Math.max(1, n));
		panneaux.forEach((p) => {
			const actif = Number(p.dataset.etape) === etape;
			p.hidden = !actif;
			p.classList.toggle("entre-avant", actif && sens > 0 && !reduit.matches);
			p.classList.toggle("entre-arriere", actif && sens < 0 && !reduit.matches);
		});
		boutonsEtape.forEach((b) => {
			const num = Number(b.dataset.allerEtape);
			if (num === etape) b.setAttribute("aria-current", "step");
			else b.removeAttribute("aria-current");
			b.classList.toggle("est-faite", num < etape);
		});
		precedent.hidden = etape === 1;
		suivant.hidden = etape === 3;
		enregistrer.hidden = etape !== 3;
		compteur.textContent = `Étape ${etape} sur 3`;
		signaler(null, "");
		$("h2", panneaux[etape - 1])?.focus({ preventScroll: true });
	};
	panneaux.forEach((p) => $("h2", p)?.setAttribute("tabindex", "-1"));

	suivant.addEventListener("click", () => {
		const erreur = erreurEtape(etape);
		if (erreur) return signaler(...erreur);
		aller(etape + 1, 1);
	});
	precedent.addEventListener("click", () => aller(etape - 1, -1));
	boutonsEtape.forEach((b) => b.addEventListener("click", () => {
		const cible = Number(b.dataset.allerEtape);
		/* On ne saute en avant qu'après avoir validé les étapes intermédiaires. */
		for (let n = etape; n < cible; n++) {
			const erreur = erreurEtape(n);
			if (erreur) { aller(n, 1); return signaler(...erreur); }
		}
		aller(cible, cible >= etape ? 1 : -1);
	}));
	form.addEventListener("submit", (ev) => {
		for (let n = 1; n <= 3; n++) {
			const erreur = erreurEtape(n);
			if (erreur) { ev.preventDefault(); aller(n, -1); signaler(...erreur); return; }
		}
	});
	/* Entrée dans un champ : avancer plutôt qu'envoyer trop tôt. */
	form.addEventListener("keydown", (ev) => {
		if (ev.key === "Enter" && ev.target.matches("input[type=text]") && etape < 3) {
			ev.preventDefault();
			suivant.click();
		}
	});

	/* ---------- Dépendances entre permissions ---------- */
	const permissions = $$("[data-permission]", form);
	const par = (cap) => permissions.find((c) => c.value === cap);
	permissions.forEach((c) => c.addEventListener("change", () => {
		if (c.checked && c.dataset.requiert) {
			const requise = par(c.dataset.requiert);
			if (requise && !requise.disabled) requise.checked = true;
		}
		if (!c.checked) {
			permissions.filter((d) => d.dataset.requiert === c.value).forEach((d) => { d.checked = false; });
		}
	}));

	/* ---------- Portée ---------- */
	const majPortee = () => {
		const portee = $("[data-portee]:checked", form)?.value;
		choixEtabs.hidden = portee !== "plusieurs";
	};
	$$("[data-portee]", form).forEach((r) => r.addEventListener("change", majPortee));

	/* ---------- Modèles ---------- */
	$$("[data-modele]", form).forEach((b) => b.addEventListener("click", () => {
		const m = donnees.modeles[b.dataset.modele];
		if (!m) return;
		if (!nom.value.trim()) nom.value = m.nom;
		const radio = $(`[data-portee][value="${m.portee}"]`, form);
		if (radio && !radio.disabled) radio.checked = true;
		permissions.forEach((c) => { c.checked = !c.disabled && m.permissions.includes(c.value); });
		$$("[data-modele]", form).forEach((x) => x.classList.toggle("est-choisi", x === b));
		majPortee();
		majApercu();
	}));

	/* ---------- Aperçu en direct ---------- */
	const apercuNom = $("[data-apercu-nom]");
	const apercuPortee = $("[data-apercu-portee] span");
	const apercuOui = $("[data-apercu-oui]");
	const apercuNon = $("[data-apercu-non]");
	const apercuVide = $("[data-apercu-vide]");
	const remplir = (liste, textes, anime) => {
		const avant = new Set($$("li", liste).map((li) => li.textContent));
		liste.replaceChildren(...textes.map((t) => {
			const li = document.createElement("li");
			li.textContent = t;
			if (anime && !avant.has(t) && !reduit.matches) li.classList.add("est-nouveau");
			return li;
		}));
	};
	const majApercu = () => {
		apercuNom.textContent = nom.value.trim() || "Nouveau rôle";
		const portee = $("[data-portee]:checked", form)?.value;
		const etabs = $$("input[name='etablissements[]']:checked", form).map((c) => c.value);
		apercuPortee.textContent = portee === "tous" ? "Tous les établissements"
			: portee === "plusieurs" ? (etabs.length ? etabs.join(", ") : "Plusieurs établissements (à cocher)")
			: "Un établissement, fixé sur chaque compte";
		const oui = permissions.filter((c) => c.checked).map((c) => donnees.permissions[c.value].phrase);
		const non = permissions.filter((c) => !c.checked).map((c) => donnees.permissions[c.value].phrase);
		remplir(apercuOui, oui, true);
		remplir(apercuNon, non, false);
		apercuVide.hidden = oui.length > 0;
	};
	form.addEventListener("input", majApercu);
	form.addEventListener("change", majApercu);

	majPortee();
	majApercu();
	aller(1, 0);
})();
