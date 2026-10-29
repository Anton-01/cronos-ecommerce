<?php

declare(strict_types=1);

namespace Cronos\Catalog\Interfaces\GraphQL\Queries;

use Cronos\Catalog\Application\AdminProductReader;
use Cronos\Catalog\Domain\Exceptions\NotFound;
use Cronos\Catalog\Domain\Ports\AttributeRepository;
use Cronos\Catalog\Domain\Ports\CategoryRepository;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\AttributeModel;
use Cronos\Catalog\Infrastructure\Persistence\Eloquent\TagModel;

/** Read side of the back office: shapes data for the API without going through the write managers. */
final class AdminCatalogQuery
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly AttributeRepository $attributeRepo,
        private readonly AdminProductReader $products,
    ) {}

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return array_map([CategoryPresenter::class, 'present'], $this->categories->all());
    }

    /** @param array<string, mixed> $args @return list<array<string, mixed>> */
    public function tags(mixed $root, array $args): array
    {
        $term = trim((string) ($args['search'] ?? ''));
        $query = TagModel::query()->with('translations')->when($term !== '', fn ($q) => $q->whereHas('translations', fn ($t) => $t->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')))->limit(500);

        return $query->get()->map(fn (TagModel $t) => self::tag($t))->all();
    }

    /** @return list<array<string, mixed>> */
    public function attributes(): array
    {
        return AttributeModel::query()->with(['translations', 'values.translations'])->orderBy('position')->get()
            ->map(fn (AttributeModel $a) => $this->attribute($a))->all();
    }

    /** @param array<string, mixed> $args */
    public function product(mixed $root, array $args): ?array
    {
        try {
            return $this->products->product($args['id']);
        } catch (NotFound) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function attribute(AttributeModel $a): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'type' => $a->type, 'isFilterable' => (bool) $a->is_filterable, 'position' => (int) $a->position,
            'names' => $a->translations->map(fn ($t) => ['locale' => $t->locale, 'value' => $t->name])->values()->all(),
            'inUse' => $this->attributeRepo->isInUse($a->id),
            'values' => $a->values->map(fn ($v) => [
                'id' => $v->id, 'attributeId' => $v->attribute_id, 'code' => $v->code, 'meta' => $v->meta, 'position' => (int) $v->position,
                'labels' => $v->translations->map(fn ($t) => ['locale' => $t->locale, 'value' => $t->label])->values()->all(),
                'inUse' => $this->attributeRepo->isValueInUse($v->id),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function tag(TagModel $t): array
    {
        return ['id' => $t->id, 'translations' => $t->translations->map(fn ($x) => ['locale' => $x->locale, 'name' => $x->name, 'slug' => $x->slug])->values()->all()];
    }
}
