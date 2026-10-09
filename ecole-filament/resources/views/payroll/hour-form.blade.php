<form method="post" action="{{ $hourAction }}" class="pay-form">@csrf
@isset($staff)<input type="hidden" name="staff_id" value="{{ $staff->id }}">@endisset
@isset($editLog)<input type="hidden" name="version" value="{{ \App\School\Services\TeacherPay::version($editLog) }}">@endisset
<label>Contrat<select name="contract_id" required @isset($editLog) style="pointer-events:none" tabindex="-1" @endisset>@foreach($ledger['contracts'] as $contract)<option value="{{ $contract->id }}" @selected(isset($editLog) && $editLog->contract_id === $contract->id)>{{ $contract->basis === 'hourly' ? 'Horaire' : 'Mensuel' }} — {{ $contract->starts_on }} / {{ $contract->ends_on ?: 'Sans date de fin' }}</option>@endforeach</select></label>
<label>Jour travaillé<input type="date" name="worked_on" value="{{ isset($editLog) ? $editLog->worked_on : now('Africa/Algiers')->toDateString() }}" max="{{ now('Africa/Algiers')->toDateString() }}" required @readonly(isset($editLog))></label>
<label>Minutes réellement travaillées<input type="number" name="minutes" min="1" max="1440" step="1" value="{{ isset($editLog) ? $editLog->minutes : '' }}" required><small>60 minutes = 1 h ; 90 minutes = 1 h 30.</small></label>
<button type="submit">{{ isset($editLog) ? 'Soumettre la correction' : 'Déclarer les heures' }}</button></form>
