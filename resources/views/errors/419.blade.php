@extends('errors::minimal')

@section('title', __('Memuat ulang'))
@section('code', '')
@section('message', '')

<script>
    (function () {
        var key = 'retry419:' + location.pathname;
        var last = Number(sessionStorage.getItem(key) || 0);
        if (Date.now() - last > 10000) {
            sessionStorage.setItem(key, String(Date.now()));
            var url = new URL(location.href);
            url.searchParams.delete('_retry');
            location.replace(url.pathname + url.search);
            return;
        }
        location.replace('/admin');
    })();
</script>
