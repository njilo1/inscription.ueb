/* Fiche d'un dossier, espace scolarité : une visionneuse par reçu (zoom,
 * rotation, déplacement), choix du reçu, points de contrôle, motifs de renvoi
 * et tampon frappé sur le reçu au moment de la décision. Un dossier peut
 * réunir deux paiements (droits universitaires, frais médicaux) : le sous-main
 * et la fiche de contrôle d'un même paiement partagent data-qf-paiement.
 * Sans JavaScript, chaque reçu reste visible avec « Ouvrir » et
 * « Télécharger », et les formulaires s'envoient tels quels. */
(() => {
	"use strict";
	const fiche = document.querySelector("[data-quitus-fiche]");
	if (!fiche) return;
	const $ = (selecteur, racine = fiche) => racine.querySelector(selecteur);
	const $$ = (selecteur, racine = fiche) => [...racine.querySelectorAll(selecteur)];
	const reduit = window.matchMedia("(prefers-reduced-motion: reduce)");
	const NIVEAUX = [1, 1.5, 2, 3, 4];
	const MAX = NIVEAUX[NIVEAUX.length - 1];

	/* ---------- Visionneuse des reçus d'un paiement ---------- */
	function visionneuse(platine) {
		const scenes = $$("[data-qf-recu]", platine);
		const outils = $("[data-qf-outils]", platine);
		const niveau = $("[data-qf-niveau]", platine);
		const annonce = $("[data-qf-annonce]", platine);
		let scene = null;
		let vue = { z: 1, r: 0, x: 0, y: 0 };

		const feuilleDe = (s) => $("[data-qf-feuille]", s);
		const estImage = (s) => s && !s.hasAttribute("data-pdf");

		/* Un quart de tour : la feuille est réduite pour tenir dans la scène. */
		function ajustement() {
			const feuille = feuilleDe(scene);
			if ((vue.r / 90) % 2 === 0 || !feuille.offsetWidth) return 1;
			return Math.min(1, (scene.clientWidth - 32) / feuille.offsetHeight, (scene.clientHeight - 32) / feuille.offsetWidth);
		}

		/* Le déplacement s'arrête quand le bord de la feuille atteint la scène. */
		function borner(echelle) {
			const feuille = feuilleDe(scene);
			const quart = (vue.r / 90) % 2 !== 0;
			const largeur = (quart ? feuille.offsetHeight : feuille.offsetWidth) * echelle;
			const hauteur = (quart ? feuille.offsetWidth : feuille.offsetHeight) * echelle;
			const mx = Math.max(0, (largeur - scene.clientWidth) / 2 + 24);
			const my = Math.max(0, (hauteur - scene.clientHeight) / 2 + 24);
			vue.x = Math.min(mx, Math.max(-mx, vue.x));
			vue.y = Math.min(my, Math.max(-my, vue.y));
		}

		function appliquer() {
			if (!estImage(scene)) return;
			if (vue.z <= 1) vue = { ...vue, z: 1, x: 0, y: 0 };
			const echelle = vue.z * ajustement();
			borner(echelle);
			const feuille = feuilleDe(scene);
			feuille.style.setProperty("--z", echelle);
			feuille.style.setProperty("--r", vue.r + "deg");
			feuille.style.setProperty("--x", vue.x + "px");
			feuille.style.setProperty("--y", vue.y + "px");
			scene.classList.toggle("est-agrandie", vue.z > 1);
			if (niveau) niveau.textContent = Math.round(vue.z * 100) + " %";
			$$("[data-qf-zoom]", platine).forEach((b) => { b.disabled = b.dataset.qfZoom === "1" ? vue.z >= MAX : vue.z <= 1; });
		}

		/* Zoom vers un point de la scène (cx, cy depuis son centre) : ce point reste sous le pointeur. */
		function zoomer(z, cx = 0, cy = 0) {
			const nouveau = Math.min(MAX, Math.max(1, z));
			const rapport = nouveau / vue.z;
			vue.x = cx - (cx - vue.x) * rapport;
			vue.y = cy - (cy - vue.y) * rapport;
			vue.z = nouveau;
			appliquer();
		}
		const palier = (sens) => {
			const suivant = sens > 0 ? NIVEAUX.find((n) => n > vue.z + 0.01) : [...NIVEAUX].reverse().find((n) => n < vue.z - 0.01);
			zoomer(suivant ?? vue.z);
		};
		const depuisCentre = (ev) => {
			const r = scene.getBoundingClientRect();
			return [ev.clientX - r.left - r.width / 2, ev.clientY - r.top - r.height / 2];
		};

		/* Choix du reçu affiché (plusieurs envois, anciens dossiers). */
		const choix = $$("[data-qf-choix]", platine);
		const legende = $("[data-qf-legende]", platine);
		const fichier = $("[data-qf-fichier]", platine);
		const ouvrir = $("[data-qf-ouvrir]", platine);
		const telecharger = $("[data-qf-telecharger]", platine);

		function afficher(id, annoncer = false) {
			const cible = scenes.find((s) => s.id === id) || scenes[0];
			if (!cible) return;
			scenes.forEach((s) => { s.hidden = s !== cible; });
			scene = cible;
			vue = { z: 1, r: 0, x: 0, y: 0 };
			appliquer();
			if (outils) outils.hidden = !estImage(cible);
			choix.forEach((lien) => {
				if (lien.hash === "#" + cible.id) lien.setAttribute("aria-current", "true");
				else lien.removeAttribute("aria-current");
			});
			if (legende) legende.textContent = cible.dataset.legende;
			if (fichier) {
				fichier.title = cible.dataset.fichier;
				$("span", fichier).textContent = cible.dataset.fichier;
			}
			if (ouvrir) ouvrir.href = cible.dataset.ouvrir;
			if (telecharger) telecharger.href = cible.dataset.telecharger;
			if (annoncer && annonce) annonce.textContent = "Reçu affiché : " + cible.dataset.legende;
		}

		if (!scenes.length) return { premier: () => {} };

		choix.forEach((lien) => lien.addEventListener("click", (ev) => {
			if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) return;
			ev.preventDefault();
			afficher(lien.hash.slice(1), true);
		}));

		$$("[data-qf-zoom]", platine).forEach((b) => b.addEventListener("click", () => palier(Number(b.dataset.qfZoom))));
		$("[data-qf-ajuster]", platine)?.addEventListener("click", () => { vue.r = 0; zoomer(1); });
		$("[data-qf-pivoter]", platine)?.addEventListener("click", () => { vue.r += 90; appliquer(); });

		scenes.filter(estImage).forEach((s) => {
			$("img", s)?.addEventListener("load", () => { if (s === scene) appliquer(); });

			/* Double-clic : agrandir à l'endroit visé, ou revenir à la vue entière. */
			s.addEventListener("dblclick", (ev) => {
				const [cx, cy] = depuisCentre(ev);
				zoomer(vue.z > 1 ? 1 : 2, cx, cy);
			});

			/* Pincement du pavé tactile ou Ctrl + molette : zoom progressif. */
			s.addEventListener("wheel", (ev) => {
				if (!ev.ctrlKey) return;
				ev.preventDefault();
				const [cx, cy] = depuisCentre(ev);
				zoomer(vue.z * Math.exp(-ev.deltaY * 0.0025), cx, cy);
			}, { passive: false });

			/* Glisser pour déplacer une feuille agrandie ; deux doigts pour zoomer. */
			const pointeurs = new Map();
			let depart = null;
			s.addEventListener("pointerdown", (ev) => {
				pointeurs.set(ev.pointerId, [ev.clientX, ev.clientY]);
				if (pointeurs.size === 2) {
					const [a, b] = [...pointeurs.values()];
					depart = { ecart: Math.hypot(a[0] - b[0], a[1] - b[1]), z: vue.z };
				} else if (vue.z > 1) {
					depart = { x: ev.clientX - vue.x, y: ev.clientY - vue.y };
					s.setPointerCapture(ev.pointerId);
					s.classList.add("est-saisie");
				}
			});
			s.addEventListener("pointermove", (ev) => {
				if (!pointeurs.has(ev.pointerId) || !depart) return;
				pointeurs.set(ev.pointerId, [ev.clientX, ev.clientY]);
				if (pointeurs.size === 2 && depart.ecart) {
					const [a, b] = [...pointeurs.values()];
					zoomer(depart.z * Math.hypot(a[0] - b[0], a[1] - b[1]) / depart.ecart);
				} else if (depart.x !== undefined) {
					vue.x = ev.clientX - depart.x;
					vue.y = ev.clientY - depart.y;
					appliquer();
				}
			});
			const relacher = (ev) => {
				pointeurs.delete(ev.pointerId);
				if (!pointeurs.size) { depart = null; s.classList.remove("est-saisie"); }
			};
			s.addEventListener("pointerup", relacher);
			s.addEventListener("pointercancel", relacher);

			/* Clavier, la scène ayant le focus : + − 0, R, flèches. */
			s.addEventListener("keydown", (ev) => {
				const pas = 48;
				const actions = {
					"+": () => palier(1), "=": () => palier(1), "-": () => palier(-1),
					"0": () => { vue.r = 0; zoomer(1); },
					r: () => { vue.r += 90; appliquer(); }, R: () => { vue.r += 90; appliquer(); },
					ArrowLeft: () => { vue.x += pas; appliquer(); }, ArrowRight: () => { vue.x -= pas; appliquer(); },
					ArrowUp: () => { vue.y += pas; appliquer(); }, ArrowDown: () => { vue.y -= pas; appliquer(); },
				};
				const action = actions[ev.key];
				if (!action || ev.ctrlKey || ev.metaKey || ev.altKey) return;
				if (ev.key.startsWith("Arrow") && vue.z <= 1) return; /* la page défile */
				ev.preventDefault();
				action();
			});
		});

		window.addEventListener("resize", () => appliquer());
		window.addEventListener("hashchange", () => {
			if (scenes.some((s) => "#" + s.id === location.hash)) afficher(location.hash.slice(1), true);
		});
		const initial = scenes.find((s) => "#" + s.id === location.hash);
		afficher(initial ? initial.id : scenes[0].id);
		if (outils && estImage(scene)) outils.hidden = false;

		return { premier: () => afficher(scenes[0].id) };
	}

	/* ---------- Fiche de contrôle d'un paiement ---------- */
	const fenetre = document.getElementById("fenetre-confirmation");
	function controle(aside, platine, vision) {
		const points = $$("[data-qf-point]", aside);
		const progression = $("[data-qf-progression]", aside);
		const valider = $('form[data-qf-tamponner="verifie"]', aside);
		const texteValider = valider?.dataset.confirmer ?? "";
		if (points.length && progression) {
			progression.hidden = false;
			const jauge = $("[data-qf-jauge]", aside);
			const compte = $("[data-qf-compte]", aside);
			const total = points.length;
			const mettreAJour = () => {
				const n = points.filter((p) => p.checked).length;
				const reste = total - n;
				jauge.style.setProperty("--p", n / total);
				compte.textContent = reste ? `${n} sur ${total} points contrôlés` : "Tout concorde : tu peux valider le paiement.";
				aside.classList.toggle("est-complete", !reste);
				/* La confirmation rappelle les points restés sans coche. */
				if (valider) {
					valider.dataset.confirmer = reste
						? `${reste === 1 ? "Un point de contrôle n’est pas coché" : reste + " points de contrôle ne sont pas cochés"}. ${texteValider}`
						: texteValider;
				}
			};
			points.forEach((p) => p.addEventListener("change", mettreAJour));
			mettreAJour();
		}

		/* Le tampon frappe le reçu de ce paiement, puis la décision s'enregistre.
		   La fenêtre de confirmation (app.js) passe d'abord ; une fois acceptée,
		   le formulaire est renvoyé : on joue alors le tampon Remotion sur le reçu
		   le plus récent, et l'envoi part à la fin de la frappe. */
		$$("form[data-qf-tamponner]", aside).forEach((form) => {
			form.addEventListener("submit", (ev) => {
				if (ev.defaultPrevented || form.dataset.tamponne === "1") return;
				if (form.dataset.confirmer && form.dataset.confirme !== "1" && fenetre?.showModal) return;
				const hote = platine && $(`[data-qf-tampon="${form.dataset.qfTamponner}"]`, platine);
				const animation = hote && $("[data-remotion-differe]", hote);
				if (!animation || reduit.matches || typeof window.uebMonterAnimation !== "function") return;
				ev.preventDefault();
				form.dataset.tamponne = "1";
				form.querySelector("button[type=submit]")?.setAttribute("aria-busy", "true");

				vision?.premier();
				$$("[data-qf-tampon-pose], [data-qf-tampon]", platine).forEach((t) => { t.hidden = t !== hote; });
				animation.dataset.remotion = animation.dataset.remotionDiffere;
				window.uebMonterAnimation(animation);

				/* Le bouton est souvent loin du reçu : on ramène le tampon à l'écran. */
				const zone = hote.getBoundingClientRect();
				const horsEcran = zone.top < 0 || zone.bottom > window.innerHeight;
				if (horsEcran) hote.scrollIntoView({ behavior: "smooth", block: "center" });
				window.setTimeout(() => form.requestSubmit(), horsEcran ? 1700 : 1200);
			});
		});
	}

	const visions = new Map();
	$$("[data-qf-platine]").forEach((platine) => visions.set(platine.dataset.qfPaiement, { platine, vision: visionneuse(platine) }));
	$$("[data-qf-controle]").forEach((aside) => {
		const lien = visions.get(aside.dataset.qfPaiement);
		controle(aside, lien?.platine, lien?.vision);
	});

	/* ---------- Motifs de renvoi en un clic ---------- */
	$$("[data-qf-motifs]").forEach((bloc) => {
		const zone = bloc.closest("form")?.querySelector("textarea");
		if (!zone) return;
		const boutons = $$("[data-qf-motif]", bloc);
		bloc.hidden = false;
		boutons.forEach((b) => {
			b.setAttribute("aria-pressed", String(zone.value === b.dataset.qfMotif));
			b.addEventListener("click", () => {
				zone.value = b.dataset.qfMotif;
				boutons.forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
				zone.focus();
				zone.setSelectionRange(zone.value.length, zone.value.length);
			});
		});
		zone.addEventListener("input", () => boutons.forEach((x) => x.setAttribute("aria-pressed", String(zone.value === x.dataset.qfMotif))));
	});
})();
