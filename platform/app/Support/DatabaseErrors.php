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
    ];

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
        if ($state === '23505') return [409, 'duplicate', 'Cette valeur est déjà utilisée.'];
        if (in_array($state, ['23503', '55P03', '40001', '40P01'], true)) {
            return [409, 'domain_conflict', 'Opération incompatible avec l’état actuel des données. Rechargez puis recommencez.'];
        }
        // The restricted role lacks the privilege or RLS refused the row: a refusal, not a fault.
        if ($state === '42501') return [403, 'forbidden', 'Cette opération n’est pas autorisée.'];
        return null;
    }
}
