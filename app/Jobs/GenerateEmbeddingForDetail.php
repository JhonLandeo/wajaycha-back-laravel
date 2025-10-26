<?php
namespace App\Jobs;

use App\Models\Detail;
use App\Services\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateEmbeddingForDetail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $detail;
    protected $categoryId;

    public function __construct(Detail $detail, int $categoryId)
    {
        $this->detail = $detail;
        $this->categoryId = $categoryId;
    }

    public function handle(EmbeddingService $embeddingService): void
    {
        $vector = $embeddingService->generate($this->detail->description);
        if ($vector) {
            $this->detail->update([
                'embedding' => $vector,
                'last_used_category_id' => $this->categoryId
            ]);
        }
    }
}