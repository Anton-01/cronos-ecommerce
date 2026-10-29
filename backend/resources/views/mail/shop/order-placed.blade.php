<x-mail::message>
# {{ __('shop_mail.order_placed.title', [], $l) }}

{{ __('shop_mail.order_placed.intro', ['number' => $data['number'], 'total' => $data['total'], 'currency' => $data['currency']], $l) }}

**{{ __('shop_mail.order_placed.items', [], $l) }}**

@foreach ($data['items'] as $item)
- {{ $item['name'] }} × {{ $item['quantity'] }} {{ $item['unit'] }} — {{ $item['total'] }} {{ $data['currency'] }}
@endforeach

<x-mail::button :url="$data['track_url']">{{ __('shop_mail.order_placed.button', [], $l) }}</x-mail::button>
</x-mail::message>
