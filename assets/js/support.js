/* Page support : valide le formulaire, prépare le message et ouvre
   WhatsApp (lien wa.me). Aucune donnée n'est envoyée au serveur. */
(function () {
	'use strict';

	var form = document.querySelector('[data-support]');
	if (!form) { return; }

	var ok = document.querySelector('[data-support-ok]');
	var lien = document.querySelector('[data-support-lien]');
	var numero = (form.getAttribute('data-whatsapp') || '').replace(/\D/g, '');

	function champDe(nom) { return form.elements[nom]; }

	function effacer(champ) {
		var boite = champ.closest('.champ');
		if (!boite) { return; }
		boite.classList.remove('champ--invalide');
		champ.removeAttribute('aria-invalid');
		var p = boite.querySelector('.champ__erreur');
		if (p) { p.remove(); }
	}

	function signaler(champ, message) {
		effacer(champ);
		var boite = champ.closest('.champ');
		boite.classList.add('champ--invalide');
		champ.setAttribute('aria-invalid', 'true');
		var p = document.createElement('p');
		p.className = 'champ__erreur';
		p.textContent = message;
		boite.appendChild(p);
	}

	['nom', 'probleme', 'description'].forEach(function (nom) {
		var c = champDe(nom);
		if (c) {
			c.addEventListener('input', function () { effacer(c); });
			c.addEventListener('change', function () { effacer(c); });
		}
	});

	form.addEventListener('submit', function (e) {
		e.preventDefault();

		var nom = champDe('nom').value.trim();
		var probleme = champDe('probleme').value;
		var description = champDe('description').value.trim();
		var premier = null;

		if (!probleme) { signaler(champDe('probleme'), 'Choisis le type de problème.'); premier = premier || champDe('probleme'); }
		if (!description) { signaler(champDe('description'), 'Écris quelques mots sur ton problème.'); premier = premier || champDe('description'); }
		if (premier) { premier.focus(); return; }

		var lignes = [
			'Bonjour,',
			'',
			(nom ? 'Je m\u2019appelle ' + nom + '. J\u2019ai' : 'J\u2019ai') + ' un problème sur la plateforme d\u2019inscription de l\u2019Université d\u2019Ebolowa.',
			'',
			'*Type de problème :* ' + probleme
		];
		lignes.push('', description, '', 'Merci de bien vouloir m\u2019aider.');

		var url = 'https://wa.me/' + numero + '?text=' + encodeURIComponent(lignes.join('\n'));
		window.open(url, '_blank', 'noopener');

		if (lien) { lien.href = url; }
		if (ok) {
			ok.hidden = false;
			ok.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	});
})();
