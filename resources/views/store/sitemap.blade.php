<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as $row)
    <url>
        <loc>{{ url($row['path']) }}</loc>
@if ($row['lastmod'])
        <lastmod>{{ $row['lastmod']->toAtomString() }}</lastmod>
@endif
        <xhtml:link rel="alternate" hreflang="id" href="{{ url($row['path']) }}"/>
        <xhtml:link rel="alternate" hreflang="en" href="{{ url($row['path']) }}?lang=en"/>
    </url>
@endforeach
</urlset>
