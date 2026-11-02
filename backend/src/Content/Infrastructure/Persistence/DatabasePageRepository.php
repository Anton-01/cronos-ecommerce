<?php

declare(strict_types=1);

namespace Cronos\Content\Infrastructure\Persistence;

use Cronos\Content\Domain\Ports\MarkdownRenderer;
use Cronos\Content\Domain\Ports\PageRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabasePageRepository implements PageRepository
{
    public function __construct(private readonly MarkdownRenderer $markdown) {}

    public function all(?string $status, ?string $search): array
    {
        $q = DB::table('pages')->whereNull('deleted_at')->orderBy('position')->orderBy('key');
        $status !== null && $q->where('status', $status);
        if ($search !== null && trim($search) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
            $q->where(fn ($w) => $w->where('key', 'ilike', $like)->orWhereIn('id', DB::table('page_translations')->whereNull('deleted_at')->where('title', 'ilike', $like)->select('page_id')));
        }

        return $this->withTranslations($q->get()->all());
    }

    public function find(string $id): ?array
    {
        $row = DB::table('pages')->where('id', $id)->whereNull('deleted_at')->first();

        return $row === null ? null : $this->withTranslations([$row])[0];
    }

    public function keyExists(string $key, ?string $exceptId): bool
    {
        return DB::table('pages')->whereNull('deleted_at')->where('key', $key)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    public function slugTaken(string $locale, string $slug, ?string $exceptPageId): bool
    {
        return DB::table('page_translations')->whereNull('deleted_at')->where(['locale' => $locale, 'slug' => $slug])->when($exceptPageId, fn ($q) => $q->where('page_id', '!=', $exceptPageId))->exists();
    }

    public function create(string $key, array $markets, int $position, array $translations, ?string $userId): string
    {
        return DB::transaction(function () use ($key, $markets, $position, $translations, $userId): string {
            $id = (string) Str::ulid();
            DB::table('pages')->insert(['id' => $id, 'key' => $key, 'status' => 'draft', 'markets' => json_encode($markets), 'position' => $position, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $this->writeTranslations($id, $translations);

            return $id;
        });
    }

    public function update(string $id, ?array $markets, ?int $position, ?array $translations, ?string $userId): void
    {
        DB::transaction(function () use ($id, $markets, $position, $translations, $userId): void {
            $set = ['updated_at' => now(), 'updated_by' => $userId];
            $markets !== null && $set['markets'] = json_encode($markets);
            $position !== null && $set['position'] = $position;
            DB::table('pages')->where('id', $id)->update($set);
            $translations !== null && $this->writeTranslations($id, $translations);
        });
    }

    public function setStatus(string $id, string $status, ?string $userId): void
    {
        DB::table('pages')->where('id', $id)->update(['status' => $status, 'published_at' => $status === 'published' ? now() : null, 'updated_at' => now(), 'updated_by' => $userId]);
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id): void {
            DB::table('page_translations')->where('page_id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]);
            DB::table('pages')->where('id', $id)->update(['deleted_at' => now(), 'status' => 'draft']);
        });
    }

    public function publishedBySlug(string $locale, string $slug): ?array
    {
        $t = DB::table('page_translations')->whereNull('deleted_at')->where(['locale' => $locale, 'slug' => $slug])->first();
        $page = $t === null ? null : DB::table('pages')->where('id', $t->page_id)->where('status', 'published')->whereNull('deleted_at')->first();

        return $page === null ? null : $this->shape($page) + ['translation' => (array) $t];
    }

    public function alternates(string $pageId): array
    {
        return DB::table('page_translations')->whereNull('deleted_at')->where('page_id', $pageId)->orderBy('locale')->get(['locale', 'slug'])->map(fn ($r) => ['locale' => $r->locale, 'slug' => $r->slug])->all();
    }

    public function published(string $locale): array
    {
        $rows = DB::table('pages')->join('page_translations as t', function ($j) use ($locale) {
            $j->on('t.page_id', '=', 'pages.id')->where('t.locale', $locale)->whereNull('t.deleted_at');
        })->where('pages.status', 'published')->whereNull('pages.deleted_at')->orderBy('pages.position')->orderBy('pages.key')
            ->get(['pages.id', 'pages.key', 'pages.markets', 'pages.position', 'pages.updated_at', 't.slug', 't.title']);

        return $rows->map(fn ($r) => ['id' => $r->id, 'key' => $r->key, 'markets' => json_decode($r->markets, true) ?: [], 'position' => (int) $r->position, 'slug' => $r->slug, 'title' => $r->title, 'updated_at' => $r->updated_at])->all();
    }

    /** @param array<string, array<string, mixed>> $translations */
    private function writeTranslations(string $pageId, array $translations): void
    {
        foreach ($translations as $locale => $t) {
            $existing = DB::table('page_translations')->where(['page_id' => $pageId, 'locale' => $locale])->whereNull('deleted_at')->value('id');
            $row = ['slug' => $t['slug'], 'title' => $t['title'], 'body_md' => $t['body_md'], 'body_html' => $this->markdown->render($t['body_md']), 'seo_title' => $t['seo_title'], 'seo_description' => $t['seo_description'], 'updated_at' => now()];
            $existing === null
                ? DB::table('page_translations')->insert($row + ['id' => (string) Str::ulid(), 'page_id' => $pageId, 'locale' => $locale, 'created_at' => now()])
                : DB::table('page_translations')->where('id', $existing)->update($row);
        }
    }

    /** @param list<object> $pages @return list<array<string, mixed>> */
    private function withTranslations(array $pages): array
    {
        $ids = array_map(static fn ($p) => $p->id, $pages);
        $byPage = DB::table('page_translations')->whereNull('deleted_at')->whereIn('page_id', $ids)->orderBy('locale')->get()->groupBy('page_id');

        return array_map(fn ($p) => $this->shape($p) + ['translations' => ($byPage->get($p->id) ?? collect())->map(fn ($t) => (array) $t)->all()], $pages);
    }

    /** @return array<string, mixed> */
    private function shape(object $p): array
    {
        return ['id' => $p->id, 'key' => $p->key, 'status' => $p->status, 'markets' => json_decode($p->markets, true) ?: [], 'position' => (int) $p->position, 'published_at' => $p->published_at, 'updated_at' => $p->updated_at];
    }
}
