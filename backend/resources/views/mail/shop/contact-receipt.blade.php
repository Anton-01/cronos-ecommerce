<x-mail::message>
# {{ __('shop_mail.contact_receipt.title', ['name' => $data['name_md']], $l) }}

{{ __('shop_mail.contact_receipt.intro', ['shop' => $data['shop_md']], $l) }}

{!! $data['quoted'] !!}

**{{ __('shop_mail.contact_receipt.reference', ['reference' => $data['reference']], $l) }}**
@if (! empty($data['support_hours_md']))

{{ __('shop_mail.contact_receipt.hours', ['hours' => $data['support_hours_md']], $l) }}
@endif

{{ __('shop_mail.contact_receipt.footer', [], $l) }}
</x-mail::message>
