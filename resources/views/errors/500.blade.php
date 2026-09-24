@extends('errors::minimal')

@section('title', __('Memuat ulang'))
@section('code', '')
@section('message', '')

<script>
    (function () {
        var key = 'retry500:' + location.pathname;
        var last = Number(sessionStorage.getItem(key) || 0);
        if (Date.now() - last > 10000) {
            sessionStorage.setItem(key, String(Date.now()));
            var url = new URL(location.href);
            url.searchParams.set('_retry', '1');
            location.replace(url.toString());
            return;
        }
        document.title = 'Server Error';
    })();
</script>
