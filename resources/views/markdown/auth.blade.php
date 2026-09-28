@php
    use Ipsocode\Scribe\Tools\Utils as u;
@endphp
# {{ u::trans("scribe::scribe.headings.auth") }}

@if(!$isAuthed)
{!! u::trans("scribe::scribe.auth.none") !!}
@else
{!! $authDescription !!}

{!! $extraAuthInfo !!}
@endif
