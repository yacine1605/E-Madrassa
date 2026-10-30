<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;

/**
 * AI Quiz Generator – proof-of-concept page for the demo video.
 *
 * Calls the Python AI service (ai-service/quiz_service.py) to generate
 * curriculum-aligned quizzes using LangChain + Groq (Llama 3.3 70B).
 */
class AiQuizGenerator extends Page
{
    protected static ?string $slug = 'ai-quiz-generator';
    protected static ?string $navigationLabel = 'Générateur de quiz IA';
    protected static string|\UnitEnum|null $navigationGroup = 'Pédagogie';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';
    protected static ?int $navigationSort = 7;
    protected string $view = 'filament.pages.ai-quiz-generator';

    /* ── Form inputs ─────────────────────────────────────────── */

    public string $subject = '';
    public string $gradeLevel = '';
    public string $topic = '';
    public int $numQuestions = 3;

    /* ── State ───────────────────────────────────────────────── */

    #[Locked]
    public ?array $quiz = null;

    public ?string $error = null;

    /* ── Page metadata ───────────────────────────────────────── */

    public function getTitle(): string
    {
        return 'Générateur de quiz IA';
    }

    public function getSubheading(): ?string
    {
        return 'Générez des évaluations QCM adaptées au programme avec l\'intelligence artificielle.';
    }

    /* ── Actions ─────────────────────────────────────────────── */

    /**
     * Call the Python AI service and store the generated quiz.
     */
    public function generateQuiz(): void
    {
        $this->validate([
            'subject'      => 'required|string|max:100',
            'gradeLevel'   => 'required|string|max:100',
            'topic'        => 'required|string|max:255',
            'numQuestions'  => 'required|integer|min:3|max:5',
        ]);

        $this->quiz  = null;
        $this->error = null;

        try {
            $defaultUrl = file_exists('/.dockerenv') ? 'http://host.docker.internal:8001' : 'http://127.0.0.1:8001';
            $baseUrl  = env('AI_QUIZ_URL', $defaultUrl);
            $response = Http::timeout(60)->post("{$baseUrl}/generate-quiz", [
                'subject'       => $this->subject,
                'grade_level'   => $this->gradeLevel,
                'topic'         => $this->topic,
                'num_questions' => $this->numQuestions,
            ]);

            if ($response->successful()) {
                $this->quiz = $response->json();
                Notification::make()
                    ->title('Quiz généré avec succès')
                    ->icon('heroicon-o-check-circle')
                    ->success()
                    ->send();
            } else {
                $detail = $response->json('detail') ?? "HTTP {$response->status()}";
                $this->error = "Le service IA a retourné une erreur : {$detail}";
            }
        } catch (\Exception $e) {
            $this->error = 'Impossible de contacter le service IA. '
                         . 'Vérifiez qu\'il est démarré (python ai-service/quiz_service.py).';
        }
    }

    /**
     * Simulated publish – shows a success toast for the demo video.
     */
    public function publishToClass(): void
    {
        Notification::make()
            ->title('Quiz publié !')
            ->body("« {$this->quiz['title']} » a été distribué à la classe. Les élèves le verront dans leur espace.")
            ->icon('heroicon-o-paper-airplane')
            ->success()
            ->duration(5000)
            ->send();
    }
}

