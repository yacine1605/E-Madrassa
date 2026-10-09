<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title') · {{ $school->name }}</title><link rel="stylesheet" href="{{ asset('css/documents.css') }}"></head><body>
<nav class="no-print"><a href="{{ route('documents.index',['tenant'=>$school->id]) }}">Bulletins scolaires</a><a href="/admin/bulletins">Gestion scolaire</a><button type="button" onclick="window.print()">Imprimer / Enregistrer en PDF</button></nav>
@if($errors->any())<div class="notice no-print" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(session('success'))<p class="notice no-print">{{ session('success') }}</p>@endif
@yield('content')
</body></html>
