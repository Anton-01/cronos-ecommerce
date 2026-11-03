<?php

declare(strict_types=1);

namespace Cronos\Newsletter\Domain\Ports;

interface EmailRenderer
{
    /**
     * @param  array{subject: string, preheader: string, body_html: string, shop: string, address: string, unsubscribe_url: string, reason: string, unsubscribe_label: string}  $c
     * @return array{html: string, text: string}
     */
    public function render(array $c): array;
}
