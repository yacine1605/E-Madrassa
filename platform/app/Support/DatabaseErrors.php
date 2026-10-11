<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Turns a rule enforced by the database into a clear API answer (NFR-UX-04). Never returns
 * SQL, bindings or personal data (NFR-SEC-06).
 */
final class DatabaseErrors
{
    /** Rules raised by the schema's triggers, with the message shown to the user. */
    private const RULES = [
        'stale_version' => 'Cet enregistrement a été modifié par quelqu’un d’autre. Rechargez-le puis recommencez.',
        'last_site' => 'Un établissement garde au moins un site en service.',
        'code_immutable' => 'Un code est définitif : il ne peut pas être modifié.',
        'identity_immutable' => 'Cet enregistrement ne peut pas changer d’identité.',
        'immutable_record' => 'Cet enregistrement est définitif.',
        'last_admin' => 'L’établissement doit garder au moins un administrateur actif qui gère les rôles.',
        'self_assignment' => 'Vous ne pouvez pas modifier vos propres rôles : un autre administrateur doit le faire.',
        'self_elevation' => 'Vous ne pouvez pas modifier un rôle que vous détenez : un autre administrateur doit le faire.',
        'role_in_use' => 'Ce rôle est encore assigné : terminez d’abord ses assignations.',
        'role_archived' => 'Ce rôle est archivé : il ne peut plus être assigné.',
        'scope_personal_required' => 'Un rôle élève ou parent porte uniquement sur les données de la personne.',
        'scope_unknown' => 'La portée désigne un élément qui n’existe pas dans cet établissement.',
        'permission_module_disabled' => 'Cette permission appartient à un module non activé pour cet établissement.',
        'segregation_of_duties' => 'Séparation des tâches : la personne qui a saisi une opération ne peut pas la valider elle-même.',
        'token_invalid' => 'Ce lien n’est plus valable : demandez-en un nouveau.',
        'password_already_set' => 'Vous avez déjà un mot de passe : connectez-vous avec lui pour accepter l’invitation.',
        'password_required' => 'Choisissez un mot de passe.',
        'account_shared' => 'Cette personne a aussi un compte dans un autre établissement : son nom, son e-mail et son mot de passe ne peuvent être changés que par elle.',
        'account_unknown' => 'Ce compte n’existe pas dans cet établissement.',
        'email_required' => 'Un rôle privilégié demande une adresse e-mail.',
        'self_account' => 'Vous ne pouvez pas faire cette opération sur votre propre compte.',
        'reason_required' => 'Indiquez un motif.',
        'activation_required' => 'Ce compte n’est pas encore activé : renvoyez-lui l’invitation.',
        'not_invited' => 'Ce compte n’attend pas d’activation.',
        'login_required' => 'Un compte a besoin d’une adresse e-mail ou d’un identifiant.',
        'name_required' => 'Indiquez le nom de la personne.',
        'membership_revoked' => 'Ce compte est révoqué définitivement.',
        'status_invalid' => 'État de compte inconnu.',
        'setting_invalid' => 'Cette valeur n’est pas permise pour ce paramètre.',
        'type_required' => 'Ce type du référentiel est obligatoire : il reste actif.',
        'type_in_use' => 'Ce type a encore des éléments actifs : archivez-les d’abord.',
        'type_inactive' => 'Ce type du référentiel est désactivé.',
        'parent_invalid' => 'Le parent choisi n’est pas du type attendu, ou il est archivé.',
        'node_has_children' => 'Cet élément a encore des éléments actifs en dessous : archivez-les d’abord.',
        'subject_in_use' => 'Cette matière est utilisée : archivez-la plutôt.',
        'year_closed' => 'Cette année est clôturée : elle ne change plus.',
        'period_outside_year' => 'La période doit rester dans les dates de son année.',
        'options_invalid' => 'Chaque valeur de la liste a un code (A-Z, 0-9, _) unique et un libellé.',
        'option_removed' => 'Une valeur de la liste ne se retire pas : des fiches peuvent l’utiliser.',
        'custom_field_limit' => 'Vingt champs personnalisés actifs au plus par type de fiche.',
        'custom_value_invalid' => 'Valeur invalide pour un champ personnalisé.',
        'custom_value_required' => 'Un champ personnalisé obligatoire est vide.',
        'custom_field_unknown' => 'Champ personnalisé inconnu.',
        'profile_unknown' => 'Profil inconnu ou non publié.',
        'profile_mismatch' => 'Cet établissement suit un autre profil.',
        'profile_published' => 'Une version publiée d’un profil ne change plus.',
        'job_finished' => 'Cette tâche est terminée : elle ne change plus.',
        'not_failed' => 'Seul un envoi en échec se relance.',
        'tenant_required' => 'Aucun établissement choisi.',
        'file_type_refused' => 'Ce type de fichier n’est pas accepté ici.',
        'file_too_large' => 'Ce fichier dépasse la taille permise par l’établissement.',
        'dependent_operation' => 'Une opération saisie depuis dépend de cet enregistrement.',
        'storage_quota_exceeded' => 'L’espace de stockage de l’établissement est plein : archivez des fichiers ou augmentez l’abonnement.',
    ];

    public static function message(string $rule): string
    {
        return self::RULES[$rule];
    }

    public static function response(QueryException $e): ?JsonResponse
    {
        $known = self::classify($e);
        return $known ? response()->json(['message' => $known[2], 'code' => $known[1]], $known[0]) : null;
    }

    /** [HTTP status, code, message] for a refusal the schema enforces; null for anything else, which is a fault. */
    public static function classify(QueryException $e): ?array
    {
        $state = (string) $e->getCode();
        $detail = $e->errorInfo[2] ?? '';
        if ($state === '25006') return [403, 'tenant_read_only', 'Cet établissement est en lecture seule : son abonnement a expiré ou il est archivé.'];
        if ($state === '23514') {
            foreach (self::RULES as $rule => $message) {
                if (preg_match('/ERROR:\s+'.preg_quote($rule, '/').'\b/', $detail)) return [409, $rule, $message];
            }
            return [409, 'domain_conflict', 'Opération incompatible avec l’état actuel des données.'];
        }
        if ($state === '23505' && preg_match('/ERROR:\s+account_exists\b/', $detail)) {
            return [409, 'account_exists', 'Cette adresse e-mail a déjà un compte dans cet établissement.'];
        }
        if ($state === '23505') return [409, 'duplicate', 'Cette valeur est déjà utilisée.'];
        if ($state === '23P01') return [409, 'overlap', 'Ces dates chevauchent celles d’un autre enregistrement.'];
        if (in_array($state, ['23503', '55P03', '40001', '40P01'], true)) {
            return [409, 'domain_conflict', 'Opération incompatible avec l’état actuel des données. Rechargez puis recommencez.'];
        }
        // A trigger found the permission missing; or the restricted role lacks the privilege, or
        // RLS refused the row: a refusal, not a fault.
        if ($state === '42501' && preg_match('/ERROR:\s+permission_denied\b/', $detail)) {
            return [403, 'permission_denied', 'Vous n’avez pas la permission nécessaire.'];
        }
        if ($state === '42501') return [403, 'forbidden', 'Cette opération n’est pas autorisée.'];
        return null;
    }
}
