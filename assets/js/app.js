/*
 * Interactions communes à toutes les pages. Aucune n'est indispensable :
 * sans JavaScript, les formulaires fonctionnent et le serveur valide tout.
 */
(() => {
	"use strict";

	const $ = (sel, racine = document) => racine.querySelector(sel);
	const $$ = (sel, racine = document) => [...racine.querySelectorAll(sel)];

	/* ---------- Menu mobile ---------- */
	const boutonMenu = $("[data-menu-mobile]");
	if (boutonMenu) {
		const nav = document.getElementById(boutonMenu.getAttribute("aria-controls"));
		boutonMenu.addEventListener("click", () => {
			const ouvert = boutonMenu.getAttribute("aria-expanded") !== "true";
			boutonMenu.setAttribute("aria-expanded", String(ouvert));
			nav?.classList.toggle("est-ouvert", ouvert);
		});
	}

	/* ---------- Afficher / masquer le mot de passe ---------- */
	$$("[data-voir-mdp]").forEach((bouton) => {
		const champ = bouton.previousElementSibling;
		const oeil = bouton.innerHTML;
		const barre = oeil.replace(/<path[\s\S]*<\/svg>/, '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24M10.73 5.08A10.4 10.4 0 0 1 12 5c7 0 10 7 10 7a13.2 13.2 0 0 1-1.67 2.68M6.61 6.61A13.5 13.5 0 0 0 2 12s3 7 10 7a9.7 9.7 0 0 0 5.39-1.61M2 2l20 20"/></svg>');
		bouton.addEventListener("click", () => {
			const visible = champ.type === "password";
			champ.type = visible ? "text" : "password";
			bouton.innerHTML = visible ? barre : oeil;
			bouton.setAttribute("aria-pressed", String(visible));
			bouton.setAttribute("aria-label", visible ? "Masquer le mot de passe" : "Afficher le mot de passe");
		});
	});

	/* ---------- Type d'identifiant reconnu ---------- */
	const DOSSIER = /^(DEMO-)?UEB-\d{4}-\d{6}$/;
	const MATRICULE = /^\d{2}[A-Z0-9]{4,13}$/;
	$$("[data-identifiant]").forEach((champ) => {
		const apercu = document.createElement("p");
		apercu.className = "apercu";
		apercu.setAttribute("aria-live", "polite");
		(champ.closest(".champ__boite") ?? champ).insertAdjacentElement("afterend", apercu);
		const maj = () => {
			const v = champ.value.replace(/\s+/g, "").toUpperCase();
			apercu.textContent = DOSSIER.test(v) ? "✓ Dossier" : MATRICULE.test(v) ? "✓ Matricule" : "";
		};
		champ.addEventListener("input", maj);
		maj();
	});

	/* ---------- Téléphone mobile camerounais : 9 chiffres commençant par 6, +237 facultatif.
	   Même règle que UEB_REGEX_TELEPHONE (inc/config.php) ; le serveur revalide. Affiché : 699 73 07 81 ---------- */
	$$("[data-telephone]").forEach((champ) => {
		const bloc = champ.closest(".champ");
		const apercu = document.createElement("p");
		apercu.className = "apercu";
		apercu.setAttribute("aria-live", "polite");
		(champ.closest(".champ__boite") ?? champ).insertAdjacentElement("afterend", apercu);
		const chiffres = () => {
			const c = champ.value.replace(/\D+/g, "");
			return c.length === 12 && c.startsWith("237") ? c.slice(3) : c;
		};
		const probleme = (c) => (c.length !== 9 ? "9 chiffres attendus" : /^6/.test(c) ? "" : "Doit commencer par 6");
		const afficher = (erreur) => {
			const c = chiffres();
			apercu.textContent = erreur || (c.length === 9 && !probleme(c) ? "✓ Numéro valide" : "");
			apercu.classList.toggle("apercu--erreur", Boolean(erreur));
		};
		champ.addEventListener("input", () => {
			/* Seuls chiffres, espaces et « + » ; pas plus de 9 chiffres (12 avec l'indicatif 237) */
			const nettoye = champ.value.replace(/[^\d\s+]/g, "");
			const max = nettoye.replace(/\D/g, "").startsWith("237") ? 12 : 9;
			let n = 0;
			champ.value = nettoye.replace(/\d/g, (d) => (++n > max ? "" : d));
			afficher("");
		});
		champ.addEventListener("blur", () => {
			const c = chiffres();
			const erreur = c ? probleme(c) : "";
			if (c && !erreur) champ.value = c.replace(/^(\d{3})(\d{2})(\d{2})(\d{2})$/, "$1 $2 $3 $4");
			bloc?.classList.toggle("champ--invalide", Boolean(erreur));
			if (erreur) champ.setAttribute("aria-invalid", "true");
			else champ.removeAttribute("aria-invalid");
			afficher(erreur);
		});
	});

	/* ---------- Règles du mot de passe, confirmation ---------- */
	$$("[data-regles-mdp]").forEach((champ) => {
		const liste = document.getElementById(champ.dataset.reglesMdp);
		if (!liste) return;
		const regles = { longueur: (v) => v.length >= 8, lettre: (v) => /\p{L}/u.test(v), chiffre: (v) => /\d/.test(v) };
		champ.addEventListener("input", () => {
			$$("[data-regle]", liste).forEach((li) => li.classList.toggle("est-ok", regles[li.dataset.regle](champ.value)));
		});
	});
	$$("[data-confirme]").forEach((champ) => {
		const original = document.getElementById(champ.dataset.confirme);
		const bloc = champ.closest(".champ");
		const message = document.createElement("p");
		message.className = "apercu";
		message.setAttribute("aria-live", "polite");
		bloc.appendChild(message);
		const maj = () => {
			if (!champ.value) { message.textContent = ""; return; }
			const ok = champ.value === original.value;
			message.textContent = ok ? "✓ Identiques" : "Différents";
			message.classList.toggle("apercu--erreur", !ok);
		};
		champ.addEventListener("input", maj);
		original?.addEventListener("input", maj);
	});

	/* ---------- Montant en lettres (mêmes règles que le PDF) ---------- */
	const U = ["zéro", "un", "deux", "trois", "quatre", "cinq", "six", "sept", "huit", "neuf", "dix", "onze", "douze", "treize", "quatorze", "quinze", "seize", "dix-sept", "dix-huit", "dix-neuf"];
	const D = { 2: "vingt", 3: "trente", 4: "quarante", 5: "cinquante", 6: "soixante" };
	const moinsDeCent = (n) => {
		if (n < 20) return U[n];
		const d = Math.floor(n / 10), u = n % 10;
		if (d <= 6) return u === 0 ? D[d] : D[d] + (u === 1 ? " et un" : "-" + U[u]);
		if (d === 7) return "soixante" + (u === 1 ? " et onze" : "-" + U[10 + u]);
		if (d === 8) return u === 0 ? "quatre-vingts" : "quatre-vingt-" + U[u];
		return "quatre-vingt-" + U[10 + u];
	};
	const moinsDeMille = (n, final) => {
		const c = Math.floor(n / 100), r = n % 100;
		let m = "";
		if (c > 0) m = (c > 1 ? moinsDeCent(c) + " " : "") + "cent" + (c > 1 && r === 0 && final ? "s" : "");
		if (r > 0) {
			let dz = moinsDeCent(r);
			if (!final) dz = dz.replace(/quatre-vingts$/, "quatre-vingt");
			m += (m ? " " : "") + dz;
		}
		return m;
	};
	const enLettres = (n) => {
		if (!n) return "";
		const mots = [];
		for (const [v, s, p] of [[1e9, "milliard", "milliards"], [1e6, "million", "millions"]]) {
			if (n >= v) { const q = Math.floor(n / v); mots.push(moinsDeMille(q, true) + " " + (q > 1 ? p : s)); n %= v; }
		}
		if (n >= 1000) { const q = Math.floor(n / 1000); mots.push((q === 1 ? "" : moinsDeMille(q, false) + " ") + "mille"); n %= 1000; }
		if (n > 0) mots.push(moinsDeMille(n, true));
		const t = mots.join(" ").trim();
		const texte = t + (/(million|milliard)s?$/.test(t) ? " de francs CFA" : " francs CFA");
		return texte.charAt(0).toUpperCase() + texte.slice(1);
	};
	const formater = (n) => n.toLocaleString("fr-FR").replace(/ | /g, " ");

	const champMontant = $("[data-montant]");
	const lettres = $("[data-montant-lettres]");
	const lireMontant = () => parseInt((champMontant?.value || "").replace(/\D+/g, ""), 10) || 0;
	if (champMontant) {
		const maj = () => {
			const n = lireMontant();
			const pos = champMontant.value.length - champMontant.selectionStart;
			champMontant.value = n ? formater(n) : "";
			champMontant.setSelectionRange(champMontant.value.length - pos, champMontant.value.length - pos);
			if (lettres) lettres.textContent = n ? enLettres(n) : "";
		};
		champMontant.addEventListener("input", maj);
		if (lettres && lireMontant()) lettres.textContent = enLettres(lireMontant());
	}

	/* ---------- Récapitulatif au bas du formulaire du quitus ---------- */
	const formQuitus = $("[data-quitus]");
	const donneesEtabs = $("#donnees-etablissements");
	if (formQuitus && donneesEtabs) {
		const etabs = JSON.parse(donneesEtabs.textContent);
		const logo = $("[data-recap-logo]");
		const champType = $("[data-type-quitus]", formQuitus);
		const champSituation = $("[name=situation]", formQuitus);
		const montantFixe = $("[data-montant-fixe]");
		let fraisMedicaux = {};
		try {
			fraisMedicaux = JSON.parse($("#frais-medicaux")?.textContent || "{}");
		} catch {
			/* pas de frais médicaux déclarés */
		}
		const maj = () => {
			/* Le type pilote l'affichage : tranches pour les droits, situation et montant fixe pour le médical. */
			const type = champType ? champType.value : "droits";
			const medical = type === "medicaux";
			formQuitus.dataset.type = type;

			const choix = $("input[name=etablissement]:checked", formQuitus);
			const e = choix ? etabs[choix.value] : null;
			$("[data-recap-etab]").textContent = e ? e.fr : "À choisir";
			logo.hidden = !e;
			if (e) logo.src = e.logo;

			const frais = medical ? fraisMedicaux[champSituation?.value] : 0;
			if (montantFixe) montantFixe.textContent = frais ? formater(frais) + " FCFA" : "—";
			const n = medical ? frais : lireMontant();
			$("[data-recap-montant]").textContent = n ? formater(n) + " FCFA" : "—";

			const tranche = $("input[name=tranche]:checked", formQuitus);
			$("[data-recap-tranche]").textContent = medical ? "Paiement unique" : tranche ? tranche.closest("label").textContent.trim() : "—";
			const recapType = $("[data-recap-type]");
			if (recapType && champType) recapType.textContent = champType.options[champType.selectedIndex].text;
		};
		formQuitus.addEventListener("input", maj);
		formQuitus.addEventListener("change", maj);
		maj();
	}

	/* ---------- Reçus : vignettes et contrôles avant envoi ---------- */
	$$("[data-envoi-recus]").forEach((form) => {
		const champ = $("input[type=file]", form);
		const depot = $("[data-depot]", form);
		const liste = $("[data-apercus]", form);
		const erreur = $("[data-depot-erreur]", form);
		const envoyer = $("[data-depot-envoyer]", form);
		const max = parseInt(champ.dataset.max, 10);
		const maxOctets = parseInt(champ.dataset.maxOctets, 10);
		const types = ["image/jpeg", "image/png", "application/pdf"];

		const verifier = () => {
			liste.innerHTML = "";
			const fichiers = [...champ.files];
			const problemes = [];
			if (fichiers.length > max) problemes.push(`${max} fichier(s) au maximum.`);
			fichiers.forEach((f) => {
				const li = document.createElement("li");
				if (!types.includes(f.type)) problemes.push(`${f.name} : format non accepté.`);
				else if (f.size > maxOctets) problemes.push(`${f.name} : plus de 5 Mo.`);
				if (f.type.startsWith("image/")) {
					const img = document.createElement("img");
					img.alt = f.name;
					img.src = URL.createObjectURL(f);
					img.onload = () => URL.revokeObjectURL(img.src);
					li.appendChild(img);
				} else {
					li.textContent = "PDF — " + f.name;
				}
				liste.appendChild(li);
			});
			erreur.hidden = problemes.length === 0;
			erreur.textContent = problemes.join(" ");
			envoyer.disabled = fichiers.length === 0 || problemes.length > 0;
		};
		champ.addEventListener("change", verifier);
		["dragenter", "dragover"].forEach((t) => depot.addEventListener(t, () => depot.classList.add("est-survole")));
		["dragleave", "drop"].forEach((t) => depot.addEventListener(t, () => depot.classList.remove("est-survole")));
	});

	/* ---------- Confirmation avant une action sensible ---------- */
	const fenetre = $("#fenetre-confirmation");
	$$("form[data-confirmer]").forEach((form) => {
		form.addEventListener("submit", (ev) => {
			if (form.dataset.confirme === "1" || !fenetre?.showModal) return;
			ev.preventDefault();
			$("[data-fenetre-texte]", fenetre).textContent = form.dataset.confirmer;
			fenetre.returnValue = "";
			fenetre.showModal();
			fenetre.addEventListener("close", () => {
				if (fenetre.returnValue === "oui") {
					form.dataset.confirme = "1";
					form.requestSubmit();
				}
			}, { once: true });
		});
	});

	/* ---------- Envoi : bouton occupé, résumé d'erreurs mis au point ---------- */
	$$("form[data-formulaire]").forEach((form) => {
		form.addEventListener("submit", () => {
			const bouton = $("button[type=submit]", form);
			if (bouton) setTimeout(() => bouton.setAttribute("aria-busy", "true"), 0);
		});
	});
	$("[data-resume-erreurs]")?.focus();
	$(".champ--invalide input, .champ--invalide select")?.focus({ preventScroll: false });
})();
