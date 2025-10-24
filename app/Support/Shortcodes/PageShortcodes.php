<?php


namespace App\Support\Shortcodes;
use App\Support\Settings;

class PageShortcodes
{
    public static function render(string $html): string
    {
        // [start consultation service="travel clinic" label="Start now"]
        $pattern = '/\[start\s+consultation(?P<attrs>[^\]]*)\]/i';
        
        // First, expand the shortcode into a CTA anchor
        $expanded = preg_replace_callback($pattern, function ($m) {
            $attrs = [];
            if (preg_match_all('/(\w+)="([^"]*)"/', $m['attrs'] ?? '', $am)) {
                foreach ($am[1] as $i => $k) {
                    $attrs[strtolower($k)] = $am[2][$i];
                }
            }
        
            $service = $attrs['service'] ?? 'travel-clinic';
            $label   = $attrs['label']   ?? 'Start now';
        
            // Use a RELATIVE URL so it works across environments and domains
            $url = "/private-services/{$service}/book";
        
            // Keep classes simple; the frontend will style .cta-primary
            return '<a href="'.e($url).'" class="cta-primary" data-service="'.e($service).'">'.$label.'</a>';
        }, $html);
        
        // Second, auto-upgrade any plain booking links into CTAs if they lack a class
        // Example matched: <a href="/private-services/travel-clinic/book">Start now</a>
        $expanded = preg_replace_callback(
            '/<a\s+([^>]*href=["\']\/private-services\/[^"\']+\/book["\'][^>]*)>/i',
            function ($m) {
                $tag = $m[0];
                // If it already has a class attribute, leave it unchanged
                if (preg_match('/\sclass=["\']/i', $tag)) {
                    return $tag;
                }
                // Otherwise inject class="cta-primary" after the opening <a
                return preg_replace('/<a\s+/i', '<a class="cta-primary" ', $tag, 1);
            },
            $expanded
        );
        
        // Third, support a [card] shortcode with color/opacity/width options.
        // Usage:
        //   [card title="Popular vaccines" color="amber" opacity="90" width="800px"]
        //     ...inner html...
        //   [/card]
        $expanded = preg_replace_callback(
            '/\[card(?P<attrs>[^\]]*)\](?P<inner>[\s\S]*?)\[\/card\]/i',
            function ($m) {
                $attrs = [];
                if (preg_match_all('/(\w+)="([^"]*)"/', $m['attrs'] ?? '', $am)) {
                    foreach ($am[1] as $i => $k) {
                        $attrs[strtolower($k)] = $am[2][$i];
                    }
                }

                $title = trim($attrs['title'] ?? '');
                $width = trim($attrs['width'] ?? '');

                // Color: allow specific values or use global default from settings
                $allowed = ['sky','amber','green','gray'];
                $global  = class_exists(Settings::class) ? Settings::get('card_theme', 'sky') : 'sky';
                $color   = strtolower($attrs['color'] ?? $global);
                if (! in_array($color, $allowed, true)) {
                    $color = in_array($global, $allowed, true) ? $global : 'sky';
                }

                // Opacity: 10–100 (%) -> 0.10–1.00
                $opacity = isset($attrs['opacity']) ? (int) $attrs['opacity'] : 96;
                $opacity = max(10, min(100, $opacity));
                $alpha   = number_format($opacity / 100, 2, '.', '');

                // Build style: CSS var for opacity + optional max-width
                $styleParts = ['--card-o:' . $alpha];
                if ($width !== '') {
                    $styleParts[] = 'max-width:' . e($width);
                }
                $style = ' style="' . implode(';', $styleParts) . '"';

                $out  = '<blockquote class="card card-' . e($color) . '"' . $style . '>';
                if ($title !== '') {
                    $out .= '<h2>' . e($title) . '</h2>';
                }
                // keep inner HTML as-is so tables/lists render naturally
                $out .= $m['inner'];
                $out .= '</blockquote>';
                return $out;
            },
            $expanded
        );
        
        return $expanded;
    }
}