<x-mail::message>
# {{ __('shop_mail.custom_order_update.title', [], $l) }}

{{ $data['message'] }}

<x-mail::button :url="$data['url']">{{ __('shop_mail.custom_order_update.button', [], $l) }}</x-mail::button>
</x-mail::message>
