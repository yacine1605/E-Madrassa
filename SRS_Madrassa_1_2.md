# Spécification des exigences logicielles (SRS) — Madrassa

**Projet :** Madrassa — Plateforme SaaS modulaire de gestion des écoles privées et des centres de formation
**Client :** Sans objet (produit éditeur). Clients cibles : écoles privées (en premier) et centres de formation en Algérie
**Préparé par :** Équipe Madrassa
**Date :** 10 octobre 2026
**Version :** 1.2 (décisions de l'analyse d'écart intégrées ; signatures à recueillir, section 11)

---

## 0. Guide de lecture et conventions

> Cette section est écrite pour un lecteur humain **et** pour un agent de code (Claude Code) chargé d'analyser l'écart entre ce SRS et une base de code existante, puis de le mettre en œuvre.

### 0.1 Identifiants stables

| Préfixe | Signification | Exemple |
|---|---|---|
| `FR-<MOD>-NN` | Exigence fonctionnelle d'un module | `FR-FIN-03` |
| `BR-NN` | Règle métier transverse (section 5) | `BR-07` |
| `NFR-<CAT>-NN` | Exigence non fonctionnelle | `NFR-SEC-02` |
| `TST-NN` | Test d'invariant critique obligatoire (section 9.3) | `TST-04` |
| `OD-NN` | Décision ouverte (section 10) | `OD-03` |
| `AC` | Critère d'acceptation, listé sous chaque FR | `FR-FIN-02 / AC2` |

Un identifiant ne change jamais et n'est jamais réutilisé. Une exigence supprimée est marquée `[RETIRÉE]`.

### 0.2 Priorités

- **MUST** : obligatoire pour le lot indiqué.
- **SHOULD** : attendu, peut être reporté avec justification écrite.
- **MAY** : optionnel.

### 0.3 Lots

Chaque exigence porte un lot (`Lot 1` à `Lot 10`, section 9). **Un lot est livrable et testable seul.** Une exigence d'un lot ne doit jamais casser un lot précédent.

### 0.4 Langue et identifiants techniques

- Les libellés métier et l'interface sont en français (arabe et anglais via i18n).
- Les identifiants techniques (tables, champs, codes de permission, états) sont en **anglais, `snake_case`**. Les états métier sont donnés sous la forme `libellé français` (`code_technique`).
- Format des permissions : `module.resource.action` (voir annexe A).

### 0.5 Procédure d'analyse d'écart demandée à l'agent de code

Avant toute modification, produire un rapport d'écart pour **chaque** identifiant `FR-*`, `BR-*`, `NFR-*` avec l'un des statuts :

| Statut | Sens |
|---|---|
| `CONFORME` | Implémenté et couvert par un test |
| `PARTIEL` | Implémenté en partie : préciser ce qui manque |
| `ABSENT` | Non implémenté |
| `DIVERGENT` | Implémenté différemment du SRS : décrire l'écart et proposer l'alignement ou la modification du SRS |
| `HORS_PÉRIMÈTRE` | Présent dans le code mais absent du SRS |

Le rapport indique pour chaque ligne : fichiers concernés, risque (bloquant / majeur / mineur) et effort estimé (S/M/L). *Réalisé pour la version 1.1 : `RAPPORT_ECART_SRS_1_1.md`. Ses décisions sont intégrées dans cette version 1.2 (section 10.1).* Il propose ensuite un plan de correction ordonné **par lot**, en traitant d'abord les invariants critiques (section 9.3). Ne rien implémenter avant validation du rapport.

---

## 1. Aperçu du projet

### 1.1 Contexte
Les écoles privées et les centres de formation algériens gèrent aujourd'hui leurs inscriptions, paiements, groupes, enseignants, salaires et dépenses avec des fichiers Excel et du papier. Il n'existe ni base centralisée ni historique fiable. Les établissements sont de tailles très différentes : une petite école où une personne cumule plusieurs fonctions, une grande école avec plusieurs sites et une équipe spécialisée, un centre de formation par spécialités.

Madrassa est un **logiciel SaaS B2B** servant plusieurs établissements indépendants, avec des modules activables à la carte selon leur taille et leurs besoins.

### 1.2 Problème
- Erreurs administratives et de calcul (soldes, salaires) dues à la saisie manuelle.
- Absence de vue fiable sur les revenus, dépenses, salaires et taux de remplissage.
- Conflits d'horaires, de salles et d'enseignants non détectés.
- Aucune traçabilité : qui a modifié quoi, quand et pourquoi.
- Les logiciels génériques ne s'adaptent ni au vocabulaire (primaire, moyen, secondaire, spécialités) ni à la taille de l'établissement.

### 1.3 Objectifs (orientés résultats)
- O1. Un établissement peut cesser d'utiliser Excel et le papier pour les opérations couvertes par les modules qu'il active.
- O2. La direction prend ses décisions financières sur des chiffres rapprochables des opérations sources.
- O3. Les erreurs de saisie financière et de planning sont détectées avant d'avoir des conséquences.
- O4. Un établissement de toute taille adopte le produit en activant uniquement les modules utiles, sans configuration par du code.
- O5. Les données de chaque établissement restent isolées, tracées et hébergées en Algérie.
- O6. L'éditeur peut vendre, activer, limiter et suspendre des modules par client sans intervention sur le code.

---

## 2. Périmètre

### 2.1 Dans le périmètre
- Plateforme multi-établissements (multi-tenant) avec console éditeur, plans, modules et quotas.
- Identités, authentification, MFA, rôles et permissions fines avec portées.
- Référentiel académique configurable (cycles, niveaux, filières, spécialités, matières), champs personnalisés, années et périodes.
- Élèves, responsables légaux, CRM d'admission.
- Programmes, groupes/classes, inscriptions.
- Sites et salles.
- Personnel (enseignants, administratifs), habilitations, disponibilités, contrats, congés.
- Paiements (enregistrement), échéanciers, reçus, remboursements, impayés.
- Dépenses et justificatifs.
- Rémunérations (règles internes, versements).
- Séances, planning manuel, conflits, remplacements, exports PDF/ICS.
- Pointage des enseignants (saisie + validation), présence des élèves.
- Tableaux de bord descriptifs.
- Import/export Excel/CSV, audit, notifications, i18n (arabe, français, anglais) avec RTL.
- Lots ultérieurs (cadrés, détaillés avant réalisation) : LMS, optimisation d'emploi du temps, assistant IA, stock, portail parents, mode dédié.

### 2.2 Hors périmètre
- Bulletins officiels ministériels (formats, trimestres réglementaires, relevés officiels). Le module de bulletins déjà développé est conservé comme **extension optionnelle hors SRS** : documenté à part, maintenu à part, sans exigence de ce document (ND-02).
- Le **mode démonstration en session** de l'interface : bac à sable non normatif, aux données fictives ; il n'implémente aucune exigence de ce document (ND-04).
- Paiement en ligne (CIB, Edahabia ou autre) : seul l'**enregistrement** des paiements reçus est couvert.
- Comptabilité générale, déclarations fiscales et sociales, paie réglementaire complète.
- Multidevise : seule la devise **DZD** est gérée.
- Pays autres que l'Algérie.
- Pointage par QR code, biométrie ou géolocalisation.
- Application mobile native (le web est responsive).
- Fonctionnement hors ligne complet.
- Caisse, point de vente et gestion complète de restaurant (le stock est un suivi de quantités).
- Protection technique du code en mode dédié (licence et contrat uniquement).
- Module de facturation du SaaS lui-même (la console gère plans, modules, quotas, dates ; la facturation reste manuelle).
- Choix du prestataire d'hébergement (le SRS impose la portabilité, voir `NFR-PORT-*`).

### 2.3 Hypothèses
- H1. Le modèle de déploiement initial est un SaaS mutualisé hébergé en Algérie.
- H2. Le segment de départ est l'école privée (cursus Préscolaire / Primaire / Moyen / Secondaire). Le modèle de données reste générique pour les centres de formation.
- H3. Les montants sont en dinars algériens (DZD), au centime.
- H4. Fuseau horaire unique : `Africa/Algiers`.
- H5. Les lots 1 à 4 représentent environ 11 à 12 mois avec une équipe de 2 à 3 développeurs, dont un trimestre pour le seul lot 1 (rapport d'écart, §6.8) ; les lots 5 à 10 sont cadrés mais non planifiés ici. *(Révisée en 1.2 : la version 1.1 visait les lots 1 à 4 en un trimestre.)*

---

## 3. Rôles et permissions

### 3.1 Principes
- P1. Les permissions suivent le format `module.resource.action` (annexe A). Elles sont **fines** pour permettre de scinder un rôle plus tard sans modifier le code.
- P2. Un **rôle** est une liste de permissions plus une **portée**. Les rôles prédéfinis sont modifiables par le tenant (copie par tenant) ; le tenant peut créer ses propres rôles.
- P3. Un utilisateur peut **cumuler** plusieurs rôles. Ses permissions effectives sont l'union des rôles, sous réserve des règles de séparation des tâches (`FR-IAM-05`).
- P4. **Aucun comportement n'est codé sur le nom d'un rôle.** Le code ne teste que des permissions.
- P5. Les contrôles d'accès sont effectués **côté serveur**, par ressource, établissement, site, niveau, groupe et matière. Les exports respectent les mêmes droits.
- P6. Un rôle n'est proposé que si le module correspondant est activé pour le tenant.
- P7. Un membre du personnel peut avoir une fiche (et une fiche salariale) **sans compte de connexion**.

### 3.2 Rôles prédéfinis (11)

| Code | Rôle | Regroupe | Description | Accès principal |
|---|---|---|---|---|
| `super_admin` | Super admin | Support, onboarding, facturation | Équipe éditeur | Tenants, plans, modules, quotas, supervision. **Aucune donnée métier par défaut** ; accès exceptionnel consenti, limité dans le temps et tracé (`FR-PLT-04`) |
| `tenant_admin` | Admin du tenant | — | Administrateur technique du client | Configuration, référentiel, utilisateurs, rôles, import initial. **Pas d'accès finance ni salaires par défaut** |
| `direction` | Direction | — | Dirigeants | Lecture globale, validations sensibles, indicateurs |
| `academic_hr` | Responsable pédagogique et RH | Directeur des études, RH | Pilote la pédagogie et le personnel | Groupes, affectations, planning, résultats, fiches staff, contrats, congés, **validation** des heures assurées. **Sans montants salariaux** |
| `supervisor` | Surveillant général | Responsable de pointage | Suit la présence | Présence des élèves, discipline, **saisie** du pointage des enseignants |
| `front_office` | Secrétariat et admission | Accueil, chargé d'admission | Premier contact et dossiers | CRM, élèves, inscriptions, groupes, encaissement **sur délégation** |
| `accountant` | Comptable / financier | — | Finance | Paiements, dépenses, rémunérations, validation financière |
| `stock_manager` | Gestionnaire de stock | — | Stock | Articles, mouvements, inventaires |
| `teacher` | Enseignant / formateur | — | Enseigne | Ses groupes, appel, ses séances, ses heures ; leçons et évaluations (LMS) |
| `student` | Élève | — | Apprenant | Son planning, sa situation ; cours et notes (LMS) |
| `parent` | Parent | — | Responsable légal | Ses enfants. **Rôle prévu dans le modèle ; portail hors MVP** |

### 3.3 Matrice synthétique des accès par défaut

Légende : **C** = lecture et écriture (créer, modifier, archiver) · **L** = lecture · **V** = droit de validation/approbation · **P** = limité à son propre périmètre (ses groupes, ses données ou ses enfants) · **D** = sur délégation explicite · **–** = aucun accès.
Colonnes : SA = super_admin · AT = tenant_admin · DIR = direction · RPH = academic_hr · SG = supervisor · SEC = front_office · CPT = accountant · STK = stock_manager · ENS = teacher · ELV = student · PAR = parent.

| Domaine | SA | AT | DIR | RPH | SG | SEC | CPT | STK | ENS | ELV | PAR |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Tenants, plans, modules, quotas | C | – | – | – | – | – | – | – | – | – | – |
| Configuration tenant, référentiel, champs personnalisés | – | C | L | L | L | L | L | L | L | – | – |
| Matières/modules et volumes horaires par programme | – | C | L | C | L | L | L | L | L | – | – |
| Comptes, rôles, assignations | – | C | L | – | – | – | – | – | – | – | – |
| Journal d'audit | – | L (accès/config) | L | – | – | – | L (finance) | – | – | – | – |
| Fiches élèves et responsables | – | – | L | C | L | C | L (identité) | – | P | P | P |
| CRM d'admission | – | – | L | L | – | C | – | – | – | – | – |
| Programmes, groupes, composition | – | – | L | C | L | C | L | – | P | – | – |
| Inscriptions (créer, modifier, transférer) | – | – | L | C | L | C | L | – | – | P | P |
| Inscriptions (valider, suspendre, annuler) | – | – | V | V | – | – | – | – | – | – | – |
| Sites et salles | – | C | L | C | L | L | – | – | L | – | – |
| Fiches personnel, habilitations, disponibilités | – | – | L | C | – | – | L (identité) | – | P | – | – |
| Contrats et congés | – | – | L | C | – | – | – | – | P | – | – |
| Planning et séances | – | – | L | C | L | L | – | – | P | P | P |
| Pointage enseignants : saisie | – | – | L | – | C | – | – | – | P (L) | – | – |
| Pointage enseignants : validation | – | – | L | V | – | – | L | – | – | – | – |
| Présence des élèves (appel) | – | – | L | L | C | – | – | – | C (ses séances) | P | P |
| Paiements : saisie | – | – | L | – | – | D | C | – | – | P (L) | P (L) |
| Paiements : validation, contre-opération | – | – | L | – | – | – | V | – | – | – | – |
| Remises, échéanciers, annulation de dette | – | – | V | – | – | – | C | – | – | – | – |
| Remboursements | – | – | V | – | – | – | C (demande) | – | – | – | – |
| Dépenses et justificatifs | – | – | V | – | – | – | C | – | – | – | – |
| Montants des rémunérations | – | – | V | – | – | – | C | – | P (L) | – | – |
| Heures assurées validées | – | – | L | V | – | – | L | – | P (L) | – | – |
| Clôture / réouverture de période financière | – | – | V | – | – | – | C (demande) | – | – | – | – |
| Stock | – | – | L | – | – | – | L | C | – | – | – |
| Tableaux de bord | – | – | C (global) | L (pédagogie) | L (présence) | L (opérationnel) | L (finance) | L (stock) | P | P | P |
| Imports | – | C (initial) | – | C (staff, groupes) | – | C (élèves, CRM) | C (finance) | C (stock) | – | – | – |
| Exports | selon la permission d'export du domaine concerné | | | | | | | | | | |

Les accès **LMS** (leçons, évaluations, notes) et **IA** sont détaillés au lot concerné.

### 3.4 Règles de séparation des tâches (résumé, voir `FR-IAM-05`)
- Un utilisateur ne peut pas **valider ses propres saisies** (pointage, dépenses, remboursements, rémunérations, contre-opérations).
- Un utilisateur ne peut pas s'attribuer à lui-même un rôle ou une permission.
- L'attribution d'une permission sensible (finance, salaires) génère une notification à la direction.

---

## 4. Architecture et modèle de déploiement

### 4.1 Principes (exigences d'architecture)

| ID | Exigence |
|---|---|
| ARC-01 | **Monolithe modulaire** : une seule application, un seul dépôt, des modules séparés par interfaces internes. Pas de microservices au départ. |
| ARC-02 | **Multi-tenant par colonne** : toute table métier porte `tenant_id` ; **Row Level Security PostgreSQL** active comme seconde barrière. Le tenant courant est fixé par la requête authentifiée (jamais par un paramètre client). |
| ARC-03 | **Hiérarchie** : Plateforme → Tenant (organisation cliente) → Site(s). Un tenant a au moins un site. |
| ARC-04 | **Modules activables** (entitlements) : un module inactif masque ses menus, bloque ses API et ses tâches, et ne s'applique pas aux calculs. Les dépendances (annexe C) sont résolues automatiquement à l'activation. |
| ARC-05 | **Isolation entre modules** : un module n'écrit jamais dans les tables d'un autre ; il passe par une interface de service ou un **événement interne** (ex. `payment.validated`, `session.delivered`). |
| ARC-06 | **Tâches de fond** pour exports volumineux, imports, PDF en lot, optimisation et IA ; avec identifiant, état, reprise sur erreur et `tenant_id` vérifié. |
| ARC-07 | **Stockage de fichiers** privé, compatible S3 (MinIO en local), préfixé par tenant, avec URL temporaires signées. |
| ARC-08 | **Portabilité** : exécution dans des conteneurs Docker, configuration par variables d'environnement, aucune dépendance à un service cloud propriétaire. |
| ARC-09 | **Moteur d'optimisation et IA** (lots 6 et 7) exécutés comme processus de tâches de fond ; l'application métier **valide et écrit** les résultats. Deux logiques concurrentes d'écriture sur les mêmes données sont interdites. |
| ARC-10 | **Fournisseur d'IA interchangeable** derrière une interface unique (modèle local à l'étude, aucun service externe activé par défaut). |
| ARC-11 | **Montants** exacts, jamais en virgule flottante : entiers en centimes (`bigint`, représentation retenue) ou `NUMERIC(14,2)` ; règle d'arrondi BR-04. *(Amendée en 1.2, ND-01.)* |
| ARC-12 | **Horodatages** en UTC en base, affichés en `Africa/Algiers`. |

### 4.2 Pile technique retenue (OD-01, tranchée en 1.2)

