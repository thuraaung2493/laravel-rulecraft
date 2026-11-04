@if ($includeAssets)
    @if ($theme === 'bootstrap')
        <link rel="stylesheet" href="{{ $assetUrls['bootstrap.css'] }}">
    @endif
    <link rel="stylesheet" href="{{ $assetUrls['builder.css'] }}">
    @if ($stylesheet)
        <link rel="stylesheet" href="{{ asset($stylesheet) }}">
    @endif
    <script src="{{ $assetUrls['builder.js'] }}" defer></script>
@endif
<section {{ $attributes->merge(['class' => 'rulecraft-builder rc-theme-'.$theme]) }}
    id="{{ $editorId }}" data-rulecraft-builder
    data-settings="{{ json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) }}"
    aria-label="Rulecraft Builder">
    <p role="status">Loading rule editor…</p>
    <noscript>Enable JavaScript to edit and preview rules.</noscript>
</section>
