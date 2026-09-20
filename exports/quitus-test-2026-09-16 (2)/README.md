# Exemples de quitus — neuf établissements

18 PDF individuels : **9 droits universitaires + 9 frais médicaux**, une page A4 et quatre coupons par PDF.

Ouvrir [tous les quitus dans un seul PDF](TOUS-LES-QUITUS-18-PAGES.pdf). Les pages alternent droits universitaires puis frais médicaux pour chaque établissement ; le PDF comporte des signets.

Aperçus comparatifs : [droits universitaires](apercus/droits-universitaires.jpg) · [frais médicaux](apercus/frais-medicaux.jpg).

Année académique : **2026-2027**. Modèles et logos du thème existant, avec une mention discrète « EXEMPLE — DONNÉES DE TEST » dans la marge basse.

## Documents

| Établissement | Quitus des droits | Quitus médical | Dossier DEMO source | Origine de la formation |
| --- | --- | --- | --- | --- |
| FS | [Droits universitaires](droits-universitaires/FS-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/FS-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000001 | Dossier et filière de cet établissement |
| FSJP | [Droits universitaires](droits-universitaires/FSJP-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/FSJP-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000014 | Dossier et filière de cet établissement |
| FSEG | [Droits universitaires](droits-universitaires/FSEG-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/FSEG-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000006 | Dossier et filière de cet établissement |
| FALSH | [Droits universitaires](droits-universitaires/FALSH-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/FALSH-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000004 | Dossier et filière de cet établissement |
| FMSP | [Droits universitaires](droits-universitaires/FMSP-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/FMSP-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000002 | Identité reprise de FS ; filière illustrative |
| ENSET | [Droits universitaires](droits-universitaires/ENSET-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/ENSET-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000003 | Identité reprise de FS ; filière illustrative |
| ISABEE | [Droits universitaires](droits-universitaires/ISABEE-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/ISABEE-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000005 | Identité reprise de FALSH ; filière illustrative |
| ESTLC | [Droits universitaires](droits-universitaires/ESTLC-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/ESTLC-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000007 | Identité reprise de FALSH ; filière illustrative |
| ENSTMO | [Droits universitaires](droits-universitaires/ENSTMO-quitus-droits-universitaires.pdf) | [Frais médicaux](frais-medicaux/ENSTMO-quitus-frais-medicaux.pdf) | DEMO-UEB-2026-000008 | Identité reprise de FS ; filière illustrative |

## Données et scénarios

Les neuf identités proviennent de dossiers `DEMO-UEB-2026-*` déjà présents dans `ueb_preinscriptions`. La base ne contient des dossiers et filières que pour FS, FSJP, FSEG et FALSH. FMSP, ENSET, ISABEE, ESTLC et ENSTMO reprennent cinq autres identités DEMO ; leur département est explicitement « Filière de démonstration ».

Les droits illustrent une première tranche de 25 000 FCFA ou les deux tranches de 50 000 FCFA. Ces montants viennent de la configuration des formations classiques ; pour les cinq établissements sans catalogue en base, ils servent uniquement à illustrer la mise en page et ne constituent pas un tarif d'établissement.

Les montants médicaux illustrent les scénarios configurés de réinscription sans interruption (3 000 FCFA) et de reprise (5 000 FCFA). Ce sont des simulations visuelles à partir des identités DEMO de préinscription : les nouveaux préinscrits ne doivent normalement pas repayer de frais médicaux.

Seules les pages de quitus sont exportées ici. Les deux fiches CMS que le téléchargement médical du site ajoute habituellement ne font pas partie de ce recueil comparatif.

La base est consultée dans une transaction en lecture seule. Aucun compte, quitus, paiement ni compteur n'est créé ou modifié. Les numéros `DEMO-*` et leurs QR codes ne sont pas enregistrés pour la vérification en ligne. Le fichier [manifest.json](manifest.json) détaille l'origine et les paramètres de chaque exemple.
