# Rapport de vérification — livraison du 26 septembre 2026

## Environnement

PHP 8.3.6 ; Laravel 12.69.2 ; Filament 5.8.4 ; Livewire 4.4.6. Versions verrouillées dans `composer.lock`. SQLite pour le compte, données métier en session.

## Tests exécutés

`vendor/bin/phpunit` : **7 tests, 70 assertions, tous réussis**.

- Chargement HTTP réussi des 30 pages après authentification.
- Refus de modification d’un paiement validé.
- Refus d’un chevauchement d’enseignant dans la semaine type.
- Paiement partiel actualisant le solde sans réactiver une inscription suspendue.
- Distinction entre note manquante et note égale à zéro.
- Rejet d’une incohérence cycle/niveau.
- Création d’un élève par l’action native Filament avec enregistrement en session.

## Contrôles navigateur

Vérification avec Chromium automatisé, puis inspection des captures.

- Connexion via la page Filament puis accès au tableau de bord.
- Ouverture du formulaire natif « Nouvel élève », rendu sur deux colonnes au bureau.
- Recherche « Lina » : une ligne retournée.
- Filtre Secondaire combiné à cette recherche : état vide affiché.
- Planning : 15 cartes de séance, affichage hebdomadaire.
- Bureau : fenêtre 1512 × 1080.
- Mobile : fenêtre 390 × 844 ; aucun débordement horizontal global du tableau de bord.
- Aucune erreur JavaScript observée sur ces parcours.

Captures : `apercu-bureau.png`, `apercu-mobile.png`, `apercu-formulaire.png`, `apercu-planning.png`.

## Limites de la recette

Les tests ne constituent pas une validation d’un backend de production. L’import CSV, les uploads privés, tous les cas d’édition de chaque module, les exports et l’impression ne disposent pas tous de tests navigateur automatisés. Aucun test de charge, de concurrence financière, de MFA, d’isolation entre établissements, de restauration ou de conformité réglementaire n’a été réalisé. Les modules IA/optimisation sont des simulations explicitement indiquées.

Les données métier restent propres à la session et peuvent être réinitialisées. Les documents de production, règles financières, barèmes et permissions doivent être implémentés et validés séparément.
