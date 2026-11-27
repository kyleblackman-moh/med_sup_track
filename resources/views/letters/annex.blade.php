{{--
    Kyle, 2026-11-27: the Quotation Comparison annex as its own landscape
    document (RequestLetter::pdf), merged after the letter by qpdf. Same footer
    and draft watermark as the letter; the running header is drawn by pdf().
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Quotation Comparison</title>
<style>
    @page { margin: 54pt 54pt 64pt 54pt; }
    @include('letters.partials.styles')
    .footer { bottom: -46pt; }
    .watermark { top: 200pt; left: 40pt; width: 600pt; }
</style>
</head>
<body>
    @if ($letter['footer'] !== '')
        <div class="footer">{{ $letter['footer'] }}</div>
    @endif
    @if ($letter['draft'])
        <div class="watermark">{{ $letter['watermark'] }}</div>
    @endif

    <div class="annex-page">
        @include('letters.partials.annex')
    </div>
</body>
</html>
