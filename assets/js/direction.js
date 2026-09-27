/*
 * Espace de gestion (page-direction.php), même comportement que celui de la
 * préinscription : assistant de rôle en fenêtre (nom, portée, permissions)
 * avec l'aperçu de la barre latérale du titulaire, modèles, dépendances entre
 * permissions ; panneau « Changer le rôle » dans la vue Comptes.
 *
 * Amélioration progressive : chaque bouton est d'abord un lien ou un
 * formulaire qui fonctionne sans script (?vue=role ouvre l'assistant,
 * ?compte=ID le changement de rôle). Aucune donnée n'est chargée ici : tout
 * est déjà dans la page, et chaque envoi est revérifié par le serveur
 * (inc/direction.php).
 */
(() => {
	"use strict";
	const $ = (s, r = document) => r.querySelector(s);
	const $$ = (s, r = document) => [...r.querySelectorAll(s)];
	const reduit = window.matchMedia("(prefers-reduced-motion: reduce)");
	const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
	const accord = (n, un, plusieurs) => `${n} ${n > 1 ? plusieurs : un}`;

	/* ---------- Dialogues : ouverture et fermeture communes ---------- */
	$$("[data-ouvrir-dialogue]").forEach((bouton) => {
		const dialogue = document.getElementById(bouton.dataset.ouvrirDialogue);
		if (!dialogue?.showModal) return;
		bouton.addEventListener("click", () => {
			dialogue.showModal();
			$("select, input:not([type=hidden])", dialogue)?.focus();
		});
		dialogue.addEventListener("close", () => bouton.focus());
	});
	$$("[data-fermer-dialogue]").forEach((b) => b.addEventListener("click", () => b.closest("dialog")?.close()));

	/* ---------- Établissement demandé seulement pour la portée « un » ---------- */
	const majEtab = (form) => {
		const role = $("[data-choix-role]", form);
		const etab = $("[data-champ-etab]", form);
		if (!role || !etab) return;
		const un = role.selectedOptions[0]?.dataset.portee === "un";
		etab.hidden = !un;
		$$("select", etab).forEach((s) => { s.disabled = !un; });
	};
	$$("[data-compte-role]").forEach((form) => {
		$("[data-choix-role]", form)?.addEventListener("change", () => majEtab(form));
		form.addEventListener("reset", () => setTimeout(() => majEtab(form)));
		majEtab(form);
	});

	/* ---------- Confirmation du nouveau mot de passe ---------- */
	$$("[data-identique-a]").forEach((champ) => {
		const original = document.getElementById(champ.dataset.identiqueA);
		const message = $("[data-identique-message]", champ.closest("label"));
		const maj = () => {
			if (!message) return;
			message.textContent = champ.value ? (champ.value === original.value ? "Identiques." : "Les deux saisies diffèrent.") : "";
			message.classList.toggle("est-erreur", Boolean(champ.value) && champ.value !== original.value);
		};
		champ.addEventListener("input", maj);
		original?.addEventListener("input", maj);
	});

	/* ---------- Comptes : le panneau de droite passe en « Changer le rôle » ---------- */
	const panneauCreer = $("[data-panneau-creer]");
	const panneauRole = $("[data-panneau-role]");
	if (panneauCreer && panneauRole) {
		const formRole = $("form", panneauRole);
		const montrer = (changer) => {
			panneauCreer.hidden = changer;
			panneauRole.hidden = !changer;
		};
		$$("[data-changer-role]").forEach((lien) => lien.addEventListener("click", (ev) => {
			ev.preventDefault();
			formRole.elements.agent_id.value = lien.dataset.changerRole;
			if ($(`option[value="${CSS.escape(lien.dataset.role)}"]`, formRole.elements.role)) formRole.elements.role.value = lien.dataset.role;
			if (lien.dataset.etab && $(`option[value="${CSS.escape(lien.dataset.etab)}"]`, formRole.elements.etablissement)) formRole.elements.etablissement.value = lien.dataset.etab;
			$("[data-titre-role]", panneauRole).textContent = `Changer le rôle de ${lien.dataset.nom}`;
			majEtab(formRole);
			montrer(true);
			panneauRole.closest("section").scrollIntoView({ behavior: reduit.matches ? "auto" : "smooth", block: "nearest" });
			formRole.elements.role.focus({ preventScroll: true });
		}));
		$("[data-annuler-role]", panneauRole)?.addEventListener("click", (ev) => {
			ev.preventDefault();
			montrer(false);
			$("input:not([type=hidden])", panneauCreer)?.focus();
		});
	}

	/* ---------- Assistant de rôle ---------- */
	const dialogue = $("#dialogue-role");
	const form = $("[data-assistant]");
	const source = $("#donnees-roles");
	if (!dialogue?.showModal || !form || !source) return;
	const donnees = JSON.parse(source.textContent);

	const fieldsets = $$("[data-etape]", form);
	const etapes = $$(".gestion-etapes li", form);
	const erreur = $("[data-erreur-role]", form);
	const precedent = $("[data-etape-precedente]", form);
	const suivant = $("[data-etape-suivante]", form);
	const enregistrer = $("[data-enregistrer]", form);
	const choixEtabs = $("[data-etabs-choix]", form);
	const cases = (nom) => $$(`[name="${nom}"]`, form);
	let etape = 0;

	const message = (texte) => {
		erreur.hidden = !texte;
		$("p", erreur).textContent = texte || "";
	};

	/* Aperçu : la barre latérale que verra le titulaire du rôle. */
	const apercu = () => {
		const portee = form.querySelector("[name=portee]:checked")?.value || "un";
		const etabs = cases("etablissements[]").filter((c) => c.checked).map((c) => c.value);
		const caps = cases("permissions[]").filter((c) => c.checked).map((c) => c.value);
		choixEtabs.hidden = portee !== "plusieurs";
		$("[data-apercu-nom]", dialogue).textContent = form.elements.nom.value.trim() || "Nouveau rôle";

		const vus = new Set();
		const entrees = donnees.ecrans.filter((e) => caps.includes(e[0]) && !vus.has(e[1]) && vus.add(e[1]));
		$("[data-apercu-nav]", dialogue).innerHTML = entrees.length
			? entrees.map((e) => `<span>${e[2]}${esc(e[1])}</span>`).join("")
			: `<span class="est-vide">${donnees.verrou}Aucun écran</span>`;

		const texte = portee === "tous"
			? "Tous les établissements, y compris ceux ajoutés plus tard."
			: portee === "plusieurs"
				? (etabs.join(", ") || "Aucun établissement choisi.")
				: "Un établissement, choisi sur chaque compte.";
		$("[data-apercu-pied]", dialogue).innerHTML =
			`<strong>Portée :</strong> ${esc(texte)}<br><strong>${caps.length}</strong> ${caps.length > 1 ? "permissions cochées" : "permission cochée"}.`;
	};

	const afficherEtape = (focaliser = true) => {
		fieldsets.forEach((f) => { f.hidden = Number(f.dataset.etape) !== etape; });
		etapes.forEach((li, i) => {
			if (i === etape) li.setAttribute("aria-current", "step");
			else li.removeAttribute("aria-current");
			li.toggleAttribute("data-fait", i < etape);
		});
		precedent.hidden = etape === 0;
		suivant.hidden = etape === 2;
		enregistrer.hidden = etape !== 2;
		if (focaliser) $(`[data-etape="${etape}"] input:not([type=hidden]):not(:disabled), [data-etape="${etape}"] select`, form)?.focus();
		apercu();
	};

	/* Contrôle léger avant d'avancer ; le serveur refait toutes les vérifications. */
	const valider = () => {
		message("");
		if (form.elements.nom.value.trim().length < 2) {
			etape = 0;
			afficherEtape();
			message("Donne un nom à ce rôle (2 caractères au moins).");
			return false;
		}
		if (etape >= 1) {
			const portee = form.querySelector("[name=portee]:checked")?.value;
			const total = cases("etablissements[]").filter((c) => c.checked).length;
			if (!portee || (portee === "plusieurs" && total < 2)) {
				etape = 1;
				afficherEtape();
				message(portee ? "Pour plusieurs établissements, coches-en au moins deux." : "Choisis la portée du rôle.");
				return false;
			}
		}
		return true;
	};

	/* Une permission qui en demande une autre la coche ; décocher la seconde décoche la première. */
	cases("permissions[]").forEach((c) => c.addEventListener("change", () => {
		if (c.checked && c.dataset.requiert) {
			const requise = cases("permissions[]").find((x) => x.value === c.dataset.requiert);
			if (requise && !requise.disabled) requise.checked = true;
		}
		if (!c.checked) {
			cases("permissions[]").filter((x) => x.dataset.requiert === c.value).forEach((x) => { x.checked = false; });
		}
	}));

	/* Un modèle ne fait que cocher des cases : tout reste modifiable. */
	$("[data-modele-role]", form)?.addEventListener("change", (ev) => {
		const perms = donnees.modeles[ev.target.value] || [];
		cases("permissions[]").forEach((c) => { if (!c.disabled) c.checked = perms.includes(c.value); });
		apercu();
	});

	const remplir = (def, slug) => {
		form.elements.role.value = slug;
		form.elements.nom.value = def.nom;
		cases("portee").forEach((r) => { r.checked = r.value === def.portee; });
		if (!form.querySelector("[name=portee]:checked")) cases("portee")[0].checked = true;
		cases("etablissements[]").forEach((c) => { c.checked = def.etablissements.includes(c.value); });
		cases("permissions[]").forEach((c) => { c.checked = def.permissions.includes(c.value); });
		const modele = $("[data-modele-role]", form);
		if (modele) modele.value = "";
	};

	/* mode : « nouveau », « modifier » ou « dupliquer » (rien n'est créé avant l'enregistrement). */
	const ouvrir = (slug, mode) => {
		const role = donnees.roles[slug];
		if (mode !== "nouveau" && !role) return;
		remplir(
			mode === "nouveau" ? { nom: "", portee: "un", etablissements: [], permissions: [] } : { ...role, nom: role.nom + (mode === "dupliquer" ? " (copie)" : "") },
			mode === "modifier" ? slug : ""
		);
		$("#dialogue-role-titre").textContent = mode === "modifier" ? "Modifier le rôle" : "Créer un rôle";
		etape = 0;
		message("");
		dialogue.showModal();
		afficherEtape();
	};

	$$("[data-nouveau-role]").forEach((lien) => lien.addEventListener("click", (ev) => {
		ev.preventDefault();
		ouvrir("", "nouveau");
	}));
	$$("[data-modifier-role]").forEach((lien) => lien.addEventListener("click", (ev) => {
		ev.preventDefault();
		ouvrir(lien.dataset.modifierRole, "modifier");
	}));
	$$("form[data-dupliquer-role]").forEach((f) => f.addEventListener("submit", (ev) => {
		ev.preventDefault();
		ouvrir(f.dataset.dupliquerRole, "dupliquer");
	}));

	form.addEventListener("input", apercu);
	form.addEventListener("change", apercu);
	suivant.addEventListener("click", () => { if (valider()) { etape++; afficherEtape(); } });
	precedent.addEventListener("click", () => { etape--; message(""); afficherEtape(); });
	form.addEventListener("submit", (ev) => {
		if (etape < 2) {
			ev.preventDefault();
			suivant.click();
			return;
		}
		if (!valider()) {
			ev.preventDefault();
			return;
		}
		if (!cases("permissions[]").some((c) => c.checked)) {
			ev.preventDefault();
			message("Coche au moins une permission.");
			return;
		}
		enregistrer.setAttribute("aria-busy", "true");
	});

	/* Fermée, l'adresse revient à la liste : un rechargement ne rouvre pas l'assistant. */
	dialogue.addEventListener("close", () => {
		const adresse = new URL(location.href);
		if (adresse.searchParams.get("vue") === "role") {
			adresse.searchParams.delete("vue");
			adresse.searchParams.delete("role");
			history.replaceState(null, "", adresse);
		}
	});

	afficherEtape(false);
	/* ?vue=role : le serveur a rempli et ouvert l'assistant ; on le rend modal
	   et on y reporte l'erreur de saisie éventuelle. */
	if (dialogue.hasAttribute("data-ouvert")) {
		dialogue.close();
		dialogue.showModal();
		const flash = $(".gestion-contenu > .alerte--erreur p");
		if (flash) message(flash.textContent);
		afficherEtape();
	}
})();
