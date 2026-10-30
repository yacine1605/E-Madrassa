<x-filament-panels::page>
{{-- Scoped styles – self-contained for the PoC --}}
<style>
.quiz-gen{max-width:56rem;margin:0 auto}
.quiz-gen .card{background:#fff;border-radius:.75rem;box-shadow:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.04);padding:1.5rem;margin-bottom:1.5rem}
.quiz-gen .card-header{display:flex;align-items:flex-start;gap:.75rem;margin-bottom:1.25rem}
.quiz-gen .card-header .icon-box{width:2.5rem;height:2.5rem;display:flex;align-items:center;justify-content:center;border-radius:.625rem;background:#f0fdfa;color:#0d9488;flex-shrink:0}
.quiz-gen .card-header h2{font-size:1.125rem;font-weight:700;color:#111827;margin:0}
.quiz-gen .subheading{font-size:.875rem;color:#6b7280;margin:.125rem 0 0}
.quiz-gen .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:640px){.quiz-gen .form-grid{grid-template-columns:1fr}}
.quiz-gen .span-2{grid-column:1/-1}
.quiz-gen label{display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.375rem}
.quiz-gen input,.quiz-gen select{width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:.5rem;font-size:.875rem;font-family:inherit;background:#fff;outline:none;transition:border-color .15s,box-shadow .15s}
.quiz-gen input:focus,.quiz-gen select:focus{border-color:#0d9488;box-shadow:0 0 0 3px rgba(13,148,136,.12)}
.quiz-gen input::placeholder{color:#9ca3af}
.quiz-gen .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.625rem 1.5rem;border:none;border-radius:.5rem;font-size:.875rem;font-weight:600;font-family:inherit;cursor:pointer;transition:background .15s,transform .1s}
.quiz-gen .btn:active{transform:scale(.98)}
.quiz-gen .btn-primary{background:#0d9488;color:#fff}
.quiz-gen .btn-primary:hover{background:#0f766e}
.quiz-gen .btn-primary:disabled{opacity:.55;cursor:not-allowed;transform:none}
.quiz-gen .btn-publish{background:#0d9488;color:#fff;padding:.75rem 2rem;font-size:.9375rem}
.quiz-gen .btn-publish:hover{background:#0f766e}
.quiz-gen .spinner{display:inline-block;width:1rem;height:1rem;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:qspin .7s linear infinite;vertical-align:middle}
@keyframes qspin{to{transform:rotate(360deg)}}
.quiz-gen .error-banner{background:#fef2f2;border:1px solid #fecaca;border-radius:.5rem;padding:.75rem 1rem;color:#991b1b;font-size:.875rem;margin-bottom:1.5rem;display:flex;align-items:flex-start;gap:.5rem}
.quiz-gen .error-banner svg{flex-shrink:0;margin-top:.125rem}
.quiz-gen .quiz-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap}
.quiz-gen .quiz-title{font-size:1.25rem;font-weight:700;color:#111827;margin:0}
.quiz-gen .quiz-meta{font-size:.8125rem;color:#6b7280;margin:.25rem 0 0}
.quiz-gen .badge{display:inline-flex;align-items:center;padding:.25rem .75rem;background:#f0fdfa;color:#0d9488;border-radius:9999px;font-size:.75rem;font-weight:700;white-space:nowrap}
.quiz-gen .q-card{border:1px solid #e5e7eb;border-radius:.625rem;padding:1.25rem;margin-bottom:1rem;transition:border-color .15s}
.quiz-gen .q-card:hover{border-color:#d1d5db}
.quiz-gen .q-num{font-size:.6875rem;font-weight:700;color:#0d9488;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem}
.quiz-gen .q-text{font-size:1rem;font-weight:600;color:#1f2937;margin-bottom:1rem;line-height:1.5}
.quiz-gen .option{display:flex;align-items:flex-start;gap:.625rem;padding:.5rem .75rem;border-radius:.375rem;margin-bottom:.375rem;font-size:.875rem;line-height:1.4;border:1px solid transparent;transition:background .15s}
.quiz-gen .option.correct{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
.quiz-gen .option.other{background:#f9fafb;color:#4b5563}
.quiz-gen .opt-letter{font-weight:700;min-width:1.25rem;flex-shrink:0}
.quiz-gen .opt-text{flex:1}
.quiz-gen .check-icon{color:#16a34a;flex-shrink:0;margin-left:auto;margin-top:.125rem}
.quiz-gen .explanation{margin-top:.75rem;padding:.75rem 1rem;background:#eff6ff;border-radius:.5rem;font-size:.8125rem;color:#1e40af;display:flex;gap:.625rem;line-height:1.5}
.quiz-gen .explanation svg{flex-shrink:0;margin-top:.125rem}
.quiz-gen .actions-bar{display:flex;justify-content:flex-end;padding-top:.5rem}
.quiz-gen .loading-overlay{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem 1rem;gap:1rem}
.quiz-gen .loading-spinner{width:2.5rem;height:2.5rem;border:3px solid #e5e7eb;border-top-color:#0d9488;border-radius:50%;animation:qspin .8s linear infinite}
.quiz-gen .loading-text{font-size:.875rem;color:#6b7280;font-weight:500}
</style>

<div class="quiz-gen">

    {{-- ───────────────── Configuration Card ───────────────── --}}
    <div class="card">
        <div class="card-header">
            <div class="icon-box">
                <x-filament::icon icon="heroicon-o-sparkles" style="width:1.25rem;height:1.25rem"/>
            </div>
            <div>
                <h2>Configurer l'évaluation</h2>
                <p class="subheading">Renseignez les paramètres et laissez l'IA générer un quiz adapté au niveau de vos élèves.</p>
            </div>
        </div>

        <form wire:submit="generateQuiz">
            <div class="form-grid">
                <div>
                    <label for="qf-subject">Matière</label>
                    <input type="text" id="qf-subject" wire:model="subject"
                           placeholder="ex. Mathématiques, Sciences…" required>
                </div>
                <div>
                    <label for="qf-grade">Niveau / Classe</label>
                    <input type="text" id="qf-grade" wire:model="gradeLevel"
                           placeholder="ex. 3AM, 2AS, 4AP…" required>
                </div>
                <div class="span-2">
                    <label for="qf-topic">Sujet de la leçon</label>
                    <input type="text" id="qf-topic" wire:model="topic"
                           placeholder="ex. Le théorème de Pythagore, La photosynthèse…" required>
                </div>
                <div>
                    <label for="qf-num">Nombre de questions</label>
                    <select id="qf-num" wire:model="numQuestions">
                        <option value="3">3 questions</option>
                        <option value="4">4 questions</option>
                        <option value="5">5 questions</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:1.25rem;display:flex;align-items:center;gap:1rem">
                <button type="submit" class="btn btn-primary"
                        wire:loading.attr="disabled" wire:target="generateQuiz">
                    <span wire:loading.remove wire:target="generateQuiz">
                        <x-filament::icon icon="heroicon-o-sparkles"
                            style="width:1.125rem;height:1.125rem"/>
                    </span>
                    <span wire:loading wire:target="generateQuiz">
                        <span class="spinner"></span>
                    </span>
                    <span wire:loading.remove wire:target="generateQuiz">Generate Assessment</span>
                    <span wire:loading wire:target="generateQuiz">Generating Quiz…</span>
                </button>
            </div>
        </form>
    </div>

    {{-- ───────────────── Loading Overlay ───────────────── --}}
    <div wire:loading wire:target="generateQuiz" class="card">
        <div class="loading-overlay">
            <div class="loading-spinner"></div>
            <span class="loading-text">L'IA analyse le programme et prépare les questions…</span>
        </div>
    </div>

    {{-- ───────────────── Error Banner ───────────────── --}}
    @if($error)
    <div class="error-banner">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.25rem;height:1.25rem">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
        </svg>
        <span><strong>Erreur :</strong> {{ $error }}</span>
    </div>
    @endif

    {{-- ───────────────── Generated Quiz ───────────────── --}}
    @if($quiz)
    <div class="card" wire:loading.remove wire:target="generateQuiz">
        <div class="quiz-header">
            <div>
                <h2 class="quiz-title">{{ $quiz['title'] }}</h2>
                <p class="quiz-meta">{{ $subject }} · {{ $gradeLevel }} · {{ $topic }}</p>
            </div>
            <span class="badge">{{ count($quiz['questions']) }} questions</span>
        </div>

        @foreach($quiz['questions'] as $i => $q)
        <div class="q-card">
            <div class="q-num">Question {{ $i + 1 }}</div>
            <div class="q-text">{{ $q['question'] }}</div>

            @foreach($q['options'] as $j => $option)
            <div class="option {{ $j === $q['correct_index'] ? 'correct' : 'other' }}">
                <span class="opt-letter">{{ chr(65 + $j) }}.</span>
                <span class="opt-text">{{ $option }}</span>
                @if($j === $q['correct_index'])
                <svg class="check-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:1rem;height:1rem">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                </svg>
                @endif
            </div>
            @endforeach

            <div class="explanation">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1rem;height:1rem">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>
                </svg>
                <span>{{ $q['explanation'] }}</span>
            </div>
        </div>
        @endforeach

        <div class="actions-bar">
            <button class="btn btn-publish" wire:click="publishToClass">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:1.25rem;height:1.25rem">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
                </svg>
                Publish to Class
            </button>
        </div>
    </div>
    @endif

</div>
</x-filament-panels::page>

