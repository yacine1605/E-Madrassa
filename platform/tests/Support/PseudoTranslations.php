<?php

namespace Tests\Support;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Translation\Translator;

/**
 * FR-I18-01 AC3 (TST-17): French as a pseudo-language. Every string of every catalog — the
 * application's, Laravel's, Filament's — is shown between ⟦ and ⟧; a text shown without the
 * marks was written in the code, or names a key that no catalog holds.
 */
final class PseudoTranslations implements Loader
{
    public const OPEN = '⟦';
    public const CLOSE = '⟧';

    public function __construct(private Loader $catalogs) {}

    /** From now on, French is marked; whatever was already loaded is loaded again. */
    public static function install(Translator $translator): void
    {
        $pseudo = new self($translator->getLoader());
        (function () use ($pseudo) {
            $this->loader = $pseudo;
            $this->loaded = [];
        })->call($translator);
    }

    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->catalogs->load($locale, $group, $namespace);
        return $locale === 'fr' ? self::mark($lines) : $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->catalogs->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->catalogs->addJsonPath($path);
    }

    public function namespaces()
    {
        return $this->catalogs->namespaces();
    }

    private static function mark(mixed $lines): mixed
    {
        if (is_array($lines)) return array_map(self::mark(...), $lines);
        if (!is_string($lines) || $lines === '') return $lines;
        // Each plural form is marked after its condition ({0}, [2,*]).
        return implode('|', array_map(fn (string $form) => preg_match('/^(\{[^}]*\}|\[[^\]]*\])\s*(.*)$/s', $form, $m)
            ? $m[1].self::OPEN.$m[2].self::CLOSE : self::OPEN.$form.self::CLOSE, explode('|', $lines)));
    }
}
