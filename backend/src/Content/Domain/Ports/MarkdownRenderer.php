<?php

declare(strict_types=1);

namespace Cronos\Content\Domain\Ports;

interface MarkdownRenderer
{
    /** Safe HTML from Markdown: raw HTML in the text is dropped, unsafe links (javascript:) are removed. */
    public function render(string $markdown): string;
}
