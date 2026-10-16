<meta name="beam-user" content="{{ auth()->id() }}">
<meta name="beam-prefix" content="{{ url(config('beam.routes.prefix', 'beam')) }}">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="beam-transfer-max" content="{{ (int) config('beam.transfer.max_bytes') }}">
<script src="{{ route('beam.assets') }}?v={{ $version }}"></script>
