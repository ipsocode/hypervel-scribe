<!doctype html>
<html lang="en">
<head>
    {{-- Anything in `scribe.external.html_attributes` is passed through to <elements-api>. See
         https://github.com/stoplightio/elements/blob/main/docs/getting-started/elements/elements-options.md --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{!! $metadata['title'] !!}</title>
    {{-- Stoplight Elements, as a web component. --}}
    <script src="https://unpkg.com/@stoplight/elements/web-components.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements/styles.min.css">
    <style>
        body {
            height: 100vh;
        }
    </style>
</head>
<body>

<elements-api
@foreach($htmlAttributes as $attribute => $value)
    {{-- Listed first, so these win: HTML keeps the first of duplicate attributes. --}}
    {!! $attribute !!}="{!! $value !!}"
@endforeach
    apiDescriptionUrl="{!! $metadata['openapi_spec_url'] !!}"
    router="hash"
    layout="sidebar"
    hideTryIt="{!! ($tryItOut['enabled'] ?? true) ? '' : 'true' !!}"
@if(!empty($metadata['logo']))
    logo="{!! $metadata['logo'] !!}"
@endif
/>

</body>
</html>
