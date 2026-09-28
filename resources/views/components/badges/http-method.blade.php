@component('scribe::components.badges.base', [
    'colour' => \Ipsocode\Scribe\Tools\WritingUtils::$httpMethodToCssColour[$method],
    'text' => $method,
    ])
@endcomponent
