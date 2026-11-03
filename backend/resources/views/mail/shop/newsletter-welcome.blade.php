<x-mail::message>
# {{ __('shop_mail.newsletter_welcome.title', [], $l) }}

{{ __('shop_mail.newsletter_welcome.intro', ['shop' => $data['shop']], $l) }}

{{ __('shop_mail.newsletter_welcome.leave', [], $l) }} [{{ __('shop_mail.newsletter_welcome.unsubscribe', [], $l) }}]({{ $data['unsubscribe_url'] }})
</x-mail::message>
