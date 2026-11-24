{{-- Kabar pembatalan atau refund untuk pelanggan (CustomerOrderUpdate). Gaya mengikuti mfa-code; semua teks lewat {{ }}. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="light dark">
	<meta name="supported-color-schemes" content="light dark">
	<title>{{ $subjectLine }}</title>
	<style>
		@media only screen and (max-width: 520px) {
			.tf-card { padding: 28px 20px !important; }
		}
		@media (prefers-color-scheme: dark) {
			.tf-bg { background-color: #1B120D !important; }
			.tf-card { background-color: #2A1D16 !important; border-color: #3E2D23 !important; }
			.tf-ink { color: #F6F0E5 !important; }
			.tf-text { color: #D9C9B5 !important; }
			.tf-muted { color: #A8937E !important; }
			.tf-notice { background-color: #33241B !important; color: #D9C9B5 !important; }
		}
	</style>
</head>
<body class="tf-bg" style="margin:0; padding:0; background-color:#F6F0E5; -webkit-text-size-adjust:100%;">
	<div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">{{ $status }}</div>

	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="tf-bg" style="background-color:#F6F0E5;">
		<tr>
			<td align="center" style="padding:32px 16px;">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
					<tr>
						<td align="center" style="padding:0 0 20px;">
							<span class="tf-ink" style="font-family:Georgia,'Times New Roman',serif; font-size:30px; font-style:italic; letter-spacing:0.5px; color:#2B1B14;">tuffero</span>
						</td>
					</tr>
					<tr>
						<td class="tf-card" style="background-color:#FFFFFF; border:1px solid #E3D5C0; border-radius:24px; padding:36px 36px 32px;">
							<h1 class="tf-ink" style="margin:0 0 8px; font-family:Georgia,'Times New Roman',serif; font-size:22px; line-height:1.3; font-weight:normal; color:#2B1B14;">{{ __('customer_mail.greeting') }}</h1>
							<p class="tf-text" style="margin:0 0 20px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.6; color:#6D4C3D;">{{ $status }}</p>

							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.6;">
								<tr>
									<td class="tf-muted" style="padding:4px 0; color:#8A7461;">{{ __('customer_mail.order') }}</td>
									<td class="tf-ink" align="right" style="padding:4px 0; color:#2B1B14; font-weight:bold;">{{ $orderNumber }}</td>
								</tr>
								@if ($reference)
									<tr>
										<td class="tf-muted" style="padding:4px 0; color:#8A7461;">{{ __('customer_mail.reference') }}</td>
										<td class="tf-ink" align="right" style="padding:4px 0; color:#2B1B14; font-weight:bold;">{{ $reference }}</td>
									</tr>
								@endif
							</table>

							<p class="tf-muted" style="margin:24px 0 6px; font-family:Arial,Helvetica,sans-serif; font-size:12px; font-weight:700; letter-spacing:1.6px; text-transform:uppercase; color:#6D4C3D;">{{ __('customer_mail.next') }}</p>
							<p class="tf-text" style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.6; color:#6D4C3D;">{{ $next }}</p>
							<p style="margin:16px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:14px;"><a href="{{ $checkUrl }}" class="tf-ink" style="color:#2B1B14; font-weight:bold;">{{ __('customer_mail.check') }}</a></p>

							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
								<tr>
									<td class="tf-notice" style="background-color:#F6F0E5; border-radius:14px; padding:14px 16px; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:1.55; color:#6D4C3D;">
										@if ($whatsapp)
											{{ __('customer_mail.contact', ['phone' => $whatsapp]) }}<br>
										@endif
										{{ __('customer_mail.safety') }}
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td align="center" class="tf-muted" style="padding:22px 12px 0; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.6; color:#8A7461;">
							{{ __('customer_mail.footer') }}<br>
							&copy; {{ date('Y') }} Tuffero.
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
