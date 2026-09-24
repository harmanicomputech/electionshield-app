<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Presenter · {{ $session->title }}</title>
    <style>
        html, body { margin: 0; height: 100%; background: #0b1a14; color: #fff; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { min-height: 100%; display: flex; flex-direction: column; justify-content: center; padding: 6vw; box-sizing: border-box; }
        .label { color: #7fd6ae; font-size: clamp(16px, 2.2vw, 28px); font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .question { font-size: clamp(28px, 5vw, 72px); line-height: 1.2; margin: 2vw 0; font-weight: 600; }
        .who { font-size: clamp(18px, 2.6vw, 36px); color: #c7d8d0; }
        .foot { position: fixed; bottom: 16px; left: 6vw; right: 6vw; display: flex; justify-content: space-between; color: #8aa598; font-size: 16px; }
    </style>
</head>
<body>
<main data-present="{{ route('townhall.present.data', $session) }}">
    <div class="label">{{ $session->title }}</div>
    <div class="question" data-q>{{ $question?->body ?? 'Waiting for the next question…' }}</div>
    <div class="who" data-who>{{ $question ? ($question->name ?: 'A voter').($question->lga ? ', '.$question->lga : '') : '' }}</div>
</main>
<div class="foot"><span data-pending></span><span>Election Shield town hall</span></div>
<script>
(function () {
    var root = document.querySelector('[data-present]');
    function tick() {
        fetch(root.getAttribute('data-present'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var q = data.question;
                document.querySelector('[data-q]').textContent = q ? q.body : 'Waiting for the next question…';
                document.querySelector('[data-who]').textContent = q ? q.name + (q.lga ? ', ' + q.lga : '') : '';
                document.querySelector('[data-pending]').textContent = data.pending ? data.pending + ' waiting for moderation' : '';
            })
            .catch(function () {});
    }
    setInterval(tick, 4000);
    tick();
})();
</script>
</body>
</html>
