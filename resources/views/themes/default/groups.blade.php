@foreach($groupedEndpoints as $group)
    <h1 id="{!! \Hypervel\Support\Str::slug($group['name']) !!}">{{ $group['name'] }}</h1>

    {!! Parsedown::instance()->text($group['description']) !!}

    @foreach($group['subgroups'] as $subgroupName => $subgroup)
        @if($subgroupName !== "")
            <h2 id="{!! \Hypervel\Support\Str::slug($group['name']) !!}-{!! \Hypervel\Support\Str::slug($subgroupName) !!}">{{ $subgroupName }}</h2>
            @php($subgroupDescription = collect($subgroup)->first(fn ($e) => $e->metadata->subgroupDescription)?->metadata?->subgroupDescription)
            @if($subgroupDescription)
                <p>
                    {!! Parsedown::instance()->text($subgroupDescription) !!}
                </p>
            @endif
        @endif
        @foreach($subgroup as $endpoint)
            @include("scribe::themes.default.endpoint")
        @endforeach
    @endforeach
@endforeach

