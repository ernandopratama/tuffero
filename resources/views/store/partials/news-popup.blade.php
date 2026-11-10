{{-- Data popup berita beranda (D-085): satu tag JSON. DOM, CSS, dan gambar popup baru dibuat oleh resources/js/store/newsPopup.js setelah halaman siap. --}}
@if (! empty($news['items']))
	<script type="application/json" id="tf-news-data">{{ $site->json([
		'version' => $news['version'],
		'css' => asset('css/tuffero-news.css').'?v='.filemtime(public_path('css/tuffero-news.css')),
		'items' => $news['items'],
		'words' => \Illuminate\Support\Arr::only(trans('store.news'), ['heading', 'close', 'prev', 'next', 'slide']),
	]) }}</script>
@endif
