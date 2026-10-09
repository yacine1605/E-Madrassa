<x-filament-panels::page>
    @if(config('school.backend'))
    <section class="surface"><h2>Bulletins · كشوف النقاط</h2><p>Primaire, moyen et secondaire : sélectionnez la classe et configurez les dates, le barème et les règles du trimestre selon la référence d’évaluation de votre établissement.</p>
    <a class="button" href="{{ route('documents.index', ['tenant'=>\App\School\Services\DeploymentSchool::id()]) }}">Ouvrir les bulletins →</a></section>
    @else<p>Disponible avec le backend scolaire connecté.</p>@endif
</x-filament-panels::page>
