<x-mail::message>
# {{ __('shop_mail.export_ready.title', ['type' => __('shop_mail.export_ready.types.'.$data['type'], [], $l)], $l) }}

{{ __('shop_mail.export_ready.intro', ['rows' => $data['rows']], $l) }}
@if (! empty($data['truncated']))

{{ __('shop_mail.export_ready.truncated', [], $l) }}
@endif

<x-mail::button :url="$data['url']">{{ __('shop_mail.export_ready.button', [], $l) }}</x-mail::button>
</x-mail::message>
