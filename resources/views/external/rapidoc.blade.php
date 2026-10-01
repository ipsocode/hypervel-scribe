<!doctype html> {{-- RapiDoc requires the doctype. --}}
<html lang="en">
<head>
    {{-- Anything in `scribe.external.html_attributes` is passed through to <rapi-doc>.
         See https://rapidocweb.com/api.html --}}
    <meta charset="utf-8"> {{-- Required: RapiDoc uses UTF-8 characters. --}}
    <title>{!! $metadata['title'] !!}</title>
    <script type="module" src="https://unpkg.com/rapidoc/dist/rapidoc-min.js"></script>
</head>
<body>
<rapi-doc
@foreach($htmlAttributes as $attribute => $value)
    {{-- Listed first, so these win: HTML keeps the first of duplicate attributes. --}}
    {!! $attribute !!}="{!! $value !!}"
@endforeach
    spec-url="{!! $metadata['openapi_spec_url'] !!}"
    render-style="read"
    allow-try="{!! ($tryItOut['enabled'] ?? true) ? 'true' : 'false' !!}"
>
    @if($metadata['logo'])
        <img slot="logo" src="{!! $metadata['logo'] !!}"/>
    @endif
</rapi-doc>
</body>
</html>
