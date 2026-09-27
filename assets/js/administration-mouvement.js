/*
 * Tableau de bord et suivi des paiements de l'administration : mouvement
 * (GSAP) et anneaux animés (Remotion). Ne modifie ni le balisage ni les
 * valeurs : il fait partir les marques de données de zéro, puis les ramène à
 * leur valeur exacte.
 *
 *   - Ouverture : les cinq valeurs des cartes comptent en cascade pendant que
 *     leurs mini-courbes se tracent ; le point doré se pose au bout.
 *   - Au défilement, une fois par panneau (IntersectionObserver) : anneau
 *     financier en un balayage (Remotion) et pourcentage synchronisé, courbes
 *     tracées, barres en cascade, montants qui comptent, registre en vague.
 *   - Paiements vérifiés : les colonnes du parcours montent ; anneau par sexe.
 *   - Page Paiements : les quatre bilans comptent à l'ouverture ; au
 *     défilement, les mois montent, l'anneau droits / médicaux se balaie
 *     (Remotion) et les taux du registre se remplissent.
 *
 * html.adm-anime (posé avant l'affichage, jamais en mouvement réduit) tient
 * les marques à leur état initial ; ce script pose html.adm-anime-pret pour
 * désarmer le filet de sécurité de ueb_page_debut().
 */
