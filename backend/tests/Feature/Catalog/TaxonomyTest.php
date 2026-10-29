<?php

namespace Tests\Feature\Catalog;

use Cronos\Catalog\Domain\Exceptions\CategoryCycle;
use Cronos\Catalog\Domain\Exceptions\CategoryNotEmpty;
use Cronos\Catalog\Domain\Exceptions\CategoryTooDeep;
use Cronos\Catalog\Domain\Exceptions\InUse;
use Cronos\Catalog\Domain\Exceptions\InvalidProduct;
use Cronos\Catalog\Domain\Exceptions\SlugTaken;
use Illuminate\Support\Facades\DB;

class TaxonomyTest extends CatalogTestCase
{
    private function path(string $id): string
    {
        return (string) DB::scalar('select path::text from categories where id = ?', [$id]);
    }

    public function test_categories_form_a_materialised_tree_and_slugs_are_derived_without_accents(): void
    {
        $root = $this->category('Listones y cintas');
        $child = $this->category('Satén', $root);
        $leaf = $this->category('Satén rojo', $child);

        $this->assertSame($root, $this->path($root));
        $this->assertSame("{$root}.{$child}.{$leaf}", $this->path($leaf));
        $this->assertSame(3, DB::table('categories')->where('id', $leaf)->value('depth'));
        $this->assertSame('listones-y-cintas', DB::table('category_translations')->where(['category_id' => $root, 'locale' => 'es-MX'])->value('slug'));
        $this->assertSame(3, (int) DB::scalar('select count(*) from categories where path <@ ?::ltree', [$root]), 'one indexed lookup returns the whole subtree');
    }

    public function test_slugs_are_unique_per_language_and_freed_when_a_category_is_deleted(): void
    {
        $a = $this->category('Cintas');
        $this->expectSlugTaken(fn () => $this->category('Cintas'));

        $this->categories()->delete($a);
        $this->assertNotEmpty($this->category('Cintas'), 'the slug is reusable after the delete');
    }

    private function expectSlugTaken(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected SlugTaken');
        } catch (SlugTaken $e) {
            $this->assertSame('SLUG_TAKEN', $e->errorCode());
        }
    }

    public function test_moving_a_branch_rewrites_its_subtree_and_leaves_siblings_alone(): void
    {
        $a = $this->category('A');
        $b = $this->category('B', $a);
        $c = $this->category('C', $b);
        $d = $this->category('D');
        $sibling = $this->category('Hermana', $a);

        $this->categories()->move($b, $d, 2);

        $this->assertSame("{$d}.{$b}", $this->path($b));
        $this->assertSame("{$d}.{$b}.{$c}", $this->path($c));
        $this->assertSame(3, DB::table('categories')->where('id', $c)->value('depth'));
        $this->assertSame("{$a}.{$sibling}", $this->path($sibling), 'siblings are untouched');
        $this->assertSame($d, DB::table('categories')->where('id', $b)->value('parent_id'));

        $this->categories()->move($b, null);
        $this->assertSame($b, $this->path($b));
        $this->assertSame("{$b}.{$c}", $this->path($c));
        $this->assertSame(2, DB::table('categories')->where('id', $c)->value('depth'));
    }

    public function test_a_category_cannot_move_under_itself_or_beyond_the_maximum_depth(): void
    {
        $a = $this->category('A');
        $b = $this->category('B', $a);
        $c = $this->category('C', $b);

        foreach ([$a => $c, $b => $b] as $node => $target) {
            try {
                $this->categories()->move($node, $target);
                $this->fail('Expected a cycle');
            } catch (CategoryCycle $e) {
                $this->assertSame('CATEGORY_CYCLE', $e->errorCode());
            }
        }

        $deep = $c;
        foreach (['D', 'E'] as $name) {
            $deep = $this->category($name, $deep);
        }
        $this->assertSame(5, DB::table('categories')->where('id', $deep)->value('depth'));
        $this->expectException(CategoryTooDeep::class);
        $this->category('F', $deep);
    }

    public function test_only_leaf_categories_can_be_deleted_and_products_are_detached(): void
    {
        $parent = $this->category('Padre');
        $child = $this->category('Hijo', $parent);
        $product = $this->piece();
        $this->products()->update($product, ['category_ids' => [$child]]);

        try {
            $this->categories()->delete($parent);
            $this->fail('Expected CategoryNotEmpty');
        } catch (CategoryNotEmpty) {
            $this->addToAssertionCount(1);
        }

        $this->categories()->delete($child);
        $this->assertSoftDeleted('categories', ['id' => $child]);
        $this->assertSame(0, DB::table('product_categories')->where('category_id', $child)->count());
        $this->assertNotNull(DB::table('products')->where('id', $product)->whereNull('deleted_at')->first(), 'the product survives');
    }

    public function test_tags_are_soft_deleted_and_free_their_slug(): void
    {
        $id = $this->tags()->create(['es-MX' => ['name' => 'Navidad'], 'en-US' => ['name' => 'Christmas']]);
        try {
            $this->tags()->create(['es-MX' => ['name' => 'navidad']]);
            $this->fail('Expected SlugTaken');
        } catch (SlugTaken) {
            $this->addToAssertionCount(1);
        }

        $this->tags()->delete($id);
        $this->assertSoftDeleted('tags', ['id' => $id]);
        $this->assertNotEmpty($this->tags()->create(['es-MX' => ['name' => 'Navidad']]));
    }

    public function test_attributes_validate_colours_and_cannot_be_deleted_while_products_use_them(): void
    {
        $a = $this->colorAndWidth();

        try {
            $this->attributes()->addValue($a['colorId'], 'verde', ['hex' => 'green'], 0, ['es-MX' => 'Verde']);
            $this->fail('Expected a hex error');
        } catch (InvalidProduct $e) {
            $this->assertStringContainsString('hex', $e->getMessage());
        }
        try {
            $this->attributes()->create('Color', 'select', true, 0, ['es-MX' => 'x']);
            $this->fail('Codes must be lower-case technical keys');
        } catch (InvalidProduct) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->attributes()->create('color', 'select', true, 0, ['es-MX' => 'Otro']);
            $this->fail('Codes are unique');
        } catch (InvalidProduct) {
            $this->addToAssertionCount(1);
        }

        $product = $this->variableRibbon($a);

        foreach ([fn () => $this->attributes()->delete($a['colorId']), fn () => $this->attributes()->deleteValue($a['red'])] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected InUse');
            } catch (InUse $e) {
                $this->assertSame('IN_USE', $e->errorCode());
            }
        }

        $this->products()->delete($product);
        $this->attributes()->deleteValue($a['red']);
        $this->attributes()->delete($a['colorId']);
        $this->assertSoftDeleted('attributes', ['id' => $a['colorId']]);
    }
}
