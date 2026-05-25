<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GST API Debug Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            background:#f4f7fb;
            margin:0;
            padding:30px;
            color:#111827;
        }
        .card {
            max-width:900px;
            margin:auto;
            background:#fff;
            border-radius:16px;
            box-shadow:0 10px 30px rgba(0,0,0,.08);
            padding:22px;
        }
        h2 { margin-top:0; }
        label { font-weight:700; display:block; margin-bottom:6px; }
        .row { display:flex; gap:10px; }
        input {
            flex:1;
            height:44px;
            border:1px solid #cbd5e1;
            border-radius:10px;
            padding:0 12px;
            font-size:15px;
            text-transform:uppercase;
        }
        button {
            height:44px;
            border:0;
            border-radius:10px;
            background:#2563eb;
            color:white;
            font-weight:700;
            padding:0 18px;
            cursor:pointer;
        }
        button:disabled { opacity:.65; cursor:not-allowed; }
        .status {
            margin-top:12px;
            padding:12px;
            border-radius:10px;
            display:none;
            font-weight:700;
        }
        .status.info { display:block; background:#dbeafe; color:#1d4ed8; }
        .status.success { display:block; background:#dcfce7; color:#15803d; }
        .status.error { display:block; background:#fee2e2; color:#b91c1c; }
        .grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:12px;
            margin-top:18px;
        }
        textarea, .field {
            width:100%;
            border:1px solid #cbd5e1;
            border-radius:10px;
            padding:10px;
            min-height:42px;
            box-sizing:border-box;
        }
        textarea { min-height:80px; }
        pre {
            background:#0f172a;
            color:#e5e7eb;
            padding:14px;
            border-radius:12px;
            overflow:auto;
            max-height:420px;
            white-space:pre-wrap;
            margin-top:18px;
        }
        @media(max-width:700px){
            body{padding:12px;}
            .row,.grid{display:block;}
            button{width:100%; margin-top:8px;}
            .grid > div{margin-top:12px;}
        }
    </style>
</head>
<body>
<div class="card">
    <h2>GST API Debug Test</h2>
    <p>This page calls <b>ajax/ajax-fetch-gst-details.php?debug=1</b> and shows the exact JSON/debug response.</p>

    <label>GSTIN Number</label>
    <div class="row">
        <input id="gstin" maxlength="15" placeholder="33ABWFM1387R1Z8">
        <button id="btn">Verify GST</button>
    </div>

    <div id="status" class="status"></div>

    <div class="grid">
        <div>
            <label>Company Name</label>
            <input id="name" class="field">
        </div>
        <div>
            <label>Status</label>
            <input id="gstStatus" class="field">
        </div>
        <div>
            <label>State</label>
            <input id="state" class="field">
        </div>
        <div>
            <label>PIN Code</label>
            <input id="pin" class="field">
        </div>
        <div style="grid-column:1/-1;">
            <label>Address</label>
            <textarea id="address"></textarea>
        </div>
    </div>

    <h3>Raw Response</h3>
    <pre id="raw">No response yet.</pre>
</div>

<script>
const gstin = document.getElementById('gstin');
const btn = document.getElementById('btn');
const statusBox = document.getElementById('status');
const raw = document.getElementById('raw');

function setStatus(type, msg) {
    statusBox.className = 'status ' + type;
    statusBox.textContent = msg;
}

gstin.addEventListener('input', function () {
    this.value = this.value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 15);
});

btn.addEventListener('click', async function () {
    const value = gstin.value.trim().toUpperCase();

    if (value.length !== 15) {
        setStatus('error', 'Enter valid 15 character GSTIN.');
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Checking...';
    setStatus('info', 'Calling GST API...');

    try {
        const res = await fetch('ajax/ajax-fetch-gst-details.php?debug=1&gstin=' + encodeURIComponent(value), {
            headers: {'Accept':'application/json'},
            credentials:'same-origin'
        });

        const text = await res.text();

        let json;
        try {
            json = JSON.parse(text);
        } catch (e) {
            raw.textContent = text;
            throw new Error('Your endpoint returned non-JSON. Check PHP error.');
        }

        raw.textContent = JSON.stringify(json, null, 2);

        if (!res.ok || (!json.ok && !json.success)) {
            throw new Error(json.message || 'GST verify failed.');
        }

        const data = json.data || {};
        document.getElementById('name').value = data.name || data.company_name || '';
        document.getElementById('gstStatus').value = data.status || '';
        document.getElementById('state').value = data.state || '';
        document.getElementById('pin').value = data.pin_code || '';
        document.getElementById('address').value = data.address || '';

        setStatus('success', 'GST verified successfully.');
    } catch (err) {
        setStatus('error', err.message || 'Failed.');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Verify GST';
    }
});
</script>
</body>
</html>
