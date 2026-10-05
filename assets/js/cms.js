/*
 * Centre médico-social, page Sécurité : les règles du nouveau mot de passe se
 * cochent pendant la saisie. Ce sont celles du serveur
 * (ueb_erreur_mot_de_passe()) : 8 caractères, une lettre, un chiffre, sans
 * l'identifiant du compte. Le serveur revalide à l'envoi.
 */
(() => {
	"use strict";
	document.querySelectorAll("[data-regles-mdp]").forEach((liste) => {
		const champ = document.getElementById(liste.dataset.reglesMdp);
		if (!champ) return;
		const identifiant = (liste.dataset.identifiantCompte || "").toLowerCase();
		const regles = {
			longueur: (v) => [...v].length >= 8,
			lettre: (v) => /\p{L}/u.test(v),
			chiffre: (v) => /\d/.test(v),
			identifiant: (v) => !identifiant || !v.toLowerCase().includes(identifiant),
		};
		const verifier = () => {
			const v = champ.value;
			liste.querySelectorAll("[data-regle]").forEach((li) => {
				const ok = regles[li.dataset.regle]?.(v) ?? false;
				/* Avant la saisie, rien n'est rouge : seules les règles tenues se cochent. */
				li.classList.toggle("est-ok", v !== "" && ok);
				li.classList.toggle("est-ko", v !== "" && !ok);
				const etat = li.querySelector("[data-regle-etat]");
				if (etat) etat.textContent = v === "" ? "" : ok ? " : respectée" : " : pas encore respectée";
			});
		};
		champ.addEventListener("input", verifier);
		verifier();
	});
})();
