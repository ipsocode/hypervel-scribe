@php
    use Ipsocode\Scribe\Tools\Utils as u;
@endphp
# {{ u::trans("scribe::scribe.headings.introduction") }}

{!! $description !!}

<aside>
    <strong>{{ u::trans("scribe::scribe.labels.base_url") }}</strong>: <code>{!! $baseUrl !!}</code>
</aside>

{!! $introText !!}

