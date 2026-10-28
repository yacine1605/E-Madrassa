<?php

namespace App\Support;

/**
 * A lesson's document as the database keeps it (SQL 078): a ProseMirror/Tiptap tree of a few
 * nodes and marks. The editor adds attributes of its own (a link's `rel` and `class`, empty ones);
 * they are dropped here before sending, and anything the schema does not know is left for the
 * database to refuse. The screens draw a document from this tree with escaped text, never as HTML.
 */
final class LessonDocument
{
    /** The attributes each node keeps. */
    private const ATTRIBUTES = ['paragraph' => ['textAlign'], 'heading' => ['level', 'textAlign'], 'orderedList' => ['start'],
        'codeBlock' => ['language'], 'image' => ['src', 'alt', 'title']];

    public const EMPTY = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];

    /** @param array<string, mixed>|null $node */
    public static function clean(?array $node): array
    {
        if ($node === null || $node === []) return self::EMPTY;
        $clean = ['type' => $node['type'] ?? null];
        if (array_key_exists('text', $node)) $clean['text'] = $node['text'];
        $keep = self::ATTRIBUTES[$clean['type']] ?? [];
        $attrs = array_filter(array_intersect_key((array) ($node['attrs'] ?? []), array_flip($keep)), fn ($value) => $value !== null && $value !== '');
        if (isset($attrs['textAlign']) && $attrs['textAlign'] === 'start') unset($attrs['textAlign']);
        if ($attrs !== []) $clean['attrs'] = $attrs;
        $marks = array_values(array_map(function ($mark) {
            if (!is_array($mark)) return $mark;
            $kept = ['type' => $mark['type'] ?? null];
            if (($mark['type'] ?? null) === 'link') {
                $kept['attrs'] = ['href' => $mark['attrs']['href'] ?? null];
                if (($mark['attrs']['target'] ?? null) === '_blank') $kept['attrs']['target'] = '_blank';
            }
            return $kept;
        }, (array) ($node['marks'] ?? [])));
        if ($marks !== []) $clean['marks'] = $marks;
        if (isset($node['content']) && is_array($node['content'])) {
            // An editor's empty text node is no text: dropped.
            $children = array_filter($node['content'], fn ($child) => !(is_array($child) && ($child['type'] ?? null) === 'text' && ($child['text'] ?? '') === ''));
            if ($children !== []) $clean['content'] = array_values(array_map(fn ($child) => is_array($child) ? self::clean($child) : $child, $children));
        }
        return $clean;
    }

    /** Whether a document says anything: text, an image or a rule. */
    public static function blank(array $node): bool
    {
        if (in_array($node['type'] ?? null, ['image', 'horizontalRule'], true) || trim((string) ($node['text'] ?? '')) !== '') return false;
        foreach ($node['content'] ?? [] as $child) if (is_array($child) && !self::blank($child)) return false;
        return true;
    }

    /** A heading's level on the page: below the lesson's own title, 2 to 5. */
    public static function headingLevel(array $node): int
    {
        return min(4, max(1, (int) ($node['attrs']['level'] ?? 2))) + 1;
    }

    /** An address the screens open: HTTPS only, whatever reached them. */
    public static function href(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
