# Demande reformulée : inscription, paiement et documents UEb

Adapter le formulaire et les PDF de l'inscription universitaire selon les règles suivantes.

1. Reconnaître automatiquement les étudiants ayant un dossier de préinscription soumis pour l'année en cours. Les positionner sur « Nouvel étudiant — préinscrit cette année », préremplir leurs informations et proposer les trois filières de leur dossier dans un menu déroulant. Ils ne paient pas de nouveaux frais médicaux.
2. Pour les anciens, proposer les filières actives de l'établissement choisi et distinguer « Réinscription sans interruption » (3 000 FCFA de frais médicaux) et « Reprise après réactivation du matricule » (5 000 FCFA). Ces montants sont fixes et dus en une seule fois par année académique.
3. Pour toute filière classique, fixer les droits annuels à 50 000 FCFA : première tranche de 25 000 FCFA, ou les deux tranches de 50 000 FCFA. Proposer ensuite la deuxième tranche de 25 000 FCFA lorsque la première possède déjà un quitus. Déterminer le type classique ou professionnel à partir du catalogue, jamais à partir d'une déclaration du navigateur. Conserver la saisie du tarif communiqué par l'établissement pour les formations professionnelles.
4. Remplacer le niveau libre par un menu. En l'absence de précision à la question « M1–M2 uniquement ou L1–M2 ? », la liste retenue provisoirement est L1, L2, L3, M1 et M2 afin d'accueillir également les nouveaux préinscrits en licence.
5. Afficher un total discret, actualisé à chaque choix et détaillant droits universitaires et frais médicaux. Les champs à tarif fixe restent non modifiables. Recalculer les montants côté serveur.

| Situation en formation classique | Première tranche + frais médicaux annuels | Deux tranches + frais médicaux annuels | Deuxième tranche suivante |
| --- | ---: | ---: | ---: |
| Nouveau préinscrit de l'année | 25 000 FCFA | 50 000 FCFA | 25 000 FCFA |
| Réinscription sans interruption | 28 000 FCFA | 53 000 FCFA | 25 000 FCFA |
| Reprise après réactivation du matricule | 30 000 FCFA | 55 000 FCFA | 25 000 FCFA |

6. Enregistrer ensemble les droits et, si nécessaire, le quitus médical annuel. Conserver des numéros, comptes bancaires et suivis de reçus distincts. Empêcher les doubles soumissions et la création d'un nouveau quitus pour une tranche déjà préparée. Un reçu rejeté se corrige sur le quitus existant et ne justifie pas de nouveaux frais.
7. Produire, pour le premier dossier d'un ancien soumis à la visite annuelle, un seul PDF de quatre pages : quitus des droits universitaires, quitus des frais médicaux, fiche CMS d'identification et fiche d'examen médical. Reprendre les modèles des pages 2 et 3 de la préinscription. Pour les nouveaux préinscrits et pour la deuxième tranche suivante, produire uniquement le quitus universitaire d'une page. Chaque page de quitus conserve ses quatre coupons. Le téléchargement séparé du quitus médical comprend ses deux fiches CMS, soit trois pages.
8. Inscrire en bas des deux fiches CMS : « @NexusCore: Nous developpons vos solutions informatiques (676295488/659490221/693899150/673414381). » Les rubriques CMS absentes du compte étudiant sont laissées à compléter sur papier.
9. Ne pas assimiler la génération d'un quitus à un paiement vérifié. Si le quitus médical annuel existe déjà, ne pas ajouter ses frais à une nouvelle tranche et rappeler à l'étudiant de régler ou faire vérifier ce quitus. Un nouveau règlement médical est nécessaire à la nouvelle année académique.
10. Sur chaque coupon universitaire ou médical, utiliser la couleur d'identité de l'établissement pour tout l'en-tête bilingue : République, devise, université et établissement. Le QR code contient directement les informations imprimées de l'étudiant (identifiant, nom complet, naissance, sexe, nationalité, filière et niveau), l'établissement, l'année et les références du paiement. Il se lit hors ligne ; le suivi du paiement reste accessible dans l'espace étudiant et les anciens liens de vérification continuent de fonctionner.
11. Afficher directement les trois coordonnées du contact d'urgence. Exiger les coordonnées CMS uniquement lorsqu'une fiche CMS est générée ; elles restent facultatives pour les droits seuls, notamment les nouveaux et la deuxième tranche suivante. Accepter le téléphone d'urgence avec ou sans l'indicatif +237. Après un échec, conserver la saisie et présenter chaque erreur dans une liste de liens vers les champs concernés, avec son message près du champ.

## Espace étudiant et documents

- Après un enregistrement réussi, le retour dans « Mon espace » lance le téléchargement du PDF une seule fois. Le bouton de téléchargement reste accessible en cas de blocage du navigateur ou de problème réseau.
- Le module « Mes quitus » contient une carte par dossier : les droits et le médical associé partagent cette carte et leur PDF de quatre pages. La deuxième tranche et les droits seuls restent des dossiers d’une page. Les anciens quitus médicaux autonomes sont conservés.
- Chaque carte affiche l’établissement, les références, la date, la filière, le niveau, la tranche, les montants et les statuts des paiements. Elle propose Télécharger, Modifier (tant qu’aucun reçu n’est envoyé et pour l’année en cours) et Envoyer mon reçu / Mes reçus. La page des reçus permet de choisir entre les deux paiements.
- Les années sont séparées et les archives peuvent être dépliées. Le 1er septembre, l’année académique active change selon la règle existante : une nouvelle inscription est possible, avec les nouveaux frais annuels, sans supprimer les anciennes. Le niveau de chaque dossier est conservé.
- À l’arrivée suivant la création du compte, une fenêtre rappelle de ne jamais partager le mot de passe. Un bandeau dans l’en-tête des pages étudiantes reprend ce conseil et indique de contacter la cellule informatique de l’établissement en cas de problème. Son défilement peut être mis en pause ; les préférences de réduction des animations sont respectées.

## Vérification locale

`/opt/lampp/bin/php tests/inscription.php` utilise des tables MySQL temporaires masquant les tables métier, avec des étudiants fictifs. WordPress est chargé en `SHORTINIT` : les hooks du thème et les migrations réelles ne sont pas exécutés par ces tests. Les PDF et aperçus HTML sont écrits dans `/tmp/ueb-inscription-review`.

`python3 tests/inscription-ui.py` contrôle ces aperçus dans Chromium : totaux, sélection des filières et des vœux, champs verrouillés, affichage de 320 à 1 280 px et réduction des animations. Les scénarios de `tests/espace_ui.py` vérifient aussi les cartes, archives, reçus, popup, navigation au clavier, pause du bandeau et téléchargements automatiques/manuels via un serveur local de fichiers fictifs.

`/opt/lampp/bin/php tests/quitus-pdf.php` génère les deux quitus des neuf établissements et un cas d'identité longue dans `/tmp/ueb-quitus-qr-review`, sans base de données. `python3 tests/quitus-pdf.py` contrôle la couleur des en-têtes français/anglais et décode les quatre QR de chaque page rendue à 300 dpi (dépendances : `pypdf`, `pypdfium2`, `Pillow`, `zxing-cpp`).
