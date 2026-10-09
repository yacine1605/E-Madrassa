<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title') · {{ $schoolName }}</title><link rel="stylesheet" href="{{ asset('css/teacher.css') }}"><link rel="stylesheet" href="{{ asset('css/payroll.css') }}"><link rel="stylesheet" href="{{ asset('css/lifecycle.css') }}"></head><body>
<header class="topbar"><a class="brand" href="/admin">{{ $schoolName }}</a><a href="{{ route('lifecycle.admissions',['tenant'=>$tenant]) }}">Admissions</a>@if(app(\App\School\Modules\ModuleRegistry::class)->canAccessPage('annual-progression', request()->attributes->get('school.permissions', [])))<a href="{{ route('lifecycle.progression',['tenant'=>$tenant]) }}">Passage annuel</a>@endif<a href="/admin/students">Élèves</a></header><main>
@if($errors->any())<div class="errors" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(session('success'))<p class="notice" role="status">{{ session('success') }}</p>@endif
@yield('content')
</main></body></html>
