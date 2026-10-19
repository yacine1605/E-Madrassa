# Revue du lot 3 : finance

Le lot 3 (finance) ne sort qu'avec deux validations humaines (SRS 1.2, critères de sortie) :

1. **le rapprochement d'un mois validé par la finance** : une personne de la finance vérifie qu'il dit ce qu'elle attend ;
2. **les reçus arabes relus** par un lecteur natif.

Ce dossier les prépare. Toutes les données sont des exemples : école, élèves, personnel et montants sont fictifs.

## Préparer la revue

Sur le poste qui a Docker et le dépôt :

```sh
cd platform
sh deploy/review-lot3.sh        # installation neuve, environ 5 minutes
sh deploy/review-lot3.sh stop   # à la fin, la retire avec ses données
```

Le script installe la plateforme à part (https://localhost:18943/school) avec l'école « École Les Oliviers » et quatre comptes. Il saisit ensuite un mois complet par l'API, chaque opération étant faite par la personne qui la ferait réellement. Le navigateur signale le certificat local : il faut l'accepter. Le script produit dans `platform/.review/` :

| Fichier | Contenu |
|---|---|
| `comptes.txt` | les quatre comptes, leur mot de passe et le secret MFA à ajouter dans une application d'authentification |
| `fiche-revue.md` | les opérations saisies avec leurs références et leurs dates, puis le rapprochement attendu à côté de celui que calcule l'application |
| `recus/` | huit reçus PDF : sept en arabe, un en français, et un duplicata |
| `ecrans/` | les écrans de la finance en français et en arabe |
| `rapprochement.json` | les rapprochements bruts |

Le script s'arrête en erreur si un seul chiffre diffère de l'attendu.

## Les comptes

| Compte | Rôle | Ce qu'il fait dans le mois |
|---|---|---|
| `direction@oliviers.example` | Direction, administrateur | approuve les remises et le remboursement, valide les dépenses et les rémunérations, valide la reprise d'un versement |
| `comptable@oliviers.example` | Comptable | saisit les paiements, les dépenses et leurs règlements, les rémunérations et leurs versements ; clôture le mois |
| `tresorier@oliviers.example` | Comptable (second) | valide les paiements saisis par le comptable : personne ne valide sa propre saisie |
| `enseignant@oliviers.example` | Enseignant | lit sa propre rémunération (« Ma rémunération ») |

## Le mois revu

Il s'agit du **mois précédent** celui de la génération : M dans la suite. Les jours indiqués sont les jours de M. Le programme « 4e année moyenne » coûte 120 000 DA.

### Encaissements

| Élève | Opérations | Reste dû |
|---|---|---:|
| Amine Benali | 40 000 en espèces le 3 ; 30 000 par chèque le 16 | 50 000 |
| Yasmine Kaci | remise « Bourse au mérite » de 15 000, approuvée par la direction (au-delà du seuil de 10 %) ; 105 000 par virement le 6 | 0 |
| Ilyes Mansouri | 120 000 par virement le 5 ; remise « Réduction fratrie » de 20 000 ; trop-perçu remboursé en espèces le 21 | 0 |
| Sara Hamidi | 30 000 en espèces le 10 | 90 000 |
| Nadir Ouali | 50 000 en espèces le 12, contre-passé ce mois-ci (voir plus bas) | 120 000 |

### Dépenses

| Catégorie | Dépense | Engagé | Réglé dans M |
|---|---|---:|---:|
| Loyer | Loyer du mois, SCI Les Oliviers, le 2 | 80 000 | 80 000 par virement le 5 |
| Équipement | Vidéoprojecteur, le 14 | 65 000 | 40 000 en espèces le 18 (reste 25 000) |
| Publicité | Affiches de rentrée, le 8 | 12 000 | 12 000 en espèces le 9 |
| Salaires | écrites par les versements ci-dessous, jamais saisies à la main | 83 000 | 83 000 |

### Rémunérations

| Membre | Rémunération de M | Versements dans M |
|---|---:|---|
| Karim Meziane, enseignant | 45 000 | 45 000 par virement le 28 |
| Omar Belkacem, administratif | 38 000 | 20 000 en espèces le 15, 18 000 en espèces le 28 |

### Rapprochement attendu de M

| Ligne | Attendu | D'où il vient |
|---|---:|---|
| Paiements | 375 000 | espèces 120 000 (3), chèque 30 000 (1), virement 225 000 (2) |
| Contre-passations | 0 | celle de Nadir est datée du mois en cours |
| Remboursements | 20 000 | Ilyes, en espèces |
| **Net encaissé** | **355 000** | 375 000 − 20 000 |
| Dépenses engagées | 240 000 | loyer 80 000 + équipement 65 000 + publicité 12 000 + salaires 83 000 |
| Dépenses réglées | 215 000 | virement 125 000 (loyer, Karim) + espèces 90 000 (vidéoprojecteur, affiches, Omar ×2) |
| Versements de rémunérations | 83 000 | 3 versements |
| Salaires engagés | 83 000 | **égaux aux versements** (TST-13) |
| **Solde de trésorerie** | **140 000** | 355 000 − 215 000 |

### Ce mois-ci, après coup

- Le paiement de Nadir avait été saisi pour le mauvais élève. Le comptable le contre-passe et le trésorier valide la contre-passation.
- Le virement de Karim est revenu de la banque. Le comptable demande la reprise du versement et la direction la valide. La dépense « Salaires » correspondante est annulée dans la même opération, puis Karim est payé à nouveau.

Une reprise est datée du jour où elle est décidée, donc **M ne change pas**. Le mois en cours montre −50 000 au net encaissé, ainsi qu'un versement et sa reprise de 45 000 : les salaires engagés y restent égaux aux versements (0).

## Ce que la finance vérifie

Se connecter avec le compte du comptable, puis :

1. **Chaque élève** (Inscriptions > fiche > Finance) : dû, payé, reste, état et historique sont ceux du tableau des encaissements.
2. **Impayés** : Amine (50 000), Sara (90 000) et Nadir (120 000) y figurent, chacun avec son reste dû. L'échéancier proposé n'a qu'une échéance, au jour de la génération : aucune n'est donc encore en retard.

   Pour Yasmine et Ilyes, une remise a été accordée après l'échéancier. Leur fiche signale donc « échéancier à revoir » : c'est voulu, et la finance peut enregistrer une nouvelle version de l'échéancier.
3. **Dépenses** et **Paiements des dépenses** : le vidéoprojecteur est « partiellement payé » ; les dépenses « Salaires » ne peuvent être ni modifiées, ni payées, ni annulées à la main.
4. **Rémunérations** : les deux rémunérations de M sont « payées ». La fiche de Karim montre le versement repris et le nouveau versement.
5. **Clôture des périodes** > Clôturer > mois M : la fenêtre affiche le rapprochement. **Le comparer au tableau ci-dessus, puis clôturer.**
6. Après la clôture : tenter un paiement daté de M doit être refusé (« période clôturée »). Un paiement daté d'aujourd'hui reste accepté.
7. Avec le compte de la direction : une réouverture exige un motif et reste tracée dans le journal.

Questions à trancher par la finance, à noter dans le procès-verbal :

- Les rubriques du rapprochement sont-elles celles qu'elle attend, et dans cet ordre ?
- Une reprise datée du jour où elle est décidée (et non du mois de l'opération reprise) lui convient-elle ?
- Le solde de trésorerie (encaissé − réglé) suffit-il, ou faut-il une ventilation par caisse ou par compte bancaire ?

## Ce que le lecteur arabe vérifie (`recus/`)

Pour chacun des sept reçus arabes :

- [ ] lettres liées, aucune lettre isolée ni carré vide ;
- [ ] lecture de droite à gauche ; les montants, dates et références restent lisibles et dans le bon ordre (`REC-…`, `PAY-…`, `VIR 2026-118`) ;
- [ ] nom de l'élève en arabe d'abord, puis en latin ; libellé du programme « السنة الرابعة متوسط » ;
- [ ] libellés : « وصل دفع », « وصل استرداد », « رقم الوصل », « التلميذ », « البرنامج », « البيان », « المبلغ » ;
- [ ] moyen de paiement traduit (espèces, chèque, virement) ;
- [ ] « الباقي للدفع بعد هذه العملية » juste ; sur le reçu de remboursement, le reste est bien celui d'après le remboursement ;
- [ ] le duplicata (`08-…`) est identique au `01-…`, avec la mention « نسخة طبق الأصل » et le filigrane.

Le reçu français (`04-…`) sert de comparaison.

Écrans arabes (`ecrans/ar-*`) : défauts relevés en préparant la revue, corrigés depuis (incrément 75) :

- dans les colonnes « من طرف » (par), les noms latins se mélangeaient autour de la virgule arabe : chaque personne est désormais sur sa ligne ;
- une référence mêlée à de l'arabe se coupait (« RMB-2026- منفَّذ، 000001 ») : les références gardent leur propre sens d'écriture et l'état d'un remboursement s'écrit une information par ligne.

## Personnalisation des reçus par l'école

Ce qu'une école règle déjà elle-même (Paramètres) et qui est figé sur chaque reçu à son émission :

- adresse, téléphone et e-mail de l'en-tête ;
- langue des reçus (français ou arabe) ;
- chiffres arabes ou occidentaux ;
- moyens de paiement acceptés.

Le nom de l'école est celui que l'éditeur a donné à sa création.

Ce qui n'existe pas encore, à décider après la revue :

- **logo** dans l'en-tête (prévu par FR-REF-06 et FR-PDF-01 ; le stockage de fichiers existe désormais) ;
- **nom de l'école en arabe**, à côté du nom latin (aujourd'hui, un reçu arabe imprime le nom tel qu'il a été saisi) ;
- **identifiants légaux** (NIF, NIS, RC, article d'imposition) et **mention de bas de page** libre (par exemple « frais non remboursables après le … ») ;
- **zone de signature ou de cachet** ;
- **format du numéro** de reçu (décision ouverte OD-11 ; aujourd'hui `REC-{année}-{6 chiffres}`, remis à zéro chaque année) et format du papier (A5 aujourd'hui).

Principe retenu : l'école choisit des **réglages** (champs, logo, mentions, options), mais pas un gabarit HTML libre. Un gabarit libre casserait la mise en page arabe vérifiée par TST-17 et ouvrirait l'injection de contenu. Tout réglage imprimé est figé dans le reçu à son émission, pour qu'un duplicata reste identique à l'original.

Défauts corrigés en préparant la revue : sur un reçu arabe, une adresse latine sortait retournée (« rue des Oliviers, Alger ,12 ») et le nom latin de l'élève sortait mélangé à son matricule et à ses parenthèses. Chaque texte saisi garde désormais son propre sens d'écriture (`ReceiptTest`).

Remarque relevée en préparant la revue : le choix des chiffres arabes est lu au moment de l'impression et non figé. Un duplicata imprimé après un changement de ce réglage diffère donc de l'original par ses chiffres. À figer avec les autres réglages.

## Procès-verbal

| | Nom | Date | Décision | Remarques |
|---|---|---|---|---|
| Rapprochement (finance) | | | validé / à reprendre | |
| Reçus arabes (lecteur natif) | porteur du projet | 17/10/2026 | validés | reçus et écrans arabes jugés bons ; gabarit de reçu propre à chaque école à fournir plus tard |
