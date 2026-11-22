<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    {{-- HARDCOPY-1: ASCII title (the PDF's Title field), so the reference is findable in the file. --}}
    <title>Hardcopy Cover Sheet {{ $sheet['reference'] }}</title>
    <style>
        /* A printed document rendered by dompdf (DejaVu Sans), not a daisyUI page. */
        @page { margin: 22mm 20mm 26mm 20mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; color: black; }
        h1 { font-size: 15pt; margin: 0 0 10mm 0; }
        .reference { font-family: "DejaVu Sans Mono", monospace; font-size: 24pt; font-weight: bold; letter-spacing: 1pt; margin: 0 0 10mm 0; }
        table { border-collapse: collapse; width: 100%; margin: 0 0 10mm 0; }
        th, td { text-align: left; vertical-align: top; padding: 2.5mm 0; border-bottom: 0.3pt solid black; }
        th { width: 45mm; font-weight: bold; }
        .instruction { border: 0.6pt solid black; padding: 4mm; }
        footer { position: fixed; bottom: -16mm; left: 0; right: 0; font-family: "DejaVu Sans Mono", monospace; font-size: 10pt; text-align: center; }
    </style>
</head>
<body>
    <footer>{{ $sheet['reference'] }}</footer>

    <h1>{{ $sheet['title'] }}</h1>

    <p class="reference">{{ $sheet['reference'] }}</p>

    <table>
        <tr><th>Vendor</th><td>{{ $sheet['organisation'] ?? '-' }}</td></tr>
        <tr><th>Request type</th><td>{{ $sheet['request_type'] ?? '-' }}</td></tr>
        <tr><th>Submitted</th><td>{{ $sheet['submitted_at'] ?? '-' }} (Port of Spain time)</td></tr>
        <tr><th>Documents uploaded</th><td>{{ $sheet['documents'] }}</td></tr>
    </table>

    <p class="instruction">{{ $sheet['instruction'] }}</p>
</body>
</html>
