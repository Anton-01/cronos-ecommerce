<x-mail::message>
# {{ __('shop_mail.order_status.title', ['number' => $data['number']], $l) }}

{{ __('shop_mail.order_status.'.$data['status'], [], $l) }}

<x-mail::button :url="$data['track_url']">{{ __('shop_mail.order_status.button', [], $l) }}</x-mail::button>
</x-mail::message>
