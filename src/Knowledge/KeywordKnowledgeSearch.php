<?php

declare(strict_types=1);

namespace Selli\Ticketing\Knowledge;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Selli\Ticketing\Contracts\KnowledgeSearch;
use Selli\Ticketing\Database\SqlDialect;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\Ticketing;

/**
 * Portable keyword search with weighted ranking — no external engine, the same
 * SQL on SQLite, MySQL/MariaDB, PostgreSQL and SQL Server.
 *
 * The query is split into terms; the database keeps only articles the audience
 * may read that match at least one term, scored by where each term appears
 * (title 5, keywords 3, summary 2, body 1) so the best candidates come first;
 * those candidates are then re-ranked in PHP on term frequency and given an
 * excerpt around the first match.
 */
class KeywordKnowledgeSearch implements KnowledgeSearch
{
    /** @var array<string, int> */
    protected const WEIGHTS = ['title' => 5, 'keywords' => 3, 'summary' => 2, 'body' => 1];

    public function search(KnowledgeQuery $query): array
    {
        $terms = self::terms($query->text);

        if ($terms === [] || $query->limit < 1) {
            return [];
        }

        $model = Ticketing::kbArticleModel();

        /** @var Builder<KbArticle> $builder */
        $builder = $model::query()->visibleTo($query->audience);

        if ($query->locale !== null) {
            $builder->where('locale', $query->locale);
        }

        if ($query->type !== null) {
            $builder->where('type', $query->type->value);
        }

        if ($query->categoryId !== null) {
            $builder->where('category_id', $query->categoryId);
        }

        // Any term in any searchable column (bound parameters)…
        $builder->where(function (Builder $match) use ($terms): void {
            foreach ($terms as $term) {
                foreach (array_keys(self::WEIGHTS) as $column) {
                    $match->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [self::like($term)]);

                    // SQLite folds only ASCII in LOWER()/LIKE (no ICU).
                    foreach (SqlDialect::caseVariants($term) as $variant) {
                        $match->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [self::like($variant)]);
                    }
                }
            }
        });

        // …best weighted matches first, so the candidate window keeps them.
        $candidates = $builder
            ->orderBy(SqlDialect::keywordScore(self::WEIGHTS, $terms), 'desc')
            ->orderByDesc('published_at')
            ->limit(max($query->limit, (int) config('ticketing.knowledge.search.candidates', 200)))
            ->get();

        $maxScore = array_sum(self::WEIGHTS) * count($terms) * 2;
        $results = [];

        foreach ($candidates as $article) {
            $score = $this->score($article, $terms);

            if ($score <= 0) {
                continue;
            }

            $results[] = new KnowledgeResult($article, min(1.0, $score / $maxScore), $this->excerpt($article, $terms));
        }

        usort($results, fn (KnowledgeResult $a, KnowledgeResult $b): int => [$b->score, $b->article->getKey()] <=> [$a->score, $a->article->getKey()]);

        return array_slice($results, 0, $query->limit);
    }

    /**
     * Lower-cased, de-duplicated search terms (letters and digits only, at least
     * `knowledge.search.min_term_length` characters).
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        $min = max(1, (int) config('ticketing.knowledge.search.min_term_length', 3));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $terms = [];

        foreach ($parts as $part) {
            if (mb_strlen($part) >= $min) {
                $terms[$part] = true;
            }
        }

        // Bound the work a single (possibly hostile) query can cause.
        return array_slice(array_keys($terms), 0, 12);
    }

    /**
     * Weighted presence plus a capped frequency bonus, per term and field.
     *
     * @param  list<string>  $terms
     */
    protected function score(KbArticle $article, array $terms): float
    {
        $fields = [
            'title' => mb_strtolower($article->title),
            'keywords' => mb_strtolower((string) $article->keywords),
            'summary' => mb_strtolower((string) $article->summary),
            'body' => mb_strtolower($article->body),
        ];

        $score = 0.0;

        foreach ($terms as $term) {
            foreach (self::WEIGHTS as $field => $weight) {
                $hits = mb_substr_count($fields[$field], $term);

                if ($hits > 0) {
                    $score += $weight * (1 + min(1.0, ($hits - 1) / 4));
                }
            }
        }

        return $score;
    }

    /**
     * The sentence around the first matching term, or the summary.
     *
     * @param  list<string>  $terms
     */
    protected function excerpt(KbArticle $article, array $terms): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($article->body)) ?? '');
        $lower = mb_strtolower($text);

        foreach ($terms as $term) {
            $position = mb_strpos($lower, $term);

            if ($position !== false) {
                $start = max(0, $position - 80);

                return ($start > 0 ? '…' : '').Str::limit(mb_substr($text, $start), 220);
            }
        }

        return Str::limit($article->summary ?? $text, 220);
    }

    protected static function like(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }
}
