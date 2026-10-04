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

	/* ---------- Filtres en direct ----------
	   <form data-filtres-direct="id-du-bloc"> : chaque saisie ou choix recharge la
	   même page en arrière-plan et n'en remplace que le bloc des résultats ;
	   l'adresse suit les filtres. Sans JavaScript, le bouton Rechercher reste.
	   Dans le bloc, un lien a[data-filtre="nom"][data-valeur] change ce champ du
	   formulaire (onglet, filtre actif retiré, page) et a[data-filtre-effacer="a b"]
	   vide ces champs ; un champ caché « p » (page) revient à 1 à chaque filtre. */
	$$("[data-filtres-direct]").forEach((form) => {
		const bloc = document.getElementById(form.dataset.filtresDirect);
		if (!bloc || !window.fetch || !window.DOMParser) return;
		$("[data-filtres-bouton]", form)?.setAttribute("hidden", "");
		let controle = null;
		let attente = null;
		const actualiser = async () => {
			const params = new URLSearchParams(new FormData(form));
			[...params.keys()].forEach((cle) => { if (params.get(cle) === "" && cle !== "vue") params.delete(cle); });
			const url = new URL(form.action, location.href);
			url.search = params.toString();
			controle?.abort();
			controle = new AbortController();
			bloc.setAttribute("aria-busy", "true");
			try {
				const reponse = await fetch(url, { credentials: "same-origin", cache: "no-store", signal: controle.signal });
				if (!reponse.ok) throw new Error("Réponse " + reponse.status);
				const page = new DOMParser().parseFromString(await reponse.text(), "text/html");
				const nouveau = page.getElementById(bloc.id);
				if (!nouveau) throw new Error("Bloc absent");
				bloc.innerHTML = nouveau.innerHTML;
				history.replaceState(null, "", url);
			} catch (erreur) {
				if (erreur.name !== "AbortError") form.submit(); // repli : rechargement classique
			} finally {
				bloc.removeAttribute("aria-busy");
			}
		};
		form.addEventListener("input", (e) => {
			if (form.elements.p && e.target.name !== "p") form.elements.p.value = "";
			clearTimeout(attente);
			attente = setTimeout(actualiser, e.target.type === "search" ? 300 : 0);
		});
		bloc.addEventListener("click", (e) => {
			const lien = e.target.closest("a[data-filtre], a[data-filtre-effacer]");
			if (!lien) return;
			const noms = lien.dataset.filtreEffacer ? lien.dataset.filtreEffacer.split(" ") : [lien.dataset.filtre];
			const champs = noms.map((nom) => form.elements[nom]).filter(Boolean);
			if (!champs.length) return; // lien ordinaire : navigation classique
			e.preventDefault();
			champs.forEach((champ) => { champ.value = lien.dataset.filtreEffacer ? "" : (lien.dataset.valeur ?? ""); });
			if (form.elements.p && lien.dataset.filtre !== "p") form.elements.p.value = "";
			form.dispatchEvent(new Event("change"));
			clearTimeout(attente);
			actualiser().then(() => { if (lien.dataset.filtre === "p") bloc.scrollIntoView({ block: "start", behavior: "smooth" }); });
		});
		form.addEventListener("submit", (e) => {
			e.preventDefault();
			clearTimeout(attente);
			actualiser();
		});
	});

	/* ---------- Étudiants UEB : la liste des filières suit l'établissement choisi ---------- */
	$$("form[data-etudiants-filtres]").forEach((form) => {
		const etab = $("[data-etu-etab]", form);
		const filiere = $("[data-etu-filiere]", form);
		if (!etab || !filiere) return;
		const accorder = () => {
			$$("optgroup", filiere).forEach((groupe) => {
				const garde = !etab.value || groupe.dataset.etab === etab.value;
				groupe.hidden = !garde;
				groupe.disabled = !garde;
			});
			if (filiere.selectedOptions[0]?.parentElement.disabled) filiere.value = "";
		};
		form.addEventListener("input", (e) => { if (e.target === etab) accorder(); }, true);
		form.addEventListener("change", accorder);
		accorder();
	});

	/* ---------- Total des cases cochées (bordereau d'un IPES) ----------
	   <form data-total-coches> : cases input[data-montant], [data-total-affiche],
	   [data-total-nombre], case [data-tout-cocher] facultative ; data-total-unite
	   nomme ce qu’on compte (« étudiant »). Indicatif : le
	   serveur recalcule et fige le total à l'envoi. */
	$$("[data-total-coches]").forEach((form) => {
		const cases = $$("input[type=checkbox][data-montant]", form);
		const affiche = $("[data-total-affiche]", form);
		const nombre = $("[data-total-nombre]", form);
		const tout = $("[data-tout-cocher]", form);
		const unite = form.dataset.totalUnite || "élément";
		const format = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " FCFA";
		const maj = () => {
			const cochees = cases.filter((c) => c.checked);
			if (affiche) affiche.textContent = format(cochees.reduce((s, c) => s + Number(c.dataset.montant || 0), 0));
			if (nombre) nombre.textContent = cochees.length + " " + unite + (cochees.length > 1 ? "s" : "");
			if (tout) {
				tout.checked = cases.length > 0 && cochees.length === cases.length;
				tout.indeterminate = cochees.length > 0 && cochees.length < cases.length;
			}
		};
		const modifie = () => { form.dataset.modifie = "1"; };
		cases.forEach((c) => c.addEventListener("change", () => { modifie(); maj(); }));
		tout?.addEventListener("change", () => { cases.forEach((c) => { c.checked = tout.checked; }); modifie(); maj(); });
		maj();
	});

	/* Étudiant d'un IPES à plusieurs tutelles : la faculté choisie (select
	   « tutelle ») ne laisse dans la liste des filières que les siennes.
	   <script data-filieres-tutelle> donne filière => faculté. */
	$$("script[data-filieres-tutelle]").forEach((donnees) => {
		const form = donnees.closest("form");
		const tutelle = form && $("select[name=tutelle]", form);
		const filiere = form && $("select[name=filiere_id]", form);
		if (!tutelle || !filiere) return;
		const carte = JSON.parse(donnees.textContent || "{}");
		const vide = filiere.options[0];
		const toutes = [...filiere.options].filter((o) => o.value !== "");
		const filtrer = () => {
			const t = tutelle.value;
			const choisie = filiere.value;
			toutes.forEach((o) => o.remove());
			const gardees = toutes.filter((o) => carte[o.value] === t);
			gardees.forEach((o) => filiere.appendChild(o));
			filiere.value = gardees.some((o) => o.value === choisie) ? choisie : "";
			filiere.disabled = !t;
			vide.textContent = !t ? "Choisis d’abord la faculté" : gardees.length ? "Choisir…" : "Aucune filière pour cette faculté";
		};
		tutelle.addEventListener("change", filtrer);
		filtrer();
	});

	/* Formulaires [data-garder-selection="#id"] (reçus d'un bordereau) : si la
	   sélection du formulaire visé a changé sans être enregistrée, ses cases
	   cochées partent avec l'envoi et le serveur les enregistre d'abord. */
	$$("form[data-garder-selection]").forEach((form) => {
		form.addEventListener("submit", (ev) => {
			if (ev.defaultPrevented) return;
			const cible = $(form.dataset.garderSelection);
			$$("[data-selection-emportee]", form).forEach((champ) => champ.remove());
			if (!cible || cible.dataset.modifie !== "1") return;
			const cachee = (nom, valeur) => {
				const champ = document.createElement("input");
				champ.type = "hidden";
				champ.name = nom;
				champ.value = valeur;
				champ.dataset.selectionEmportee = "";
				form.appendChild(champ);
			};
			cachee("selection_etudiants", "1");
			$$("input[type=checkbox][name='etudiants[]']:checked", cible).forEach((c) => cachee("etudiants[]", c.value));
		});
	});

	/* ---------- Logo d'un IPES : aperçu de l'image choisie avant l'envoi ---------- */
	$$("[data-logo-ipes]").forEach((zone) => {
		const champ = $("input[type=file]", zone);
		const apercu = $("[data-logo-apercu]", zone);
		const vide = $("[data-logo-vide]", zone);
		const titre = $("[data-logo-titre]", zone);
		if (!champ || !apercu) return;
		let url = null;
		champ.addEventListener("change", () => {
			const fichier = champ.files?.[0];
			if (!fichier || !/^image\/(png|jpeg)$/.test(fichier.type)) return;
			if (url) URL.revokeObjectURL(url);
			url = URL.createObjectURL(fichier);
			apercu.src = url;
			apercu.hidden = false;
			if (vide) vide.hidden = true;
			if (titre) titre.textContent = fichier.name;
		});
	});

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

	/* ---------- Matricule : lettres, chiffres, « - », « _ » ou « . », 3 à 30 caractères.
	   Même règle que UEB_REGEX_MATRICULE (inc/config.php) ; le serveur revalide. ---------- */
	const MATRICULE = /^[A-Z0-9._-]{3,30}$/;
	$$("[data-identifiant]").forEach((champ) => {
		const apercu = document.createElement("p");
		apercu.className = "apercu";
		apercu.setAttribute("aria-live", "polite");
		(champ.closest(".champ__boite") ?? champ).insertAdjacentElement("afterend", apercu);
		const maj = () => {
			const v = champ.value.replace(/\s+/g, "").toUpperCase();
			const interdit = v !== "" && /[^A-Z0-9._-]/.test(v);
			apercu.textContent = interdit ? "Lettres, chiffres, « - », « _ » ou « . » seulement" : MATRICULE.test(v) ? "✓ Matricule" : "";
			apercu.classList.toggle("apercu--erreur", interdit);
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
		const trancheCochee = () => $("input[name=tranche]:checked", formQuitus);
		let derniereTranche = trancheCochee()?.value || "";
		/* Dernier montant saisi pour la première tranche, retrouvé après un passage par la deuxième. */
		let montantPremiere = config.formations.find((f) => String(f.id) === filiere.value)?.type_formation === "classique" && derniereTranche === "1" ? champMontant.value : "";
		if (config.formations.find((f) => String(f.id) === filiere.value)?.type_formation === "pro") {
			montantProfessionnel.set(filiere.value, champMontant.value);
		}
		/* Mêmes règles que ueb_erreur_montant_classique() côté serveur. */
		const messageMontantClassique = (n, regle) => {
			if (!n) return "Saisis le montant de ta première tranche : 25 000 FCFA au moins.";
			if (n % regle.pas) return "Saisis un multiple de 5 000 FCFA : 25 000, 30 000, 35 000…";
			if (n < regle.min) return "La première tranche est de 25 000 FCFA au moins.";
			if (n > regle.max) return "La première tranche va jusqu’à 45 000 FCFA. Pour payer 50 000 FCFA, choisis « Les deux tranches ».";
			return "";
		};
		const somme = champMontant.closest("[data-somme]");
		const tranchesChoix = champMontant.form.querySelector(".choix-tranche");
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
			const niveau = $("[name=parcours]", formQuitus)?.value || "";
			/* Filières de l'établissement ouvertes au niveau choisi. */
			if (`${etablissement}|${niveau}` !== dernierEtab) {
				const selection = filiere.value;
				const liste = niveau ? config.formations.filter((f) => f.etablissement === etablissement && f.niveaux.includes(niveau)) : [];
				const invite = !etablissement ? "Choisis d’abord ton établissement" : !niveau ? "Choisis d’abord ton niveau" : liste.length ? "Choisir une filière…" : "Aucune filière à ce niveau";
				filiere.replaceChildren(new Option(invite, ""));
				liste.forEach((f) => filiere.add(new Option((f.choix ? `Choix ${f.choix} — ` : "") + f.libelle, String(f.id))));
				filiere.value = liste.some((f) => String(f.id) === selection) ? selection : "";
				dernierEtab = `${etablissement}|${niveau}`;
			}
			const formation = config.formations.find((f) => String(f.id) === filiere.value);
			const classique = formation?.type_formation === "classique";
			const regle = config.regleDroits;
			const tranche = trancheCochee();
			const t = tranche?.value || "";
			/* Formation classique : deuxième tranche = le reste, les deux = 50 000 (verrouillés) ;
			   la première se saisit, par pas de 5 000. */
			const fixe = classique && !medicalSeul ? (t === "2" ? regle.reste : t === "3" ? regle.total : null) : null;
			if (derniereFormation !== filiere.value) {
				champMontant.value = classique ? (montantPremiere || formater(regle.min)) : (montantProfessionnel.get(filiere.value) || "");
				derniereFormation = filiere.value;
			}
			if (fixe !== null) {
				champMontant.value = formater(fixe);
			} else if (classique && derniereTranche !== t && ["2", "3"].includes(derniereTranche)) {
				champMontant.value = montantPremiere || formater(regle.min);
			}
			derniereTranche = t;
			champMontant.readOnly = !formation || medicalSeul || fixe !== null;
			somme?.classList.toggle("est-fixe", fixe !== null);
			somme?.classList.toggle("est-libre", Boolean(formation) && !classique);
			tranchesChoix?.classList.toggle("est-libre", Boolean(formation) && !classique);
			let erreurMontant = "";
			if (classique && !medicalSeul && fixe === null) {
				const n = lireMontant();
				montantPremiere = champMontant.value;
				erreurMontant = messageMontantClassique(n, regle);
				$$("[data-pas]", somme).forEach((b) => { b.disabled = Number(b.dataset.pas) < 0 ? n <= regle.min : n >= regle.max; });
			} else if (formation && !classique) montantProfessionnel.set(filiere.value, champMontant.value);
			signalerMontant(erreurMontant);
			const deja = regle.total - regle.reste;
			texte("#champ-montant-aide", classique
				? (t === "2" ? `Calculé pour toi : 50 000 − ${formater(deja)} FCFA de première tranche.`
					: t === "3" ? "Les droits de l’année, en une seule fois."
					: "25 000 FCFA au moins, par multiples de 5 000, jusqu’à 45 000. Pour 50 000 FCFA, choisis « Les deux tranches ».")
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
			const champNiveau = $("[name=parcours]", formQuitus);
			texte("[data-recap-niveau]", champNiveau?.value ? champNiveau.options[champNiveau.selectedIndex].text : "À choisir");
			texte("[data-recap-tranche]", medicalSeul ? "Paiement unique" : tranche ? tranche.dataset.libelle || tranche.closest("label").textContent.trim() : "—");
			texte("[data-recap-moyen]", $("select[name=moyen_paiement]", formQuitus)?.value || "À choisir");
			/* Jauge de l'année (formations classiques) : elle annonce la deuxième tranche à venir. */
			const jauge = $("[data-jauge-annee]", formQuitus);
			if (jauge) {
				jauge.hidden = !classique || medicalSeul;
				const total = deja + lireMontant();
				$("[data-jauge-ce]", jauge).style.setProperty("--part", String(Math.min(1, total / regle.total)));
				texte("[data-jauge-texte]", (deja ? `Déjà préparé : ${formater(deja)} FCFA. ` : "")
					+ (total >= regle.total
						? "Avec ce versement, les 50 000 FCFA de l’année sont couverts."
						: `Avec ce versement : ${formater(total)} sur 50 000 FCFA. Ta deuxième tranche sera de ${formater(regle.total - total)} FCFA.`));
			}
			/* Documents réellement produits : le quitus médical et les fiches CMS
			   n'existent que s'il y a des frais médicaux à payer avec ce quitus. */
			$$("[data-document=medical]").forEach((element) => { element.hidden = frais === 0; });
			const pages = $$("[data-document]").filter((d) => !d.hidden).reduce((n, d) => n + Number(d.dataset.pages), 0);
			texte("[data-document-pages]", String(pages));
			texte("[data-document-pages-suffix]", pages > 1 ? "s" : "");
		};
		formQuitus.addEventListener("input", maj);
		formQuitus.addEventListener("change", maj);
		/* Boutons − et + : la première tranche avance par pas de 5 000, entre 25 000 et 45 000. */
		$$("[data-pas]", formQuitus).forEach((bouton) => bouton.addEventListener("click", () => {
			const regle = config.regleDroits;
			const n = lireMontant() || regle.min;
			const arrondi = Math.round(n / regle.pas) * regle.pas;
			const suivant = Math.min(regle.max, Math.max(regle.min, arrondi + Number(bouton.dataset.pas) * regle.pas));
			champMontant.value = formater(suivant);
			champMontant.dispatchEvent(new Event("input", { bubbles: true }));
		}));
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

	/* ---------- Liste déroulante illustrée (lieu de paiement) ----------
	   Un <select data-liste-logos='{"valeur": {"src": logo, "fond": couleur}}'> reste dans le
	   formulaire (envoi, validation, page sans JavaScript) ; il est doublé d'une
	   liste à logos au modèle « select-only combobox » : flèches, Entrée, Échap,
	   Début/Fin et première lettre au clavier. */
	$$("select[data-liste-logos]").forEach((select, n) => {
		const logos = JSON.parse(select.dataset.listeLogos || "{}");
		const champ = select.closest(".champ");
		const natif = select.closest(".champ__select") || select;
		const libelle = $("label", champ);
		const options = [...select.options].filter((o) => o.value);
		const id = `liste-logos-${n}`;
		libelle.id ||= `${id}-libelle`;

		const tuile = (valeur) => {
			const t = document.createElement("span");
			t.className = "liste-logos__tuile";
			t.style.background = logos[valeur]?.fond || "";
			const img = document.createElement("img");
			img.src = logos[valeur]?.src || "";
			img.alt = "";
			t.appendChild(img);
			return t;
		};
		const combo = document.createElement("div");
		combo.className = "liste-logos__bouton";
		combo.id = `${id}-bouton`;
		combo.tabIndex = 0;
		combo.setAttribute("role", "combobox");
		combo.setAttribute("aria-haspopup", "listbox");
		combo.setAttribute("aria-expanded", "false");
		combo.setAttribute("aria-controls", `${id}-options`);
		combo.setAttribute("aria-labelledby", `${libelle.id} ${id}-bouton`);
		if (select.hasAttribute("aria-describedby")) combo.setAttribute("aria-describedby", select.getAttribute("aria-describedby"));
		const liste = document.createElement("ul");
		liste.className = "liste-logos__options";
		liste.id = `${id}-options`;
		liste.setAttribute("role", "listbox");
		liste.setAttribute("aria-labelledby", libelle.id);
		liste.tabIndex = -1;
		liste.hidden = true;
		const items = options.map((o, i) => {
			const li = document.createElement("li");
			li.id = `${id}-option-${i}`;
			li.setAttribute("role", "option");
			li.append(tuile(o.value), Object.assign(document.createElement("span"), { textContent: o.text }));
			li.insertAdjacentHTML("beforeend", '<svg class="icone liste-logos__coche" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>');
			li.addEventListener("mousedown", (ev) => ev.preventDefault()); // le focus reste sur le bouton
			li.addEventListener("click", () => { choisir(i); fermer(); });
			return li;
		});
		liste.append(...items);
		const enveloppe = document.createElement("div");
		enveloppe.className = "liste-logos";
		enveloppe.append(combo, liste);
		natif.after(enveloppe);
		natif.classList.add("liste-logos__natif");
		select.tabIndex = -1;
		select.setAttribute("aria-hidden", "true");
		libelle.htmlFor = combo.id;

		let actif = -1;
		const courant = () => options.findIndex((o) => o.value === select.value);
		const afficher = () => {
			const i = courant();
			combo.replaceChildren();
			if (i < 0) {
				combo.insertAdjacentHTML("beforeend", '<span class="liste-logos__vide">Choisir…</span>');
			} else {
				combo.append(tuile(options[i].value), Object.assign(document.createElement("span"), { textContent: options[i].text }));
			}
			combo.insertAdjacentHTML("beforeend", '<svg class="icone liste-logos__chevron" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>');
			items.forEach((li, j) => li.setAttribute("aria-selected", String(j === i)));
		};
		const activer = (i) => {
			actif = Math.max(0, Math.min(items.length - 1, i));
			items.forEach((li, j) => li.classList.toggle("est-actif", j === actif));
			combo.setAttribute("aria-activedescendant", items[actif].id);
			items[actif].scrollIntoView({ block: "nearest" });
		};
		const ouvrir = () => {
			if (!liste.hidden) return;
			liste.hidden = false;
			enveloppe.classList.add("est-ouverte");
			combo.setAttribute("aria-expanded", "true");
			activer(Math.max(0, courant()));
		};
		const fermer = () => {
			liste.hidden = true;
			enveloppe.classList.remove("est-ouverte");
			combo.setAttribute("aria-expanded", "false");
			combo.removeAttribute("aria-activedescendant");
		};
		const choisir = (i) => {
			select.value = options[i].value;
			select.dispatchEvent(new Event("change", { bubbles: true }));
			champ.classList.remove("champ--invalide");
			$$(".champ__erreur", champ).forEach((e) => { e.hidden = true; });
			afficher();
		};
		combo.addEventListener("click", () => (liste.hidden ? ouvrir() : fermer()));
		combo.addEventListener("keydown", (ev) => {
			const ouverte = !liste.hidden;
			switch (ev.key) {
				case "ArrowDown": ev.preventDefault(); ouverte ? activer(actif + 1) : ouvrir(); break;
				case "ArrowUp": ev.preventDefault(); ouverte ? activer(actif - 1) : ouvrir(); break;
				case "Home": if (ouverte) { ev.preventDefault(); activer(0); } break;
				case "End": if (ouverte) { ev.preventDefault(); activer(items.length - 1); } break;
				case "Enter": case " ":
					ev.preventDefault();
					if (ouverte) { choisir(actif); fermer(); } else ouvrir();
					break;
				case "Escape": if (ouverte) { ev.preventDefault(); fermer(); } break;
				case "Tab": if (ouverte) { choisir(actif); fermer(); } break;
				default:
					if (ev.key.length === 1 && /\S/.test(ev.key)) {
						const i = options.findIndex((o) => o.text.toLowerCase().startsWith(ev.key.toLowerCase()));
						if (i >= 0) { ouvrir(); activer(i); }
					}
			}
		});
		combo.addEventListener("blur", fermer);
		/* Lien du résumé d'erreurs ou mise au point automatique : vers le bouton. */
		select.addEventListener("focus", () => combo.focus());
		/* Champ requis vide à l'envoi : erreur affichée près du champ, sans bulle sur un select caché. */
		select.addEventListener("invalid", (ev) => {
			ev.preventDefault();
			champ.classList.add("champ--invalide");
			let erreur = $(".champ__erreur", champ);
			if (!erreur) {
				erreur = document.createElement("p");
				erreur.className = "champ__erreur";
				erreur.textContent = "Choisis où tu vas payer.";
				champ.appendChild(erreur);
			}
			erreur.hidden = false;
			combo.focus();
		});
		afficher();
	});

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
		/* Libellés du bouton, surchargeables par data-libelle-un / data-libelle-plusieurs (« {n} » = nombre). */
		const libelleUn = form.dataset.libelleUn || "Envoyer mon reçu";
		const libellePlusieurs = form.dataset.libellePlusieurs || "Envoyer {n} reçus";
		const max = parseInt(champ.dataset.max, 10);
		const maxOctets = parseInt(champ.dataset.maxOctets, 10);
		const types = ["image/jpeg", "image/png", "application/pdf"];
		const COTE_MAX = 2000;
		const QUALITE = 0.82;
		const taille = (o) => (o >= 1048576 ? (o / 1048576).toFixed(1).replace(".", ",") + " Mo" : Math.max(1, Math.round(o / 1024)) + " Ko");
		const peutRegrouper = typeof DataTransfer === "function";
		/* data-un-seul (reçus des étudiants) : une seule photo par envoi ; dès qu'elle
		   est chargée, la caméra et l'import sont bloqués jusqu'à ce qu'on la retire. */
		const unSeul = form.hasAttribute("data-un-seul");
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
				/* Côté étudiant (data-un-seul), seul le poids final s'affiche : la compression ne le concerne pas. */
				const gain = !unSeul && s.origine > f.size * 1.1;
				infos.textContent = souci ? souci.charAt(0).toUpperCase() + souci.slice(1)
					: gain ? `${taille(s.origine)} → ${taille(f.size)}` : taille(f.size);
				if (!souci && gain) infos.classList.add("depot__gain");
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
			const verrou = unSeul && (occupe || selection.length >= 1);
			if (unSeul) {
				champ.disabled = verrou;
				if (capture) capture.disabled = verrou;
				const boutonCamera = $("[data-camera-ouvrir]", form);
				if (boutonCamera) boutonCamera.disabled = verrou;
				$("[data-camera-natif]", form)?.classList.toggle("est-verrouille", verrou);
				depot.classList.toggle("est-verrouille", verrou);
				depot.setAttribute("aria-disabled", String(verrou));
			}
			if (titre) titre.textContent = occupe ? (unSeul ? "Préparation du reçu…" : "Compression des photos…") : verrou ? "Reçu chargé : retire-le pour en choisir un autre" : selection.length ? `${selection.length} fichier${selection.length > 1 ? "s" : ""} prêt${selection.length > 1 ? "s" : ""} à l’envoi` : titreInitial;
			if (libelle) libelle.textContent = selection.length > 1 ? libellePlusieurs.replace("{n}", selection.length) : libelleUn;
		};

		const ajouter = async (fichiers) => {
			if (!fichiers.length) return;
			if (!peutRegrouper) return;
			if (unSeul) {
				if (occupe || selection.length) return;
				fichiers = fichiers.slice(0, 1);
			}
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
		/* Un champ désactivé n'est pas envoyé : on le réactive au moment de l'envoi. */
		form.addEventListener("submit", () => { champ.disabled = false; });
		["dragenter", "dragover"].forEach((t) => depot.addEventListener(t, () => depot.classList.add("est-survole")));
		["dragleave", "drop"].forEach((t) => depot.addEventListener(t, () => depot.classList.remove("est-survole")));

		/* Caméra : sur téléphone ou tablette, l'appareil photo natif (champ
		   capture) : pleine résolution, mise au point, aucun recadrage. Ailleurs,
		   aperçu en direct si le navigateur y donne accès (HTTPS ou localhost). */
		const dialogue = $("[data-camera]");
		const ouvrir = $("[data-camera-ouvrir]", form);
		const natif = $("[data-camera-natif]", form);
		const tactile = window.matchMedia("(pointer: coarse)").matches;
		const direct = !tactile && Boolean(dialogue?.showModal && navigator.mediaDevices?.getUserMedia && window.isSecureContext);
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
				/* Image 4:3, celle du capteur : un format 16:9 imposé la recadre (effet de zoom). */
				flux = await navigator.mediaDevices.getUserMedia({
					video: { facingMode: { ideal: "environment" }, aspectRatio: { ideal: 4 / 3 }, width: { ideal: 2560 } },
					audio: false,
				});
				if (!dialogue.open) return arreter();
				/* Zoom au plus large quand la caméra le permet. */
				const piste = flux.getVideoTracks()[0];
				const zoom = piste?.getCapabilities?.().zoom;
				if (zoom && piste.getSettings().zoom > zoom.min) {
					await piste.applyConstraints({ advanced: [{ zoom: zoom.min }] }).catch(() => {});
				}
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

	/* ---------- Confirmation avant une action sensible ----------
	   data-confirmer : le texte ; data-confirmer-titre, -bouton, -annuler ;
	   data-confirmer-ton="enregistrer" (vert) ou destructive par défaut (rouge). */
	const fenetre = $("#fenetre-confirmation");
	const demander = (source, valider) => {
		const d = source.dataset;
		const enregistrer = d.confirmerTon === "enregistrer";
		const bouton = $("[data-fenetre-valider]", fenetre);
		fenetre.dataset.ton = enregistrer ? "enregistrer" : "danger";
		$("[data-fenetre-titre]", fenetre).textContent = d.confirmerTitre || "Confirmer";
		$("[data-fenetre-texte]", fenetre).textContent = d.confirmer;
		$("[data-fenetre-annuler]", fenetre).textContent = d.confirmerAnnuler || (enregistrer ? "Revenir" : "Annuler");
		bouton.textContent = d.confirmerBouton || "Confirmer";
		bouton.classList.toggle("btn--danger", !enregistrer);
		bouton.classList.toggle("btn--primaire", enregistrer);
		fenetre.returnValue = "";
		fenetre.showModal();
		fenetre.addEventListener("close", () => { if (fenetre.returnValue === "oui") valider(); }, { once: true });
	};
	/* Un clic sur le voile referme sans rien faire. */
	fenetre?.addEventListener("click", (ev) => { if (ev.target === fenetre) fenetre.close("non"); });
	$$("form[data-confirmer]").forEach((form) => {
		form.addEventListener("submit", (ev) => {
			/* Formulaire refusé par sa propre validation : rien à confirmer. */
			if (ev.defaultPrevented || form.dataset.confirme === "1" || !fenetre?.showModal) return;
			ev.preventDefault();
			demander(form, () => {
				form.dataset.confirme = "1";
				form.requestSubmit();
			});
		});
	});
	/* Sur un bouton : seul ce bouton demande confirmation, et c'est bien lui qui
	   est envoyé ensuite (son nom compte pour le serveur, ex. « envoyer »). */
	$$("button[data-confirmer]").forEach((bouton) => {
		bouton.form?.addEventListener("submit", (ev) => {
			if (ev.defaultPrevented || ev.submitter !== bouton || bouton.dataset.confirme === "1" || !fenetre?.showModal) return;
			ev.preventDefault();
			demander(bouton, () => {
				bouton.dataset.confirme = "1";
				bouton.form.requestSubmit(bouton);
			});
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
