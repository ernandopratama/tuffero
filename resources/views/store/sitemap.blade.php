<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as $path)
    <url>
        <loc>{{ url($path) }}</loc>
        <xhtml:link rel="alternate" hreflang="id" href="{{ url($path) }}"/>
        <xhtml:link rel="alternate" hreflang="en" href="{{ url($path) }}?lang=en"/>
    </url>
@endforeach
</urlset>
