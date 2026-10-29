<x-mail::message>
# {{ __('shop_mail.quote_ready.title', [], $l) }}

{{ __('shop_mail.quote_ready.intro', ['total' => $data['total'], 'currency' => $data['currency'], 'valid_until' => $data['valid_until']], $l) }}

<x-mail::button :url="$data['url']">{{ __('shop_mail.quote_ready.button', [], $l) }}</x-mail::button>
</x-mail::message>
