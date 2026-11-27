{{--
    The request letter PDF (App\Letters\RequestLetter). Rendered by dompdf, so
    plain CSS 2.1 and tables only: no flexbox, grid or web fonts. Every string
    comes built from RequestLetter::data(); this file only lays it out.
    Kyle, 2026-11-27: numbered sections (letters/partials/blocks). The annex is
    its own landscape document (letters/annex) unless $annexInline.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $letter['subject'] }}</title>
<style>
    @page { margin: 54pt 54pt 64pt 100pt; }
    @include('letters.partials.styles')
    .band { position: fixed; top: -54pt; bottom: -64pt; left: -100pt; width: 60pt; background: {{ $letter['band_colour'] }}; }
    .letterhead { margin: -30pt 0 18pt 0; border-bottom: 1.5pt solid #555; padding-bottom: 3pt; }
    .letterhead .logo { display: inline-block; width: 52pt; height: 40pt; vertical-align: bottom; }
    .letterhead .title { font-family: 'DejaVu Sans', sans-serif; font-size: 24pt; font-weight: bold; line-height: 1; }
    .letterhead .subtitle { font-family: 'DejaVu Sans', sans-serif; font-size: 12.5pt; line-height: 1.2; }
    .lines p, .lines div { margin: 0; }
    .subject { font-weight: bold; }
    .close { margin-top: 14pt; page-break-inside: avoid; }
    .close .organisation { font-weight: bold; }
    .signature { height: 42pt; }
    .attach { margin-top: 14pt; }
    .annex { page-break-before: always; }
    .footer { bottom: -46pt; }
    .watermark { top: 300pt; left: -60pt; width: 640pt; }
</style>
</head>
<body>
    <div class="band"></div>
    @if ($letter['footer'] !== '')
        <div class="footer">{{ $letter['footer'] }}</div>
    @endif
    @if ($letter['draft'])
        <div class="watermark">{{ $letter['watermark'] }}</div>
    @endif

    <div class="letterhead">
        {{-- Logo slot: organisations.logo_path, added later. --}}
        <div class="title">{{ $letter['letterhead']['title'] }}</div>
        @if ($letter['letterhead']['subtitle'])
            <div class="subtitle">{{ $letter['letterhead']['subtitle'] }}</div>
        @endif
    </div>

    <p>{{ $letter['date'] }}</p>

    <div class="lines">
        @foreach ($letter['recipient'] as $line)
            <div>{{ $line }}</div>
        @endforeach
    </div>
    <p></p>

    <p>{{ $letter['salutation'] }}</p>

    <p class="subject">{{ $letter['subject'] }}</p>

    @foreach ($letter['sections'] as $section)
        <h2 class="section">{{ $section['number'] }}. {{ $section['title'] }}</h2>
        @include('letters.partials.blocks', ['blocks' => $section['blocks']])
    @endforeach

    <div class="close">
        <p>Submitted for your consideration and approval.</p>
        <p>Yours faithfully,</p>
        <p class="organisation">{{ $letter['close']['organisation'] }}</p>
        <div class="signature"></div>
        <div class="lines">
            @foreach (['name', 'title', 'division'] as $key)
                @if ($letter['close'][$key])
                    <div>{{ $letter['close'][$key] }}</div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- The uploaded documents follow the letter as pages. Only one that could not be appended is listed. --}}
    @if ($letter['attachments'] !== [])
        <div class="attach lines">
            <div>/Attach (not appended)</div>
            @foreach ($letter['attachments'] as $doc)
                <div>{{ $doc['title'] }} ({{ $doc['category'] }})</div>
            @endforeach
        </div>
    @endif

    @if ($letter['annex'] && ($annexInline ?? true))
        <div class="annex">
            @include('letters.partials.annex')
        </div>
    @endif
</body>
</html>
