<x-filament-panels::page>
@if(config('school.backend'))<section class="surface"><h2>Heures, rémunérations et versements</h2><p>Suivez les heures déclarées, validez la rémunération mensuelle, puis enregistrez les versements et consultez le solde.</p><a class="button" href="{{ route('staff-pay.index',['tenant'=>\App\School\Services\DeploymentSchool::id()]) }}">Ouvrir le suivi des paiements →</a></section>@else<p>Disponible avec le backend scolaire connecté.</p>@endif
</x-filament-panels::page>
