<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 24px 28px; }
    body { font-family: DejaVu Sans, sans-serif; color: #2B323B; font-size: 9px; }
    .head { border-bottom: 3px solid #0E6B7B; padding-bottom: 8px; margin-bottom: 12px; }
    .logo { font-size: 18px; font-weight: bold; color: #0E5563; }
    .logo span { color: #F2603F; }
    h1 { font-size: 14px; margin: 4px 0 0; color: #0E5563; }
    .muted { color: #6B7785; }
    .summary { margin: 8px 0 12px; }
    .summary span { display: inline-block; background: #EEF8F9; color: #0E5563; padding: 4px 8px; border-radius: 6px; margin-right: 6px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0E6B7B; color: #fff; text-align: left; padding: 5px; font-size: 8.5px; }
    td { padding: 4px 5px; border-bottom: 1px solid #DDE2E8; }
    tr:nth-child(even) td { background: #F7F8FA; }
</style>
</head>
<body>
<div class="head">
    <div class="logo">Stay<span>Voyage</span></div>
    <h1>{{ $title }}</h1>
    <div class="muted">{{ $label }}: {{ $from->format('j M Y') }} – {{ $to->format('j M Y') }} · Generated {{ now()->format('j M Y, H:i') }}</div>
</div>
<div class="summary">
    @foreach($report['summary'] as $k => $v)
        @if(!is_array($v))<span>{{ ucwords(str_replace('_', ' ', $k)) }}: <strong>{{ is_float($v) ? number_format($v, 2) : $v }}</strong></span>@endif
    @endforeach
</div>
<table>
    <thead><tr>@foreach($report['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse(array_slice($report['rows'], 0, 2000) as $row)
        <tr>@foreach($row as $cell)<td>{{ is_float($cell) ? number_format($cell, 2) : $cell }}</td>@endforeach</tr>
    @empty
        <tr><td colspan="{{ count($report['columns']) }}" class="muted">No data for this period.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
