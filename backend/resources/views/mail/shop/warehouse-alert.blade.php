<x-mail::message>
# {{ $data['title_md'] }}

{{ $data['detail_md'] }}

@if (! empty($data['url']))
<x-mail::button :url="$data['url']">
{{ __('shop_mail.warehouse_alert.open', [], $l) }}
</x-mail::button>
@endif
</x-mail::message>
