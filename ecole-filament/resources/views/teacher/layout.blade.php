<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'Espace enseignant') · Madrassa</title><link rel="stylesheet" href="{{ asset('css/teacher.css') }}"></head>
<body>
<header class="topbar"><a class="brand" href="{{ route('teacher.home') }}"><span class="brand-icon">م</span><span>{{ $schoolName ?? 'Madrassa' }}<small>Espace enseignant</small></span></a>
@isset($teacher)<div class="identity"><span dir="auto">{{ $teacher->name }}</span><form method="post" action="{{ route('teacher.logout') }}">@csrf<button class="quiet" type="submit">Déconnexion</button></form></div>@endisset</header>
@isset($teacher)<nav aria-label="Navigation enseignant"><a @class(['selected'=>request()->routeIs('teacher.home','teacher.classroom')]) href="{{ route('teacher.home') }}">Mes classes</a><a @class(['selected'=>request()->routeIs('teacher.grades.*')]) href="{{ route('teacher.grades.index') }}">Notes</a><a @class(['selected'=>request()->routeIs('teacher.schedule')]) href="{{ route('teacher.schedule') }}">Mon planning</a>@if(app(\App\School\Modules\ModuleRegistry::class)->enabled('payroll'))<a @class(['selected'=>request()->routeIs('teacher.pay*')]) href="{{ route('teacher.pay') }}">Ma rémunération</a>@endif<a @class(['selected'=>request()->routeIs('teacher.account')]) href="{{ route('teacher.account') }}">Mon compte</a></nav>@endisset
<main>
@if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="errors" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@yield('content')
</main><footer>Un espace pour votre quotidien en classe.</footer>
</body></html>
