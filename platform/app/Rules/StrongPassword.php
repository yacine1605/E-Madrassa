<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * FR-IAM-02: at least 10 characters, not a common password, not built on the person's own
 * name, email or username, not a run of one character or of a keyboard sequence.
 */
final class StrongPassword implements ValidationRule
{
    /** Passwords and bases common in French, Arabic-speaking and English-speaking countries. */
    private const COMMON = ['password', 'motdepasse', 'azerty', 'azertyuiop', 'qwerty', 'qwertyuiop', 'qwertz', 'admin',
        'administrateur', 'welcome', 'bienvenue', 'bonjour', 'salut', 'soleil', 'iloveyou', 'jetaime', 'football', 'princess',
        'princesse', 'sunshine', 'superman', 'starwars', 'dragon', 'monkey', 'letmein', 'master', 'secret', 'changeme', 'default',
        'ecole', 'madrassa', 'madrasa', 'school', 'college', 'lycee', 'algerie', 'algeria', 'dzair', 'alger', 'oran', 'constantine',
        'bismillah', 'allahakbar', 'mohamed', 'mohammed', 'amine', 'yacine', 'fatima', 'test', 'user', 'utilisateur', 'login'];

    /** @param list<?string> $identity name, email, username: the password may not contain them */
    public function __construct(private array $identity = []) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || mb_strlen($value) < 10) { $fail('validation.password_policy.short')->translate(); return; }
        if (mb_strlen($value) > 128) { $fail('validation.password_policy.long')->translate(); return; }
        $lower = mb_strtolower($value);
        // The part that remains once digits and symbols are taken off the ends: "Password123!" is "password".
        $core = trim(preg_replace('/^[\d\W_]+|[\d\W_]+$/u', '', $lower));
        if (in_array($core, self::COMMON, true) || $this->runOrSequence($lower)) {
            $fail('validation.password_policy.common')->translate();
            return;
        }
        foreach ($this->identity as $part) {
            foreach (preg_split('/[\s@._-]+/u', mb_strtolower((string) $part)) as $piece) {
                if (mb_strlen($piece) >= 4 && str_contains($lower, $piece)) { $fail('validation.password_policy.personal')->translate(); return; }
            }
        }
    }

    private function runOrSequence(string $value): bool
    {
        if (preg_match('/^(.)\1+$/u', $value)) return true;
        foreach (['0123456789', '9876543210', 'abcdefghijklmnopqrstuvwxyz', 'azertyuiopqsdfghjklmwxcvbn', 'qwertyuiopasdfghjklzxcvbnm', '1q2w3e4r5t6y7u8i9o0p'] as $sequence) {
            if (str_contains($sequence.$sequence, $value)) return true;
        }
        return false;
    }
}
