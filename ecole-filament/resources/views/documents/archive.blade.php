<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bulletins définitifs · {{ $school->name }}</title><style>{!! $styles !!}</style></head><body>
@unless($download)
<nav class="no-print"><a href="{{ route('documents.index',['tenant'=>$school->id,'cohort'=>$archive->cohort_id]) }}">Bulletins scolaires</a><button type="button" onclick="window.print()">Imprimer / Enregistrer en PDF</button><a href="{{ route('documents.archive',['tenant'=>$school->id,'archive'=>$archive->id,...request()->only('enrollment','page'),'download'=>1]) }}">Télécharger l’archive HTML</a></nav>
@if(session('success'))<p class="notice no-print">{{ session('success') }}</p>@endif
<p class="no-print">État : Clôturé · Validé le {{ \Illuminate\Support\Carbon::parse($archive->validated_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · Clôturé le {{ \Illuminate\Support\Carbon::parse($archive->closed_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · Archive figée {{ $archive->id }}</p>
@endunless
@foreach($renderedCards as $html){!! $html !!}@endforeach
@unless($download)<div class="no-print">{{ $students->links() }}<p>Impression par lots de 50 élèves. Le document et ses styles ont été conservés à la clôture.</p></div>@endunless
</body></html>
