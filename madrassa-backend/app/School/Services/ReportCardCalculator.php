<?php
namespace App\School\Services;

/** No national formula is assumed: rules come from the school's referenced term policy. */
final class ReportCardCalculator
{
    public static function subject(array $rule, array $grades, int $scale): array
    {
        $components = []; $issues = []; $total = 0; $weights = 0;
        if (array_filter($grades,fn($g)=>!in_array($g['kind'],['continu','devoir','examen'],true))) $issues[] = 'évaluation publiée sans type : à classer';
        foreach (['continu','devoir','examen'] as $kind) {
            $weight = (float)($rule[$kind] ?? 0);
            if ($weight <= 0) { $components[$kind] = null; continue; }
            $selected = array_values(array_filter($grades, fn($g) => $g['kind'] === $kind));
            $sum = 0; $count = 0;
            if (!$selected) $issues[] = $kind.': aucune évaluation publiée';
            foreach ($selected as $g) {
                if ($g['status'] === 'exempt') continue;
                if ($g['status'] !== 'marked' || $g['score'] === null) {
                    $issues[] = $kind.': '.($g['status'] === 'absent' ? 'absence à traiter' : 'note manquante');
                    continue;
                }
                $assessmentWeight = (float)$g['coefficient'];
                $sum += (float)$g['score'] / (float)$g['max_score'] * $scale * $assessmentWeight;
                $count += $assessmentWeight;
            }
            $components[$kind] = $count > 0 ? $sum / $count : null;
            if ($count > 0) { $total += $components[$kind] * $weight; $weights += $weight; }
            elseif ($selected && !array_filter($selected, fn($g) => $g['status'] !== 'exempt')) $issues[] = $kind.': dispense';
        }
        $exempt = $grades && !array_filter($grades, fn($g) => $g['status'] !== 'exempt');
        return ['components'=>$components, 'average'=>!$issues && $weights > 0 ? $total / $weights : null,
            'exempt'=>(bool)$exempt, 'issues'=>array_values(array_unique($issues))];
    }

    public static function overall(array $subjects): ?float
    {
        $total = 0; $weights = 0;
        foreach ($subjects as $subject) {
            if ($subject['qualitative'] || $subject['exempt']) continue;
            if ($subject['average'] === null) return null;
            $total += $subject['average'] * $subject['coefficient'];
            $weights += $subject['coefficient'];
        }
        return $weights > 0 ? $total / $weights : null;
    }
}
