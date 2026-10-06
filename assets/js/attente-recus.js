/*
 * Tableaux de bord de la scolarité et du CMS : la carte rouge des reçus en
 * attente de validation et la file des plus anciens restent à jour.
 *
 * Toutes les 30 s, et dès que l'agent revient sur l'onglet, une requête en
 * lecture seule (admin-ajax.php, action « ueb_attente_recus »,
 * inc/attente-recus.php) renvoie la carte et la file recalculées. Rien ne
 * bouge si rien n'a changé. Quand un reçu arrive : la carte et sa mini-courbe
 * se mettent à jour, une pastille « +1 nouveau » s'affiche, l'onglet du
 * navigateur porte le nombre en attente et un message est lu par les
 * lecteurs d'écran.
 */
(() => {
	"use strict";

	const PERIODE = 30000;
	const titre = document.title;
	const cartes = document.querySelectorAll("[data-attente]");
	if (!cartes.length) return;

	/* Un seul message d'état pour la page, en dehors des cartes remplacées. */
	const annonce = document.createElement("p");
	annonce.className = "sr";
	annonce.setAttribute("role", "status");
	annonce.setAttribute("aria-atomic", "true");
	document.body.append(annonce);

	const remplacer = (ancien, html) => {
		const modele = document.createElement("template");
		modele.innerHTML = html.trim();
		const neuf = modele.content.firstElementChild;
		if (neuf) ancien.replaceWith(neuf);
		return neuf || ancien;
	};
	const pluriel = (n, un, plusieurs) => `${n} ${n > 1 ? plusieurs : un}`;

	const surveiller = (depart) => {
		let carte = depart;
		const type = carte.dataset.attente;
		const source = carte.dataset.attenteSource;
		let etat = { nombre: Number(carte.dataset.attenteNombre) || 0, dernier: carte.dataset.attenteDernier || "" };
		let enCours = false;

		const marquerOnglet = () => {
			document.title = (etat.nombre ? `(${etat.nombre}) ` : "") + titre;
		};

		const actualiser = async () => {
			if (enCours || document.hidden) return;
			enCours = true;
			try {
				const url = new URL(source, location.href);
				if (etat.dernier) url.searchParams.set("vu", etat.dernier);
				const reponse = await fetch(url, { credentials: "same-origin", headers: { Accept: "application/json" } });
				if (!reponse.ok) return;
				const { success, data } = await reponse.json();
				if (!success || (data.nombre === etat.nombre && data.dernier === etat.dernier)) return;

				carte = remplacer(carte, data.carte);
				/* L'ouverture du tableau cache nombres et tracés jusqu'à leur animation :
				   la carte redessinée s'affiche telle quelle. */
				carte.querySelectorAll(".adm-kpi__valeur, .adm-spark figcaption b").forEach((el) => { el.style.opacity = "1"; });
				carte.querySelectorAll(".adm-spark__zone svg").forEach((el) => { el.style.clipPath = "none"; });
				const courbe = carte.querySelector("[data-mini-courbe]");
				if (courbe && typeof window.uebActiverMiniCourbe === "function") window.uebActiverMiniCourbe(courbe);
				const file = document.querySelector(`[data-attente-file="${type}"]`);
				if (file && data.file) remplacer(file, data.file);

				const nouveaux = data.nombre ? data.nouveaux : 0;
				if (nouveaux > 0) {
					const pastille = document.createElement("span");
					pastille.className = "adm-attente__nouveaux";
					pastille.textContent = `+${pluriel(nouveaux, "nouveau", "nouveaux")}`;
					carte.querySelector(".adm-kpi__valeur")?.append(pastille);
				}
				annonce.textContent = nouveaux > 0
					? `${pluriel(nouveaux, "nouveau reçu", "nouveaux reçus")} en attente de validation, ${data.nombre} au total.`
					: data.nombre
						? `${pluriel(data.nombre, "reçu", "reçus")} en attente de validation.`
						: "Aucun reçu en attente de validation.";
				etat = { nombre: data.nombre, dernier: data.dernier };
				marquerOnglet();
			} catch (e) {
				/* Réseau indisponible : nouvel essai au prochain tour. */
			} finally {
				enCours = false;
			}
		};

		marquerOnglet();
		setInterval(actualiser, PERIODE);
		document.addEventListener("visibilitychange", () => {
			if (!document.hidden) actualiser();
		});
	};

	cartes.forEach(surveiller);
})();
