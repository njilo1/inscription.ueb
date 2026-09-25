/*
 * Interactions communes à toutes les pages. Aucune n'est indispensable :
 * sans JavaScript, les formulaires fonctionnent et le serveur valide tout.
 */
(() => {
	"use strict";

	const $ = (sel, racine = document) => racine.querySelector(sel);
	const $$ = (sel, racine = document) => [...racine.querySelectorAll(sel)];

	/* ---------- Confidentialité : défilement continu, message de première visite ---------- */
	const confidentialite = $("[data-confidentialite]");
	if (confidentialite) {
		const mouvement = matchMedia("(prefers-reduced-motion: reduce)");
		const actualiser = () => {
			confidentialite.classList.toggle("est-anime", !mouvement.matches);
		};
		mouvement.addEventListener("change", actualiser);
		actualiser();
		const entete = $("[data-entete]");
		if (entete && "ResizeObserver" in window) {
			new ResizeObserver(() => document.documentElement.style.setProperty("--hauteur-entete", entete.offsetHeight + "px")).observe(entete);
		}
	}
	const bienvenue = $("[data-bienvenue]");
	const copierTexte = async (texte) => {
		if (navigator.clipboard?.writeText) {
			await navigator.clipboard.writeText(texte);
			return;
		}
		const zone = document.createElement("textarea");
		zone.value = texte;
		zone.setAttribute("readonly", "");
		zone.style.position = "fixed";
		zone.style.opacity = "0";
		document.body.append(zone);
		zone.select();
		if (!document.execCommand("copy")) throw new Error("Copie indisponible");
		zone.remove();
	};
	$$('[data-copier-mot-de-passe]').forEach((bouton) => {
		bouton.addEventListener("click", async () => {
			const libelle = $("span", bouton);
			try {
				await copierTexte(bouton.dataset.copierMotDePasse);
				if (libelle) libelle.textContent = "Copié";
				bouton.classList.add("est-copie");
				setTimeout(() => { if (libelle) libelle.textContent = "Copier le mot de passe"; bouton.classList.remove("est-copie"); }, 2200);
			} catch {
				if (libelle) libelle.textContent = "Sélectionne le mot de passe";
			}
		});
	});
	$$("[data-ouvrir-agent-mdp]").forEach((bouton) => {
		const dialog = document.getElementById(bouton.getAttribute("data-ouvrir-agent-mdp"));
		if (!dialog) return;
		bouton.addEventListener("click", (event) => {
			event.preventDefault();
			if (typeof dialog.showModal === "function") dialog.showModal();
			else dialog.setAttribute("open", "");
		});
		$("[data-fermer-agent-mdp]", dialog)?.addEventListener("click", (event) => {
			event.preventDefault();
			if (typeof dialog.close === "function") dialog.close();
			else dialog.removeAttribute("open");
		});
		dialog.addEventListener("click", (event) => {
			if (event.target !== dialog) return;
			if (typeof dialog.close === "function") dialog.close();
			else dialog.removeAttribute("open");
		});
	});
	if (bienvenue && typeof bienvenue.showModal === "function") {
		bienvenue.addEventListener("close", () => {
			const contenu = $("#contenu");
			contenu?.setAttribute("tabindex", "-1");
			contenu?.focus({ preventScroll: true });
		});
		bienvenue.showModal();
	}

	/* Une redirection après enregistrement affiche le dossier et démarre son PDF.
	   Le lien reste disponible si le navigateur bloque le téléchargement ou si le réseau échoue. */
	const telechargement = $("[data-telechargement-auto]");
	if (telechargement) {
		const lien = $("[data-telechargement-lien]", telechargement);
		const message = $("[data-telechargement-message]", telechargement);
		message.setAttribute("role", "status");
		const demarrer = async () => {
			const controle = new AbortController();
			const delai = setTimeout(() => controle.abort(), 30000);
			try {
				message.textContent = "Téléchargement du PDF en cours…";
				const reponse = await fetch(lien.href, { credentials: "same-origin", cache: "no-store", signal: controle.signal });
				if (!reponse.ok || !reponse.headers.get("Content-Type")?.includes("application/pdf")) throw new Error("PDF indisponible");
				const fichier = await reponse.blob();
				const url = URL.createObjectURL(fichier);
				const a = document.createElement("a");
				a.href = url;
				a.download = lien.download || "mes-quitus.pdf";
				document.body.append(a);
				a.click();
				a.remove();
				setTimeout(() => URL.revokeObjectURL(url), 60000);
				message.textContent = "Le PDF a été transmis au navigateur. S’il ne se télécharge pas, utilise le bouton Télécharger le PDF.";
			} catch {
				message.textContent = "Le téléchargement automatique n’a pas abouti. Tes documents sont enregistrés : utilise le bouton Télécharger le PDF pour réessayer.";
			} finally {
				clearTimeout(delai);
			}
		};
		demarrer();
	}

	// Une ancre vers un dossier ouvre aussi l'année archivée qui le contient.
	const ouvrirDossier = () => {
		const cible = document.getElementById(location.hash.slice(1));
		const annee = cible?.closest(".quitus-annee");
		if (annee) annee.open = true;
	};
	ouvrirDossier();
	addEventListener("hashchange", ouvrirDossier);

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
	/* Jauge de solidité : longueur, variété des caractères. */
	$$("[data-force-mdp]").forEach((bloc) => {
		const champ = document.getElementById(bloc.dataset.forceMdp);
		const libelle = $("[data-force-libelle]", bloc);
		if (!champ || !libelle) return;
		const noms = ["à saisir", "trop faible", "moyen", "solide", "très solide"];
		champ.addEventListener("input", () => {
			const v = champ.value;
			let niveau = 0;
			if (v) {
				const familles = [/\p{Ll}/u, /\p{Lu}/u, /\d/, /[^\p{L}\d]/u].filter((r) => r.test(v)).length;
				niveau = v.length < 8 || !/\p{L}/u.test(v) || !/\d/.test(v) ? 1 : 2;
				if (niveau === 2 && (v.length >= 12 || familles >= 3)) niveau = 3;
				if (niveau === 3 && v.length >= 14 && familles >= 3) niveau = 4;
			}
			bloc.dataset.niveau = String(niveau);
			libelle.textContent = noms[niveau];
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

	/* ---------- Formation, tarifs imposés et récapitulatif du paiement ---------- */
	const formQuitus = $("[data-quitus]");
	const donneesEtabs = $("#donnees-etablissements");
	if (formQuitus && donneesEtabs) {
		const etabs = JSON.parse(donneesEtabs.textContent);
		const config = JSON.parse($("#donnees-paiement").textContent);
		const logo = $("[data-recap-logo]");
		const filiere = $("[name=filiere_id]", formQuitus);
		const situation = $("select[name=situation]", formQuitus);
		const medicalSeul = formQuitus.dataset.type === "medicaux";
		const champsCms = $$("[data-cms-champ]", formQuitus);
		champsCms.forEach((champ) => {
			const label = champ.labels[0];
			if (!$(".facultatif", label)) {
				const mention = document.createElement("span");
				mention.className = "facultatif";
				mention.textContent = " (facultatif)";
				label.appendChild(mention);
			}
		});
		let dernierEtab;
		let derniereFormation = filiere.value;
		const montantProfessionnel = new Map();
		let montantClassique = config.formations.find((f) => String(f.id) === filiere.value)?.type_formation === "classique" ? champMontant.value : "";
		if (config.formations.find((f) => String(f.id) === filiere.value)?.type_formation === "pro") {
			montantProfessionnel.set(filiere.value, champMontant.value);
		}
		/* Mêmes règles que ueb_erreur_montant_classique() côté serveur. */
		const messageMontantClassique = (n, regle) => {
			if (!n) return regle.second ? `Saisis le montant de ton second versement (${formater(regle.max)} FCFA au plus).` : "Saisis le montant que tu verses : 25 000 FCFA au moins.";
			if (n % regle.pas) return "Saisis un multiple de 5 000 FCFA : 25 000, 30 000, 35 000…";
			if (n < regle.min) return `Le premier versement est de ${formater(regle.min)} FCFA au moins.`;
			if (n > regle.max) return regle.second
				? `Il te reste ${formater(regle.max)} FCFA à payer cette année : ne dépasse pas ce montant.`
				: "Les droits de l’année sont de 50 000 FCFA au plus (les deux tranches).";
			return "";
		};
		/* Erreur visible dès qu'un montant est saisi ; un champ vide n'est signalé qu'à l'envoi. */
		const signalerMontant = (message) => {
			champMontant.setCustomValidity(message);
			const champ = champMontant.closest(".champ");
			let bulle = $("#champ-montant-erreur", champ);
			const visible = Boolean(message && lireMontant());
			if (!bulle && visible) {
				bulle = document.createElement("p");
				bulle.className = "champ__erreur";
				bulle.id = "champ-montant-erreur";
				champ.appendChild(bulle);
			}
			if (bulle) {
				bulle.hidden = !visible;
				if (visible && bulle.textContent !== message) bulle.textContent = message;
			}
			champ.classList.toggle("champ--invalide", visible);
			champMontant.setAttribute("aria-invalid", String(visible));
			const decrit = new Set((champMontant.getAttribute("aria-describedby") || "").split(" ").filter(Boolean));
			decrit[visible ? "add" : "delete"]("champ-montant-erreur");
			champMontant.setAttribute("aria-describedby", [...decrit].join(" "));
		};
		const texte = (selecteur, valeur) => {
			const cible = $(selecteur);
			if (cible && cible.textContent !== valeur) cible.textContent = valeur;
		};
		const maj = () => {
			const etablissement = $("input[name=etablissement]:checked", formQuitus)?.value || "";
			if (etablissement !== dernierEtab) {
				const selection = filiere.value;
				const liste = config.formations.filter((f) => f.etablissement === etablissement);
				filiere.replaceChildren(new Option(etablissement ? (liste.length ? "Choisir une filière…" : "Aucune filière disponible") : "Choisis d’abord ton établissement", ""));
				liste.forEach((f) => filiere.add(new Option((f.choix ? `Choix ${f.choix} — ` : "") + f.libelle, String(f.id))));
				filiere.value = liste.some((f) => String(f.id) === selection) ? selection : "";
				dernierEtab = etablissement;
			}
			const formation = config.formations.find((f) => String(f.id) === filiere.value);
			const classique = formation?.type_formation === "classique";
			const regle = config.regleDroits;
			if (derniereFormation !== filiere.value) {
				/* Entre deux formations classiques, le montant saisi est conservé. */
				champMontant.value = classique ? (montantClassique || (regle.second ? formater(regle.max) : "")) : (montantProfessionnel.get(filiere.value) || "");
				derniereFormation = filiere.value;
			}
			champMontant.readOnly = !formation || medicalSeul;
			$("[data-tranche-auto]", formQuitus)?.classList.toggle("est-auto", classique && !medicalSeul);
			let erreurMontant = "";
			if (classique && !medicalSeul) {
				/* Formation classique : la tranche découle du montant saisi. */
				const n = lireMontant();
				montantClassique = champMontant.value;
				erreurMontant = messageMontantClassique(n, regle);
				const valeur = regle.second ? "2" : (n >= config.droitsClassiques ? "3" : "1");
				$$("input[name=tranche]", formQuitus).forEach((radio) => { radio.checked = !erreurMontant && radio.value === valeur; });
			} else if (formation) montantProfessionnel.set(filiere.value, champMontant.value);
			signalerMontant(erreurMontant);
			const tranche = $("input[name=tranche]:checked", formQuitus);
			texte("#champ-montant-aide", classique
				? (regle.second
					? `Reste à payer cette année : ${formater(regle.max)} FCFA. Tu peux verser moins, par multiples de 5 000 FCFA.`
					: "De 25 000 à 50 000 FCFA, par multiples de 5 000. Moins de 50 000 : première tranche ; 50 000 : les deux tranches.")
				: formation ? "Pour une formation professionnelle, indique le montant communiqué par ton établissement." : "Choisis une filière pour connaître les modalités de paiement.");
			const frais = situation.value === "nouveau" ? 0 : (config.medicalInclus ? (config.montantsMedicaux[situation.value] || 0) : 0);
			const cmsRequis = medicalSeul || (config.medicalInclus && situation.value !== "nouveau");
			champsCms.forEach((champ) => {
				champ.required = cmsRequis;
				$(".facultatif", champ.labels[0]).hidden = cmsRequis;
			});
			texte("[data-cms-aide]", cmsRequis
				? "Pour tes fiches CMS, complète ton email, ton adresse et les trois coordonnées de ton contact d’urgence ci-dessous."
				: "Ces coordonnées sont facultatives pour ce paiement : aucune fiche CMS n’est à générer.");
			const droits = medicalSeul ? 0 : lireMontant();
			const pret = medicalSeul || Boolean(formation && tranche && droits && !erreurMontant);
			const total = droits + frais;
			texte("[data-montant-fixe]", formater(frais) + " FCFA");
			texte("[data-montant-lettres]", droits ? enLettres(droits) : "");
			texte("[data-total-paiement]", pret ? formater(total) + " FCFA" : "—");
			texte("[data-detail-paiement]", pret
				? (medicalSeul ? "" : formater(droits) + " FCFA de droits universitaires + ") + formater(frais) + " FCFA de frais médicaux."
				: "Choisis ta formation et saisis un montant valable pour afficher le total.");
			texte("[data-note-medicale]", situation.value === "nouveau"
				? "Aucun frais médical supplémentaire : cette situation est considérée comme une nouvelle inscription."
				: config.medicalInclus ? "Les frais médicaux sont payables en une seule fois pour l’année en cours, sur le compte des services centraux."
				: `Les frais médicaux figurent déjà sur ton quitus ${config.medicalExistant.numero}${config.medicalExistant.statut === "verifie" ? " (paiement vérifié)" : " (paiement à régler ou à faire vérifier)"} : ils ne sont pas ajoutés à cette tranche.`);
			const e = etabs[etablissement];
			texte("[data-recap-etab]", e ? e.fr : "À choisir");
			logo.hidden = !e;
			if (e) logo.src = e.logo;
			texte("[data-recap-montant]", pret ? formater(medicalSeul ? frais : droits) + " FCFA" : "—");
			texte("[data-recap-medicaux]", formater(frais) + " FCFA");
			texte("[data-recap-total]", pret ? formater(total) + " FCFA" : "—");
			texte("[data-recap-formation]", formation?.libelle || "À choisir");
			const niveau = $("[name=parcours]", formQuitus);
			texte("[data-recap-niveau]", niveau?.value ? niveau.options[niveau.selectedIndex].text : "À choisir");
			texte("[data-recap-tranche]", medicalSeul ? "Paiement unique" : tranche ? tranche.closest("label").textContent.trim() : "—");
			const medicalDocuments = $$("[data-document-medical]");
			medicalDocuments.forEach((element) => { element.hidden = frais === 0; });
			const pages = frais === 0 ? 1 : 4;
			texte("[data-document-pages]", String(pages));
			texte("[data-document-pages-suffix]", pages > 1 ? "s" : "");
		};
		formQuitus.addEventListener("input", maj);
		formQuitus.addEventListener("change", maj);
		const telephoneUrgence = $("[name=numero_urgence]", formQuitus);
		telephoneUrgence.addEventListener("input", () => telephoneUrgence.setCustomValidity(""));
		formQuitus.addEventListener("submit", (ev) => {
			if (ev.submitter?.name === "actualiser_paiement") return;
			const numero = telephoneUrgence.value.replace(/\D+/g, "");
			telephoneUrgence.setCustomValidity(numero && !/^(237)?6\d{8}$/.test(numero)
				? "Saisis un numéro camerounais à 9 chiffres, avec ou sans +237." : "");
			if (!formQuitus.reportValidity()) ev.preventDefault();
		});
		maj();
	}

	/* ---------- Reçus : sélection, compression des photos, caméra ----------
	   Les fichiers choisis, glissés ou photographiés s'ajoutent à une même
	   sélection (retirables un à un). Les photos sont réduites à 2 000 px et
	   réencodées en JPEG avant l'envoi : une photo de téléphone de 4 Mo pèse
	   alors quelques centaines de Ko. */
	$$("[data-envoi-recus]").forEach((form) => {
		const champ = $("input[type=file][data-max]", form);
		const capture = $("[data-capture]", form);
		const depot = $("[data-depot]", form);
		const liste = $("[data-apercus]", form);
		const erreur = $("[data-depot-erreur]", form);
		const envoyer = $("[data-depot-envoyer]", form);
		const titre = $("[data-depot-titre]", form);
		const titreInitial = titre?.textContent || "";
		const libelle = $("[data-depot-libelle]", form);
		const max = parseInt(champ.dataset.max, 10);
		const maxOctets = parseInt(champ.dataset.maxOctets, 10);
		const types = ["image/jpeg", "image/png", "application/pdf"];
		const COTE_MAX = 2000;
		const QUALITE = 0.82;
		const taille = (o) => (o >= 1048576 ? (o / 1048576).toFixed(1).replace(".", ",") + " Mo" : Math.max(1, Math.round(o / 1024)) + " Ko");
		const peutRegrouper = typeof DataTransfer === "function";
		let selection = [];
		let occupe = false;

		/* Sans DataTransfer (très vieux navigateurs), on garde le comportement natif. */
		if (peutRegrouper && capture) capture.removeAttribute("name");

		const compresser = async (fichier) => {
			if (!["image/jpeg", "image/png"].includes(fichier.type) || typeof createImageBitmap !== "function") return fichier;
			try {
				const image = await createImageBitmap(fichier, { imageOrientation: "from-image" });
				const ratio = Math.min(1, COTE_MAX / Math.max(image.width, image.height));
				const toile = document.createElement("canvas");
				toile.width = Math.round(image.width * ratio);
				toile.height = Math.round(image.height * ratio);
				const ctx = toile.getContext("2d");
				ctx.fillStyle = "#fff";
				ctx.fillRect(0, 0, toile.width, toile.height);
				ctx.drawImage(image, 0, 0, toile.width, toile.height);
				image.close?.();
				const blob = await new Promise((ok) => toile.toBlob(ok, "image/jpeg", QUALITE));
				if (!blob || (fichier.type === "image/jpeg" && blob.size >= fichier.size)) return fichier;
				return new File([blob], fichier.name.replace(/\.[^.]+$/, "") + ".jpg", { type: "image/jpeg", lastModified: Date.now() });
			} catch {
				return fichier;
			}
		};

		const synchroniser = () => {
			if (peutRegrouper) {
				const transfert = new DataTransfer();
				selection.forEach((s) => transfert.items.add(s.fichier));
				champ.files = transfert.files;
			}
			liste.innerHTML = "";
			const problemes = [];
			if (selection.length > max) problemes.push(`${max} fichier${max > 1 ? "s" : ""} au plus pour ce paiement : retire-en ${selection.length - max}.`);
			selection.forEach((s, i) => {
				const f = s.fichier;
				let souci = "";
				if (!types.includes(f.type)) souci = "format non accepté";
				else if (f.size > maxOctets) souci = "plus de 5 Mo";
				if (souci) problemes.push(`${f.name} : ${souci}.`);
				const li = document.createElement("li");
				li.style.setProperty("--i", i);
				li.classList.toggle("est-invalide", Boolean(souci));
				const vignette = document.createElement("span");
				vignette.className = "depot__vignette";
				if (f.type.startsWith("image/")) {
					const img = document.createElement("img");
					img.alt = "";
					img.src = URL.createObjectURL(f);
					img.onload = () => URL.revokeObjectURL(img.src);
					vignette.appendChild(img);
				} else {
					vignette.textContent = "PDF";
					vignette.classList.add("depot__vignette--pdf");
				}
				const nom = document.createElement("span");
				nom.className = "depot__nom";
				nom.textContent = f.name;
				const infos = document.createElement("small");
				infos.textContent = souci ? souci.charAt(0).toUpperCase() + souci.slice(1)
					: s.origine > f.size * 1.1 ? `${taille(s.origine)} → ${taille(f.size)}` : taille(f.size);
				if (!souci && s.origine > f.size * 1.1) infos.classList.add("depot__gain");
				const retirer = document.createElement("button");
				retirer.type = "button";
				retirer.className = "depot__retirer";
				retirer.setAttribute("aria-label", "Retirer " + f.name);
				retirer.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
				retirer.addEventListener("click", () => {
					selection.splice(i, 1);
					synchroniser();
					champ.focus();
				});
				li.append(vignette, nom, infos, retirer);
				liste.appendChild(li);
			});
			erreur.hidden = problemes.length === 0;
			erreur.textContent = problemes.join(" ");
			envoyer.disabled = occupe || selection.length === 0 || problemes.length > 0;
			depot.classList.toggle("est-rempli", selection.length > 0);
			if (titre) titre.textContent = occupe ? "Compression des photos…" : selection.length ? `${selection.length} fichier${selection.length > 1 ? "s" : ""} prêt${selection.length > 1 ? "s" : ""} à l’envoi` : titreInitial;
			if (libelle) libelle.textContent = selection.length > 1 ? `Envoyer ${selection.length} reçus` : "Envoyer mon reçu";
		};

		const ajouter = async (fichiers) => {
			if (!fichiers.length) return;
			if (!peutRegrouper) return;
			occupe = true;
			synchroniser();
			for (const f of fichiers) selection.push({ fichier: await compresser(f), origine: f.size });
			occupe = false;
			synchroniser();
		};
		/* À la sélection, champ.files ne contient que les nouveaux fichiers : on les ajoute puis on réécrit la liste complète. */
		champ.addEventListener("change", () => (peutRegrouper ? ajouter([...champ.files]) : null));
		capture?.addEventListener("change", () => {
			ajouter([...capture.files]);
			capture.value = "";
		});
		["dragenter", "dragover"].forEach((t) => depot.addEventListener(t, () => depot.classList.add("est-survole")));
		["dragleave", "drop"].forEach((t) => depot.addEventListener(t, () => depot.classList.remove("est-survole")));

		/* Caméra : aperçu en direct si le navigateur y donne accès (HTTPS ou
		   localhost), sinon l'appareil photo du téléphone via le champ natif. */
		const dialogue = $("[data-camera]");
		const ouvrir = $("[data-camera-ouvrir]", form);
		const natif = $("[data-camera-natif]", form);
		const direct = Boolean(dialogue?.showModal && navigator.mediaDevices?.getUserMedia && window.isSecureContext);
		const tactile = window.matchMedia("(pointer: coarse)").matches;
		if (ouvrir) ouvrir.hidden = !direct;
		if (natif) natif.hidden = direct || !tactile;
		const blocCamera = ouvrir?.parentElement;
		if (blocCamera) blocCamera.hidden = ouvrir.hidden && natif.hidden;
		if (!direct) return;

		const video = $("[data-camera-video]", dialogue);
		const cliche = $("[data-camera-cliche]", dialogue);
		const message = $("[data-camera-message]", dialogue);
		const declencher = $("[data-camera-declencher]", dialogue);
		const reprendre = $("[data-camera-reprendre]", dialogue);
		const utiliser = $("[data-camera-utiliser]", dialogue);
		let flux = null;

		const arreter = () => {
			flux?.getTracks().forEach((piste) => piste.stop());
			flux = null;
			video.srcObject = null;
		};
		const mode = (etat) => {
			dialogue.dataset.etat = etat;
			const photo = etat === "cliche";
			cliche.hidden = !photo;
			video.hidden = photo;
			declencher.hidden = photo;
			declencher.disabled = etat !== "vue";
			reprendre.hidden = !photo;
			utiliser.hidden = !photo;
		};
		const demarrer = async () => {
			mode("attente");
			message.textContent = "Ouverture de la caméra…";
			try {
				flux = await navigator.mediaDevices.getUserMedia({
					video: { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 } },
					audio: false,
				});
				if (!dialogue.open) return arreter();
				video.srcObject = flux;
				await video.play();
				message.textContent = "";
				mode("vue");
				declencher.focus();
			} catch (e) {
				mode("erreur");
				message.textContent = e?.name === "NotAllowedError"
					? "Accès à la caméra refusé. Autorise-le dans les réglages du navigateur, ou choisis plutôt un fichier."
					: "Aucune caméra disponible sur cet appareil. Choisis plutôt un fichier.";
			}
		};
		ouvrir.addEventListener("click", () => {
			dialogue.showModal();
			demarrer();
		});
		declencher.addEventListener("click", () => {
			cliche.width = video.videoWidth;
			cliche.height = video.videoHeight;
			cliche.getContext("2d").drawImage(video, 0, 0);
			mode("cliche");
			utiliser.focus();
		});
		reprendre.addEventListener("click", () => {
			mode("vue");
			declencher.focus();
		});
		utiliser.addEventListener("click", () => {
			cliche.toBlob((blob) => {
				if (!blob) return;
				const heure = new Date().toTimeString().slice(0, 5).replace(":", "h");
				ajouter([new File([blob], `photo-recu-${heure}.jpg`, { type: "image/jpeg", lastModified: Date.now() })]);
				dialogue.close();
			}, "image/jpeg", 0.92);
		});
		$("[data-camera-fermer]", dialogue).addEventListener("click", () => dialogue.close());
		dialogue.addEventListener("close", () => {
			arreter();
			ouvrir.focus();
		});
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
		form.addEventListener("submit", (ev) => {
			if (ev.defaultPrevented) return;
			const bouton = $("button[type=submit]", form);
			if (bouton) setTimeout(() => bouton.setAttribute("aria-busy", "true"), 0);
		});
	});
	const resumeErreurs = $("[data-resume-erreurs]");
	if (resumeErreurs) resumeErreurs.focus();
	else $(".champ--invalide input, .champ--invalide select")?.focus({ preventScroll: false });
	$$("[data-lien-erreur]").forEach((lien) => lien.addEventListener("click", (ev) => {
		const cible = document.getElementById(lien.hash.slice(1));
		if (!cible) return;
		ev.preventDefault();
		const groupe = cible.closest("details");
		if (groupe) groupe.open = true;
		(cible.matches("input, select, textarea") ? cible : $("input:not(:disabled), select:not(:disabled)", cible) || cible).focus();
	}));
})();
