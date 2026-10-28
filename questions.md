# Questions pour le client

Date : 26 octobre 2026.

Ces questions portent sur ce qui reste ouvert dans le [SRS 1.2](SRS_Madrassa_1_2.md), la [préparation des lots 5 à 10](PREPARATION_LOTS_5_A_10.md) et les revues des lots 3 et 4. Chaque question indique ce que la plateforme fait aujourd'hui ou ce qui est proposé : si cela convient, le client peut simplement répondre « d'accord ».

Les points déjà tranchés ne sont pas repris : durées de conservation (OD-07), accès aux cours en cas de paiement partiel (OD-05), reçus arabes, alertes d'absence, libellés arabes.

## 1. Finance et lot 3 (à régler en priorité)

1. **Rapprochement d'un mois** : qui, côté finance, peut relire le mois de [REVUE_LOT3.md](REVUE_LOT3.md) et signer le procès-verbal ? À quelle date ?
2. **Rémunération des enseignants (OD-06)** : aujourd'hui, seules les séances au pointage validé sont payées.
   - Une séance annulée par l'école est-elle payée à l'enseignant ? Entièrement, en partie, ou pas du tout ?
   - Et si elle est annulée moins de 24 heures avant ?
   - Une absence justifiée de l'enseignant (maladie…) est-elle payée ?
   - Un remplaçant est-il payé à son propre tarif ou à celui de l'enseignant remplacé ?
   - Ces règles changent-elles selon le mode de paiement (à l'heure, à la séance, mixte) ?
3. **Seuils d'approbation (OD-09)** : au-delà de quel montant une remise, un remboursement ou une dépense doit-il être approuvé par la direction ?
4. **Auto-validation (OD-10)** : dans une très petite école, une même personne peut-elle enregistrer et valider un paiement ? (Aujourd'hui : non par défaut.)
5. **Numérotation (OD-11)** : le format actuel des reçus `REC-2026-000123`, remis à zéro chaque année, convient-il ? Et celui des matricules élèves ?

## 2. Reçus personnalisés

La personnalisation a été reportée le 17 octobre. Quand le client sera prêt :

6. Pouvez-vous fournir un reçu papier actuel de chaque école comme modèle ?
7. Faut-il afficher sur le reçu : le logo, le nom de l'école en arabe, le NIF, le NIS, le RC, l'article d'imposition, un cachet ou une signature ?
8. Quelle mention en bas de page (par exemple « frais non remboursables après le … ») ?
9. Le format A5 convient-il, ou faut-il A4 ou un ticket ?

## 3. Organisation de l'école

10. **Liste d'attente (OD-14)** : quand un groupe est plein, faut-il une liste d'attente ? Si oui, une place libérée est-elle proposée automatiquement au premier inscrit, ou la direction choisit-elle ?
11. **Planning** : la publication du planning se fait pour toute l'école, par période (proposition du 17 octobre). Est-ce confirmé ?
12. **Résultat de gestion** : le tableau de bord calcule « encaissements nets − dépenses payées − rémunérations versées ». Ce calcul correspond-il à ce que la direction veut suivre ?
13. **Programmes (OD-13)** : les noms des matières et des niveaux du profil « école privée algérienne », en français et en arabe, sont-ils ceux que l'école utilise ?
14. **E-mails (OD-12)** : quel service d'envoi d'e-mails utiliser (Gmail professionnel, OVH, Brevo…) ? Quelle adresse d'expéditeur ?

## 4. Pédagogie (lot 5)

Des choix ont été proposés dans la [spécification du lot 5](SPECIFICATION_LOT5_PEDAGOGIE.md). Le client doit les confirmer ou les corriger :

15. **Notation** : sur combien l'école note-t-elle (sur 10, sur 20, sur 100) ? Le seuil de réussite est-il bien la moitié ? Arrondit-on au quart de point, au demi-point ou au point ?
16. **Moyennes** : sur quelle période sont-elles calculées (trimestre, semestre, mois) ? Les coefficients diffèrent-ils selon les matières ?
17. **Remises de devoirs** : les élèves remettent leurs devoirs par lien (Google Drive, OneDrive, GitHub), sans envoi de fichier. Cela convient-il ? Faut-il d'autres sites que ceux-là ?
18. **Retards** : une remise après l'échéance est refusée par défaut, sauf si l'enseignant l'autorise. Est-ce la règle de l'école ?
19. **Quiz** : une seule tentative par défaut. Combien de tentatives l'école veut-elle permettre ?
20. **Relecture** : les leçons doivent-elles être relues par un responsable avant publication ? (Aujourd'hui : non par défaut.)
21. **Comptes élèves** : à partir de quel âge ou de quel niveau un élève a-t-il son propre compte ?
22. **Test** : quel enseignant et quels élèves peuvent tester le parcours complet (publication, réalisation, correction) ?

## 5. Emplois du temps automatiques (lot 6)

23. Quelles préférences comptent le plus pour l'école : éviter les heures creuses, répartir une matière dans la semaine, équilibrer la charge des enseignants, limiter les changements de salle ou de site ? Pouvez-vous les classer ?
24. Quelles règles ne doivent jamais être enfreintes, en plus des conflits de salle et d'enseignant (par exemple : pas plus de deux heures d'une même matière par jour) ?
25. Pouvez-vous fournir les données d'une école réelle (groupes, matières, volumes horaires, disponibilités, salles), anonymisées, et l'emploi du temps actuel qui sert de référence ?
26. Un délai de cinq minutes pour obtenir une proposition est-il acceptable ?

## 6. Assistant IA (lot 7)

27. **Usage** : l'école veut-elle l'IA pour préparer des devoirs et des quiz, pour aider à corriger, ou pour les deux ?
28. **Modèle (OD-03)** : l'IA doit-elle fonctionner uniquement sur nos serveurs, ou un service externe (avec accord de l'école) est-il acceptable ? Quel budget matériel ou mensuel est envisageable ?
29. **Offre (OD-02)** : l'IA est-elle une option payante ? Avec quel quota mensuel par école ? Que se passe-t-il quand le quota est épuisé : blocage, ou dépassement facturé ?
30. **Évaluation** : un enseignant peut-il fournir une trentaine de réponses d'élèves en arabe et en français, anonymisées, avec la note et le commentaire qu'il aurait donnés ?

## 7. Stock (lot 8)

31. Quels articles l'école veut-elle suivre (fournitures, manuels, uniformes, matériel informatique) ? Dans quels lieux (magasin, salles, sites) ?
32. Qui peut faire entrer ou sortir du stock ? Qui valide un inventaire ?
33. Faut-il, dès le début, gérer les fournisseurs, les commandes ou la valeur du stock, ou seulement les quantités ?

## 8. Installation chez le client (lot 9)

34. Des écoles veulent-elles installer la plateforme sur leurs propres serveurs ? Si oui, lesquelles et quand ?
35. **Licence** : quelle durée, quelles limites (nombre d'élèves, modules) ? Comment se fait le renouvellement ? (Le principe est déjà fixé : lecture seule à l'expiration, sans perte de données.)
36. **Hébergement (OD-04)** : pour l'offre hébergée, quel hébergeur privilégier (en Algérie ou à l'étranger) ? Quelles exigences de sauvegarde et de disponibilité ?
37. Pouvez-vous mettre à disposition une machine vierge pour la recette d'installation ?

## 9. Portail parents et élèves (lot 10)

38. Les parents sont-ils **invités par l'école**, ou s'inscrivent-ils eux-mêmes avant d'être approuvés ?
39. La double authentification est-elle obligatoire pour les parents ? Comment un parent récupère-t-il un compte perdu (e-mail, téléphone, passage à l'école) ?
40. Que voit le parent : planning, absences, notes publiées, situation de paiement ? Faut-il cacher certains éléments (par exemple le paiement pour un parent non payeur) ?
41. Quand deux parents sont séparés, chacun voit-il tout, ou l'école choisit-elle ce que voit chaque parent ?

## 10. Comptes et juridique

42. **WhatsApp** : l'école a-t-elle un compte WhatsApp Business ? Pouvez-vous fournir les modèles de messages approuvés par Meta et le jeton d'accès, par un canal sécurisé ?
43. **Loi 18-07 (OD-08)** : quel juriste peut valider le contrat de sous-traitance, les consentements et la déclaration auprès de l'autorité ? Avant quelle date ?
44. **Intégration continue** : la facturation GitHub peut-elle être régularisée pour relancer les tests automatiques ?
45. **Premier client** : quelle école sera la première à utiliser la plateforme en conditions réelles, et à quelle date ?