(() => {
	"use strict";
	const racine = document.documentElement;
	const gsap = window.gsap;
	const tableau = document.querySelector(".adm-dashboard");
	const paiements = document.querySelector(".adm-paiements");
	if ((!tableau && !paiements) || !gsap || !racine.classList.contains("adm-anime") || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
		racine.classList.remove("adm-anime");
		return;
	}
	racine.classList.add("adm-anime-pret");

	const $ = (s, r = document) => r.querySelector(s);
	const $$ = (s, r = document) => [...r.querySelectorAll(s)];
	const SORTIE = "expo.out";

	/* ---------- Compteurs ----------
	   Le premier nombre du texte (« 100 333 », « 14 % », « +13 », « 7,2 % »)
	   part de zéro ; séparateurs, décimales, préfixe et suffixe sont gardés.
	   La largeur finale est réservée : rien ne bouge autour pendant le comptage. */
	const CHIFFRE = /[-−]?\d[\d   ]*(?:,\d+)?/;
	const formater = (v, dec, sep) => {
		const [entier, decimales] = Math.abs(v).toFixed(dec).split(".");
		return (v < 0 ? "−" : "") + entier.replace(/\B(?=(\d{3})+(?!\d))/g, sep) + (decimales ? "," + decimales : "");
	};
	const noeudChiffre = (el) => {
		const parcours = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
		for (let n = parcours.nextNode(); n; n = parcours.nextNode()) {
			if (/\d/.test(n.nodeValue)) return n;
		}
		return null;
	};
	const compter = (el, duree = 1.4, ease = SORTIE) => {
		const noeud = el && noeudChiffre(el);
		const texte = noeud?.nodeValue || "";
		const m = texte.match(CHIFFRE);
		if (!m) return null;
		const brut = m[0].replace(/[   ]+$/, "");
		const valeur = parseFloat(brut.replace(/[   ]/g, "").replace(",", ".").replace("−", "-"));
		if (!isFinite(valeur)) return null;
		const avant = texte.slice(0, m.index);
		const apres = texte.slice(m.index + brut.length);
		const dec = (brut.split(",")[1] || "").length;
		const sep = brut.includes(" ") ? " " : brut.includes(" ") ? " " : " ";
		/* Élément propre, qu'aucune règle du tableau ne vise (un <span> hériterait
		   des tailles prévues pour les libellés voisins). */
		const porteur = document.createElement("adm-compte");
		noeud.parentNode.insertBefore(porteur, noeud);
		porteur.appendChild(noeud);
		porteur.style.minWidth = porteur.getBoundingClientRect().width + "px";
		const etat = { v: 0 };
		noeud.nodeValue = avant + formater(0, dec, sep) + apres;
		return gsap.to(etat, {
			v: valeur,
			duration: duree,
			ease,
			onUpdate: () => { noeud.nodeValue = avant + formater(etat.v, dec, sep) + apres; },
			onComplete: () => { noeud.nodeValue = texte; porteur.style.minWidth = ""; },
		});
	};
	/* Révèle un élément caché par adm-anime et fait compter son nombre. */
	const apparaitre = (tl, el, position, duree = 1.4, ease = SORTIE) => {
		if (!el) return;
		tl.to(el, { opacity: 1, duration: 0.3, ease: "power1.out" }, position);
		const t = compter(el, duree, ease);
		if (t) tl.add(t, position);
	};

	/* ---------- Déclenchement au défilement, une fois ---------- */
	const observateur = new IntersectionObserver((entrees) => {
		entrees.filter((e) => e.isIntersecting).forEach((e, rang) => {
			observateur.unobserve(e.target);
			e.target.__jouer?.(rang);
		});
	}, { rootMargin: "0px 0px -10% 0px", threshold: 0.2 });
	const quandVisible = (el, jouer) => {
		if (!el) return;
		el.__jouer = jouer;
		observateur.observe(el);
	};

	/* Monte la composition Remotion « donut » par-dessus le SVG serveur. */
	const monterDonut = (conteneur, props) => {
		if (typeof window.uebMonterAnimation !== "function") return false;
		const hote = document.createElement("div");
		hote.className = "animation animation--donut";
		hote.dataset.remotion = "donut";
		hote.dataset.props = JSON.stringify(props);
		hote.setAttribute("aria-hidden", "true");
		hote.innerHTML = '<div class="animation__scene" data-remotion-scene></div>';
		conteneur.prepend(hote);
		window.uebMonterAnimation(hote);
		return true;
	};

	if (tableau) {
		/* ---------- 1. Cartes : l'ouverture ---------- */
		const ouverture = gsap.timeline({ delay: 0.12 });
		$$(".adm-kpi", tableau).forEach((carte, i) => {
			const t = i * 0.09;
			apparaitre(ouverture, $(".adm-kpi__valeur", carte), t, 1.6);
			const trace = $(".adm-spark__zone svg", carte);
			if (trace) ouverture.to(trace, { clipPath: "inset(-8px 0% -8px -8px)", duration: 1.35, ease: "power2.inOut" }, t + 0.08);
			const fin = $(".adm-spark__fin", carte);
			if (fin) ouverture.fromTo(fin, { scale: 0, transformOrigin: "50% 50%" }, { scale: 1, duration: 0.55, ease: "back.out(2.4)" }, t + 1.3);
			apparaitre(ouverture, $(".adm-spark figcaption b", carte), t + 1.1, 1);
		});

		/* ---------- 2. Anneau financier : Remotion + pourcentage synchronisé ---------- */
		const finances = $(".adm-finances", tableau);
		quandVisible(finances, () => {
			const donut = $(".adm-donut", finances);
			const svg = donut && $("svg", donut);
			const cercles = svg ? $$("circle:not(.adm-donut__piste)", svg) : [];
			const cles = { foret: "encaisse", bleu: "verification", or: "declare", neutre: "non_declare" };
			const parts = cercles.map((c) => {
				const couleur = c.getAttribute("stroke") || "";
				const nom = (couleur.match(/--dash-([a-z]+)/) || [])[1];
				return { cle: cles[nom] || nom || "part", valeur: parseFloat(c.getAttribute("stroke-dasharray")) || 0, couleur };
			});
			if (!(donut && parts.length && monterDonut(donut, { parts, piste: "var(--dash-neutre)" })) && svg) {
				gsap.to(cercles, { opacity: 1, duration: 0.8, stagger: 0.15 });
			}
			/* Le balayage Remotion dure 1,8 s (images 4 à 58) ; chaque légende
			   s'allume quand il atteint le début de sa part. */
			const tl = gsap.timeline({ delay: 0.13 });
			apparaitre(tl, $(".adm-donut p b", finances), 0, 1.8, "power2.inOut");
			let debut = 0;
			const departs = {};
			parts.forEach((p) => { departs[p.cle] = debut; debut += p.valeur; });
			const ordre = ["encaisse", "verification", "declare", "non_declare"];
			$$(".adm-finances__legende li", finances).forEach((li, i) => {
				const t = 1.8 * ((departs[ordre[i]] ?? 100) / 100);
				tl.to(li, { opacity: 1, duration: 0.45, ease: "power1.out" }, t);
				apparaitre(tl, $("b", li), t, 1.1);
			});
			apparaitre(tl, $(".adm-finances__total b", finances), 0.9, 1.4);
		});

		/* ---------- 3. Évolution : les courbes se tracent ---------- */
		const evolution = $(".adm-evolution", tableau);
		quandVisible(evolution, () => {
			const tl = gsap.timeline();
			const trace = $(".courbes__trace", evolution);
			if (trace) tl.to(trace, { clipPath: "inset(0px 0% 0px 0px)", duration: 1.5, ease: "power2.inOut" }, 0);
			tl.fromTo($$(".courbes__point--fin", evolution), { scale: 0 }, { scale: 1, opacity: 1, duration: 0.5, ease: "back.out(2.2)", stagger: 0.1 }, 1.35);
			tl.fromTo($$(".courbes__fin", evolution), { x: -8 }, { x: 0, opacity: 1, duration: 0.5, ease: "power2.out", stagger: 0.1 }, 1.4);
			$$(".adm-evolution__bilan b", evolution).forEach((b, i) => apparaitre(tl, b, 0.35 + i * 0.1, 1.3));
		});

		/* ---------- 4. Situation des quitus et comparaison : barres en cascade ---------- */
		const barres = (panneau, pas, delai = 0) => {
			const tl = gsap.timeline({ delay: delai });
			apparaitre(tl, $(".adm-statistiques__total b", panneau), 0, 1.3);
			$$(".adm-barre", panneau).forEach((barre, i) => {
				const t = 0.15 + i * pas;
				tl.to($("i", barre), { scaleX: 1, duration: 1.05, ease: SORTIE }, t);
				const valeur = barre.closest("li")?.querySelector(":scope > b, a > b");
				apparaitre(tl, valeur, t, 1.1);
			});
		};
		quandVisible($(".adm-statistiques", tableau), () => barres($(".adm-statistiques", tableau), 0.12));
		quandVisible($(".adm-comparaison", tableau), () => barres($(".adm-comparaison", tableau), 0.055, 0.1));

		/* ---------- 5. Reste à encaisser ---------- */
		const solde = $(".adm-solde", tableau);
		quandVisible(solde, () => {
			const tl = gsap.timeline({ delay: 0.15 });
			apparaitre(tl, $(".adm-solde__montant", solde), 0, 1.7);
			$$(".adm-solde dd", solde).forEach((dd, i) => apparaitre(tl, dd, 0.45 + i * 0.12, 1.2));
		});

		/* ---------- 6. Registre des établissements : une vague, ligne par ligne ---------- */
		$$(".adm-registre__ligne", tableau).forEach((ligne) => {
			quandVisible(ligne, (rang) => {
				const tl = gsap.timeline({ delay: rang * 0.06 });
				apparaitre(tl, $(".adm-registre__effectif b", ligne), 0, 1.1);
				apparaitre(tl, $(".adm-registre__total b", ligne), 0.05, 1.1);
				const mesure = $(".adm-registre__mesure .suivi-barre", ligne);
				if (mesure) tl.to(mesure, { clipPath: "inset(0px 0% 0px 0px)", duration: 1.1, ease: "power3.inOut" }, 0.1);
				apparaitre(tl, $(".adm-registre__mesure > b", ligne), 0.1, 1.1);
			});
		});

		/* ---------- 7. Paiements vérifiés : les colonnes du parcours montent
		   l'une après l'autre, leur nombre compte avec elles ---------- */
		const verifies = $(".adm-verifies", tableau);
		quandVisible(verifies, () => {
			const tl = gsap.timeline({ delay: 0.1 });
			apparaitre(tl, $(".adm-verifies__montant b", verifies), 0, 1.5);
			$$(".adm-parcours__etape", verifies).forEach((etape, i) => {
				const t = 0.15 + i * 0.14;
				tl.to($(".adm-parcours__colonne i", etape), { scaleY: 1, duration: 0.95, ease: SORTIE }, t);
				apparaitre(tl, $(".adm-parcours__valeur", etape), t, 1.1);
				apparaitre(tl, $("small", etape), t + 0.2, 1);
			});
			apparaitre(tl, $(".adm-verifies__constat b", verifies), 0.9, 1.1);
		});

		/* ---------- 7 bis. Répartition par sexe : l'anneau se trace ---------- */
		const sexe = $(".adm-complements .graphe__anneau", tableau)?.closest(".graphe");
		if (sexe) {
			const arcs = $$(".graphe__anneau svg circle:not(.graphe__anneau-piste)", sexe);
			const finaux = arcs.map((c) => c.getAttribute("stroke-dasharray") || "0 0");
			arcs.forEach((c, i) => {
				const tour = finaux[i].split(" ").reduce((s, v) => s + parseFloat(v || 0), 0);
				c.setAttribute("stroke-dasharray", `0 ${tour}`);
			});
			quandVisible(sexe, () => {
				const tl = gsap.timeline({ delay: 0.1 });
				arcs.forEach((c, i) => tl.to(c, { attr: { "stroke-dasharray": finaux[i] }, duration: 1.1, ease: "power3.inOut" }, 0.15 + i * 0.35));
				[$(".graphe__anneau-centre b", sexe), ...$$(".graphe__legende b", sexe)].forEach((b, i) => apparaitre(tl, b, 0.15 + i * 0.1, 1.3));
			});
		}

		/* ---------- 8. Vue d'un établissement : niveaux et filières ---------- */
		const niveaux = $(".adm-par-niveau");
		if (niveaux) {
			const colonnes = $$(".adm-colonnes__barre i", niveaux);
			const valeurs = $$(".adm-colonnes b", niveaux);
			gsap.set(colonnes, { scaleY: 0 });
			gsap.set(valeurs, { opacity: 0 });
			quandVisible(niveaux, () => {
				const tl = gsap.timeline();
				tl.to(colonnes, { scaleY: 1, duration: 0.95, ease: SORTIE, stagger: 0.08 }, 0);
				valeurs.forEach((b, i) => apparaitre(tl, b, i * 0.08, 1.1));
			});
		}
		const filieres = $(".adm-filieres");
		if (filieres) {
			const lignes = $$(".adm-filieres__liste li", filieres);
			gsap.set($$(".adm-filieres__barre i", filieres), { scaleX: 0 });
			gsap.set($$(".adm-filieres__liste b", filieres), { opacity: 0 });
			quandVisible(filieres, () => {
				const tl = gsap.timeline();
				lignes.forEach((li, i) => {
					tl.to($(".adm-filieres__barre i", li), { scaleX: 1, duration: 1, ease: SORTIE }, i * 0.05);
					apparaitre(tl, $("b", li), i * 0.05, 1);
				});
			});
		}
		const recouvrement = $(".graphe--filieres");
		if (recouvrement) {
			const lignes = $$(".filieres__ligne", recouvrement);
			gsap.set($$(".suivi-barre", recouvrement), { clipPath: "inset(0px 100% 0px 0px)" });
			gsap.set($$(".filieres__taux", recouvrement), { opacity: 0 });
			quandVisible(recouvrement, () => {
				const tl = gsap.timeline();
				lignes.forEach((li, i) => {
					tl.to($(".suivi-barre", li), { clipPath: "inset(0px 0% 0px 0px)", duration: 1.1, ease: "power3.inOut" }, i * 0.08);
					apparaitre(tl, $(".filieres__taux", li), i * 0.08, 1.1);
				});
			});
		}
	}

	if (!paiements) return;

	/* ---------- Paiements, 1. Les quatre bilans : l'ouverture ---------- */
	const ouvertureP = gsap.timeline({ delay: 0.12 });
	$$(".pay-kpi", paiements).forEach((carte, i) => {
		const t = i * 0.09;
		apparaitre(ouvertureP, $(".pay-kpi__valeur", carte), t, 1.6);
		apparaitre(ouvertureP, $(".pay-kpi__repere", carte), t + 0.7, 1.1);
	});

	/* ---------- 2. Situation des étudiants et contrôles en attente ---------- */
	$$(".pay-tuile", paiements).forEach((tuile) => {
		quandVisible(tuile, (rang) => {
			const tl = gsap.timeline({ delay: 0.1 + rang * 0.08 });
			apparaitre(tl, $(".pay-tuile__chiffre", tuile), 0, 1.4);
			const jauge = $(".pay-jauge", tuile);
			if (jauge) tl.to(jauge, { clipPath: "inset(0 0% 0 0 round 5px)", duration: 1.2, ease: "power3.inOut" }, 0.15);
			$$(".pay-tuile__details b", tuile).forEach((b, i) => apparaitre(tl, b, 0.3 + i * 0.1, 1.1));
		});
	});

	/* ---------- 3. Encaissements par mois : les colonnes montent, mois après mois ---------- */
	const histogramme = $(".pay-histogramme", paiements);
	quandVisible(histogramme, () => {
		const tl = gsap.timeline({ delay: 0.1 });
		apparaitre(tl, $(".pay-histogramme__total b", histogramme), 0, 1.5);
		const reels = $$(".pay-mois:not(.pay-mois--avenir) .pay-mois__barres", histogramme);
		tl.to(reels, { scaleY: 1, duration: 1.05, ease: SORTIE, stagger: 0.08 }, 0.1);
		tl.to($$(".pay-mois--avenir", histogramme), { opacity: 1, duration: 0.5, ease: "power1.out", stagger: 0.06 }, 0.1 + reels.length * 0.08);
	});

	/* ---------- 4. Répartition : l'anneau se balaie (Remotion), la légende suit ---------- */
	const repartition = $(".pay-repartition", paiements);
	quandVisible(repartition, () => {
		const anneau = $(".pay-anneau", repartition);
		const svg = anneau && $("svg", anneau);
		const cercles = svg ? $$("circle:not(.pay-anneau__piste)", svg) : [];
		const parts = cercles.map((c) => ({
			cle: c.classList.contains("pay-anneau__medicaux") ? "medicaux" : "droits",
			valeur: parseFloat(c.getAttribute("stroke-dasharray")) || 0,
			couleur: c.classList.contains("pay-anneau__medicaux") ? "var(--pay-or)" : "var(--pay-vert)",
		}));
		if (!(parts.length && monterDonut(anneau, { parts, piste: "var(--pay-piste)", taille: 140, rayon: 52, epaisseur: 18 }))) {
			gsap.to(cercles, { opacity: 1, duration: 0.8, stagger: 0.15 });
		}
		const tl = gsap.timeline({ delay: 0.13 });
		apparaitre(tl, $(".pay-anneau b", repartition), 0, 1.8, "power2.inOut");
		$$(".pay-repartition li", repartition).forEach((li, i) => {
			const t = i && parts[0] ? 1.8 * (parts[0].valeur / 100) : 0;
			apparaitre(tl, $("b", li), t, 1.2);
			apparaitre(tl, $("small", li), t, 1.2);
		});
	});

	/* ---------- 4 bis. Recouvrement par niveau : les jauges se remplissent,
	   puis la ligne du taux moyen se pose ---------- */
	const parNiveau = $(".pay-niveaux", paiements);
	quandVisible(parNiveau, () => {
		const tl = gsap.timeline({ delay: 0.1 });
		const colonnes = $$(".pay-niveau", parNiveau);
		colonnes.forEach((niveau, i) => {
			const t = i * 0.09;
			tl.to($$(".pay-niveau__jauge i", niveau), { scaleY: 1, duration: 1, ease: SORTIE, stagger: 0.18 }, t);
			apparaitre(tl, $(".pay-niveau__taux", niveau), t, 1.2);
		});
		tl.add(() => parNiveau.classList.add("est-mesure"), 0.6 + colonnes.length * 0.09);
	});

	/* ---------- 5. Écart de rapprochement : les chiffres comptent, les parts se remplissent ---------- */
	const rapprochement = $(".pay-rapprochement", paiements);
	quandVisible(rapprochement, () => {
		const tl = gsap.timeline({ delay: 0.1 });
		apparaitre(tl, $(".pay-rapprochement__montant b", rapprochement), 0, 1.5);
		apparaitre(tl, $(".pay-rapprochement__compte b", rapprochement), 0.15, 1);
		$$(".pay-ecart", rapprochement).forEach((ecart, i) => {
			const t = 0.25 + i * 0.08;
			tl.to($(".pay-ecart__barre i", ecart), { scaleX: 1, duration: 1, ease: SORTIE }, t);
			apparaitre(tl, $(".pay-ecart__montant", ecart), t, 1.1);
			apparaitre(tl, $(".pay-ecart__part", ecart), t + 0.1, 1.1);
		});
	});

	/* ---------- 6. Registre : les taux se remplissent, en vague ----------
	   Chaque ligne se remplit à son entrée à l'écran : aussi celles d'une
	   autre page du registre ou révélées par un filtre, à leur affichage. */
	$$(".pay-registre tbody tr", paiements).forEach((ligne) => {
		quandVisible(ligne, (rang) => {
			const tl = gsap.timeline({ delay: 0.1 + rang * 0.06 });
			tl.to($(".pay-taux > i > span", ligne), { scaleX: 1, duration: 1.1, ease: "power3.inOut" }, 0);
			apparaitre(tl, $(".pay-taux > b", ligne), 0, 1.1);
		});
	});
})();
