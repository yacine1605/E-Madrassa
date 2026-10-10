# Architecture et exploitation

## Décisions structurantes

Un monolithe modulaire Laravel suffit à démarrer : une transaction peut couvrir l’inscription, sa facture, l’audit et l’événement. PostgreSQL est la source de vérité. Redis partage sessions et cache entre les instances HTTP ; il ne remplace ni une contrainte SQL ni une transaction financière.

L’unité d’isolation est **l’établissement**, pas le campus. Un réseau possédant plusieurs campus peut rester un établissement. Des écoles juridiquement/opérationnellement indépendantes ont des `tenant_id` différents. Une même personne peut avoir des adhésions différentes dans plusieurs établissements. Il n’y a pas de dossier élève partagé implicitement entre eux.

Une formation est représentée par programme → niveau → offre (période, campus, prix) → groupe. Une école peut créer les programmes primaire/moyen/secondaire ; un centre peut créer anglais, informatique, métiers, sessions courtes, etc. Le catalogue ne présuppose ni une durée annuelle, ni un âge, ni un diplôme réglementaire. Les libellés acceptent Unicode arabe/français ; traductions d’interface, RTL et nomenclatures officielles ne sont pas incluses.

## Isolation et autorisations

Chaque requête métier ouvre une transaction, définit `app.user_id` localement, vérifie l’adhésion active, puis définit `app.tenant_id`. RLS requiert à la fois ce tenant et l’adhésion de cet utilisateur. Le contexte est supprimé au commit/rollback, même avec un pool de connexions en mode transaction.

Les références métier utilisent `(tenant_id, id)` : une salle d’une autre école ne peut pas être reliée à l’offre courante. Les vues sont `security_invoker`. Les tables sont en `ENABLE` et `FORCE ROW LEVEL SECURITY`. Le compte HTTP est sans propriété des tables, sans `BYPASSRLS`, sans administration des adhésions et sans suppression métier.

RLS protège contre les oublis de filtre dans l’application ; les réglages `app.*` ne sont pas des identifiants cryptographiques. Quiconque possède le compte SQL runtime peut fixer ces réglages. Ne jamais exposer ce compte, un éditeur SQL ou une fonction exécutant du SQL client. Les permissions d’opération sont vérifiées par Laravel ; RLS assure la frontière entre établissements, pas tous les droits fonctionnels d’un membre.

Les presets `secretariat`, `pedagogy`, `finance`, `hr` sont cumulables. Le preset `technical` est vide. Les comptes enseignants sans permissions globales utilisent `/enseignant` : le middleware vérifie l’adhésion et la fiche enseignant active à chaque requête. Les classes et listes d’élèves sont limitées aux affectations de cette fiche ; le planning est filtré par son identifiant enseignant. Les élèves n’ont pas encore de portail connecté. Le provisionnement et la révocation d’adhésions sont réservés à l’opérateur habilité, hors runtime.

## Transactions et historique

- L’inscription réserve une place et fige le prix du catalogue. Les inscriptions suspendues et en attente réservent aussi une place ; une annulation la libère. Le modèle courant ne permet pas de transfert direct de groupe.
- Une facture de gestion couvre le prix complet d’une inscription. Une annulation pédagogique ne supprime pas sa créance. Un remboursement rend cette somme de nouveau exigible : il ne constitue pas un avoir. Un paiement saisi par erreur est annulé par une contre-opération (`payment_reversals`, motif obligatoire) qui retire son effet du solde sans modifier le paiement ; un paiement remboursé ne peut plus être annulé, et inversement. Une référence de paiement est unique dans l’école et ne se réutilise jamais. Une politique d’avoirs, d’annulation de dette et d’échéances multiples doit être ajoutée avant d’utiliser ces scénarios commercialement.
- Les encaissements/verrouillages de remboursement passent par la facture verrouillée ; aucun dépassement du montant net dû. Les paiements restent immuables. Un remboursement ne peut excéder son paiement. Pas d’encaissement sur inscription annulée.
- Les séances utilisent des intervalles semi-ouverts : une séance finissant à 09 h peut être suivie d’une autre à 09 h. Les chevauchements élèves sont vérifiés lors d’une création de séance **et** d’une inscription.
- Le planning et les inscriptions utilisent un verrou consultatif par établissement. Ce choix privilégie une règle correcte et simple. Il sérialise ces écritures au sein d’une école, sans bloquer les lectures ni les autres écoles. Il faudra mesurer les attentes avant un éventuel découpage des verrous par ressource.
- Les rémunérations ont un verrou personnel/mois, un snapshot du contrat et des minutes validées. Le calcul crée un brouillon ; une autre personne que son auteur le valide (séparation des tâches, imposée en base), et seule cette validation crée la dépense de salaire. Un brouillon peut être supprimé pour corriger les heures. De même, la personne qui a saisi des heures ne peut pas les valider. Une seule dépense d’engagement est liée à chaque rémunération. Le cash sort uniquement par `disbursements`, pour éviter le double comptage. Aucun calcul automatique des charges légales.
- L’audit enregistre acteur, entité, opération, champs modifiés et, pour une modification, l’ancienne et la nouvelle valeur ; les données personnelles et les contenus volumineux y sont masqués (`[masqué]`). Il relie chaque entrée au motif saisi, à l’identifiant de requête et à l’adresse IP. Les motifs de transition sont aussi dans l’outbox ; ceux des corrections de résultat sont sur la ligne. Pour une exigence d’historique complet des motifs successifs, ajouter une table de révisions immuable avant ouverture de ces workflows.
- L’outbox enregistre les événements dans la transaction ; aucun envoi externe n’est effectué. Le consommateur n’est pas inclus. Avant d’ajouter notifications/imports, créer un worker avec compte de service et contexte tenant, verrous `FOR UPDATE SKIP LOCKED`, tentatives, backoff et destinataire idempotent. Ne marquer `processed_at` qu’après succès. Le modèle de livraison est au moins une fois.

