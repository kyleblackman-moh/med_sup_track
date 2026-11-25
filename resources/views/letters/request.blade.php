{{--
    The request letter PDF (App\Letters\RequestLetter). Rendered by dompdf, so
    plain CSS 2.1 and tables only: no flexbox, grid or web fonts. Every string
    comes built from RequestLetter::data(); this file only lays it out.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $letter['subject'] }}</title>
<style>
    @page { margin: 54pt 54pt 64pt 100pt; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.3; color: #000; margin: 0; }
    .band { position: fixed; top: -54pt; bottom: -64pt; left: -100pt; width: 60pt; background: {{ $letter['band_colour'] }}; }
    .letterhead { margin: -30pt 0 18pt 0; border-bottom: 1.5pt solid #555; padding-bottom: 3pt; }
    .letterhead .logo { display: inline-block; width: 52pt; height: 40pt; vertical-align: bottom; }
    .letterhead .title { font-family: 'DejaVu Sans', sans-serif; font-size: 24pt; font-weight: bold; line-height: 1; }
    .letterhead .subtitle { font-family: 'DejaVu Sans', sans-serif; font-size: 12.5pt; line-height: 1.2; }
    p { margin: 0 0 11pt 0; }
    .lines p, .lines div { margin: 0; }
    .subject { font-weight: bold; }
    table.grid { width: 100%; border-collapse: collapse; margin: 0 0 12pt 0; page-break-inside: auto; }
    table.grid th, table.grid td { border: 1pt solid #000; font-size: 11pt; line-height: 1.3; padding: 2pt 3pt; text-align: center; vertical-align: middle; }
    table.grid th { font-weight: bold; }
    table.grid tr { page-break-inside: avoid; }
    table.small th, table.small td { font-size: 6.5pt; line-height: 1.2; padding: 2pt 1.5pt; }
    .close { margin-top: 14pt; page-break-inside: avoid; }
    .signature { height: 42pt; }
    .attach { margin-top: 14pt; }
    .annex { page-break-before: always; }
    .annex h2 { font-size: 13pt; text-align: center; margin: 0 0 10pt 0; }
    .footer { position: fixed; bottom: -46pt; left: 0; right: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; text-align: center; color: #333; border-top: 0.5pt solid #777; padding-top: 3pt; }
    .watermark { position: fixed; top: 300pt; left: -60pt; width: 640pt; text-align: center; font-family: 'DejaVu Sans', sans-serif; font-size: 54pt; font-weight: bold; color: #888; opacity: 0.25; transform: rotate(-35deg); }
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

    <p>{{ $letter['opening'] }}</p>

    @if ($letter['award'])
        <p>{{ $letter['award']['sentence'] }}</p>
        <table class="grid">
            <thead>
                <tr>
                    <th>Supplier</th><th>Manufacturer</th><th>Brand</th><th>Quantity Awarded</th><th>% Awarded</th><th>Unit Cost</th><th>Total Cost</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($letter['award']['rows'] as $row)
                    <tr>
                        <td>{{ $row['supplier'] }}</td>
                        <td>{{ $row['manufacturer'] }}@if ($row['country'])<br>({{ $row['country'] }})@endif</td>
                        <td>{{ $row['brand'] }}</td>
                        <td>{{ $row['quantity'] }}</td>
                        <td>{{ $row['percentage'] }}</td>
                        <td>{{ $row['unit_cost'] }}</td>
                        <td>{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($letter['orders'])
        <p>{{ $letter['orders']['sentence'] }}</p>
        <table class="grid">
            <thead>
                <tr>
                    <th>Supplier</th><th>PO#</th><th>Quantity Ordered</th><th>Date Ordered</th><th>Quantity Delivered</th><th>Quantity Outstanding</th><th>Delivery Date</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($letter['orders']['rows'] as $row)
                    <tr>
                        <td>{{ $row['supplier'] }}</td>
                        <td>{{ $row['po'] }}</td>
                        <td>{{ $row['ordered'] }}</td>
                        <td>{{ $row['ordered_on'] }}</td>
                        <td>{{ $row['delivered'] }}</td>
                        <td>{{ $row['outstanding'] }}</td>
                        <td>{{ $row['delivery'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($letter['issues'])
        <p>{{ $letter['issues'] }}</p>
    @endif

    @if ($letter['clinical_note'])
        <p>{{ $letter['clinical_note'] }}</p>
    @endif

    @includeWhen($letter['kind'] === 'variation', 'letters.partials.variation')
    @includeWhen($letter['kind'] !== 'variation', 'letters.partials.purchase')

    <div class="close">
        <p>Submitted for your consideration and approval.</p>
        <p>Yours faithfully,<br>{{ $letter['close']['organisation'] }}</p>
        <div class="signature"></div>
        <div class="lines">
            @foreach (['name', 'title', 'division'] as $key)
                @if ($letter['close'][$key])
                    <div>{{ $letter['close'][$key] }}</div>
                @endif
            @endforeach
        </div>
    </div>

    @if ($letter['attachments'] !== [])
        <div class="attach lines">
            <div>/Attach</div>
            @foreach ($letter['attachments'] as $doc)
                <div>{{ $doc['title'] }} ({{ $doc['category'] }})</div>
            @endforeach
        </div>
    @endif

    @includeWhen($letter['annex'], 'letters.partials.annex')
</body>
</html>
