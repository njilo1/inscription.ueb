# Accueil — Variante Ebolowa

Modèle parallèle, sans modification de l’accueil actuel et sans écriture de configuration ou création de page WordPress.

## Prévisualisation

Sur l’installation locale :

http://localhost/inscription-ueb/wp-content/themes/inscription-ueb/variante-accueil/

`index.php` charge WordPress depuis son emplacement relatif, puis affiche `modele.php`. L’aperçu porte les directives `noindex,nofollow` et n’est lié à aucun menu existant. Les boutons de compte pointent vers les vrais écrans du site.

## Contenu

- `modele.php` : modèle WordPress autonome « Accueil — Variante Ebolowa ».
- `style.css` : styles limités à `.ueb-v2`, palette et polices du thème.
- `animation.js` : apparition du titre et des sections, navigation mobile, recherche combinée aux villes, copie des RIB avec solution de repli.
- Animation Remotion : réutilisation du fichier compilé existant, de la composition `parcours` et de `ueb_props_parcours()` ; aucune modification de ses sources.
- Données : `ueb_landing_donnees()`, année académique et état de connexion réels.

Sans JavaScript, tous les établissements, coordonnées, questions et liens restent consultables. Les filtres, boutons de copie et menu repliable s’activent seulement avec JavaScript. La préférence de réduction des animations est respectée. Les coordonnées et questions utilisent des éléments `details` natifs.

## Direction visuelle

Structure guidée par ui-ux-pro-max : accueil, accès rapides, quatre étapes, annuaire, campus, aide, action finale. Le catalogue ne possède pas de stack WordPress/PHP : application des règles HTML/CSS et d’accessibilité générales, sans introduction de framework.

Exécution guidée par frontend-design : grand titre Source Serif 4, interface Source Sans 3, vert forêt `#0f2c1f`, vert profond `#0a1f16`, vert `#1d5a38`, or `#e3a822`, papier `#f4f7f3`, blanc `#ffffff`. Signature : le lecteur Remotion encadré comme un document à coupons. Photographies existantes du campus et de l’amphithéâtre.

## Activation ultérieure

La variante n’est pas activée. Après validation explicite, son modèle pourra être utilisé par `front-page.php`. La présence du fichier `front-page.php` actuel lui donne priorité sur l’affectation d’un modèle dans l’administration : choisir ce modèle dans WordPress ne suffit donc pas à remplacer l’accueil. Retirer alors le lien de comparaison et réexaminer les directives d’indexation. Ne rien activer avant validation.
