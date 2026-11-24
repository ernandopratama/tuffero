{{ __('customer_mail.greeting') }}

{{ $status }}

{{ __('customer_mail.order') }}: {{ $orderNumber }}
@if ($reference)
{{ __('customer_mail.reference') }}: {{ $reference }}
@endif

{{ __('customer_mail.next') }}: {{ $next }}

{{ __('customer_mail.check') }}: {{ $checkUrl }}

@if ($whatsapp)
{{ __('customer_mail.contact', ['phone' => $whatsapp]) }}
@endif
{{ __('customer_mail.safety') }}

{{ __('customer_mail.footer') }}
