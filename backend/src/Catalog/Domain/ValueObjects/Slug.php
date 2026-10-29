<?php

declare(strict_types=1);

namespace Cronos\Catalog\Domain\ValueObjects;

use Cronos\Catalog\Domain\Exceptions\InvalidProduct;

/** URL-safe, accent-free, lower-case identifier. */
final readonly class Slug implements \Stringable
{
    private function __construct(public string $value) {}

    public static function from(string $text): self
    {
        $ascii = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()')?->transliterate($text) ?: mb_strtolower($text);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');

        return self::fromValid($slug);
    }

    /** Accepts a hand-typed slug only if it is already in canonical form. */
    public static function fromValid(string $slug): self
    {
        (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) && strlen($slug) <= 191
            || throw new InvalidProduct("[{$slug}] is not a valid slug (lower-case letters, digits and hyphens).");

        return new self($slug);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
