<x-mail::message>
# {{ __('shop_mail.shipment_created.title', [], $l) }}

{{ __('shop_mail.shipment_created.intro', ['carrier' => $data['carrier']], $l) }}
@if (! empty($data['tracking_number']))

{{ __('shop_mail.shipment_created.tracking', ['tracking_number' => $data['tracking_number']], $l) }}
@endif
@if (empty($data['complete']))

{{ __('shop_mail.shipment_created.partial', [], $l) }}
@endif

<x-mail::button :url="$data['tracking_url'] ?: $data['track_url']">{{ __('shop_mail.shipment_created.button', [], $l) }}</x-mail::button>
</x-mail::message>
