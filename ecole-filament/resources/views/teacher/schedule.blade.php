@extends('teacher.layout')
@section('title', 'Mon planning')
@section('content')
<link rel="stylesheet" href="{{ asset('css/teacher-support.css') }}?v={{ filemtime(public_path('css/teacher-support.css')) }}">
<p class="eyebrow">Organisation</p><h1>Mon planning</h1><p class="muted">Vos séances du {{ $start->format('d/m/Y') }} au {{ $end->subDay()->format('d/m/Y') }}.</p>
<form class="date-filter" method="get"><label>À partir du<input type="date" name="date" value="{{ $start->toDateString() }}" required></label><button type="submit">Afficher la semaine</button></form>
<section class="card">@forelse($sessions->groupBy(fn($session)=>\Carbon\Carbon::parse($session->starts_at)->timezone('Africa/Algiers')->format('d/m/Y')) as $day => $lessons)
<h2>{{ $day }}</h2>@foreach($lessons as $lesson)<article class="lesson {{ $lesson->activity === 'support' ? ($lesson->subject_code === 'PRI-EN' ? 'support-english' : 'support-french') : '' }}"><div class="time">{{ \Carbon\Carbon::parse($lesson->starts_at)->timezone('Africa/Algiers')->format('H:i') }}<small>{{ \Carbon\Carbon::parse($lesson->ends_at)->timezone('Africa/Algiers')->format('H:i') }}</small></div><div><h3>{{ $lesson->activity === 'support' ? 'Atelier / soutien' : $lesson->subject }}</h3>@if($lesson->activity === 'support')<p>{{ $lesson->subject_code === 'PRI-EN' ? 'Anglais' : 'Français' }}</p>@endif<p>{{ $lesson->classroom }}</p><p class="muted">{{ $lesson->room }} · {{ $lesson->status === 'published' ? 'Publiée' : 'Planifiée' }}</p></div></article>@endforeach
@empty<div class="empty"><h2>Aucune séance cette semaine</h2><p>Vos séances apparaîtront ici dès que la direction les aura programmées. Vous pouvez aussi choisir une autre semaine.</p></div>@endforelse</section>
@endsection
