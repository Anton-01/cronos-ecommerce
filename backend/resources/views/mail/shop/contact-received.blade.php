<x-mail::message>
# {{ __('shop_mail.contact_received.title', [], $l) }}

{{ __('shop_mail.contact_received.intro', ['name' => $data['name_md'], 'topic' => __('shop_mail.contact_topics.'.$data['topic'], [], $l)], $l) }}

{!! $data['quoted'] !!}

<x-mail::table>
| | |
|:--|:--|
| **{{ __('shop_mail.contact_received.from', [], $l) }}** | {!! $data['name_md'] !!} — {!! $data['email_md'] !!} |
@if (! empty($data['phone_md']))
| **{{ __('shop_mail.contact_received.phone', [], $l) }}** | {!! $data['phone_md'] !!} |
@endif
@if (! empty($data['order_md']))
| **{{ __('shop_mail.contact_received.order', [], $l) }}** | {!! $data['order_md'] !!} |
@endif
| **{{ __('shop_mail.contact_received.topic', [], $l) }}** | {{ __('shop_mail.contact_topics.'.$data['topic'], [], $l) }} |
| **{{ __('shop_mail.contact_received.store', [], $l) }}** | {{ $data['market'] ?: '—' }}{{ $data['country'] ? ' · '.$data['country'] : '' }} |
| **{{ __('shop_mail.contact_received.reference', [], $l) }}** | {{ $data['reference'] }} |
</x-mail::table>

<x-mail::button :url="'mailto:'.$data['email'].'?subject='.rawurlencode('Re: ['.$data['reference'].']')">{{ __('shop_mail.contact_received.button', [], $l) }}</x-mail::button>

{{ __('shop_mail.contact_received.footer', [], $l) }}
</x-mail::message>
