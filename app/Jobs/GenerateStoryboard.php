<?php

namespace App\Jobs;

use App\Models\MarketingDraft;
use App\Services\Marketing\MarketingModelException;
use App\Services\StoryboardGenerator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menyusun naskah dan storyboard satu draf video di belakang layar. Satu antrean per draf: catatan atau foto yang datang
 * beruntun sebelum job berjalan tidak membuat panggilan Claude ganda; yang datang saat job berjalan mengantre satu lagi.
 */
class GenerateStoryboard implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(public int $draftId) {}

    public function uniqueId(): string
    {
        return (string) $this->draftId;
    }

    public function handle(StoryboardGenerator $storyboards): void
    {
        $draft = MarketingDraft::find($this->draftId);
        if (! $draft) {
            return;
        }

        try {
            $storyboards->generate($draft);
        } catch (MarketingModelException $e) {
            // Log tanpa isi prompt atau jawaban.
            Log::warning('Storyboard gagal', ['draft_id' => $this->draftId, 'attempt' => $this->attempts(), 'reason' => $e->getMessage()]);
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }
            $storyboards->markFailed($this->draftId, $e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(StoryboardGenerator::class)->markFailed($this->draftId, 'Storyboard gagal dibuat. Kirim catatan untuk mencoba lagi.');
    }
}
