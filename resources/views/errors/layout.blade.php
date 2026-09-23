<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · RightAlly</title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F3F6FB;color:#041527;font-family:"Instrument Sans",-apple-system,"Segoe UI",Roboto,Arial,sans-serif;padding:24px}
        .card{max-width:480px;background:#fff;border:1px solid #DFE5EF;border-radius:14px;padding:32px}
        img{height:28px;margin-bottom:24px}
        h1{font-size:1.5rem;margin:0 0 8px;letter-spacing:-.01em}
        p{color:#56627A;line-height:1.55;margin:0 0 20px}
        a.btn{display:inline-block;background:#1457EC;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600}
        .code{font-size:.8rem;color:#5E6B82;margin-top:20px}
    </style>
</head>
<body>
<main class="card">
    <img src="/brand/logo.png" alt="RightAlly">
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    <a class="btn" href="{{ $action ?? url('/') }}">{{ $actionLabel ?? 'Go to the start' }}</a>
    <div class="code">Error {{ $code }}</div>
</main>
</body>
</html>
