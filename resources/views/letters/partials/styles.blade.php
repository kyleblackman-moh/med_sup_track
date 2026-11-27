{{-- Shared by letters/request and letters/annex. dompdf: CSS 2.1 and tables only. --}}
body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.3; color: #000; margin: 0; }
p { margin: 0 0 11pt 0; }
h2.section, h3 { font-size: 12pt; font-weight: bold; margin: 14pt 0 8pt 0; page-break-after: avoid; }
h3 { margin-top: 10pt; }
ol.numbered { margin: 0 0 11pt 0; padding-left: 22pt; }
ol.numbered li { margin: 0 0 3pt 0; }
table.grid { width: 100%; border-collapse: collapse; margin: 0 0 12pt 0; page-break-inside: auto; }
table.grid th, table.grid td { border: 1pt solid #000; font-size: 10.5pt; line-height: 1.3; padding: 2pt 3pt; text-align: center; vertical-align: middle; }
table.grid th { font-weight: bold; }
table.grid tr { page-break-inside: avoid; }
table.narrow { width: 60%; page-break-inside: avoid; }
table.details th, table.details td { text-align: left; }
table.details td:first-child { width: 35%; }
table.small th, table.small td { font-size: 8.5pt; line-height: 1.2; padding: 2pt 2pt; }
table.grid tr.recommended td { background: #fff2a8; }
table.annex-header { border-collapse: collapse; margin: 0 0 10pt 0; }
table.annex-header th { text-align: left; font-weight: bold; padding: 1pt 12pt 1pt 0; font-size: 11pt; }
table.annex-header td { padding: 1pt 0; font-size: 11pt; }
h2.annex-title { font-size: 13pt; text-align: center; margin: 0 0 10pt 0; }
.watermark { position: fixed; text-align: center; font-family: 'DejaVu Sans', sans-serif; font-size: 54pt; font-weight: bold; color: #888; opacity: 0.25; transform: rotate(-35deg); }
.footer { position: fixed; left: 0; right: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; text-align: center; color: #333; border-top: 0.5pt solid #777; padding-top: 3pt; }
