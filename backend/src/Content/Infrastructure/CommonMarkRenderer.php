<?php

declare(strict_types=1);

namespace Cronos\Content\Infrastructure;

use Cronos\Content\Domain\Ports\MarkdownRenderer;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/** GitHub-flavoured Markdown (tables, lists, links) with raw HTML stripped and unsafe link schemes refused: what an editor types can never run a script on the shop. */
final class CommonMarkRenderer implements MarkdownRenderer
{
    public function render(string $markdown): string
    {
        return trim((new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]))->convert($markdown)->getContent());
    }
}
