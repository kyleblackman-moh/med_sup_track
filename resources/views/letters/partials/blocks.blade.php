{{-- One section's blocks (App\Letters\RequestLetter::sections): paragraphs, tables, numbered lists and numbered subsections. --}}
@foreach ($blocks as $block)
    @switch($block['type'])
        @case('p')
            <p>{{ $block['text'] }}</p>
            @break
        @case('list')
            <ol class="numbered">
                @foreach ($block['items'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ol>
            @break
        @case('table')
            <table class="grid {{ $block['style'] }}">
                <thead>
                    <tr>
                        @foreach ($block['head'] as $heading)<th>{{ $heading }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($block['rows'] as $row)
                        <tr>
                            @foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @break
        @case('subsection')
            <h3>{{ $block['number'] }} {{ $block['title'] }}</h3>
            @include('letters.partials.blocks', ['blocks' => $block['blocks']])
            @break
    @endswitch
@endforeach
