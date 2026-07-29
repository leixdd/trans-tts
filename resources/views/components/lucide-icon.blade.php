@props([
    'name',
])

@php
    $iconPath = base_path("node_modules/lucide-static/icons/{$name}.svg");
    $svg = file_exists($iconPath) ? file_get_contents($iconPath) : null;

    if ($svg !== null) {
        $svg = preg_replace('/<!--.*?-->\s*/s', '', $svg) ?? $svg;
        $style = trim($attributes->get('style', ''));
        $style = ($style === '' ? '' : rtrim($style, ';').' ').'width: 1rem; height: 1rem;';
        $svgAttributes = $attributes
            ->except(['aria-hidden', 'class', 'height', 'style', 'width'])
            ->class($attributes->get('class', 'size-4 shrink-0'))
            ->merge([
                'aria-hidden' => $attributes->get('aria-hidden', 'true'),
                'height' => '16',
                'style' => $style,
                'width' => '16',
            ])
            ->toHtml();
        $svg = preg_replace_callback(
            '/<svg\b[^>]*>/',
            static function (array $matches) use ($svgAttributes): string {
                $root = preg_replace('/\s(?:aria-hidden|class|height|style|width)="[^"]*"/', '', $matches[0]) ?? $matches[0];

                return preg_replace('/<svg\b/', '<svg '.$svgAttributes, $root, 1) ?? $root;
            },
            $svg,
            1,
        ) ?? $svg;
    }
@endphp

@if ($svg !== null)
    {!! $svg !!}
@endif
