@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none; font-size: 26px; letter-spacing: -0.01em; font-weight: 500; color: #0B44F5;">
@if (config('everdigitech.mail_logo_url'))
<img src="{{ config('everdigitech.mail_logo_url') }}" alt="EverDigitech" width="180" height="27" style="display: block; border: 0; width: 180px; height: auto; max-width: 100%;">
@else
Ever<span style="font-weight: 800; color: #0A2A9E;">Digitech</span>
@endif
</a>
</td>
</tr>
