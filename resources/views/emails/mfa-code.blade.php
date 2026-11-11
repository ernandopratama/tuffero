<!DOCTYPE html>
<html lang="id" xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="light dark">
	<meta name="supported-color-schemes" content="light dark">
	<title>Kode verifikasi login Tuffero</title>
	<style>
		/* Gaya dasar ada inline (klien email tidak semuanya membaca <style>); blok ini hanya untuk HP dan mode gelap. */
		@media only screen and (max-width: 520px) {
			.tf-card { padding: 28px 20px !important; }
			.tf-code { font-size: 32px !important; letter-spacing: 8px !important; }
		}
		@media (prefers-color-scheme: dark) {
			.tf-bg { background-color: #1B120D !important; }
			.tf-card { background-color: #2A1D16 !important; border-color: #3E2D23 !important; }
			.tf-ink { color: #F6F0E5 !important; }
			.tf-text { color: #D9C9B5 !important; }
			.tf-muted { color: #A8937E !important; }
			.tf-codebox { background-color: #3A2A1F !important; border-color: #C6A15B !important; }
			.tf-notice { background-color: #33241B !important; color: #D9C9B5 !important; }
		}
	</style>
</head>
<body class="tf-bg" style="margin:0; padding:0; background-color:#F6F0E5; -webkit-text-size-adjust:100%;">
	{{-- Pratinjau di daftar kotak masuk: kodenya langsung terlihat tanpa membuka email. --}}
	<div style="display:none; max-height:0; overflow:hidden; opacity:0; mso-hide:all;">Kode Anda {{ $code }}. Berlaku {{ $minutes }} menit.&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>

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
							<p class="tf-muted" style="margin:0 0 6px; font-family:Arial,Helvetica,sans-serif; font-size:12px; font-weight:700; letter-spacing:1.6px; text-transform:uppercase; color:#6D4C3D;">Verifikasi dua langkah</p>
							<h1 class="tf-ink" style="margin:0 0 16px; font-family:Georgia,'Times New Roman',serif; font-size:26px; line-height:1.25; font-weight:normal; color:#2B1B14;">Halo {{ $name }},</h1>
							<p class="tf-text" style="margin:0 0 24px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.6; color:#6D4C3D;">Gunakan kode di bawah untuk menyelesaikan masuk ke dashboard Tuffero.</p>

							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
								<tr>
									<td align="center" class="tf-codebox" style="background-color:#FBF4E2; border:1px dashed #C6A15B; border-radius:18px; padding:22px 12px;">
										<span class="tf-code tf-ink" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace; font-size:38px; font-weight:bold; letter-spacing:12px; color:#2B1B14; padding-left:12px;">{{ $code }}</span>
									</td>
								</tr>
							</table>

							<p class="tf-text" style="margin:16px 0 0; text-align:center; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:1.5; color:#6D4C3D;">Berlaku <strong class="tf-ink" style="color:#2B1B14;">{{ $minutes }} menit</strong> dan hanya bisa dipakai sekali.</p>

							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
								<tr>
									<td class="tf-notice" style="background-color:#F6F0E5; border-radius:14px; padding:14px 16px; font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:1.55; color:#6D4C3D;">
										<strong class="tf-ink" style="color:#2B1B14;">Bukan Anda yang masuk?</strong><br>
										Abaikan email ini dan segera ganti password Anda. Jangan berikan kode ini kepada siapa pun, termasuk yang mengaku dari Tuffero.
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td align="center" class="tf-muted" style="padding:22px 12px 0; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.6; color:#8A7461;">
							Email otomatis dari Tuffero, mohon tidak dibalas.<br>
							&copy; {{ date('Y') }} Tuffero. Hak cipta dilindungi.
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
