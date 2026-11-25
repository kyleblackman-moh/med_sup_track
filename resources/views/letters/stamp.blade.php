{{-- The draft watermark alone (App\Letters\RequestLetter::stamp): qpdf lays this page over each appended document page. Same placement as .watermark in letters/request. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; }
    .watermark { position: fixed; top: 364pt; left: 40pt; width: 640pt; text-align: center; font-family: 'DejaVu Sans', sans-serif; font-size: 54pt; font-weight: bold; color: #888; opacity: 0.25; transform: rotate(-35deg); }
</style>
</head>
<body>
    <div class="watermark">{{ $watermark }}</div>
</body>
</html>
