<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logistics Workstation Command Sheet</title>
    <style>
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            background: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            text-transform: uppercase;
            font-weight: 900;
        }
        .header p {
            margin: 5px 0 0;
            font-size: 13px;
            color: #475569;
            font-family: monospace;
        }
        .section-title {
            font-size: 16px;
            font-weight: 800;
            text-transform: uppercase;
            margin: 25px 0 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }
        .card {
            border: 2px solid #0f172a;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            background: #ffffff;
        }
        .badge {
            display: inline-block;
            background: #0f172a;
            color: #ffffff;
            font-size: 10px;
            font-family: monospace;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .bin-badge {
            background: #047857;
        }
        .card-title {
            font-weight: 700;
            font-size: 14px;
            margin: 6px 0 2px;
        }
        .card-desc {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 10px;
        }
        .barcode-box {
            background: #f1f5f9;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            font-weight: 800;
            letter-spacing: 2px;
            font-size: 13px;
            border: 1px dashed #cbd5e1;
        }
        .print-btn {
            background: #0f172a;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
            float: right;
        }
        @media print {
            body { background: #ffffff; padding: 0; }
            .container { box-shadow: none; padding: 0; max-width: 100%; }
            .print-btn { display: none; }
            .card { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <button class="print-btn" onclick="window.print()">Print Barcode Sheet</button>
        <div class="header">
            <h1>Hardware Laser Command & Sort Sheet</h1>
            <p>Code128 Barcodes for Logistics Workstation Operators</p>
        </div>

        <div class="section-title">1. Operational Command Barcodes</div>
        <div class="grid-3">
            @foreach ($commands as $cmd)
                <div class="card">
                    <span class="badge">Command</span>
                    <div class="card-title">{{ $cmd['label'] }}</div>
                    <div class="card-desc">{{ $cmd['description'] }}</div>
                    <div class="barcode-box">
                        <svg id="barcode-{{ $cmd['code'] }}"></svg>
                        <div>*{{ $cmd['code'] }}*</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="section-title">2. Standard Hub Sorting Bins</div>
        <div class="grid-4">
            @foreach ($bins as $bin)
                <div class="card">
                    <span class="badge bin-badge">{{ $bin['label'] }}</span>
                    <div class="card-title">{{ $bin['code'] }}</div>
                    <div class="barcode-box">
                        <svg id="barcode-{{ $bin['code'] }}"></svg>
                        <div>*{{ $bin['code'] }}*</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            @foreach ($commands as $cmd)
                JsBarcode("#barcode-{{ $cmd['code'] }}", "{{ $cmd['code'] }}", {
                    format: "CODE128",
                    width: 2,
                    height: 45,
                    displayValue: false
                });
            @endforeach
            @foreach ($bins as $bin)
                JsBarcode("#barcode-{{ $bin['code'] }}", "{{ $bin['code'] }}", {
                    format: "CODE128",
                    width: 1.6,
                    height: 38,
                    displayValue: false
                });
            @endforeach
        });
    </script>
</body>
</html>
