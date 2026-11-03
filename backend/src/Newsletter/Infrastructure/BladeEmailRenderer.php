<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Infrastructure;

use Cronos\Newsletter\Domain\Ports\EmailRenderer;

final class BladeEmailRenderer implements EmailRenderer
{
    public function render(array $c): array
    {
        $html = view('mail.campaign', $c + ['accent' => (string) config('newsletter.accent', '#F59A57')])->render();
        // Plain-text twin (some inboxes and spam filters want one): links kept as "text (url)", the rest stripped.
        $body = (string) preg_replace('~<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>~is', '$2 ($1)', $c['body_html']);
        $body = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</h1>', '</h2>', '</h3>', '<br>', '<br />'], "\n\n", $body)), ENT_QUOTES, 'UTF-8'));
        $text = $c['subject']."\n\n".preg_replace("/\n{3,}/", "\n\n", $body)."\n\n--\n".$c['shop'].($c['address'] === '' ? '' : "\n{$c['address']}")."\n{$c['reason']}\n{$c['unsubscribe_label']}: {$c['unsubscribe_url']}\n";

        return ['html' => $html, 'text' => $text];
    }
}