## Performances et montée en charge

Les index métier commencent par `tenant_id`, y compris les références utilisées pour les jointures. Les listes ont un plafond de 100 lignes et une pagination par curseur. La base conserve les contraintes uniques, les états et montants exacts. Aucun téléchargement externe, PDF ou appel IA n’est exécuté dans une transaction métier.

Commencer avec PostgreSQL, une ou deux instances PHP et Redis privés. Augmenter les instances HTTP après externalisation des sessions et des fichiers. Un stockage objet privé et ses sauvegardes doivent suivre la résidence des données demandée. Les clés de cache futures doivent inclure tenant + filtres + version ; les objets sensibles ne doivent pas être mis en cache globalement.

Suivre : latence p50/p95/p99 par route, 409 et attentes de verrou, transactions longues, connexion DB, taille des index, erreurs de permission, retard de l’outbox, cache et jobs. Activer les statistiques de requêtes côté DBA et inspecter `EXPLAIN (ANALYZE, BUFFERS)` avec des volumes représentatifs. Les agrégats du résumé financier balayent les données du tenant : introduire des agrégats journaliers réconciliés lorsque la mesure le justifie, pas un solde éditable à la main.

Cible de recette issue du projet : jusqu’à 2 000 élèves et 100 sessions utilisateur concurrentes, p95 API inférieur à 800 ms. **Ce ne sont pas des performances démontrées** par cette livraison. Tester avec le réseau, le serveur PHP, Redis et PostgreSQL de destination. Les 34 tests embarqués ne sont pas un benchmark. Partitionner l’audit/les événements par période uniquement après mesure et adaptation des contraintes. Une base dédiée par grand client pourra être proposée sans modifier les concepts métier.

## Déploiement et sauvegardes

Les conteneurs fournis servent au développement. Pour la production, épingler les images par version/digest après validation, ne pas publier Redis, limiter PostgreSQL au réseau privé et utiliser TLS, supervision et rotation des secrets. Héberger application, données, fichiers, sauvegardes, journaux et traitements associés en Algérie conformément à la contrainte du projet ; aucune région cloud n’est provisionnée par cette archive.

Faire des sauvegardes chiffrées, une politique de rétention par type de données et des restaurations régulières sur environnement isolé. Objectifs à contractualiser : RPO 24 h et RTO 4 h ; WAL/PITR si besoin d’un RPO plus court. Les données métiers et les fichiers doivent être restaurables ensemble. Avant migration de production : sauvegarde vérifiée, essai sur copie anonymisée, procédure de retour arrière. La migration initiale refuse volontairement un rollback destructif.

Les tests HTTP authentifiés complets, la charge, la restauration, les intégrations financières locales et l’examen réglementaire restent des portes de validation avant production. Aucune certification de conformité ou de performance n’est revendiquée.

## Sources techniques

- PostgreSQL : [Row Security Policies](https://www.postgresql.org/docs/current/ddl-rowsecurity.html), [Constraints](https://www.postgresql.org/docs/current/ddl-constraints.html).
- Laravel 12 : [Database](https://laravel.com/docs/12.x/database), [Queues](https://laravel.com/docs/12.x/queues).
- Versions du frontend inspecté : Laravel 12.69.2, Filament 5.8.4, Livewire 4.4.6. Aucun changement de framework requis.
