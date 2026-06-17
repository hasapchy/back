<?php

namespace App\Http\Resources;

use App\Models\News;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        if (! $this->resource instanceof News) {
            return (array) $this->resource;
        }

        /** @var News $news */
        $news = $this->resource;

        $reactionsSummary = $news->getAttribute('reactions_summary');
        if (! is_array($reactionsSummary)) {
            $reactionsSummary = [];
        }

        return [
            'id' => (int) $news->id,
            'title' => $news->title,
            'content' => $news->content,
            'is_important' => (bool) $news->is_important,
            'company_id' => (int) $news->company_id,
            'creator_id' => $news->creator_id ? (int) $news->creator_id : null,
            'meta' => $news->meta,
            'author' => $news->relationLoaded('author') ? $news->author : ($news->relationLoaded('creator') ? $news->creator : null),
            'company' => $news->relationLoaded('company') ? $news->company : null,
            'comments_count' => (int) ($news->comments_count ?? 0),
            'reactions_count' => (int) ($news->reactions_count ?? 0),
            'acknowledgements_count' => (int) ($news->acknowledgements_count ?? 0),
            'acknowledged_by_me' => (bool) ($news->acknowledged_by_me ?? false),
            'acknowledged_at' => $news->acknowledged_at,
            'viewed_by' => is_array($news->viewed_by ?? null) ? $news->viewed_by : [],
            'acknowledged_by' => is_array($news->acknowledged_by ?? null) ? $news->acknowledged_by : [],
            'reactions_summary' => $reactionsSummary,
            'created_at' => $news->created_at?->toISOString(),
            'updated_at' => $news->updated_at?->toISOString(),
        ];
    }
}
