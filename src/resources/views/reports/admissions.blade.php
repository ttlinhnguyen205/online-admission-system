<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Báo cáo tuyển sinh</title>
    <style>
        @page { margin: 24px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #172033; }
        h1 { font-size: 20px; } h2 { font-size: 13px; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; table-layout: fixed; }
        thead { display: table-header-group; } tr { page-break-inside: avoid; }
        th, td { border: 1px solid #cbd5e1; padding: 5px; text-align: left; overflow-wrap: break-word; }
        th { background: #e2e8f0; } .muted { color: #475569; }
    </style>
</head>
<body>
    <h1>BÁO CÁO TUYỂN SINH</h1>
    <p>Thời gian tạo: {{ $generatedAt }}</p>
    @foreach ($filters->labels() as $label => $value)
        <p>{{ $label }}: {{ $value }}</p>
    @endforeach
    <p class="muted">{{ $scope }} Số hồ sơ, thí sinh, nguyện vọng và kết quả được thống kê riêng.</p>
    <h2>Tổng quan</h2>
    <table><thead><tr><th>Chỉ số</th><th>Số lượng</th></tr></thead><tbody>
        @foreach ($summary['metrics'] as $label => $count)
            <tr><td>{{ $label }}</td><td>{{ $count }}</td></tr>
        @endforeach
    </tbody></table>
    @foreach (['Hồ sơ' => $applicationRows, 'Kết quả' => $resultRows] as $title => $rows)
        <h2>{{ $title }}</h2>
        <table><thead><tr>
            @foreach ($headers[$title] as $header)<th>{{ $header }}</th>@endforeach
        </tr></thead><tbody>
            @forelse ($rows as $row)
                <tr>@foreach ($row as $value)<td>{{ $value }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($headers[$title]) }}">Không có dữ liệu phù hợp.</td></tr>
            @endforelse
        </tbody></table>
    @endforeach
</body>
</html>
