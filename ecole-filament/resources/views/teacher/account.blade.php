@extends('teacher.layout')
@section('title', 'Mon compte')
@section('content')
<p class="eyebrow">Votre profil</p><h1>Mon compte</h1><section class="card account-card"><h2 dir="auto">{{ $teacher->name }}</h2><p>{{ auth()->user()->email }}</p><hr><h2>Changer mon mot de passe</h2><p class="muted">Choisissez un mot de passe personnel d’au moins 12 caractères.</p>
<form class="form-stack" method="post" action="{{ route('teacher.password') }}">@csrf
<label>Mot de passe actuel<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>Nouveau mot de passe<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
<label>Confirmer le nouveau mot de passe<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="128" required></label>
<button type="submit">Enregistrer mon mot de passe</button></form></section>
@endsection
