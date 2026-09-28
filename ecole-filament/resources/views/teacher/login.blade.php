@extends('teacher.layout')
@section('title', 'Connexion enseignant')
@section('content')
<section class="login-card"><p class="eyebrow">Bienvenue dans votre école</p><h1>Votre espace enseignant</h1><p class="muted">Retrouvez vos classes, vos élèves et votre planning.</p>
<form action="{{ route('teacher.authenticate') }}" method="post" class="form-stack">@csrf
<label>Adresse e-mail<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
<label>Mot de passe<input type="password" name="password" autocomplete="current-password" required></label>
<button type="submit">Se connecter</button></form>
<p class="muted small">Utilisez les identifiants transmis par la direction. Pour récupérer votre accès, contactez le secrétariat.</p>
<a class="text-link" href="/admin/login">Accès administration</a></section>
@endsection
