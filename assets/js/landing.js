/*
 * Page d'accueil : fiche établissement, copie du RIB et motion design
 * (GSAP + ScrollTrigger) :
 *   - entrée orchestrée du titre et de l'écran d'animation ;
 *   - apparitions au défilement ([data-apparition], [data-apparition-groupe]) ;
 *   - frise des étapes qui se remplit.
 * Sans JS ou avec « réduire les animations », la page est complète.
 */
(() => {
	"use strict";

	const $ = (s, r = document) => r.querySelector(s);
	const $$ = (s, r = document) => [...r.querySelectorAll(s)];

	/* ---------- Copie d'un RIB (annuaire, fiche) ---------- */
	async function copier(texte, bouton) {
		try {
			await navigator.clipboard.writeText(texte);
			bouton.textContent = "Copié ✓";
			bouton.classList.add("est-copie");
			setTimeout(() => { bouton.textContent = "Copier"; bouton.classList.remove("est-copie"); }, 2200);
		} catch {
			bouton.textContent = "Sélectionne le numéro";
		}
	}
	$$("[data-copier-rib]").forEach((b) => b.addEventListener("click", () => copier(b.dataset.copierRib, b)));

	/* ---------- Fiche établissement (RIB, contacts) ---------- */
	const fiche = $("#fiche-etab");
	const donnees = $("#donnees-fiches");
	if (fiche && donnees) {
		const fiches = JSON.parse(donnees.textContent);
		const remplir = (cible, valeur, courriel = false) => {
			cible.replaceChildren();
			if (!valeur) {
				const s = document.createElement("span");
				s.className = "texte-discret";
				s.textContent = "Bientôt disponible";
				cible.append(s);
			} else if (courriel) {
				const a = document.createElement("a");
				a.href = "mailto:" + valeur;
				a.textContent = valeur;
				cible.append(a);
			} else {
				cible.textContent = valeur;
			}
		};
		$$("[data-fiche]").forEach((bouton) => {
			bouton.addEventListener("click", () => {
				const e = fiches[bouton.dataset.fiche];
				$("[data-fiche-bande]", fiche).style.background = e.couleur;
				$("[data-fiche-logo]", fiche).src = e.logo;
				$("[data-fiche-nom]", fiche).textContent = e.fr;
				$("[data-fiche-en]", fiche).textContent = e.en;
				$("[data-fiche-rib]", fiche).textContent = e.rib;
				$("[data-fiche-bp]", fiche).textContent = e.bp;
				remplir($("[data-fiche-tel]", fiche), e.tel);
				remplir($("[data-fiche-email]", fiche), e.email, true);
				$("[data-copier]", fiche).textContent = "Copier";
				fiche.showModal();
			});
		});
		$("[data-copier]", fiche)?.addEventListener("click", (ev) => copier($("[data-fiche-rib]", fiche).textContent, ev.currentTarget));
		fiche.addEventListener("click", (ev) => { if (ev.target === fiche) fiche.close(); });
	}

	if (!window.gsap || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
		$$("[data-frise]").forEach((f) => f.style.setProperty("--progression", 1));
		return;
	}
	const { gsap, ScrollTrigger } = window;
	gsap.registerPlugin(ScrollTrigger);

	/* ---------- Entrée du titre et de l'écran d'animation ---------- */
	gsap.timeline({ defaults: { ease: "power4.out" } })
		.from("[data-intro-ligne]", { yPercent: 115, rotate: 2, duration: 1.1, stagger: 0.12 }, 0.2)
		.from("[data-intro]", { opacity: 0, y: 24, duration: 0.9, stagger: 0.1 }, 0.6)
		.from("[data-intro-visuel]", { opacity: 0, y: 70, scale: 0.94, rotateX: 10, transformPerspective: 1400, duration: 1.5 }, 0.5);

	/* ---------- Apparitions au défilement ---------- */
	$$("[data-apparition]").forEach((bloc) => {
		gsap.from(bloc, { opacity: 0, y: 36, duration: 0.9, ease: "power3.out", scrollTrigger: { trigger: bloc, start: "top 85%" } });
	});
	$$("[data-apparition-groupe]").forEach((groupe) => {
		gsap.from(groupe.children, { opacity: 0, y: 30, scale: 0.96, duration: 0.6, ease: "back.out(1.4)", stagger: { each: 0.07 }, scrollTrigger: { trigger: groupe, start: "top 82%" } });
	});

	/* ---------- Frise des étapes ---------- */
	$$("[data-frise]").forEach((frise) => {
		gsap.fromTo(frise, { "--progression": 0 }, { "--progression": 1, ease: "none", scrollTrigger: { trigger: frise, start: "top 75%", end: "bottom 55%", scrub: 0.6 } });
	});
})();
