<x-mail::message>
# {{ __('shop_mail.newsletter_confirm.title', [], $l) }}

{{ __('shop_mail.newsletter_confirm.intro', ['shop' => $data['shop']], $l) }}

<x-mail::button :url="$data['url']">{{ __('shop_mail.newsletter_confirm.button', [], $l) }}</x-mail::button>

{{ __('shop_mail.newsletter_confirm.expires', ['days' => $data['days']], $l) }}

{{ __('shop_mail.newsletter_confirm.ignore', [], $l) }}
</x-mail::message>
