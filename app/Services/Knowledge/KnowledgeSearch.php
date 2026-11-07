<?php

namespace App\Services\Knowledge;

use App\Models\Faq;
use App\Models\KnowledgeChunk;
use App\Support\SearchText;
use Illuminate\Support\Facades\DB;

/**
 * Retrieval over APPROVED knowledge only: company-wide chunks + this website's chunks + this
 * website's active FAQs. MySQL uses a FULLTEXT pre-filter (cheap on shared hosting); every
 * driver then ranks candidates by normalised term overlap.
 */
class KnowledgeSearch
{
    public const MIN_RELEVANCE = 0.34;

    /**
     * @return array<int, array{id: string, title: string, content: string, score: float, type: string}>
     */
    public static function search(string $tenantId, ?string $propertyId, string $query, int $limit = 5): array
    {
        $terms = SearchText::terms($query);
        if (! $terms) {
            return [];
        }

        $base = KnowledgeChunk::query()
            ->join('knowledge_documents as d', 'd.id', '=', 'knowledge_chunks.document_id')
            ->where('knowledge_chunks.tenant_id', $tenantId)
            ->where('d.status', 'approved')
            ->whereColumn('d.current_version_id', 'knowledge_chunks.version_id')
            ->where(fn ($q) => $q->whereNull('knowledge_chunks.property_id')->when($propertyId, fn ($w) => $w->orWhere('knowledge_chunks.property_id', $propertyId)))
            ->select('knowledge_chunks.id', 'knowledge_chunks.content', 'knowledge_chunks.keywords', 'd.title');

        $like = (clone $base)->where(function ($q) use ($terms) {
            foreach (array_slice($terms, 0, 12) as $t) {
                $q->orWhere('knowledge_chunks.keywords', 'like', '%' . addcslashes($t, '%_\\') . '%');
            }
        })->limit(300);

        $candidates = collect();
        if (DB::getDriverName() === 'mysql') {
            // FULLTEXT pre-filter (fast on large knowledge bases). It cannot see short tokens,
            // MySQL stopwords or uncommitted rows, so an empty result falls back to keyword LIKE.
            $candidates = (clone $base)->whereRaw('MATCH(knowledge_chunks.content, knowledge_chunks.keywords) AGAINST (? IN NATURAL LANGUAGE MODE)', [implode(' ', $terms)])->limit(60)->get();
        }
        if ($candidates->isEmpty()) {
            $candidates = $like->get();
        }

        $results = [];
        foreach ($candidates as $c) {
            $score = self::score($terms, explode(' ', (string) $c->keywords), SearchText::terms((string) $c->title));
            if ($score >= self::MIN_RELEVANCE) {
                $results[] = ['id' => 'k' . $c->id, 'title' => (string) $c->title, 'content' => (string) $c->content, 'score' => $score, 'type' => 'knowledge'];
            }
        }

        if ($propertyId) {
            $faqs = Faq::where('property_id', $propertyId)->where('is_active', true)->get(['id', 'question', 'answer', 'category']);
            foreach ($faqs as $f) {
                $qTerms = SearchText::terms($f->question);
                $all = array_merge($qTerms, SearchText::terms($f->answer . ' ' . $f->category));
                $score = self::score($terms, $all, $qTerms) * 1.1; // FAQs are curated answers
                if ($score >= self::MIN_RELEVANCE) {
                    $results[] = ['id' => 'f' . $f->id, 'title' => (string) $f->question, 'content' => "Q: {$f->question}\nA: {$f->answer}", 'score' => round($score, 4), 'type' => 'faq'];
                }
            }
        }

        usort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /** Fraction of query terms found in the document (+ bonus for title/question hits). */
    private static function score(array $queryTerms, array $docTerms, array $titleTerms): float
    {
        $doc = array_flip($docTerms);
        $title = array_flip($titleTerms);
        $hit = 0.0;
        foreach ($queryTerms as $t) {
            if (isset($title[$t])) {
                $hit += 1.25;
            } elseif (isset($doc[$t])) {
                $hit += 1.0;
            } else {
                foreach ($docTerms as $d) { // prefix match for inflections (renew/renewal)
                    if (mb_strlen($t) >= 4 && (str_starts_with($d, $t) || str_starts_with($t, $d) && mb_strlen($d) >= 4)) {
                        $hit += 0.6;
                        break;
                    }
                }
            }
        }

        return round(min(1.5, $hit / max(1, count($queryTerms))), 4);
    }
}