| Couche | Choix | Remarque |
|---|---|---|
| Application | PHP 8.3 / Laravel 12, monolithe modulaire | Pile du code existant ; elle satisfait tous les `ARC-*` (rapport d'écart, §2.3) |
| Base de données | PostgreSQL 16 | RLS, JSONB, contraintes d'exclusion, déclencheurs d'invariants |
| Interface | Rendu serveur : Filament 5 / Livewire, Alpine.js | Un composant JS isolé pour l'éditeur d'emploi du temps |
| Tâches de fond | File Laravel sur Redis + planificateur | Service `worker` distinct du serveur web |
| Fichiers | Compatible S3 (MinIO en local) | |
| PDF | Moteur supportant l'arabe (RTL, ligatures, polices embarquées) | À valider sur prototype (`TST-17`) |
| Optimisation, IA | Services Python (OR-Tools CP-SAT, modèle d'IA) derrière la file de tâches | Lots 6 et 7 ; l'application valide et écrit les résultats (`ARC-09`, `ARC-10`) |

Les `ARC-*` priment sur le choix de la pile : une évolution de pile passe par une demande de changement.

### 4.3 Profils de déploiement

| Profil | Description | Lot |
|---|---|---|
| **SaaS mutualisé** | Plusieurs tenants, une base partagée avec RLS, console éditeur | 1 et suivants |
| **Dédié (chez le client)** | Même code, packagé en Docker Compose, un seul tenant, licence (clé, modules, nombre d'élèves). Configuration initiale par l'éditeur ; exploitation par le client | Lot 9 |

Le mode dédié n'est industrialisé que lorsqu'un client le demande ; l'architecture ne doit toutefois rien rendre impossible (`ARC-08`). **Les deux profils partagent le même code** : le mode dédié est une option d'épinglage d'un tenant unique, pas une variante du produit (ND-06).

---

## 5. Règles métier transverses

| ID | Règle |
|---|---|
| BR-01 | **Isolation** : aucune lecture ni écriture ne peut traverser les tenants, dans les écrans, API, exports, fichiers, tâches de fond, notifications et journaux. |
| BR-02 | **Pas de suppression de données avec historique** : tout enregistrement référencé (élève, paiement, séance, enseignant, nœud de référentiel…) est **archivé**, jamais supprimé. Un élément de configuration (matière, module, catégorie, moyen de paiement…) **jamais référencé** peut être supprimé définitivement ; s'il a été référencé, il est archivé. |
| BR-03 | **Immutabilité financière** : un paiement validé, une dépense validée, une rémunération validée et une inscription annulée ne sont jamais modifiés. Toute correction passe par une **opération liée** (contre-opération, ajustement, avoir) conservant auteur, date et motif. |
| BR-04 | **Montants** : décimal exact, devise DZD, 2 décimales. Arrondi *half-up* au centime **par ligne** ; un total est la somme des lignes arrondies. |
| BR-05 | **Idempotence** : toute opération créant une écriture financière, une inscription ou un versement exige une clé d'idempotence ; un double clic ou une relance réseau ne crée jamais de doublon. |
| BR-06 | **Atomicité** : la création d'un paiement et la mise à jour de son solde, d'une inscription et de l'occupation du groupe, d'un versement et de sa dépense liée, se font dans une seule transaction. |
| BR-07 | **Capacité** : le nombre d'élèves actifs d'un groupe ne dépasse jamais sa capacité, y compris en cas de requêtes simultanées. |
| BR-08 | **Séparation des tâches** : un utilisateur ne valide pas ses propres saisies (pointage, dépenses, remboursements, rémunérations, contre-opérations). Exception unique et configurable : auto-validation de paiement (`FR-FIN-03`), tracée. |
| BR-09 | **Audit** : tout changement de droits, dossier élève, inscription, note, paiement, salaire, publication de planning est journalisé (auteur, date, objet, ancienne et nouvelle valeur ou opération liée, motif si requis, adresse IP). Les journaux sont en ajout seul. |
| BR-10 | **Dates de validité** : groupes, tarifs, règles de rémunération, rôles et affectations ont des dates de début et de fin. Les valeurs historiques ne sont jamais réécrites. |
| BR-11 | **Engagement figé** : le prix et les conditions d'une inscription sont copiés sur l'inscription. Modifier le catalogue ne réécrit pas les engagements existants. |
| BR-12 | **Deux dimensions d'état d'une inscription** : état pédagogique (`pending`, `validated`, `suspended`, `cancelled`) et règlement financier (`unpaid`, `partially_paid`, `paid`), le second étant **calculé**. Un paiement ne réactive jamais une inscription suspendue ou annulée. |
| BR-13 | **Référentiel** : tout champ « niveau », « filière », « spécialité », « matière » est une **référence** au référentiel du tenant, jamais un texte libre. |
| BR-14 | **Donnée absente ≠ zéro** : une note manquante, un appel non saisi, une heure non pointée sont distincts d'un zéro ou d'une absence. |
| BR-15 | **Clôture** : une période financière clôturée bloque les modifications ordinaires ; la réouverture est exceptionnelle, motivée et tracée. |
| BR-16 | **Aucune règle obligatoire n'est assouplie silencieusement** (capacité, conflit, habilitation, séparation des tâches). |
| BR-17 | **Données personnelles** : minimisation, pas de données personnelles ni de secrets dans les journaux techniques ; traitement des mineurs soumis à la politique de conservation du tenant (`NFR-DATA-*`). |
| BR-18 | **Codes stables** : tout élément configurable (nœud, moyen de paiement, catégorie, rôle) a un code technique immuable et des libellés traduisibles. |
| BR-19 | **Notifications** : jamais de données financières ou pédagogiques envoyées à un tiers non autorisé ; notification de planning uniquement **après publication**. |
| BR-20 | **Module inactif** : un indicateur, un menu, une API ou une tâche d'un module inactif n'existe pas pour le tenant ; les données déjà saisies sont conservées et redeviennent visibles à la réactivation. |

---

## 6. Exigences fonctionnelles

> Format de chaque exigence : **Priorité · Lot · Dépend de**, puis Description, Flux principal, Données, Règles, Cas limites, Critères d'acceptation. Les exigences des lots 5 à 10 sont **cadrées** : elles seront détaillées (flux, données, cas limites) avant le démarrage du lot concerné.

---

### Module PLT — Console plateforme (éditeur)

#### FR-PLT-01 — Gestion des tenants
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-IAM-01
**Description :** Le super admin crée, suspend, réactive et archive un tenant.
**Flux principal :**
1. Le super admin saisit nom, identifiant court (`slug`), profil modèle (FR-PLT-03), plan, langue par défaut, e-mail du premier admin.
2. Le système crée le tenant, un site par défaut, applique le profil, active les modules du plan et invite l'admin (FR-IAM-01).
3. Le tenant devient `active`.
**Données :** `name` texte requis ≤ 150 ; `slug` unique `[a-z0-9-]{3,40}` immuable ; `status` ∈ `active`, `suspended`, `archived` ; `default_locale` ∈ `fr`, `ar`, `en`.
**Règles :**
- Un tenant `suspended` : connexions refusées avec message explicite, données conservées, tâches de fond arrêtées.
- Un tenant `archived` : lecture seule pour l'export final ; purge selon la politique de conservation (OD-07).
- Création, suspension et archivage sont journalisés.
**Cas limites :** `slug` déjà pris → erreur de validation ; échec d'envoi de l'invitation → tenant créé, invitation renvoyable ; double clic → un seul tenant (BR-05).
**Critères d'acceptation :**
- AC1 : créer un tenant crée exactement un site, un admin invité et les données du profil.
- AC2 : un tenant suspendu ne peut plus ouvrir de session ; les sessions existantes sont révoquées.
- AC3 : la création double-soumise ne produit qu'un tenant.

#### FR-PLT-02 — Plans, modules et quotas (entitlements)
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-PLT-01
**Description :** Le super admin affecte à un tenant un plan, des modules et des quotas.
**Flux principal :**
1. Choix d'un plan (`essential`, `pro`, `complete`, ou personnalisé).
2. Ajout/retrait de modules ; le système ajoute automatiquement les dépendances (annexe C) et refuse le retrait d'un module dont un autre actif dépend.
3. Saisie des quotas et des dates de validité.
**Données :** `plan_code` ; `modules[]` (clés annexe C) ; `quota_active_students` entier ≥ 0 ; `quota_storage_mb` ; `quota_ai_requests_month` ; `valid_from`, `valid_until` ; `grace_days` (défaut 15).
**Règles :**
- Le dépassement du quota d'élèves actifs **bloque la création d'inscriptions supplémentaires**, avec message clair ; il ne bloque ni la consultation ni les paiements.
- À l'expiration, après la période de grâce, le tenant passe en **lecture seule** (pas de perte de données).
- Un changement de module est effectif sans redéploiement (BR-20).
- Toute vérification se fait côté serveur à chaque requête (cache invalidé au changement).
**Cas limites :** désactivation d'un module contenant des données → données conservées, invisibles ; quota atteint pendant un import → l'import s'arrête proprement avec rapport.
**Critères d'acceptation :**
- AC1 : activer `payroll` active automatiquement `staff` ; désactiver `staff` est refusé tant que `payroll` est actif.
- AC2 : un appel API d'un module inactif renvoie 403 avec code `module_disabled`.
- AC3 : un tenant expiré hors grâce ne peut plus créer ni modifier (lecture seule), mais peut exporter.

#### FR-PLT-03 — Catalogue de profils modèles
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-REF-01
**Description :** L'éditeur maintient des profils modèles qui pré-remplissent le référentiel d'un nouveau tenant.
**Règles :**
- Profils fournis : `private_school_dz` (Préscolaire : sections `PS`, `MS`, `GS` ; Primaire 1AP–5AP, Moyen 1AM–4AM, Secondaire 1AS–3AS, filières du secondaire ; le préscolaire n'a ni filière ni obligation de matières, voir FR-PRG-02) et `training_center` (domaine → spécialité → parcours, sans nœuds initiaux imposés).
- Un profil est **versionné** ; l'application d'une nouvelle version à un tenant existant n'est **jamais automatique**.
- Après application, le référentiel appartient au tenant (copie), modifiable par son admin.
- Chaque nœud porte un `platform_code` optionnel de correspondance.
**Critères d'acceptation :**
- AC1 : créer un tenant avec `private_school_dz` produit les 4 cycles (préscolaire, primaire, moyen, secondaire) et leurs niveaux avec libellés `fr` et `ar`.
- AC2 : publier la version 2 d'un profil ne modifie aucun tenant existant.

#### FR-PLT-04 — Accès exceptionnel du support (break-glass)
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-IAM-03
**Description :** Le support accède temporairement aux données d'un tenant, avec consentement.
**Flux principal :** 1. L'admin du tenant (ou la direction) accorde un accès pour une durée et un périmètre. 2. Le super admin ouvre l'accès ; une bannière visible indique le mode support. 3. L'accès expire automatiquement.
**Règles :** lecture seule par défaut ; durée maximale 72 h ; données salariales et justificatifs financiers exclus sauf consentement explicite distinct ; chaque consultation est journalisée dans le journal du **tenant**.
**Critères d'acceptation :** AC1 : sans consentement, aucune donnée métier n'est accessible au super admin. AC2 : à l'expiration, l'accès est refusé. AC3 : le tenant voit la liste de tous les accès support.

#### FR-PLT-05 — Supervision
**Priorité :** SHOULD · **Lot :** 1 · **Dépend de :** FR-PLT-01
**Description :** Tableau de bord éditeur : tenants, modules actifs, usage des quotas, état des tâches de fond, erreurs. **Aucune donnée métier de tenant** n'y figure (comptages agrégés uniquement).

---

### Module IAM — Identités, authentification, rôles

#### FR-IAM-01 — Cycle de vie des comptes
**Priorité :** MUST · **Lot :** 1
**Description :** Créer, activer, suspendre, réactiver et révoquer des comptes, individuellement ou par import.
**Flux principal :**
1. Un utilisateur habilité (`user.account.create`) crée un compte (nom, e-mail ou identifiant, rôles, portée).
2. Le système envoie un lien d'activation temporaire (validité 72 h) **ou** génère un mot de passe provisoire à changer à la première connexion.
3. L'utilisateur définit son mot de passe (et son MFA si requis).
**Données :** `username` unique par tenant ; `email` optionnel pour élève/parent, requis pour les rôles privilégiés ; `status` ∈ `invited`, `active`, `suspended`, `revoked` ; identifiant stable `external_ref` pour l'import.
**Règles :**
- La suspension révoque immédiatement toutes les sessions.
- Un compte `revoked` ne peut pas être réactivé (nouveau compte).
- Un compte peut être lié à une fiche élève, personnel ou responsable légal ; une fiche peut exister sans compte.
- Le dernier `tenant_admin` actif ne peut pas être suspendu ni perdre son rôle.
**Cas limites :** lien expiré → renvoi possible ; e-mail déjà utilisé dans le tenant → refus ; échec d'envoi → l'admin peut copier le lien sécurisé.
**Critères d'acceptation :** AC1 : suspension → les requêtes suivantes de l'utilisateur échouent en moins de 5 s. AC2 : tentative de suspendre le dernier admin → refusée. AC3 : import de 500 comptes avec identifiants stables, relancé deux fois, sans doublon.

#### FR-IAM-02 — Authentification et MFA
**Priorité :** MUST · **Lot :** 1
**Description :** Connexion sécurisée, MFA obligatoire pour les profils privilégiés.
**Règles :**
- MFA TOTP **obligatoire** pour `super_admin`, `tenant_admin`, `direction`, `accountant` et tout rôle porteur d'une permission marquée `sensitive` (annexe A) ; optionnel pour les autres.
- Codes de récupération à usage unique générés à l'activation du MFA.
- Mots de passe : ≥ 10 caractères, hachage **Argon2id**, refus des mots de passe courants.
- Limitation des tentatives : verrouillage temporaire progressif par compte et par IP après 5 échecs ; messages d'erreur non révélateurs.
- Expiration de session : 30 min d'inactivité (paramétrable par tenant, 15 à 120 min) ; durée absolue 12 h.
- Récupération de mot de passe par lien à usage unique valable 1 h.
**Critères d'acceptation :** AC1 : un `accountant` sans MFA configuré est forcé de l'activer avant tout accès. AC2 : 6e échec consécutif → verrouillage. AC3 : lien de récupération réutilisé → refusé.

#### FR-IAM-03 — Rôles, permissions et cumul
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-IAM-01
**Description :** Gérer le catalogue de permissions, les rôles prédéfinis (section 3.2) et les rôles personnalisés.
**Flux principal :** l'admin duplique ou crée un rôle, coche des permissions (groupées par module actif), définit une portée, puis l'assigne à des utilisateurs avec dates de validité optionnelles.
**Règles :**
- Les 11 rôles prédéfinis sont **semés** à la création du tenant et copiés ; le tenant peut les modifier sans affecter les autres.
- Les permissions des modules inactifs ne sont pas assignables.
- Un utilisateur peut avoir N rôles ; permissions effectives = union.
- Les permissions `sensitive` sont signalées visuellement.
- Supprimer un rôle assigné est interdit (archivage uniquement).
**Critères d'acceptation :** AC1 : modifier le rôle `front_office` d'un tenant n'affecte pas un autre tenant. AC2 : un utilisateur `front_office + accountant` reçoit l'union. AC3 : aucune ligne de code métier ne référence un code de rôle (vérifiable par recherche statique, `TST-02`).

#### FR-IAM-04 — Portées
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-IAM-03, FR-REF-02
**Description :** Une assignation de rôle est limitée à une portée : tout le tenant, un ou plusieurs sites, niveaux, groupes ou matières.
**Règles :**
- Portées **personnelles** implicites : un enseignant voit ses groupes ; un élève ses propres données ; un parent les élèves liés.
- Une portée absente signifie « tout le tenant » **uniquement** pour les rôles internes, jamais pour `student` ni `parent`.
- Le filtrage s'applique aux listes, recherches, exports, tableaux de bord et API.
**Critères d'acceptation :** AC1 : un enseignant ne voit aucun groupe dont il n'est pas titulaire ni remplaçant. AC2 : un élève ne voit pas les dossiers de ses camarades (`TST-03`). AC3 : un export d'un secrétaire limité au site A ne contient que le site A.

#### FR-IAM-05 — Séparation des tâches
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-IAM-03
**Description :** Le système empêche un utilisateur de valider ses propres saisies et de s'élever lui-même.
**Règles :**
- Table de règles `sod_rule(entity, entry_action, approve_action)` vérifiée à l'approbation : `approved_by ≠ created_by`.
- Entités couvertes : pointage enseignant, dépense, remboursement, contre-opération, rémunération, clôture de période. Paiement : exception paramétrable `finance.allow_self_validation` (défaut `false`) ; lorsqu'elle est active, l'enregistrement porte `self_validated = true`.
- Un utilisateur ne peut pas modifier ses propres assignations de rôles.
- Assigner une permission `sensitive` génère une notification à la direction.
**Critères d'acceptation :** AC1 : un utilisateur cumulant `supervisor` et `academic_hr` ne peut pas valider un pointage qu'il a saisi, mais peut valider celui d'un autre (`TST-05`). AC2 : le message d'erreur explique la règle.

---

### Module REF — Référentiel académique et paramétrage

#### FR-REF-01 — Profils et types de nœuds
**Priorité :** MUST · **Lot :** 1
**Description :** Le référentiel est un arbre générique. Le profil du tenant définit la **hiérarchie des types de nœuds** et leurs libellés.
**Données :** `node_type` : `code`, `labels{fr,ar,en}`, `level_order`, `is_required`.
**Exemples :** école : `cycle` → `grade` (niveau) → `track` (filière/option) ; centre : `domain` → `specialty` → `pathway`.
**Règles :** la hiérarchie des types est fixée par le profil ; le tenant peut renommer les libellés et activer/désactiver les types optionnels ; il ne peut pas créer de type libre (BR-13).
**Critères d'acceptation :** AC1 : un tenant « centre » voit « Spécialité » là où un tenant « école » voit « Niveau », sans changer de code. AC2 : renommer un libellé ne change pas le `code`.

#### FR-REF-02 — Gestion des nœuds
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-REF-01
**Description :** L'admin du tenant ajoute, renomme, réordonne, désactive et archive des nœuds.
**Flux principal :** choisir un type → saisir code, libellés → choisir le parent compatible → enregistrer.
**Données :** `code` unique par (tenant, type), `[A-Z0-9_]{1,20}`, immuable ; `labels` au moins `fr` **ou** `ar` ; `parent_id` du type parent attendu ; `sort_order` ; `status` ∈ `active`, `archived` ; `platform_code` optionnel.
**Règles :**
- Doublon de code ou de libellé (normalisé : casse, espaces, diacritiques arabes) dans un même parent → refusé.
- Un nœud référencé ne se supprime pas ; il s'**archive** (n'apparaît plus dans les sélecteurs, reste visible dans l'historique).
- Archiver un nœud parent est refusé tant que des enfants actifs existent.
- Toute modification est journalisée.
**Critères d'acceptation :** AC1 : créer deux niveaux « 1AS » dans le même cycle est refusé. AC2 : un niveau utilisé par une inscription ne peut qu'être archivé. AC3 : les listes déroulantes de tout le produit (élèves, groupes, habilitations, planning) proviennent de cette seule source (`TST-18`).

#### FR-REF-03 — Matières et modules (catalogue)
**Priorité :** MUST · **Lot :** 1 · **Dépend de :** FR-REF-02
**Description :** Le tenant gère **manuellement** son catalogue de matières (écoles) ou de modules (centres de formation) : ajout, modification, archivage et suppression. Le volume horaire et le nombre de séances se définissent par programme (FR-PRG-02).
**Flux principal :** 1. Ouvrir le catalogue. 2. Ajouter une entrée (code, libellés, description). 3. Modifier ou désactiver. 4. Supprimer si jamais utilisée, sinon archiver.
**Données :** `code` unique par tenant, `[A-Z0-9_]{1,20}`, immuable ; `labels{fr,ar,en}` (au moins `fr` ou `ar`) ; `description` optionnelle ; `kind` ∈ `subject`, `module` (le libellé affiché « Matière » ou « Module » dépend du profil du tenant) ; `status` ∈ `active`, `archived`.
**Règles :**
- Permission `subject.subject.manage` : rôles par défaut `academic_hr` et `tenant_admin`.
- Mêmes règles d'unicité et d'archivage que FR-REF-02 (doublons normalisés refusés).
- **Suppression** : autorisée uniquement si la matière/le module n'est référencé nulle part (programme, habilitation, séance, note) ; sinon proposition d'archivage (BR-02).
- Une entrée archivée n'apparaît plus dans les sélecteurs mais reste visible dans l'historique.
**Cas limites :** suppression d'une matière utilisée par un programme d'une année clôturée → refusée ; création simultanée du même code → un seul enregistrement.
**Critères d'acceptation :** AC1 : ajouter « Mathématiques » puis la supprimer immédiatement réussit. AC2 : la même matière rattachée à un programme ne peut qu'être archivée. AC3 : un tenant « centre » voit « Modules » et un tenant « école » voit « Matières » sans changement de code.

#### FR-REF-04 — Champs personnalisés
**Priorité :** SHOULD · **Lot :** 1 · **Dépend de :** FR-REF-01
**Description :** L'admin définit des champs supplémentaires pour un nombre limité d'entités (`student`, `guardian`, `staff`, `lead`).
**Données :** `key` unique, `type` ∈ `text`, `number`, `date`, `boolean`, `select` (liste de valeurs avec codes), `required`, `labels{fr,ar,en}`, `is_sensitive`. Valeurs en JSONB.
**Règles :** maximum 20 champs par entité ; modification du `type` interdite si des valeurs existent ; un champ `is_sensitive` n'est visible que des rôles ayant `student.sensitive.read` ; les valeurs d'un champ archivé sont conservées.
**Critères d'acceptation :** AC1 : un champ `select` n'accepte que ses valeurs ; AC2 : un champ requis bloque l'enregistrement s'il est vide.

#### FR-REF-05 — Années scolaires et périodes
**Priorité :** MUST · **Lot :** 1
**Description :** Gérer les années (ex. 2026-2027) et leurs périodes (trimestres, semestres, modules).
**Données :** `year(code, start_date, end_date, status)` ; `period(year_id, code, labels, start_date, end_date)`.
**Règles :** une seule année `current` ; pas de chevauchement de périodes d'une même année ; clôturer une année fige ses groupes, inscriptions et résultats validés (corrections par opération liée).
**Critères d'acceptation :** AC1 : deux périodes qui se chevauchent sont refusées. AC2 : après clôture, modifier une inscription de l'année est refusé.

#### FR-REF-06 — Paramètres d'établissement
**Priorité :** MUST · **Lot :** 1
**Description :** Paramètres du tenant : identité (nom, logo, coordonnées pour les reçus), langues activées, jours ouverts, premier jour de semaine, créneaux horaires, politiques (accès avec paiement partiel, délai de session, seuils d'approbation).
**Règles :** par défaut, semaine du dimanche au jeudi ouverte, vendredi et samedi fermés (modifiable) ; fuseau `Africa/Algiers` non modifiable ; tout paramètre sensible est journalisé.
**Critères d'acceptation :** AC1 : modifier les jours ouverts est pris en compte par le planning (FR-PLN-01).

---

### Module TRV — Services transverses

#### FR-AUD-01 — Journal d'audit
**Priorité :** MUST · **Lot :** 1
**Description :** Enregistrer les événements de BR-09.
**Données :** `occurred_at`, `actor_id`, `tenant_id`, `action`, `object_type`, `object_id`, `before` JSON, `after` JSON ou `linked_operation_id`, `reason`, `ip`, `request_id`.
**Règles :** ajout seul (aucune modification ni suppression par l'application, privilèges base restreints) ; lecture par permission `audit.log.read` avec filtres (utilisateur, objet, période) ; accès technique ≠ accès aux journaux financiers ; pas de données sensibles dans `before/after` au-delà de l'identifiant (champs salaires masqués sauf droit).
**Critères d'acceptation :** AC1 : toute modification d'une note produit une entrée avec ancienne valeur, nouvelle valeur, auteur, motif. AC2 : aucune API ne permet de modifier une entrée d'audit.

#### FR-I18-01 — Internationalisation et RTL
**Priorité :** MUST · **Lot :** 1
**Description :** Interface en français et arabe au MVP (RTL inclus) ; anglais ajouté par traductions.
**Règles :**
- Aucun texte en dur ; catalogues de traduction par langue ; langue par utilisateur avec repli sur la langue du tenant puis `fr`.
- Styles **logiques** (début/fin) pour un miroir RTL sans duplication.
- Libellés du référentiel, catégories, moyens de paiement, rôles : champ par langue (BR-18).
- Noms de personnes : nom en arabe et nom en alphabet latin (au moins un des deux) ; recherche tolérante (normalisation alif/hamza, ya/alif maqsura, ta marbuta, suppression des diacritiques ; translittération insensible à la casse).
- Dates affichées en calendrier grégorien, chiffres occidentaux par défaut (option chiffres arabes-indiens), formats localisés ; montants « 12 500,00 DA ».
- Contenu pédagogique non traduit.
**Critères d'acceptation :** AC1 : chaque écran du MVP est utilisable en `ar` sans débordement ni inversion cassée. AC2 : rechercher « احمد » trouve « أحمد ». AC3 : un test de pseudo-localisation ne trouve aucune chaîne en dur (`TST-17`).

#### FR-IMP-01 — Import Excel/CSV
**Priorité :** MUST · **Lot :** 1
**Description :** Importer des données depuis des fichiers modèles pour : élèves, responsables, personnel, groupes, programmes, inscriptions, paiements, dépenses, comptes, articles de stock.
**Flux principal :**
1. Télécharger le modèle (Excel ou CSV) du type de données.
2. Déposer le fichier ; le système crée une tâche d'import (arrière-plan).
3. **Prévisualisation** : lignes valides, doublons, références manquantes, erreurs.
4. L'utilisateur confirme ; l'import s'exécute par lots transactionnels.
5. Un **rapport de rejets** téléchargeable est produit.
**Règles :**
- Chaque ligne porte un identifiant stable `external_ref` ; réimporter le même fichier met à jour ou ignore, **jamais ne duplique** (BR-05).
- Les références (niveau, filière, matière, formation) se résolvent par **code** du référentiel ; une valeur inconnue est rejetée, jamais créée automatiquement.
- Contrôle des doublons (matricule, nom normalisé + date de naissance).
- Un import financier est rapproché : nombre de lignes, somme des montants, soldes calculés vs soldes fournis.
- Mode **reprise à blanc** (sans écriture définitive) disponible ; **retour arrière** d'un import complet tant qu'aucune opération dépendante n'a été saisie.
- Le fichier original est conservé (stockage privé).
**Critères d'acceptation :** AC1 : importer 500 élèves puis relancer le même fichier ne crée aucun doublon (`TST-14`). AC2 : une ligne avec un niveau inexistant est rejetée avec le numéro de ligne et la cause. AC3 : l'aperçu n'écrit rien en base.

#### FR-IMP-02 — Exports
**Priorité :** MUST · **Lot :** 1
**Description :** Exporter listes et rapports en Excel, CSV et PDF.
**Règles :** exports soumis aux **mêmes permissions et portées** que les écrans ; permission d'export distincte par domaine ; exports volumineux (> 2 000 lignes) en arrière-plan avec notification ; chaque export est journalisé (utilisateur, filtres, nombre de lignes) ; fichiers temporaires purgés après 7 jours ; neutralisation des formules (préfixe sur les cellules commençant par `=`, `+`, `-`, `@`).
**Critères d'acceptation :** AC1 : un export réalisé par un secrétaire ne contient aucune colonne salariale. AC2 : une cellule `=CMD()` est exportée de façon inerte.

#### FR-FIC-01 — Fichiers et pièces jointes
**Priorité :** MUST · **Lot :** 1
**Description :** Gérer les pièces jointes (justificatifs, photos, documents d'élève, supports pédagogiques).
**Règles :**
- Stockage privé compatible S3, clé préfixée `tenant/{id}/…`.
- Téléchargement uniquement via l'application (contrôle d'accès) ou lien signé temporaire (≤ 15 min).
- Types et tailles autorisés **configurables** (défaut : PDF, JPEG, PNG, DOCX, XLSX ; ≤ 10 Mo) ; vérification du type réel (signature de contenu), pas seulement l'extension ; nom d'origine nettoyé.
- Catégories de droits distinctes : `finance_attachment`, `student_document`, `student_photo`, `staff_document`, `pedagogy_resource`.
- Quota de stockage par tenant (FR-PLT-02).
- Un conteneur remplaçable n'est jamais l'unique stockage.
**Critères d'acceptation :** AC1 : un fichier `.exe` renommé `.pdf` est rejeté. AC2 : un justificatif financier n'est pas accessible à un enseignant même avec l'URL. AC3 : un lien signé expire.

#### FR-NOT-01 — Notifications
**Priorité :** MUST · **Lot :** 1
**Description :** Notifications internes (cloche) et e-mail optionnel.
**Règles :** événements configurables (échéance proche, impayé, planning publié, validation requise, import terminé) ; préférences par utilisateur ; échecs d'e-mail suivis et rejouables ; contenus minimaux (BR-19) ; aucune donnée financière dans un e-mail à un destinataire non autorisé ; fournisseur d'e-mail configurable (SMTP).
**Critères d'acceptation :** AC1 : un échec SMTP crée une entrée « échec » consultable et ne bloque pas l'opération métier. AC2 : un élève ne reçoit jamais de notification sur la situation d'un autre.

#### FR-JOB-01 — Tâches de fond
**Priorité :** MUST · **Lot :** 1
**Description :** Cadre commun pour imports, exports, PDF en lot, rémunérations en masse, optimisation et IA.
**Règles :** chaque tâche : identifiant, `tenant_id`, utilisateur, état (`queued`, `running`, `succeeded`, `failed`, `cancelled`), progression, message d'erreur compréhensible ; relance sur erreur transitoire ; idempotence ; annulation possible ; page « Mes tâches » ; la tâche **vérifie** le tenant à l'exécution.
**Critères d'acceptation :** AC1 : une tâche d'un tenant ne lit jamais les données d'un autre (`TST-01`). AC2 : une tâche relancée après crash ne duplique pas ses effets.

#### FR-PDF-01 — Génération de documents PDF
**Priorité :** MUST · **Lot :** 1
**Description :** Service commun de génération PDF (reçus, emplois du temps, listes, fiches) multilingue et RTL.
**Règles :** polices embarquées couvrant arabe et latin ; ligatures et sens RTL corrects ; en-tête avec logo et identité du tenant ; document généré dans la langue du destinataire ou du tenant ; numérotation et référence figées à la génération.
**Critères d'acceptation :** AC1 : un reçu en arabe affiche les lettres liées et les montants dans le bon ordre visuel (`TST-17`).

---

### Module ELV — Élèves et responsables légaux

#### FR-ELV-01 — Fiche élève
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-REF-02
**Description :** Créer, modifier et consulter la fiche d'un élève avec son historique.
**Flux principal :** 1. Recherche préalable (anti-doublon, FR-ELV-04). 2. Saisie de la fiche. 3. Enregistrement : le système attribue un matricule. 4. La fiche affiche inscriptions, groupes, paiements, présence (selon droits).
**Données :**
- `matricule` : texte, unique par tenant, généré (`{année}{séquence 5 chiffres}`) ou importé, immuable.
- `last_name_ar`, `first_name_ar`, `last_name_lat`, `first_name_lat` : texte ≤ 80 ; au moins un nom complet (arabe ou latin) requis.
- `birth_date` : date, ≤ aujourd'hui ; `birth_place` ; `gender` ∈ `m`, `f` (optionnel selon paramétrage).
- `phone`, `email`, `address` : optionnels ; `photo_file_id` : image optionnelle (voir règles ci-dessous).
- `status` ∈ `active`, `former`, `blocked` ; `origin_school` ; champs personnalisés (FR-REF-04).
**Règles :**
- Les données des mineurs suivent la politique de conservation du tenant (`NFR-DATA-03`).
- Un élève avec historique ne peut pas être supprimé (BR-02).
- **Photo (optionnelle)** : aucune fonction ne dépend de sa présence. Formats JPEG, PNG, WebP ; ≤ 2 Mo à l'envoi ; recadrage carré proposé ; le serveur redimensionne (original ≤ 1024 px, vignette ≤ 128 px), recompresse et **supprime les métadonnées** (EXIF, géolocalisation). Ajout, remplacement et suppression par `student.record.update` ; l'ancienne image est **supprimée définitivement** au remplacement ou au retrait (minimisation, BR-17) ; l'événement est journalisé sans l'image.
- La photo est visible par les utilisateurs autorisés à lire la fiche, par l'élève lui-même et par les enseignants sur la liste d'appel de leurs groupes ; jamais dans un export, une notification ou un lien public sans permission explicite. Un tenant peut **désactiver** la fonction (`student.photo.enabled`) ; la désactiver masque les photos sans les supprimer.
- Un élève sans photo affiche un avatar neutre (initiales).
- Un élève `blocked` ne peut pas être inscrit ; la raison du blocage est obligatoire.
- Un élève peut suivre plusieurs formations/programmes (inscriptions multiples).
- Toute action est historisée (BR-09).
**Cas limites :** doublon probable → avertissement avec choix « ouvrir la fiche existante » ou « créer quand même » (motif obligatoire, journalisé) ; date de naissance future → refus ; fiche modifiée simultanément par deux utilisateurs → contrôle de version optimiste, message de conflit.
**Critères d'acceptation :** AC0 : créer un élève sans photo est possible et ne produit aucune erreur ni champ bloquant ; téléverser un fichier non image est refusé ; l'image enregistrée ne contient plus de métadonnées EXIF. AC1 : créer un élève produit un matricule unique, même en création simultanée. AC2 : suppression d'un élève avec une inscription → refusée avec proposition d'archivage. AC3 : un enseignant ne voit que les élèves de ses groupes et uniquement les champs autorisés.

#### FR-ELV-02 — Responsables légaux
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-ELV-01
**Description :** Gérer père, mère, tuteur et contacts d'urgence liés à un élève.
**Données :** `relationship` ∈ `father`, `mother`, `guardian`, `other` ; nom (ar/lat), téléphone, e-mail, profession (optionnelle) ; `is_primary_contact`, `is_payer`, `emergency_contact`.
**Règles :** un responsable peut être lié à plusieurs élèves (fratrie) sans duplication ; au moins un contact principal avec téléphone pour un élève mineur ; un lien `parent` ↔ compte (portail futur) est prévu mais non exposé au MVP.
**Critères d'acceptation :** AC1 : lier un même parent à deux enfants ne crée qu'une fiche responsable. AC2 : un élève mineur sans contact principal ne peut pas être inscrit.

#### FR-ELV-03 — Statuts et archivage
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-ELV-01
**Règles :** transitions `active` → `former` (fin de parcours) ; `active` ↔ `blocked` (motif) ; archivage d'un `former` après la durée définie (OD-07). Le changement de statut est journalisé avec motif. Un élève `former` conserve son historique en lecture.
**Critères d'acceptation :** AC1 : bloquer un élève avec inscriptions actives avertit des inscriptions concernées et ne les modifie pas automatiquement.

#### FR-ELV-04 — Recherche et détection de doublons
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-I18-01
**Description :** Recherche par nom (ar/lat), matricule, téléphone, date de naissance ; détection de doublons à la création et à l'import.
**Règles :** normalisation arabe et latine (FR-I18-01) ; score de similarité simple (nom normalisé + date de naissance, ou téléphone) ; résultats paginés, filtrés par portée.
**Critères d'acceptation :** AC1 : « Mohamed Amine » et « محمد أمين » sont proposés comme doublon probable lorsque la date de naissance est identique et saisie sous deux alphabets.

---

### Module CRM — Admission

#### FR-CRM-01 — Prospects et pipeline
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-REF-02
**Description :** Suivre les premiers contacts jusqu'à l'inscription.
**Flux principal :** 1. Créer un prospect (parent et/ou enfant, niveau visé, source). 2. Enregistrer des interactions (appel, visite, rendez-vous) et des rappels. 3. Faire évoluer le statut. 4. Convertir (FR-CRM-02) ou clore avec un motif.
**Données :** `lead` : contact (nom, téléphone, e-mail), enfant(s) (nom, niveau visé via référentiel), `source` ∈ liste paramétrable (bouche-à-oreille, réseaux sociaux, affichage, autre), `status` ∈ liste paramétrable avec défaut `new`, `contacted`, `visit_scheduled`, `visited`, `registered`, `lost` ; `lost_reason` ; `assigned_to`.
**Règles :** statuts `registered` et `lost` terminaux (réouverture avec motif) ; rappel échu → notification à l'assigné ; recherche anti-doublon sur téléphone ; un prospect n'est ni un élève ni compté dans les effectifs.
**Critères d'acceptation :** AC1 : un rappel échu apparaît dans les notifications de l'assigné. AC2 : clore sans motif est refusé. AC3 : un prospect n'apparaît dans aucun effectif de groupe.

#### FR-CRM-02 — Conversion en élève et inscription
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-CRM-01, FR-ELV-01, FR-INS-01
**Description :** Convertir un prospect en fiche élève et en inscription en préremplissant les données.
**Règles :** la conversion est atomique (élève + responsable + inscription ou rien) ; le prospect passe à `registered` et conserve le lien vers l'élève ; conversion refusée si le prospect est déjà converti (idempotence).
**Critères d'acceptation :** AC1 : un double clic sur « Convertir » crée un seul élève. AC2 : l'historique des interactions reste consultable sur la fiche élève.

---

### Module SLL — Sites et salles

#### FR-SLL-01 — Sites
**Priorité :** MUST · **Lot :** 2
**Description :** Gérer les sites (campus) d'un tenant.
**Données :** `code`, `name{fr,ar,en}`, adresse, téléphone, `status`.
**Règles :** au moins un site actif ; les groupes, salles et séances appartiennent à un site ; l'archivage d'un site avec salles actives est refusé.

#### FR-SLL-02 — Salles
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-SLL-01
**Description :** Gérer les salles : capacité, type, équipements, état.
**Données :** `code` unique par site ; `name` ; `capacity` entier ≥ 1 ; `room_type` (classe, laboratoire, informatique, amphi, autre ; paramétrable) ; `equipment[]` (liste paramétrable) ; `status` ∈ `active`, `unavailable`, `archived` ; fermetures datées (travaux).
**Règles :** la capacité d'une salle est comparée à la capacité du groupe lors d'une réservation (avertissement bloquant à la création de séance, FR-PLN-03) ; une salle `unavailable` ne peut pas être affectée à de nouvelles séances.
**Critères d'acceptation :** AC1 : affecter à une salle de 20 places un groupe de capacité 25 est signalé comme conflit de capacité. AC2 : une fermeture datée empêche toute séance sur la période.

---

### Module STF — Personnel (enseignants et administratifs)

#### FR-STF-01 — Fiches du personnel
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-REF-02
**Description :** Gérer les fiches des membres du personnel : enseignant interne/externe, administratif, autre.
**Données :** `staff_number` unique ; nom (ar/lat) ; `staff_type` ∈ `teacher_internal`, `teacher_external`, `administrative`, `other` ; téléphone, e-mail ; date d'embauche ; `status` ∈ `active`, `on_leave`, `left` ; `user_id` optionnel (compte de connexion).
**Règles :** une fiche existe sans compte ; les données salariales sont stockées à part (module REM) ; le départ d'un membre conserve ses leçons, résultats, séances et affectations historiques (statut `left` + date, jamais de suppression) ; un `left` n'est plus proposé pour de nouvelles affectations.
**Critères d'acceptation :** AC1 : un enseignant `left` reste visible dans l'historique des séances passées. AC2 : une fiche sans compte peut recevoir une rémunération.

#### FR-STF-02 — Habilitations des enseignants
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-STF-01, FR-REF-03
**Description :** Définir pour chaque enseignant les couples **matière × niveau/spécialité** qu'il est habilité à enseigner.
**Données :** `qualification(staff_id, subject_id, node_id, valid_from, valid_to)` où `node_id` est un nœud du référentiel (ex. niveau `1AS`, ou spécialité `Data Science`).
**Règles :** une habilitation sur un nœud parent couvre ses enfants (ex. habilitation « Primaire » couvre 1AP à 5AP) ; la couverture est calculée, non dupliquée ; un enseignant ne peut être affecté à une séance que s'il est habilité pour (matière, niveau du groupe) à la date de la séance ; supprimer une habilitation utilisée est interdit (fin de validité).
**Critères d'acceptation :** AC1 : affecter un enseignant non habilité à une séance est refusé (BR-16). AC2 : une habilitation au cycle `Primaire` autorise 3AP.

#### FR-STF-03 — Disponibilités et bornes horaires
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-STF-01
**Description :** Déclarer les disponibilités hebdomadaires, indisponibilités datées et limites horaires.
**Données :** `availability(staff_id, weekday, start_time, end_time, valid_from, valid_to)` ; `unavailability(staff_id, date_from, date_to, reason)` ; `min_weekly_hours`, `max_daily_hours`, `max_weekly_hours`.
**Règles :** saisie par l'enseignant (propre fiche) ou par le responsable ; changement soumis à validation par le responsable pédagogique si le paramètre `staff.availability.require_approval` est actif ; une indisponibilité prend le pas sur une disponibilité ; tout dépassement de borne est signalé à la création de séance.
**Critères d'acceptation :** AC1 : une séance hors disponibilité est signalée en conflit (FR-PLN-03). AC2 : dépasser `max_daily_hours` déclenche l'avertissement.

#### FR-STF-04 — Contrats et congés
**Priorité :** SHOULD · **Lot :** 2 · **Dépend de :** FR-STF-01
**Description :** Gérer contrats (type, dates, volume horaire contractuel) et absences planifiées (congé, formation, maladie).
**Règles :** les contrats sont versionnés avec dates de validité ; le **montant** de la rémunération n'est **pas** porté par le contrat RH mais par les règles de rémunération (FR-REM-01, permission distincte) ; une absence planifiée crée une indisponibilité et signale les séances à remplacer.
**Critères d'acceptation :** AC1 : le rôle `academic_hr` consulte les contrats sans voir aucun montant salarial. AC2 : poser un congé liste les séances impactées.

---

### Module GRP — Programmes, groupes, inscriptions

#### FR-PRG-01 — Programmes (cursus et formations)
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-REF-02, FR-REF-05
**Description :** Un **programme** est l'offre à laquelle on s'inscrit : pour une école, un niveau (et filière) pour une année (« 3AS Sciences expérimentales 2026-2027 ») ; pour un centre, une formation/spécialité (« Full Stack, session octobre »).
**Données :** `code` ; `name{fr,ar,en}` ; `node_id` (référentiel) ; `year_id` ; `duration` (valeur + unité) ; `description` ; `base_price` NUMERIC(14,2) ≥ 0 ; `price_version` ; `payment_terms` (modèle d'échéancier) ; `subjects[]` (voir FR-PRG-02) ; `status` ∈ `draft`, `active`, `inactive`.
**Règles :** modifier le tarif crée une **nouvelle version**, n'affecte pas les inscriptions existantes (BR-11) ; désactiver un programme n'affecte pas les inscriptions en cours ; une année clôturée fige ses programmes ; un programme `inactive` n'accepte plus d'inscription.
**Critères d'acceptation :** AC1 : changer le prix de 120 000 à 130 000 DA ne modifie pas le montant dû des inscriptions existantes. AC2 : un programme inactif n'apparaît pas dans le sélecteur d'inscription.

#### FR-PRG-02 — Matières/modules d'un programme (volume horaire et séances)
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-PRG-01, FR-REF-03
**Description :** Configurer manuellement, pour chaque programme, la liste des matières ou modules avec leur **volume horaire** et/ou leur **nombre de séances**.
**Flux principal :** 1. Ouvrir un programme. 2. Ajouter une matière/module du catalogue. 3. Saisir le volume (heures totales, heures par semaine et/ou nombre de séances) et le coefficient éventuel. 4. Enregistrer. 5. Modifier ou retirer à tout moment selon les règles ci-dessous.
**Données :** `program_subject(program_id, subject_id, total_hours?, weekly_hours?, session_count?, session_duration_minutes?, coefficient?, valid_from, valid_to)`.
**Règles :**
- Au moins **une** des trois valeurs (`total_hours`, `weekly_hours`, `session_count`) est obligatoire ; elles peuvent être combinées.
- Heures en décimal à 2 décimales (minutes sur 60) ; `session_count` entier ≥ 1 ; `session_duration_minutes` entier ≥ 15 (défaut : durée de séance du tenant).
- Si `total_hours` et `session_count` sont fournis, la durée moyenne implicite (`total_hours × 60 / session_count`) est affichée ; un écart supérieur à 10 % avec `session_duration_minutes` génère un avertissement non bloquant.
- Une même matière n'apparaît qu'une fois par programme et période de validité.
- **Retrait** : possible tant qu'aucune séance n'existe pour cette matière dans le programme ; sinon fin de validité (`valid_to`), jamais d'effacement des séances passées (BR-02, BR-10).
- **Modification** du volume : versionnée ; elle n'affecte pas les séances déjà passées, et le planning signale les écarts avec le nouveau volume (FR-PLN-02).
- Permission `program.subject.manage` : rôles par défaut `academic_hr` et `tenant_admin`.
- Un programme peut n'avoir **aucune** matière (ex. préscolaire fonctionnant par activités).
- Le volume de référence alimente le suivi de planning (FR-PLN-02), l'optimisation (FR-EDT-01) et le calcul des progressions.
**Cas limites :** valeur 0 ou négative → refus ; année clôturée → refus de modification ; copie d'un programme vers l'année suivante → copie des matières et volumes, modifiables.
**Critères d'acceptation :** AC1 : ajouter « Python — 40 h — 20 séances » à un programme de formation, puis modifier à 36 h, conserve les deux versions. AC2 : saisir une ligne sans aucune des trois valeurs est refusé. AC3 : retirer une matière ayant des séances passées met fin à sa validité sans supprimer les séances. AC4 : un programme de préscolaire sans matière est valide.

#### FR-GRP-01 — Groupes et classes
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-PRG-01, FR-SLL-01
**Description :** Un **groupe** est l'objet unifié : classe d'école, groupe de soutien, session de formation. Il est typé.
**Données :** `code` unique par (tenant, année) ; `name` ; `group_type` ∈ `class` (principal), `support`, `option`, `session` ; `program_id` ; `site_id` ; `capacity` entier ≥ 1 ; `main_room_id` optionnel ; `lead_teacher_id` optionnel ; `valid_from`, `valid_to` ; `status` ∈ `draft`, `active`, `closed`.
**Règles :**
- Un groupe `class` est la classe principale d'un élève pour un programme ; un élève a **au plus un groupe principal actif par inscription** (FR-GRP-02).
- Les groupes non principaux (soutien, option) n'entrent pas dans cette contrainte mais respectent le non-chevauchement horaire.
- Réduire la capacité sous l'effectif actif est refusé.
- Fermer un groupe conserve sa composition historique.
**Critères d'acceptation :** AC1 : créer un groupe de capacité 25 pour 3AS-Sciences/2026-2027. AC2 : réduire cette capacité à 20 avec 22 élèves actifs est refusé.

#### FR-GRP-02 — Composition des groupes
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-GRP-01, FR-INS-01
**Description :** Ajouter, retirer et transférer des élèves, avec dates de validité.
**Flux principal :** 1. Sélection d'un groupe et d'une inscription valide. 2. Vérification (capacité, unicité du principal, conflits). 3. Création de l'**affectation** `group_membership(enrollment_id, group_id, valid_from, valid_to, is_main)`. 4. Mise à jour de l'effectif et notification.
**Règles :**
- BR-07 : contrôle de capacité **atomique** (verrou sur le groupe ou compteur contraint en base) ; deux requêtes simultanées sur la dernière place → une seule réussit.
- Un transfert clôt l'affectation source (`valid_to`) et crée la cible dans la même transaction ; l'historique est conservé.
- Un élève peut appartenir à plusieurs groupes de programmes différents s'il n'y a pas de conflit horaire (FR-PLN-03).
- Seule une inscription `validated` ou `pending` peut être affectée ; une inscription `cancelled` ne peut plus l'être.
**Cas limites :** retrait d'un élève avec présences saisies → conservées ; groupe plein → message « capacité atteinte » proposant la liste d'attente (SHOULD : file d'attente ordonnée notifiée à la libération d'une place).
**Critères d'acceptation :** AC1 : 30 requêtes simultanées d'affectation sur un groupe de capacité 25 produisent exactement 25 affectations (`TST-07`). AC2 : un second groupe principal pour la même inscription est refusé. AC3 : l'historique d'un transfert montre l'ancien et le nouveau groupe avec dates.

#### FR-INS-01 — Inscription
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-ELV-01, FR-PRG-01
**Description :** Inscrire un élève à un programme.
**Flux principal :**
1. Sélectionner ou créer l'élève.
2. Choisir le programme (actif).
3. Le système crée l'inscription `pending`, **copie** prix et conditions (BR-11), génère le plan d'échéances (module `student_finance` actif).
4. Affecter l'inscription à un groupe (FR-GRP-02).
5. Enregistrer le paiement (FR-FIN-02, si le module est actif).
6. Un utilisateur habilité valide (`validated`) ou annule (`cancelled`).
**Données :** `enrollment(student_id, program_id, year_id, status, agreed_price, discount_total, terms_snapshot, created_by, validated_by, validated_at, cancel_reason, idempotency_key)`.
**Règles :**
- Un même élève ne peut avoir qu'une inscription non annulée par programme.
- Un élève peut avoir plusieurs inscriptions de programmes différents.
- La validation exige : groupe principal affecté (pour les programmes avec groupe) et élève non bloqué.
- Le quota d'élèves actifs du plan est contrôlé (FR-PLT-02).
**Critères d'acceptation :** AC1 : double clic sur « Inscrire » ne crée qu'une inscription (`TST-08`). AC2 : inscrire un élève bloqué est refusé. AC3 : le prix de l'inscription reste inchangé après modification du programme.

#### FR-INS-02 — États pédagogique et financier
**Priorité :** MUST · **Lot :** 2 (pédagogique), Lot 3 (financier) · **Dépend de :** FR-INS-01
**Description :** Deux dimensions d'état (BR-12).

| Dimension | Valeurs | Évolution |
|---|---|---|
| Pédagogique | `pending` (en attente), `validated` (validée), `suspended` (suspendue), `cancelled` (annulée) | Manuelle, par utilisateur habilité (`enrollment.enrollment.validate/suspend/cancel`) |
| Financier | `unpaid` (non payé), `partially_paid` (payé partiellement), `paid` (payé) | **Calculé** : paiements validés − contre-opérations vs montant dû ; ne se saisit jamais |

**Règles :**
- Le libellé combiné affiché reprend les anciens libellés (« en attente », « payé partiellement », « payé », « suspendu », « annulé ») sans fusionner les deux états en base.
- Retard : une échéance non réglée à sa date rend l'inscription `overdue` (indicateur calculé, pas un état).
- Aucun paiement ne réactive une inscription `suspended` ou `cancelled` (BR-12).
**Critères d'acceptation :** AC1 : paiement partiel puis complet fait passer le financier de `unpaid` à `partially_paid` puis `paid` sans toucher à l'état pédagogique (`TST-09`). AC2 : un paiement sur une inscription suspendue laisse l'état suspendu.

#### FR-INS-03 — Annulation, suspension, transfert d'inscription
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-INS-02
**Règles :**
- Une inscription **annulée est figée** : aucune modification ni nouvelle affectation ; une correction financière ultérieure passe par une opération liée (BR-03) ; **aucun remboursement automatique** (FR-FIN-04).
- Suspension : motif obligatoire ; réversible par l'habilité ; l'accès aux cours (lot 5) est bloqué pendant la suspension.
- Transfert d'un programme à un autre : annule l'inscription source (état `cancelled`, motif `transfer`), crée la cible liée (`transferred_from`), et propose le report des paiements par opération liée validée par la finance.
**Critères d'acceptation :** AC1 : modifier une inscription annulée → refusé avec message explicite. AC2 : le transfert conserve le lien entre les deux inscriptions et leur historique.

#### FR-INS-04 — Politique d'accès liée au paiement
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-INS-02, FR-REF-06
**Description :** Calculer un indicateur `access_granted` utilisé par le LMS et le portail.
**Règles :** par défaut, accès accordé si l'inscription est `validated` ; paramètre `enrollment.access.require_payment` ∈ `none`, `first_installment`, `full` (défaut `none`, à valider par chaque tenant, OD-05) ; calculé à la demande, jamais stocké comme vérité.
**Critères d'acceptation :** AC1 : avec `first_installment`, une inscription sans paiement validé renvoie `access_granted = false`.

#### FR-INS-05 — Passage annuel
**Priorité :** MUST · **Lot :** 2 · **Dépend de :** FR-INS-01, FR-REF-05 · *(Ajoutée en 1.2, ND-03)*
**Description :** En fin d'année, l'habilité enregistre pour chaque inscription validée une décision : passage, redoublement, départ ou fin de cycle.
**Flux principal :** 1. Il sélectionne une ou plusieurs inscriptions validées d'une année. 2. Il choisit la décision, le motif, et pour un passage ou un redoublement le groupe cible et l'échéance. 3. Le système crée, pour un passage ou un redoublement, une nouvelle inscription `pending` dans l'année cible (FR-INS-01), avec sa facture.
**Données :** `decision` ∈ `promoted`, `repeated`, `left`, `completed` ; `reason` obligatoire ; `target_group_id`, `due_date` pour `promoted` et `repeated` ; `decided_by`.
**Règles :**
- Le groupe cible appartient à une année scolaire postérieure ; un redoublement conserve le niveau, un passage le change.
- Un lot de décisions est atomique : si une seule inscription cible échoue (capacité, doublon), aucune n'est créée.
- L'inscription source, ses factures, ses notes et son historique restent inchangés (BR-03, BR-10).
- Une décision par inscription source ; une relance identique ne crée rien ; une décision différente est refusée.
**Critères d'acceptation :** AC1 : un lot dont une cible dépasse la capacité ne crée aucune inscription. AC2 : une relance identique ne crée pas de doublon. AC3 : les inscriptions, factures et notes de l'année source sont inchangées. AC4 : un redoublement qui change de niveau, ou un passage vers la même année, est refusé.

---

### Module FIN — Finance des élèves

#### FR-FIN-01 — Échéancier, tarification et remises
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-INS-01
**Description :** Définir le montant dû et les échéances d'une inscription.
**Données :** `installment(enrollment_id, seq, due_date, amount)` ; `adjustment(enrollment_id, type ∈ discount, debt_waiver, penalty, amount, reason, approved_by)`.
**Règles :**
- Somme des échéances = montant convenu − remises (contrôle bloquant, arrondi BR-04).
- Une remise, une annulation de dette ou une modification d'échéancier exige `finance.discount.grant` / `finance.schedule.manage`, un **motif**, et une approbation de la direction au-delà d'un seuil configurable.
- Le plan d'échéances peut provenir d'un modèle du programme ; les modifications sont versionnées.
**Critères d'acceptation :** AC1 : un échéancier dont la somme diffère du dû est refusé. AC2 : une remise sans motif est refusée.

#### FR-FIN-02 — Enregistrement d'un paiement
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-FIN-01
**Description :** Enregistrer un paiement total ou partiel, lié obligatoirement à une inscription.
**Flux principal :**
1. Rechercher l'élève/l'inscription ; le système affiche dû, déjà payé, solde.
2. Saisir montant, date, moyen de paiement, référence externe éventuelle, justificatif optionnel.
3. Le système génère une `reference` unique et une clé d'idempotence côté formulaire.
4. Création en une transaction atomique du paiement et recalcul du solde (BR-06).
5. Le reçu est disponible (FR-FIN-05).
**Données :** `payment(id, enrollment_id, amount > 0, paid_on ≤ aujourd'hui, method_code, external_ref, reference unique, status ∈ pending_validation, validated, reversed, created_by, validated_by, idempotency_key, self_validated)`.
**Règles :**
- Moyens de paiement **paramétrables par tenant** (espèces, chèque, virement, CCP, autre) ; devise unique DZD.
- Rôle `front_office` : encaissement **sur délégation** (`finance.payment.create.delegated`) ; l'enregistrement est `pending_validation` jusqu'à validation par la finance (FR-FIN-03).
- Un paiement sans inscription est impossible.
- Le montant ne peut pas dépasser le solde, sauf saisie explicite d'un **trop-perçu** (FR-FIN-04).
- Une relance réseau ou un double clic avec la même clé d'idempotence renvoie le paiement existant (BR-05).
**Cas limites :** montant ≤ 0 → refus ; date future → refus ; période clôturée → refus (BR-15) ; inscription annulée → refus de nouveau paiement hors correction liée.
**Critères d'acceptation :** AC1 : 5 envois simultanés de la même demande créent un seul paiement (`TST-10`). AC2 : le solde affiché après paiement est cohérent avec la somme des paiements validés. AC3 : `reference` est unique et non réutilisable.

#### FR-FIN-03 — Validation, immutabilité et corrections
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-FIN-02, FR-IAM-05
**Description :** Un paiement validé est immuable ; toute erreur est corrigée par contre-opération.
**Flux principal :** 1. Un utilisateur disposant de `finance.payment.validate` (≠ auteur, BR-08) valide. 2. Le paiement devient `validated` et entre dans le solde. 3. En cas d'erreur, création d'une **contre-opération liée** (`reversal` avec `reverses_payment_id`, motif obligatoire) puis nouvelle saisie correcte.
**Règles :**
- Aucun champ d'un paiement validé n'est modifiable (contrainte applicative **et** base : interdiction d'`UPDATE` hors champs d'état technique).
- Un paiement `pending_validation` peut être corrigé ou annulé par son auteur avant validation.
- Option tenant `finance.allow_self_validation` (BR-08) : si active, un utilisateur possédant le droit peut valider son propre paiement, ce qui est marqué et audité.
- Auteur, date, motif consultables sur chaque paiement et contre-opération.
**Critères d'acceptation :** AC1 : tenter de modifier le montant d'un paiement validé échoue côté API et côté base (`TST-11`). AC2 : la contre-opération annule l'effet sur le solde sans supprimer l'original. AC3 : un utilisateur ne peut pas valider un paiement qu'il a créé (sauf option), avec message explicite (`TST-05`).

#### FR-FIN-04 — Remboursements et trop-perçu
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-FIN-03
**Description :** Gérer remboursements et trop-perçus de façon explicite.
**Flux principal :** 1. La finance crée une **demande de remboursement** (montant, motif, inscription). 2. La direction approuve ou rejette. 3. Une fois approuvé, le remboursement effectif est enregistré (moyen, date, référence) et réduit le solde net.
**Règles :**
- Circuit **distinct** du paiement ; approbateur ≠ demandeur.
- Un remboursement ne dépasse pas le net payé.
- Une inscription annulée **ne déclenche aucun remboursement automatique** ; la finance décide (remboursement, report vers une autre inscription, conservation).
- Un **trop-perçu** est enregistré comme crédit sur l'inscription avec une décision explicite : rembourser, affecter à une autre inscription du même élève, ou conserver (motif).
**Critères d'acceptation :** AC1 : annuler une inscription payée ne crée aucun mouvement financier. AC2 : un remboursement sans approbation ne peut pas être exécuté.

#### FR-FIN-05 — Reçus
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-PDF-01
**Description :** Générer un reçu PDF pour un paiement validé (et pour un remboursement).
**Règles :** numéro unique `REC-{année}-{séquence 6 chiffres}` par tenant ; contenu figé à la génération (nom, montant, moyen, reference, solde après paiement, identité du tenant) ; régénération = copie identique marquée « duplicata » ; langue configurable (ar/fr).
**Critères d'acceptation :** AC1 : deux paiements simultanés ne reçoivent jamais le même numéro. AC2 : un reçu arabe est correctement rendu (`TST-17`).

#### FR-FIN-06 — Impayés et retards
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-FIN-01
**Description :** Lister les soldes ouverts et retards par élève, programme, groupe, période.
**Règles :** retard calculé à partir des échéances et de la date du jour ; export contrôlé ; notifications d'échéance (J-3, J, J+7, paramétrables) vers l'intéressé autorisé ; les relances sont **manuelles ou semi-automatiques**, jamais de sanction automatique (suspension ou blocage) sans action humaine.
**Critères d'acceptation :** AC1 : le total des soldes ouverts est égal à dû − paiements validés nets pour tous les élèves listés. AC2 : un enseignant n'accède pas à cette liste.

#### FR-FIN-07 — Clôture de période financière
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-FIN-03
**Description :** Clôturer un mois (ou période) ; réouverture exceptionnelle.
**Règles :** après clôture, aucune création ni validation avec une date dans la période ; les corrections se font dans la période ouverte via opération liée ; réouverture : permission distincte, motif obligatoire, approbation direction, journalisée ; un rapport de rapprochement est généré à la clôture.
**Critères d'acceptation :** AC1 : tenter de saisir un paiement daté d'une période clôturée est refusé. AC2 : la réouverture est tracée.

---

### Module DEP — Dépenses

#### FR-DEP-01 — Catégories de dépenses
**Priorité :** MUST · **Lot :** 3
**Description :** Catégories paramétrables.
**Règles :** catégories initiales : `salaries`, `advertising`, `missions`, `rent`, `equipment`, `other` (libellés traduisibles) ; `salaries` est **système** (non supprimable, alimentée par FR-REM-04) ; `other` exige un libellé et un motif ; archivage plutôt que suppression ; imputation optionnelle à un programme.
**Critères d'acceptation :** AC1 : la catégorie `salaries` ne peut pas être saisie manuellement (évite le double comptage).

#### FR-DEP-02 — Saisie et cycle de validation
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-DEP-01, FR-FIC-01
**Description :** Enregistrer une dépense et la faire valider, puis la payer.
**Données :** `expense(id, date, amount > 0, category_id, beneficiary, reason, program_id?, validation_status ∈ draft, submitted, validated, rejected, payment_status ∈ unpaid, partially_paid, paid, created_by, validated_by, attachment_ids[])`.
**Règles :**
- Distinguer **dépense engagée** (validée) et **paiement effectif** (BR du tableau de bord).
- Validation par `expense.expense.validate` ≠ auteur ; approbation direction au-delà du seuil.
- Justificatif consultable uniquement par `expense.attachment.read`.
- Filtres et exports par période, catégorie, bénéficiaire, état.
**Critères d'acceptation :** AC1 : un utilisateur ne valide pas sa propre dépense. AC2 : une dépense validée n'est plus modifiable. AC3 : un enseignant ne peut ni voir ni télécharger un justificatif.

#### FR-DEP-03 — Corrections et annulations
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-DEP-02
**Règles :** correction d'une dépense validée par **contre-opération liée** avec motif ; pas d'effacement ; clôture de période appliquée (BR-15).
**Critères d'acceptation :** AC1 : annuler une dépense validée crée une écriture négative liée et conserve l'originale.

---

### Module REM — Rémunérations

> Les lots 3 et 4 sont complémentaires : au lot 3, les rémunérations couvrent le salaire fixe, les primes, les ajustements, le tarif horaire, le tarif par séance et le mode mixte, avec tarifs par niveau, sur des **heures et séances saisies manuellement** ; au lot 4, les heures et séances proviennent des **séances assurées et validées** du pointage (FR-PTG-03).

#### FR-REM-01 — Règles de rémunération versionnées
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-STF-01, FR-REF-02
**Description :** Définir comment chaque personne est rémunérée. Enseignants, instructeurs et formateurs : à l'**heure**, à la **séance**, au **salaire mensuel fixe** ou en **mode mixte**, avec possibilité d'un **tarif par niveau**. Personnel administratif : **salaire mensuel fixe** uniquement.
**Données :**
- `pay_rule(staff_id, kind ∈ fixed_monthly, hourly, per_session, mixed, monthly_amount?, variable_basis? ∈ hourly, per_session, included_quantity?, valid_from, valid_to)` ; primes récurrentes optionnelles.
- `pay_rule_rate(pay_rule_id, node_id?, unit_amount)` : tarifs unitaires. `node_id` vide = **tarif par défaut** ; `node_id` renseigné = tarif d'un niveau, d'une filière ou d'une spécialité du référentiel. L'unité est l'**heure** pour `hourly` et pour `mixed` à base horaire, la **séance** pour `per_session` et pour `mixed` à base séance.

| Type | Champs requis | Principe |
|---|---|---|
| `fixed_monthly` | `monthly_amount` | Montant mensuel fixe |
| `hourly` | au moins le tarif par défaut | Σ (heures assurées × tarif applicable) |
| `per_session` | au moins le tarif par défaut | Σ (séances assurées × tarif applicable) |
| `mixed` | `monthly_amount`, `variable_basis`, tarif par défaut, `included_quantity` (≥ 0, défaut 0) | Part fixe + part variable au-delà de la quantité incluse |

**Règles :**
- Types de personnel `teacher_internal` et `teacher_external` : les quatre types sont autorisés. Types `administrative` et `other` : `fixed_monthly` uniquement ; toute autre saisie est refusée.
- Montants et tarifs toujours > 0 (DZD) ; `included_quantity` exprimée en heures (base horaire) ou en séances (base séance).
- `fixed_monthly` ne porte aucun tarif unitaire ; `hourly`, `per_session` et `mixed` n'acceptent que des tarifs exprimés dans l'unité de leur règle. Le `node_id` d'un tarif est une référence au référentiel du tenant (BR-13), jamais un texte libre.
- **Résolution du tarif applicable** à une séance : le tarif du nœud du **programme du groupe** de la séance ; s'il n'existe pas, celui du **plus proche ancêtre** dans le référentiel (ex. tarif du cycle `Secondaire` pour une séance de `2AS`) ; à défaut, le **tarif par défaut**. Le tarif le plus spécifique l'emporte ; la date de la séance détermine la version de la règle. La règle et les tarifs appliqués sont ceux de **la personne qui a assuré** la séance (le remplaçant pour une séance remplacée).
- Un seul tarif par (règle, nœud). Si aucun tarif ne peut être résolu pour une séance (règle sans défaut ni tarif de niveau), la ligne est marquée **« tarif manquant »** : elle n'est jamais comptée à zéro silencieusement (BR-16) et bloque la validation de la rémunération.
- Accès réservé à `payroll.rule.manage` ; versionné avec dates de validité (BR-10) ; une règle ne change qu'à partir du **premier jour d'un mois** ; une période utilise la règle applicable ; changement rétroactif interdit sans ajustement.
- Un membre du personnel peut avoir une règle sans compte de connexion.
- Une seule règle active par personne et par période.
**Critères d'acceptation :** AC1 : modifier un tarif horaire pour juin ne change pas le calcul de mai. AC2 : le rôle `academic_hr` n'a pas accès aux règles. AC3 : attribuer `hourly`, `per_session` ou `mixed` à un membre `administrative` est refusé. AC4 : passer un enseignant de `hourly` à `fixed_monthly` à partir du 1er du mois suivant conserve le calcul des mois précédents. AC5 : avec un tarif `Secondaire` et un tarif par défaut, une séance de `2AS` utilise le tarif `Secondaire` et une séance de `4AM` le tarif par défaut. AC6 : une règle `mixed` sans part fixe ou sans base variable est refusée. AC7 : une règle `fixed_monthly` portant un tarif unitaire est refusée. AC8 : un second tarif pour le même couple (règle, nœud), ou un tarif sur un nœud hors du référentiel du tenant, est refusé.

#### FR-REM-02 — Génération mensuelle
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-REM-01
**Description :** Générer les rémunérations d'un mois pour tout ou partie du personnel.
**Flux principal :** 1. Choisir le mois. 2. Le système calcule pour chaque personne une ligne `brouillon` (base, heures, taux, primes, ajustements, net à payer). 3. Revue. 4. Validation.
**Règles :**
- Distinguer formateurs et administratifs.
- Une seule rémunération par (personne, période) : génération répétée = mise à jour du brouillon, jamais de doublon (BR-05).
- États : `draft`, `validated`, `partially_paid`, `paid`. La **validation fige** le calcul.
- **Unités assurées** : pour un mois donné, chaque séance assurée par la personne (y compris comme remplaçant, FR-PTG-03) constitue une unité. Lot 3 : saisie manuelle de lignes (niveau, nombre d'heures ou de séances). Lot 4 : heures et séances issues du pointage **validé**. Les séances planifiées non pointées ne génèrent **aucune** rémunération définitive.
- **`hourly`** : base = Σ (heures de la séance × tarif horaire applicable). Heures calculées en minutes ÷ 60 ; chaque ligne arrondie au centime (BR-04), le total est la somme des lignes.
- **`per_session`** : base = Σ (tarif par séance applicable) pour chaque séance assurée, quelle que soit sa durée. Une séance validée « présent » ou « en retard » compte ; « absent » et « annulée » ne comptent pas.
- **`fixed_monthly`** : base = `monthly_amount`, indépendante des séances et des heures. Les retenues éventuelles (absence, congé sans solde) passent par un **ajustement tracé** (FR-REM-03).
- **`mixed`** : base = `monthly_amount` + part variable. La part variable rémunère les unités (heures ou séances selon `variable_basis`) **au-delà de `included_quantity`**. Les unités incluses sont celles **les plus anciennes du mois** (ordre chronologique) ; chaque unité excédentaire est payée à son tarif applicable (par niveau le cas échéant). Avec `included_quantity = 0`, toutes les unités sont rémunérées en plus du fixe.
- **Proratisation** : pour un mois incomplet (entrée ou départ en cours de mois), la **part fixe** (`fixed_monthly`, part fixe de `mixed`) est proratisée au nombre de jours calendaires, désactivable par paramètre et ajustable avant validation. Les parts variables ne sont jamais proratisées.
- Les primes récurrentes et ponctuelles s'ajoutent à la base pour tous les types.
- Cas non tranchés ci-dessus pour `hourly`, `per_session` et `mixed` (séance annulée rémunérée ou non, absence justifiée, tarif d'un remplaçant) : documentés et paramétrables avant mise en production (OD-06) ; jusqu'à cette décision, une séance annulée ou non assurée ne compte pas (FR-PTG-03).
**Critères d'acceptation :** AC1 : relancer la génération pour un mois ne crée pas de doublon (`TST-12`). AC2 : un brouillon est recalculable ; un calcul validé ne l'est pas. AC3 : un enseignant `hourly` avec 42,5 h validées à 1 500 DA/h (tarif par défaut) reçoit une base de 63 750,00 DA. AC4 : un enseignant `fixed_monthly` reçoit son montant fixe quel que soit le nombre de séances pointées. AC5 : un enseignant `hourly` avec 10 h en `2AS` (tarif Secondaire 2 000 DA/h) et 8 h en `4AM` (défaut 1 500 DA/h) reçoit 20 000,00 + 12 000,00 = 32 000,00 DA. AC6 : un enseignant `per_session` avec 12 séances à 3 000 DA reçoit 36 000,00 DA, quelle que soit la durée des séances. AC7 : un enseignant `mixed` avec 40 000 DA fixes, base horaire, 20 h incluses, 26 h assurées à 1 500 DA/h reçoit 40 000 + (6 × 1 500) = 49 000,00 DA. AC8 : une séance sans tarif résolvable produit une ligne « tarif manquant » et empêche la validation. AC9 : un mois incomplet proratise la part fixe de `mixed` mais pas sa part variable. AC10 : un enseignant `per_session` avec 5 séances en `2AS` (tarif Secondaire 3 500 DA) et 7 séances en `4AM` (défaut 3 000 DA) reçoit 17 500,00 + 21 000,00 = 38 500,00 DA. AC11 : un enseignant `mixed` à base séance, 40 000 DA fixes, 10 séances incluses, ayant assuré 10 séances `2AS` puis 4 séances `4AM` (défaut 3 000 DA), reçoit 40 000 + (4 × 3 000) = 52 000,00 DA ; les 10 séances les plus anciennes sont les séances incluses. AC12 : un enseignant `mixed` à base horaire avec 20 h incluses et 18 h assurées reçoit uniquement sa part fixe ; la part variable n'est jamais négative. AC13 : une séance remplacée est comptée pour le remplaçant, selon sa règle et ses tarifs, et non pour le titulaire.

#### FR-REM-03 — Détail, primes et ajustements
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-REM-02
**Règles :** le détail du calcul est consultable ligne par ligne (séance, niveau, heures ou séances, tarif appliqué, part fixe, part variable, primes, ajustements) ; le détail conserve une **copie** du niveau, de la quantité et du tarif appliqués : modifier ensuite la règle ne réécrit aucun calcul validé (BR-03, BR-10) ; une correction après validation passe par un **ajustement tracé** (montant ±, motif, auteur) appliqué au mois courant ou à une ligne de correction liée ; validation par `payroll.run.validate` ≠ auteur du calcul.
**Critères d'acceptation :** AC1 : tenter de modifier un calcul validé est refusé ; un ajustement tracé est accepté. AC2 : après validation, modifier un tarif de la règle ne change ni le détail ni le total du calcul.

#### FR-REM-04 — Versements et lien avec les dépenses
**Priorité :** MUST · **Lot :** 3 · **Dépend de :** FR-REM-03, FR-DEP-01
**Description :** Enregistrer les versements (totaux ou partiels) d'une rémunération validée.
**Règles :** un versement crée **automatiquement** la dépense `salaries` liée (même transaction, BR-06) ; la somme des versements ≤ net à payer ; montants à payer, versés et restants par personne et période ; aucune seconde saisie ; empêcher un versement en double (idempotence) ; correction par contre-opération.
**Critères d'acceptation :** AC1 : un versement de 50 % passe l'état à `partially_paid` et crée une dépense de ce montant. AC2 : les dépenses de catégorie `salaries` du mois égalent les versements du mois (aucun double comptage, `TST-13`).

#### FR-REM-05 — Consultation personnelle
**Priorité :** SHOULD · **Lot :** 3 · **Dépend de :** FR-REM-04
**Règles :** si le tenant l'active (`payroll.self_view`), un enseignant voit ses heures validées et ses rémunérations versées, jamais celles des autres.

---

### Module PLN — Planification manuelle

#### FR-PLN-01 — Calendrier et créneaux
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-REF-05, FR-REF-06
**Description :** Définir jours ouverts, fermetures, vacances et grille de créneaux.
**Données :** `closure(date_from, date_to, reason, scope ∈ tenant, site)` ; `time_slot(code, start, end)` ; durée de séance par défaut.
**Règles :** par défaut dimanche à jeudi ouverts ; fuseau `Africa/Algiers` ; les exceptions datées priment sur la règle hebdomadaire.
**Critères d'acceptation :** AC1 : aucune séance ne peut être créée un jour fermé.

#### FR-PLN-02 — Créneaux récurrents et séances
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-GRP-01, FR-STF-02, FR-SLL-02
**Description :** Planifier un cours hebdomadaire récurrent d'un groupe, générant des **séances** datées.
**Flux principal :** 1. Choisir groupe, matière, enseignant, salle, jour, créneau, période de validité. 2. Le système contrôle les conflits (FR-PLN-03). 3. Il génère les séances. 4. Édition d'une séance isolée ou de la série.
**Données :** `session(id, group_id, subject_id, teacher_id, room_id, start_at, end_at, status ∈ planned, delivered, cancelled, replaced, version_id, locked)` ; `recurrence(...)`.
**Règles :** une séance **planifiée** est distincte d'une séance **assurée** (BR-14, FR-PTG-03) ; un **suivi de volume** affiche par groupe et par matière/module les heures et séances planifiées, assurées et restantes par rapport au volume de FR-PRG-02 ; modifier une série ne modifie pas les séances déjà pointées ; séances `locked` conservées en cas de régénération.
**Critères d'acceptation :** AC1 : une récurrence hebdomadaire sur un trimestre génère le bon nombre de séances en excluant fermetures et jours non ouverts. AC2 : éditer une séance isolée n'affecte pas la série.

#### FR-PLN-03 — Détection de conflits
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-PLN-02
**Description :** Détecter les conflits avant enregistrement.
**Règles — conflits bloquants :** chevauchement pour un **enseignant**, une **salle**, un **groupe**, un **élève** (y compris entre groupes de programmes différents) ; enseignant non habilité (FR-STF-02) ; enseignant ou salle indisponible/fermé ; salle de capacité inférieure à l'effectif ou à la capacité du groupe.
**Règles — avertissements :** dépassement de bornes horaires de l'enseignant ; dépassement du volume prévu (heures par semaine, heures totales ou nombre de séances) ; changement de site sans temps de déplacement.
**Règles — contrainte base :** pour les chevauchements strictement bloquants d'enseignant et de salle, une **contrainte d'exclusion PostgreSQL** (plage horaire) double le contrôle applicatif.
**Critères d'acceptation :** AC1 : deux séances simultanées pour le même enseignant sont refusées même en requêtes concurrentes (`TST-15`). AC2 : un élève dans deux groupes de formations différentes avec séances simultanées déclenche un conflit.

#### FR-PLN-04 — Vues et exports
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-PLN-02, FR-PDF-01
**Description :** Vues par groupe, enseignant, salle, élève ; export PDF et calendrier ICS.
**Règles :** vue hebdomadaire/mensuelle, RTL correct ; fuseau Africa/Algiers dans l'ICS ; exceptions datées (annulation, remplacement) reflétées ; portée par rôle (un élève voit son planning, un enseignant le sien).
**Critères d'acceptation :** AC1 : l'ICS importé dans un agenda affiche les bonnes heures locales.

#### FR-PLN-05 — Versions et publication
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-PLN-02
**Description :** Un planning est préparé en brouillon, validé, puis publié.
**Règles :** versions conservées avec auteur et motif ; comparaison de deux versions ; publication par `schedule.timetable.publish` ; **notification uniquement après publication** et uniquement aux personnes concernées (BR-19) ; un planning publié ne contient **aucun conflit bloquant**.
**Critères d'acceptation :** AC1 : publier avec un conflit bloquant est refusé. AC2 : aucune notification avant publication.

#### FR-PLN-06 — Annulations et remplacements
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-PLN-02, FR-STF-02
**Description :** Annuler une séance ou désigner un remplaçant.
**Règles :** motif obligatoire ; le remplaçant doit être habilité et disponible (conflits recontrôlés) ; la séance passe à `replaced` avec lien vers le remplaçant ; les séances non concernées sont inchangées ; notification aux élèves et enseignants concernés ; l'information alimente le pointage et la rémunération (heures assurées par le remplaçant).
**Critères d'acceptation :** AC1 : un remplaçant non habilité est refusé. AC2 : le remplacement apparaît dans l'historique du groupe.

---

### Module PTG — Pointage et présences

#### FR-PTG-01 — Saisie du pointage des enseignants
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-PLN-02
**Description :** Le surveillant général enregistre, séance par séance, la présence effective de l'enseignant.
**Flux principal :** 1. Ouvrir la liste des séances du jour. 2. Pour chaque séance, saisir `present`, `late` (minutes), `absent`, `replaced` (remplaçant). 3. Enregistrer (état `submitted`).
**Données :** `teacher_attendance(session_id, status, late_minutes, actual_start, actual_end, replacement_teacher_id, note, entered_by, entered_at, validation_status ∈ submitted, validated, rejected, validated_by, validated_at)`.
**Règles :** manuel uniquement (pas de QR, biométrie ni géolocalisation) ; une séance sans saisie reste « non pointée », distincte d'« absent » (BR-14) ; saisie possible jusqu'à J+N paramétrable ; modification avant validation par l'auteur.
**Critères d'acceptation :** AC1 : une séance non pointée n'est pas comptée comme absence. AC2 : saisir un pointage pour une séance future est refusé.

#### FR-PTG-02 — Validation du pointage
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-PTG-01, FR-IAM-05
**Description :** Le responsable pédagogique et RH valide ou rejette les pointages.
**Règles :** validation individuelle ou par lot ; **validateur ≠ auteur de la saisie** (BR-08) ; rejet avec motif renvoyant la saisie à son auteur ; notification des rejets ; validation par période (semaine, mois).
**Critères d'acceptation :** AC1 : un utilisateur cumulant les deux rôles ne peut valider que les pointages saisis par d'autres (`TST-05`).

#### FR-PTG-03 — Heures assurées, corrections et verrouillage
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-PTG-02
**Description :** Produire les **heures assurées validées** par enseignant et période.
**Règles :** heures = durée effective (ou planifiée si présent sans écart) des séances `validated` ; remplaçant crédité ; séance annulée ou non assurée = 0 h ; un pointage validé est **verrouillé** ; toute correction crée un **ajustement tracé** (ancienne valeur, nouvelle valeur, motif, auteur) ; une période déjà intégrée à une rémunération validée n'est modifiée que par ajustement de rémunération (FR-REM-03) ; les heures planifiées ne génèrent pas seules une rémunération.
**Critères d'acceptation :** AC1 : les heures d'un mois = somme des séances validées du mois (rapprochement). AC2 : corriger un pointage validé crée une trace sans modifier l'original.

#### FR-PRE-01 — Appel des élèves
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-PLN-02, FR-GRP-02
**Description :** Faire l'appel par séance.
**Flux principal :** 1. L'enseignant (ou le surveillant) ouvre la séance. 2. La liste des élèves affectés au groupe **à cette date** s'affiche. 3. Marquer présent / absent / retard / excusé. 4. Enregistrer.
**Règles :** appel non saisi ≠ absence (BR-14) ; justification et correction tracées ; seuils de notification d'absence configurables ; notification aux responsables légaux uniquement quand le portail ou les contacts sont activés ; un enseignant n'appelle que ses séances.
**Critères d'acceptation :** AC1 : un élève affecté après la date de la séance n'apparaît pas dans l'appel. AC2 : modifier un appel conserve l'ancienne valeur.

---

### Module TDB — Tableaux de bord

#### FR-TDB-01 — Indicateurs financiers
**Priorité :** MUST · **Lot :** 4 · **Dépend de :** FR-FIN-*, FR-DEP-*, FR-REM-*
**Description :** Tableau de bord direction/finance. Chaque indicateur affiche période, filtres, date de mise à jour, base de calcul, et permet de **retrouver les opérations sources** selon les droits.

| Indicateur | Définition |
|---|---|
| Revenus facturés | Somme des montants dus des inscriptions non annulées, par mois d'exigibilité |
| Encaissements nets | Paiements validés − contre-opérations − remboursements, par date de paiement |
| Dépenses engagées | Dépenses validées par date de dépense |
| Dépenses payées | Dépenses `paid` / `partially_paid` (part payée) par date de paiement |
| Charges salariales | Rémunérations validées (engagées) et versements (payés), jamais additionnées à la catégorie `salaries` des dépenses (double comptage exclu) |
| Résultat net de gestion | Encaissements nets − dépenses payées (convention affichée et paramétrable : base facturée/engagée possible). **Ce n'est ni une trésorerie ni un résultat comptable certifié** ; intitulé et formule visibles |
| Rentabilité d'un programme | Revenus attribués − coûts directs − quote-part de charges communes ; **méthode de répartition affichée**, coûts manquants signalés |
| Impayés | Soldes ouverts et retards (FR-FIN-06) |

**Règles :** les indicateurs d'un module inactif n'apparaissent pas (BR-20) ; calcul reproductible depuis les opérations ; accès limité aux permissions `dashboard.finance.read`.
**Critères d'acceptation :** AC1 : la somme des encaissements nets d'un mois égale la somme des paiements validés moins corrections de ce mois (`TST-16`). AC2 : charges salariales + autres dépenses ne comptent jamais deux fois un versement.

#### FR-TDB-02 — Indicateurs opérationnels
**Priorité :** MUST · **Lot :** 4
**Description :** Remplissage (effectif actif / capacité) par groupe et programme ; inscriptions en attente ; conversion CRM ; absences ; taux de pointage validé ; séances non pointées.
**Règles :** filtres temporels ; date de mise à jour ; portée par rôle (un enseignant voit ses groupes, le surveillant la présence).
**Critères d'acceptation :** AC1 : le taux de remplissage d'un groupe = élèves actifs / capacité, identique à la liste de composition.

#### FR-TDB-03 — Traçabilité et exports
**Priorité :** SHOULD · **Lot :** 4
**Règles :** chaque chiffre est cliquable vers la liste des opérations sources ; exports PDF/Excel respectant les permissions ; mise en cache avec invalidation sur événement.

---

### Modules ultérieurs (cadrés — à détailler avant le lot)

#### Module LMS — Pédagogie (Lot 5)

**FR-LMS-01 — Leçons.** MUST · Dépend de : FR-GRP-01, FR-FIC-01. Organisation matière → chapitre → leçon ; titre, objectifs, prérequis, durée, contenu enrichi, pièces jointes, liens autorisés. Cycle brouillon → relecture facultative → publication programmée → archivage ; **versions conservées** ; ciblage par groupe ; prévisualisation en mode élève ; ressources privées sous contrôle d'accès ; accès conditionné par `access_granted` (FR-INS-04). AC : modifier une leçon ne modifie pas les tentatives de quiz déjà passées.

**FR-LMS-02 — Devoirs.** MUST. Échéance, dépôt de fichiers, état de remise (non remis, remis, en retard), accusé de réception, reprise après coupure réseau (dépôt idempotent), retour de correction. AC : un dépôt relancé après coupure ne crée qu'une remise.

**FR-LMS-03 — Quiz.** MUST. Choix multiples corrigés automatiquement, questions ouvertes corrigées par l'enseignant ; barème, durée, nombre de tentatives, date de visibilité des résultats. AC : le résultat d'un élève n'est pas visible avant la date de visibilité.

**FR-LMS-04 — Notes et moyennes simples.** MUST. Carnet de notes par groupe, coefficients, moyennes pondérées, règles d'arrondi et de passage **versionnées** par année ou programme ; validation avant publication ; **note manquante ≠ zéro** (BR-14) ; toute modification conserve ancienne valeur, auteur, date, motif. **Hors périmètre : bulletins officiels ministériels.** Exports simples (liste de notes). AC : changer un coefficient pour N+1 ne modifie pas N.

**FR-LMS-05 — Progression.** SHOULD. Distinguer ouverture, achèvement déclaré, maîtrise vérifiée ; une consultation n'est pas une preuve de compréhension.

#### Module EDT — Optimisation de l'emploi du temps (Lot 6)

**FR-EDT-01 — Génération par contraintes.** MUST · Dépend de : FR-PLN-*, FR-STF-*. Moteur de programmation par contraintes (OR-Tools CP-SAT) exécuté en tâche de fond avec état d'avancement. **Contraintes obligatoires** : aucun chevauchement (enseignant, salle, groupe, élève y compris inter-formations) ; disponibilités et fermetures ; habilitations ; capacités et équipements des salles ; volumes horaires requis ; bornes horaires et pauses ; séances verrouillées. **Préférences pondérées (poids configurables)** : réduire les heures creuses, répartir matières et charge, limiter les changements de salle et de site.

**FR-EDT-02 — Proposition, comparaison et publication.** MUST. Présenter la proposition, les préférences non satisfaites et le statut du solveur ; comparer des versions ; édition manuelle avec **recontrôle complet** des conflits ; validation et publication par un utilisateur habilité ; zéro violation obligatoire sur un planning publié.

**FR-EDT-03 — Incompatibilité et délai.** MUST. Incompatibilité **prouvée** → diagnostic exploitable. Délai atteint sans solution → message « aucune solution trouvée dans le délai » sans conclure à l'impossibilité. Solution partielle jamais publiable comme complète. Tout assouplissement d'une règle obligatoire exige une validation explicite (BR-16).

**FR-EDT-04 — Replanification locale et remplacements.** SHOULD. Verrouiller les séances acceptées, replanifier localement après absence/indisponibilité, mettre en évidence les modifications, préserver les séances non concernées.

**FR-EDT-05 — Affectation assistée des élèves et enseignants.** SHOULD. Propositions de répartition des élèves (formation, niveau, filière, options, capacité, verrous, équilibre des effectifs ; **aucun regroupement par niveau de réussite sans règle pédagogique explicite**) et d'affectation des enseignants (couverture des matières, disponibilités, charges, continuité). Vérification de faisabilité avec le moteur de planning avant validation ; historique des paramètres et versions ; validation humaine.
**Critères de recette du lot :** instance réalisable de 500 élèves / 20 enseignants avec solution de référence ; objectif proposé « solution réalisable en moins de 5 minutes sur une machine de référence » sans garantie d'optimalité ; tests : instance impossible, délai dépassé, remplacement, affectation correcte en volume mais impossible à planifier.

#### Module IAI — Assistant IA (Lot 7)

**FR-IAI-01 — Interface de fournisseur de modèle.** MUST. Couche d'abstraction unique ; changement de modèle par configuration ; modèle local à l'étude (OD-03) ; **aucun service externe activé par défaut** ; consentement explicite du tenant pour tout service externe ; résidence des données respectée.

**FR-IAI-02 — Génération de devoirs et de quiz.** MUST · Dépend de : FR-LMS-*. L'enseignant fournit des contenus autorisés, un niveau, une difficulté ; le système produit un **brouillon** ; l'enseignant relit, modifie et publie. Jamais de publication automatique.

**FR-IAI-03 — Correction assistée.** MUST. Le système **propose** une note et un commentaire pour les réponses ouvertes ; l'enseignant valide ou modifie ; **le modèle n'écrit jamais une note définitive** ; la réponse de l'élève est traitée comme une **entrée non fiable** (défense contre l'injection d'instructions) ; conservation de la proposition, de la décision et de l'auteur.

**FR-IAI-04 — Quotas, coûts et évaluation.** MUST. Quota mensuel de requêtes par tenant (FR-PLT-02) ; journalisation (tenant, utilisateur, usage, volume) ; contexte (RAG) strictement limité aux contenus du tenant ; évaluation **séparée en arabe et en français** sur un échantillon annoté ; repli et message clair si le service est indisponible ; une indisponibilité de l'IA ne bloque aucune autre fonction.

**FR-IAI-05 — Analyses prédictives (option ultérieure).** MAY. Alertes de risque de non-validation uniquement après audit d'historique et validation pédagogique ; comparaison à une règle simple ; aucune décision automatique (sanction, suspension, passage) ; données salariales exclues.

#### Module STK — Stock (Lot 8)

**FR-STK-01 — Articles et emplacements.** MUST. Articles (code, libellé multilingue, catégorie, unité), catégories, lieux de stockage, seuil d'alerte.
**FR-STK-02 — Mouvements.** MUST. Le stock est un **registre de mouvements** (entrée, sortie, ajustement, transfert) ; la quantité courante est **calculée** ; un mouvement est immuable, corrigé par mouvement lié ; motif obligatoire pour sortie/ajustement ; aucun stock négatif sauf paramètre explicite.
**FR-STK-03 — Alertes et inventaire.** SHOULD. Alertes de seuil ; inventaire par comptage avec écarts transformés en ajustements.
**FR-STK-04 — Extensions prévues (non livrées).** MAY. Fournisseurs, bons de commande, valorisation, péremption, lien avec les Dépenses (achats). Le modèle de mouvements est conçu pour les accueillir sans migration destructive.
Hors périmètre : caisse, point de vente, gestion de restaurant.

#### Module POR — Portail parents et élèves (Lot 10)
**FR-POR-01.** MAY. Comptes `parent` liés aux élèves ; consultation du planning, de la présence, des notes publiées et de la situation de paiement ; notifications ; chaque parent ne voit que ses enfants ; consentement et conformité (loi 18-07).

#### Module DED — Mode dédié (Lot 9)
**FR-DED-01 — Packaging.** MAY. Images Docker versionnées, `docker-compose` avec application, base, stockage compatible S3, file de tâches ; configuration par variables d'environnement.
**FR-DED-02 — Licence.** MAY. Clé de licence (modules, nombre d'élèves, date) équivalente aux entitlements ; verrouillage en lecture seule à l'expiration, sans perte de données.
**FR-DED-03 — Exploitation.** MAY. Guide d'installation et guide d'exploitation remis au client ; migrations automatiques et réversibles ; procédure de sauvegarde/restauration ; accès de support distant sur autorisation du client uniquement. Première configuration par l'éditeur, exploitation par le client.

---

## 7. Modèle de données de haut niveau

### 7.1 Colonnes communes
Toute table métier : `id` (UUID), `tenant_id` (NOT NULL, indexé, couvert par RLS), `created_at`, `created_by`, `updated_at`, `updated_by`, `version` (verrou optimiste), `archived_at` (nullable). Les tables historiques ou financières n'ont pas de mise à jour des champs métier après validation.

### 7.2 Entités par module

| Module | Entités principales |
|---|---|
| Plateforme | `tenant`, `site`, `plan`, `tenant_entitlement(module_key, quota…)`, `support_access_grant`, `profile_template(version)` |
| IAM | `user`, `credential`, `mfa_device`, `session`, `role`, `role_permission`, `role_assignment(scope, valid_from, valid_to)`, `permission_catalog`, `sod_rule` |
| Référentiel | `node_type`, `ref_node(parent_id, type, code, labels)`, `subject`, `program_subject(total_hours, weekly_hours, session_count, session_duration_minutes, coefficient, valid_from, valid_to)`, `custom_field_def`, `academic_year`, `period`, `tenant_setting` |
| Transverses | `audit_log`, `file_object`, `notification`, `job`, `import_batch`, `import_row`, `export_log` |
| Élèves/CRM | `student`, `guardian`, `student_guardian`, `lead`, `lead_interaction` |
| Sites/salles | `room`, `room_closure` |
| Personnel | `staff`, `qualification`, `availability`, `unavailability`, `contract`, `leave` |
| Cursus | `program(price_version)`, `group`, `group_membership`, `enrollment`, `enrollment_status_history` |
| Finance | `installment`, `adjustment`, `payment`, `payment_reversal`, `refund_request`, `refund`, `receipt`, `finance_period` |
| Dépenses | `expense_category`, `expense`, `expense_reversal` |
| Rémunérations | `pay_rule(kind, monthly_amount, variable_basis, included_quantity)`, `pay_rule_rate(node_id, unit_amount)`, `payroll_run`, `payroll_line`, `payroll_adjustment`, `payout` |
| Planning | `closure`, `time_slot`, `recurrence`, `session`, `timetable_version` |
| Présences | `teacher_attendance`, `teacher_hours_adjustment`, `student_attendance` |
| Lots ultérieurs | `lesson(version)`, `assignment`, `submission`, `quiz`, `attempt`, `grade`, `ai_request`, `stock_item`, `stock_location`, `stock_movement`, `license` |

### 7.3 Contraintes d'intégrité clés
- Clés étrangères systématiques ; pas d'enregistrement orphelin ; pas de `ON DELETE CASCADE` sur les données historiques.
- `UNIQUE (tenant_id, matricule)`, `UNIQUE (tenant_id, receipt_number)`, `UNIQUE (tenant_id, payment.reference)`, `UNIQUE (tenant_id, idempotency_key)` par table concernée.
- Une affectation principale active unique par inscription : index unique partiel `(enrollment_id) WHERE is_main AND valid_to IS NULL`.
- Chevauchement interdit d'enseignant et de salle : contrainte d'exclusion sur plage horaire (`tstzrange`), **en complément** des déclencheurs qui refusent les conflits de groupe et d'élève, qu'une contrainte d'exclusion ne peut pas exprimer (ND-05).
- `payment.amount > 0` ; une `payment_reversal` référence un paiement validé unique.
- Les composites référencent toujours le même `tenant_id` (clés étrangères composites `(tenant_id, id)`) afin de rendre impossible un lien inter-tenants.
- Protection d'immutabilité par déclencheur (triggers) sur `payment`, `expense`, `payroll_line` validés, `audit_log`, `stock_movement`.

---

## 8. Exigences non fonctionnelles

### 8.1 Performance et capacité
| ID | Exigence (objectifs de recette, à confirmer après prototype) |
|---|---|
| NFR-PERF-01 | Pour un tenant de référence (500 élèves, historique simulé de 5 ans) et 100 sessions actives pendant 30 min : p95 des API courantes ≤ 800 ms ; pages courantes ≤ 2,5 s sur réseau de référence ; erreurs serveur < 1 %. |
| NFR-PERF-02 | Charge mixte proposée : 70 % lectures, 20 % saisies, 10 % soumissions ; pics (rentrée, examens) mesurés séparément ; documenter matériel, réseau, cadence, taille des fichiers. |
| NFR-PERF-03 | Capacité plateforme : test avec au moins 50 tenants simulés et 25 000 élèves actifs au total ; un tenant de 2 000 élèves sans dégradation du p95 au-delà de 1,2 s. |
| NFR-PERF-04 | Listes paginées (25 par défaut, 100 maximum) ; exports > 2 000 lignes, imports, PDF en lot et calculs lourds en arrière-plan. |
| NFR-PERF-05 | Poids des pages courantes ≤ 500 Ko compressés hors fichiers utilisateur, pour connexions limitées ; pas de rechargement complet pour les actions courantes. |

### 8.2 Sécurité
| ID | Exigence |
|---|---|
| NFR-SEC-01 | HTTPS (TLS ≥ 1.2) partout ; HSTS ; cookies `Secure`, `HttpOnly`, `SameSite`. |
| NFR-SEC-02 | Mots de passe hachés (Argon2id) ; secrets hors du code et du dépôt ; rotation possible. |
| NFR-SEC-03 | Protection CSRF, XSS (échappement et CSP), injection SQL (requêtes paramétrées), SSRF, téléversement de fichiers (FR-FIC-01), neutralisation des formules d'export. |
| NFR-SEC-04 | Contrôle d'accès par ressource côté serveur sur **toutes** les routes ; test automatisé de chaque route contre la matrice de permissions (`TST-02`, `TST-03`). |
| NFR-SEC-05 | Limitation de débit des connexions, récupérations de compte et API sensibles. |
| NFR-SEC-06 | Aucune donnée personnelle, mot de passe, jeton ou justificatif dans les journaux techniques. |
| NFR-SEC-07 | Journalisation de sécurité (échecs de connexion, changements de droits) ; alertes sur comportements anormaux (SHOULD). |
| NFR-SEC-08 | Analyse des dépendances et correctifs de sécurité intégrés au processus de livraison (SHOULD). |
| NFR-SEC-09 | Chiffrement des sauvegardes ; chiffrement du disque ou de la base au repos si l'hébergement le permet (SHOULD). |

### 8.3 Isolation multi-tenant
| ID | Exigence |
|---|---|
| NFR-TEN-01 | RLS active sur toutes les tables métier ; l'utilisateur applicatif de la base n'est ni propriétaire des tables ni `BYPASSRLS`. |
| NFR-TEN-02 | Un test automatisé crée deux tenants et vérifie l'impossibilité d'accéder aux données de l'autre par écrans, API, exports, fichiers, tâches de fond, notifications et recherche (`TST-01`). |
| NFR-TEN-03 | Cache, files de tâches et stockage sont également scindés par tenant (clés préfixées). |

### 8.4 Disponibilité, sauvegarde, continuité
| ID | Exigence |
|---|---|
| NFR-AVL-01 | Disponibilité mensuelle cible de 99,5 % hors maintenance planifiée ; fenêtre de service et méthode de mesure à contractualiser. |
| NFR-REC-01 | Perte maximale de données (RPO) 24 h ; reprise (RTO) 4 h ; sauvegarde quotidienne chiffrée de la base **et des fichiers** ; copie séparée de l'environnement de production en Algérie. |
| NFR-REC-02 | Test de restauration complète et cohérente (base + fichiers) avant lancement puis selon fréquence convenue. |
| NFR-REC-03 | La finance valide si la perte possible de 24 h est acceptable. |
| NFR-OPS-01 | Environnements développement, recette, production séparés ; migrations de base avec retour arrière ; supervision, alertes, procédure d'incident ; identifiant de corrélation par requête. |

### 8.5 Données, conformité, résidence
| ID | Exigence |
|---|---|
| NFR-DATA-01 | **Résidence en Algérie** : application, base, fichiers, sauvegardes, journaux, traitements d'optimisation et d'IA. Les services de notification et d'IA respectent la même contrainte pour les données qu'ils traitent. |
| NFR-DATA-02 | Conformité à la loi algérienne n° 18-07 relative à la protection des données personnelles : bases légales/consentements, déclaration ou autorisation auprès de l'autorité, droits d'accès et de rectification, contrat de sous-traitance avec chaque client (l'éditeur est sous-traitant). **À faire valider par un juriste avant le premier client payant** (OD-08). |
| NFR-DATA-03 | Durées de conservation configurables par catégorie (dossiers élèves, mineurs, finance, journaux), avec procédure de purge et d'anonymisation ; valeurs par défaut à valider (OD-07). |
| NFR-DATA-04 | Procédure d'export complet des données d'un tenant à la fin du contrat ; procédure de rectification d'une donnée personnelle. |
| NFR-DATA-05 | Les sauvegardes et exports suivent la même politique de protection que la production. |
| NFR-DATA-06 | Les jeux de test utilisent des données fictives ou anonymisées ; les données d'un tenant ne servent jamais à un autre. |

### 8.6 Ergonomie, accessibilité, compatibilité
| ID | Exigence |
|---|---|
| NFR-UX-01 | Interface claire pour personnel non technique : navigation par habilitations, informations clés sans navigation complexe, libellés explicites. |
| NFR-UX-02 | Responsive ordinateur, tablette, téléphone. |
| NFR-UX-03 | Accessibilité visée : WCAG 2.1 AA (clavier, contrastes, libellés, focus visibles). |
| NFR-UX-04 | Messages d'erreur indiquant la cause et l'action à entreprendre ; message explicite pour une action interdite ; confirmations réservées aux opérations sensibles. |
| NFR-UX-05 | Une saisie n'est pas perdue en cas d'interruption lorsque c'est possible ; confirmation des dépôts réussis ; hors ligne complet non inclus. |
| NFR-COMP-01 | Dernières 2 versions majeures de Chrome, Firefox, Edge, Safari ; navigateurs mobiles Android et iOS récents. |

### 8.7 Internationalisation
| ID | Exigence |
|---|---|
| NFR-I18N-01 | Arabe (RTL), français, anglais ; MVP livré en français et arabe, anglais par traductions ensuite ; aucun texte en dur ; styles logiques. |
| NFR-I18N-02 | PDF multilingues avec polices embarquées et rendu arabe correct (`TST-17`). |
| NFR-I18N-03 | Tri et recherche tolérants à la langue (normalisation arabe, translittération). |

### 8.8 Portabilité et maintenabilité
| ID | Exigence |
|---|---|
| NFR-PORT-01 | Exécution en conteneurs Docker ; configuration par environnement ; aucune dépendance à un service cloud propriétaire ; stockage compatible S3 ; file de tâches et e-mail configurables. |
| NFR-PORT-02 | Aucune dépendance à un GPU permanent pour le socle. |
| NFR-MNT-01 | Intégration continue : tests automatisés, analyse statique, migrations vérifiées à chaque modification. |
| NFR-MNT-02 | Tests unitaires et d'intégration obligatoires pour les invariants critiques (section 9.3) et **écrits avec chaque lot**. |
| NFR-MNT-03 | Documentation : schéma de données, API, règles de calcul, guides d'exploitation et d'administration. |
| NFR-MNT-04 | Versionnement des migrations, des modèles de profils et des paramètres de calcul. |

---

## 9. Plan de livraison et recette

### 9.1 Lots

| Lot | Contenu | FR concernés | Condition de passage |
|---|---|---|---|
| **1 — Fondations** | Plateforme, comptes, MFA, rôles et portées, référentiel, années/périodes, paramètres, audit, i18n/RTL, import/export, fichiers, notifications, tâches, PDF | FR-PLT-*, FR-IAM-*, FR-REF-*, FR-AUD-01, FR-I18-01, FR-IMP-*, FR-FIC-01, FR-NOT-01, FR-JOB-01, FR-PDF-01 | `TST-01` à `TST-06`, `TST-14`, `TST-17`, `TST-18` verts ; deux tenants isolés créés de bout en bout |
| **2 — Cœur scolaire** | Élèves, responsables, CRM, sites/salles, personnel, habilitations, programmes, groupes, inscriptions (état pédagogique) | FR-ELV-*, FR-CRM-*, FR-SLL-*, FR-STF-*, FR-PRG-*, FR-GRP-*, FR-INS-* | `TST-07`, `TST-08`, parcours élève → inscription → groupe ; import des 500 élèves de référence |
| **3 — Finance** | Échéanciers, paiements, reçus, remboursements, impayés, clôture, dépenses, rémunérations | FR-FIN-*, FR-DEP-*, FR-REM-* | `TST-09` à `TST-13` et `TST-19` verts ; rapprochement validé par la finance ; reçus arabes |
| **4 — Exploitation** | Planning manuel, conflits, remplacements, pointage, appel, tableaux de bord | FR-PLN-*, FR-PTG-*, FR-PRE-01, FR-TDB-* | `TST-05`, `TST-15`, `TST-16` verts ; heures assurées validées intégrées aux rémunérations |
| 5 — Pédagogie (LMS) | Leçons, devoirs, quiz, notes simples | FR-LMS-* | Parcours enseignant → élève validé |
| 6 — Optimisation | Génération d'emplois du temps, affectations assistées | FR-EDT-* | Critères de recette FR-EDT |
| 7 — Assistant IA | Génération et correction assistées, quotas | FR-IAI-* | Évaluation arabe/français, validation humaine |
| 8 — Stock | Articles, mouvements, alertes | FR-STK-* | Quantités = somme des mouvements |
| 9 — Mode dédié | Packaging, licence, guides | FR-DED-* | Installation d'un tenant dédié sur environnement vierge |
| 10 — Portail parents | Accès parents | FR-POR-01 | Isolation parent/enfant testée |

Un lot est **terminé** lorsque : (a) toutes ses exigences MUST sont `CONFORME` ; (b) les tests d'invariants du lot sont verts en intégration continue ; (c) une revue de sécurité des permissions du lot est faite ; (d) les migrations sont réversibles ; (e) la documentation du lot est à jour.

### 9.2 Parcours de référence (recette fonctionnelle)

| ID | Parcours | Résultat attendu |
|---|---|---|
| R01 | Comptes et rôles | Création, activation, suspension, récupération ; refus d'accès entre élèves, groupes et tenants ; exports soumis aux mêmes droits |
| R02 | Import Excel | Reprise de 500 élèves et données associées ; aperçu, doublons, rejets ; rapprochement des soldes et totaux ; relance sans doublon |
| R03 | Cycle d'inscription | Élève → programme → inscription → groupe → paiement → validation ; inscriptions multiples autorisées ; capacité jamais dépassée |
| R04 | Statuts | Paiement partiel puis complet met à jour le solde ; suspension inchangée par un paiement ; inscription annulée figée |
| R05 | Paiements | Paiement validé non modifiable ; reçu, correction et remboursement liés ; double clic et relance sans double encaissement |
| R06 | Dépenses et salaires | Calcul mensuel selon règles versionnées (horaire, séance, fixe, mixte, tarifs par niveau) ; heures et séances validées ; primes et ajustements tracés ; versements partiels ; aucune double dépense |
| R07 | Tableaux financiers | Totaux rapprochés des opérations ; indicateurs expliqués et reproductibles |
| R08 | Planning et pointage | Aucun conflit publié ; remplacement ; pointage saisi puis validé par une autre personne ; heures assurées |
| R09 | Modularité | Un tenant sans `student_finance` n'a ni menus, ni API, ni indicateurs financiers ; réactivation restitue les données |
| R10 | Référentiel | Un tenant « école » et un tenant « centre » utilisent le même code avec des libellés et hiérarchies différents |
| R11 | Exploitation | Test de charge documenté ; restauration base et fichiers ; redéploiement sans perte des pièces ; stockage en Algérie vérifié |

### 9.3 Tests d'invariants critiques (obligatoires, automatisés)

| ID | Invariant | Vérification |
|---|---|---|
| TST-01 | Isolation entre tenants | Deux tenants ; aucune lecture ni écriture croisée par UI, API, export, fichier, tâche, recherche, notification (BR-01) |
| TST-02 | Permissions | Pour chaque route et action : refus sans permission, refus hors portée ; aucune référence à un code de rôle dans la logique métier |
| TST-03 | Visibilité élève/enseignant | Un élève ne voit que ses données ; un enseignant que ses groupes |
| TST-04 | Entitlements | Module inactif → API 403, menus absents, tâches non exécutées |
| TST-05 | Séparation des tâches | Auteur ≠ valideur pour pointage, dépenses, remboursements, rémunérations, contre-opérations ; cas du cumul de rôles |
| TST-06 | Audit | Chaque événement de BR-09 produit une entrée ; aucune modification possible |
| TST-07 | Capacité | N requêtes simultanées sur la dernière place → une seule réussit |
| TST-08 | Idempotence d'inscription | Double soumission → une inscription |
| TST-09 | Deux dimensions d'état | Paiements ne modifient pas l'état pédagogique ; calcul financier correct |
| TST-10 | Idempotence de paiement | Double clic et relance réseau → un seul paiement ; référence et reçu uniques |
| TST-11 | Immutabilité financière | Modification d'un paiement/dépense/rémunération validé refusée (applicatif et base) |
| TST-12 | Rémunération unique | Génération/versement répété → aucun doublon |
| TST-13 | Pas de double comptage | Dépenses `salaries` = versements ; indicateurs sans double somme |
| TST-14 | Import idempotent | Réimport → pas de doublon ; rejets documentés ; aperçu sans écriture |
| TST-15 | Conflits de planning | Chevauchements enseignant/salle/groupe/élève refusés, y compris en concurrence |
| TST-16 | Rapprochement des indicateurs | Totaux des tableaux de bord = somme des opérations sources |
| TST-17 | PDF et RTL | Reçu et planning arabes rendus correctement ; pseudo-localisation sans chaîne en dur |
| TST-18 | Référentiel unique | Aucun champ « niveau/matière » en texte libre ; doublons refusés ; archivage des nœuds utilisés |
| TST-19 | Calcul de rémunération | Les quatre types et la résolution du tarif par niveau reproduisent au centime les exemples de FR-REM-02 ; une séance sans tarif résolvable n'est jamais comptée à zéro (BR-16) ; le détail d'un calcul validé est inchangé après modification de la règle |

---

## 10. Décisions ouvertes

| ID | Question | Responsable | Avant |
|---|---|---|---|
| OD-01 | ~~Confirmation de la pile technique~~ — **tranchée en 1.2** : PHP / Laravel 12, PostgreSQL 16, Filament 5 (§4.2) | Équipe technique | — |
| OD-02 | Modèle commercial : packs (Essentiel/Pro/Complet), paliers par élèves actifs, option IA avec quota, abonnement annuel, mode dédié (frais d'installation + licence + maintenance) | Direction produit | Premier client |
| OD-03 | Choix du modèle d'IA local (qualité en arabe et en français, matériel, coût) | Data / IA | Lot 7 |
| OD-04 | Choix du prestataire d'hébergement et des engagements (sauvegardes, restauration, quotas, réseau privé, supervision) | Direction produit | Avant la mise en production |
| OD-05 | Politique d'accès aux cours avec paiement partiel (défaut proposé : `none`) | Chaque tenant | Lot 2 (indicateur `access_granted`, FR-INS-04) |
| OD-06 | Règles de rémunération des séances annulées, absences et remplacements (défauts à proposer) | Chaque tenant / finance | Lot 3 |
| OD-07 | Durées de conservation par catégorie (élèves, mineurs, finance, journaux) | Chaque tenant + juriste | Avant le premier client |
| OD-08 | Validation juridique (loi 18-07, contrat de sous-traitance, déclarations auprès de l'autorité) | Éditeur | Avant le premier client payant |
| OD-09 | Seuils d'approbation (remises, remboursements, dépenses) par défaut | Direction produit | Lot 3 |
| OD-10 | Valeur par défaut de `finance.allow_self_validation` pour les très petits établissements | Direction produit | Lot 3 |
| OD-11 | Politique de numérotation des matricules et des reçus (format, remise à zéro annuelle) | Direction produit | Lot 2 |
| OD-12 | Fournisseur d'e-mail (SMTP) et passerelle SMS éventuelle (hébergés en Algérie ?) | Équipe technique | Lot 1 |
| OD-13 | Équivalences et libellés exacts du profil `private_school_dz` (niveaux du préscolaire et libellés arabes, filières du secondaire, options) | Direction produit | Lot 1 |
| OD-14 | File d'attente de groupe (SHOULD de FR-GRP-02) : incluse au lot 2 ou reportée | Direction produit | Lot 2 |

### 10.1 Décisions prises après l'analyse d'écart (version 1.2)

Décisions issues de `RAPPORT_ECART_SRS_1_1.md` (§8), retenues le 10 octobre 2026 sur les recommandations du rapport.

| ID | Décision |
|---|---|
| OD-01 | Pile conservée : PHP / Laravel 12, PostgreSQL 16, Filament 5 (§4.2) |
| ND-01 | Montants en entiers de centimes admis comme représentation exacte (`ARC-11` amendée) |
| ND-02 | Bulletins scolaires : extension optionnelle hors SRS (§2.2) |
| ND-03 | Passage annuel inscrit au SRS : `FR-INS-05` |
| ND-04 | Mode démonstration : bac à sable d'interface non normatif (§2.2) |
| ND-05 | Contrainte d'exclusion enseignant/salle en complément des déclencheurs (§7.3) |
| ND-06 | SaaS mutualisé et dédié sur le même code (§4.3) |
| ND-07 | Validateurs d'emploi du temps figés : sortis du produit, conservés comme jeux d'essai |
| ND-08 | Hypothèse H5 révisée (§2.3) |
| ND-09 | Réalisation sur une **nouvelle base de schéma** écrite depuis la section 7, sur la même pile, en reprenant les invariants éprouvés du code existant et leurs tests ; l'application actuelle reste disponible tant que la nouvelle n'a pas atteint son périmètre |

---

## 11. Validation

*En signant ci-dessous, le signataire confirme que les exigences documentées sont complètes, exactes et approuvées pour le démarrage du développement. Toute modification ultérieure passe par une demande de changement formelle et peut avoir un impact sur le calendrier.*

| | Nom | Signature | Date |
|---|---|---|---|
| **Direction produit** | | | |
| **Responsable technique** | | | |

*Historique des versions*

| Version | Date | Auteur | Modifications |
|---|---|---|---|
| 1.0 | 9 octobre 2026 | Équipe Madrassa | Première version complète |
| 1.1 | 9 octobre 2026 | Équipe Madrassa | Cycle préscolaire ; rémunération par heure, par séance, salaire mensuel fixe ou mixte, avec tarif par niveau (staff administratif : mensuel fixe) ; gestion manuelle des matières/modules avec volume horaire et nombre de séances ; photo de l'élève |
| 1.2 | 10 octobre 2026 | Équipe Madrassa | Décisions de l'analyse d'écart (§10.1) : pile retenue (§4.2), `ARC-11` amendée, H5 révisée, bulletins et mode démonstration hors SRS, `FR-INS-05` passage annuel, contrainte d'exclusion en complément (§7.3), profils de déploiement sur le même code, OD-05 avancée au lot 2 |

---

## Annexe A — Catalogue des permissions

Format `module.resource.action`. **S** = permission sensible (MFA obligatoire pour tout rôle qui la porte ; assignation notifiée à la direction). Les rôles par défaut utilisent les codes de la section 3.2.

| Permission | Description | S | Rôles par défaut |
|---|---|---|---|
| `platform.tenant.manage` | Gérer tenants, plans, modules, quotas | S | super_admin |
| `platform.support.access` | Accès exceptionnel consenti | S | super_admin |
| `tenant.settings.manage` | Paramètres du tenant | | tenant_admin |
| `referential.node.read` / `.manage` | Lire / gérer le référentiel | | tous (lecture) ; tenant_admin (gestion) |
| `custom_field.definition.manage` | Gérer les champs personnalisés | | tenant_admin |
| `user.account.read/create/update/suspend` | Gérer les comptes | | tenant_admin |
| `role.definition.manage` / `role.assignment.manage` | Gérer rôles et assignations | S | tenant_admin |
| `audit.log.read` | Lire le journal (filtré par domaine) | S | direction, tenant_admin (accès/config), accountant (finance) |
| `import.job.create/read` | Importer (par type de données) | | selon section 3.3 |
| `export.job.create` + `<domaine>.export` | Exporter | | selon domaine |
| `student.record.read/create/update/archive` | Dossiers élèves | | front_office, academic_hr ; lecture : direction, supervisor |
| `student.sensitive.read` | Champs sensibles (santé, notes sociales) | S | direction, academic_hr |
| `guardian.record.read/create/update` | Responsables légaux | | front_office, academic_hr |
| `crm.lead.read/create/update/convert` | Prospects | | front_office |
| `program.program.read/manage` | Programmes et tarifs de catalogue | | academic_hr (gestion), autres (lecture) |
| `subject.subject.manage` | Catalogue des matières/modules (ajout, modification, suppression/archivage) | | academic_hr, tenant_admin |
| `program.subject.manage` | Matières/modules d'un programme avec volume horaire et séances | | academic_hr, tenant_admin |
| `group.group.read/manage` | Groupes | | academic_hr |
| `group.membership.manage` | Composition des groupes | | academic_hr, front_office |
| `enrollment.enrollment.read/create/update` | Inscriptions | | front_office, academic_hr |
| `enrollment.enrollment.validate/suspend/cancel` | États pédagogiques | | academic_hr, direction |
| `enrollment.progression.decide` | Passage annuel (FR-INS-05) | | academic_hr, direction |
| `site.site.manage` / `room.room.manage` | Sites et salles | | tenant_admin, academic_hr |
| `staff.profile.read/create/update` | Fiches personnel | | academic_hr |
| `staff.qualification.manage` | Habilitations | | academic_hr |
| `staff.availability.manage` / `.approve` | Disponibilités | | teacher (propres), academic_hr |
| `staff.contract.read/manage` | Contrats (sans montants) | | academic_hr |
| `staff.leave.read/manage` | Congés et absences | | academic_hr |
| `schedule.session.read/create/update/cancel` | Séances et planning | | academic_hr |
| `schedule.timetable.publish` | Publier un planning | | academic_hr |
| `attendance.teacher.entry.create/update` | Saisir le pointage | | supervisor |
| `attendance.teacher.validation.approve` | Valider le pointage | S | academic_hr |
| `attendance.student.entry.create/update` | Appel des élèves | | supervisor, teacher (ses séances) |
| `finance.payment.read` | Lire les paiements | | accountant, direction |
| `finance.payment.create` | Saisir un paiement | | accountant |
| `finance.payment.create.delegated` | Encaisser sur délégation | S | front_office (si délégué) |
| `finance.payment.validate` | Valider un paiement | S | accountant |
| `finance.payment.reverse` | Contre-opération | S | accountant |
| `finance.discount.grant` / `finance.schedule.manage` | Remises, échéanciers | S | accountant (+ approbation direction) |
| `finance.refund.request` / `.approve` | Remboursement | S | accountant / direction |
| `finance.period.close` / `.reopen` | Clôture / réouverture | S | accountant (demande) / direction |
| `expense.expense.read/create/update` | Dépenses | | accountant |
| `expense.expense.validate` / `.pay` / `.reverse` | Valider, payer, corriger | S | accountant, direction |
| `expense.attachment.read` | Justificatifs | S | accountant, direction |
| `payroll.rule.manage` | Règles de rémunération (horaire, par séance, mensuel fixe ou mixte ; tarifs par niveau) | S | accountant |
| `payroll.amount.read` | Voir les montants salariaux | S | accountant, direction |
| `payroll.run.generate/validate` | Générer / valider | S | accountant / direction |
| `payroll.payout.record` | Enregistrer un versement | S | accountant |
| `payroll.self_view` | Voir ses propres paiements | | teacher (si activé) |
| `hours.validated.read` | Heures assurées validées | | academic_hr, accountant, teacher (propres) |
| `dashboard.finance.read` / `.pedagogy.read` / `.global.read` / `.operations.read` | Tableaux de bord | | selon section 3.3 |
| `stock.item.manage`, `stock.movement.create`, `stock.inventory.manage` | Stock | | stock_manager |
| `lms.*`, `ai.*` | Voir lots 5 et 7 | | à détailler |

---

## Annexe B — Événements internes (contrat inter-modules)

| Événement | Émetteur | Consommateurs possibles |
|---|---|---|
| `enrollment.created`, `.validated`, `.suspended`, `.cancelled` | Inscriptions | Finance, Groupes, Tableaux de bord, Notifications |
| `group.membership.changed` | Groupes | Planning, Présences, Tableaux de bord |
| `payment.validated`, `payment.reversed` | Finance | Inscriptions (solde), Tableaux de bord, Notifications |
| `session.published`, `session.cancelled`, `session.replaced` | Planning | Pointage, Notifications, Rémunérations |
| `attendance.teacher.validated` | Pointage | Rémunérations, Tableaux de bord |
| `payroll.payout.recorded` | Rémunérations | Dépenses |
| `expense.validated` | Dépenses | Tableaux de bord |
| `lead.converted` | CRM | Élèves, Inscriptions |

Règle : les consommateurs sont **idempotents** et vérifient le `tenant_id` ; l'absence d'un consommateur (module inactif) ne bloque jamais l'émetteur.

---

## Annexe C — Modules, clés d'activation et dépendances

| Clé | Module | Dépend de (obligatoire) | Optionnel |
|---|---|---|---|
| `core` | Noyau (tenants, IAM, référentiel, audit, i18n, import/export, fichiers, notifications) | — | — |
| `sites_rooms` | Sites et salles | core | — |
| `students_crm` | Élèves et CRM | core | — |
| `enrollment_groups` | Programmes, groupes, inscriptions | students_crm | sites_rooms |
| `staff` | Personnel, habilitations, contrats | core | — |
| `scheduling_manual` | Séances et planning manuel | enrollment_groups, staff | sites_rooms |
| `scheduling_optimization` | Optimisation d'emploi du temps | scheduling_manual | — |
| `attendance` | Pointage enseignants et présences élèves | scheduling_manual | staff |
| `student_finance` | Finance des élèves | enrollment_groups | — |
| `expenses` | Dépenses | core | payroll, stock |
| `payroll` | Rémunérations | staff, expenses | attendance |
| `lms` | Pédagogie | enrollment_groups | — |
| `ai_assistant` | Assistant IA | lms | — |
| `stock` | Stock | core | expenses |
| `parent_portal` | Portail parents | students_crm | student_finance, attendance, scheduling_manual, lms |
| `dashboards` | Tableaux de bord | core | tous les modules actifs |

**Packs commerciaux (proposition, voir OD-02) :**
- *Essentiel* : core, students_crm, enrollment_groups, sites_rooms, student_finance.
- *Pro* : Essentiel + staff, expenses, scheduling_manual, attendance, dashboards.
- *Complet* : Pro + payroll, lms, scheduling_optimization, stock, ai_assistant (option), parent_portal.

---

## Annexe D — Glossaire

| Terme | Définition |
|---|---|
| Tenant | Organisation cliente isolée (établissement ou groupe scolaire) |
| Site | Campus ou implantation d'un tenant |
| Nœud de référentiel | Élément du référentiel académique (cycle, niveau, filière, spécialité…) |
| Matière / module | Discipline enseignée (école) ou module de formation (centre), avec volume horaire et/ou nombre de séances défini par programme |
| Préscolaire | Cycle précédant le primaire, avec ses propres sections ; sans filière |
| Programme | Offre d'inscription (niveau/filière pour une année, ou formation) avec tarif |
| Groupe | Objet unifié pour classe, groupe de soutien, option ou session de formation |
| Groupe principal | Classe principale d'une inscription (un seul actif) |
| Inscription | Lien élève ↔ programme avec prix et conditions figés |
| Séance | Occurrence datée d'un cours (planifiée, assurée, annulée ou remplacée) |
| Pointage | Enregistrement de la présence effective d'un enseignant à une séance |
| Heures assurées | Heures de séances pointées **et validées** |
| Tarif applicable | Tarif unitaire (heure ou séance) retenu pour une séance : celui de son niveau, à défaut celui du plus proche ancêtre du référentiel, à défaut le tarif par défaut |
| Rémunération mixte | Part fixe mensuelle + part variable (heures ou séances) au-delà d'une quantité incluse |
| Contre-opération | Écriture liée qui annule l'effet d'une opération validée sans l'effacer |
| Entitlement | Droit d'un tenant à utiliser un module et ses quotas |
| Break-glass | Accès exceptionnel, consenti, limité et tracé |
| DZD / DA | Dinar algérien |
