<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    /**
     * GET /api/categories — top-level categories with their direct children.
     * Only active rows. Drives the storefront homepage tiles.
     */
    public function indexPublic(): AnonymousResourceCollection
    {
        $tree = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->with([
                'children' => fn ($q) => $q
                    ->where('is_active', true)
                    ->withCount(['products' => fn ($q2) => $q2->where('is_active', true)])
                    ->orderBy('display_order'),
            ])
            ->orderBy('display_order')
            ->get();

        $this->rollUpCounts($tree);
        return CategoryResource::collection($tree);
    }

    /**
     * GET /api/admin/categories — full tree, includes inactive rows so the
     * admin can toggle them back.
     */
    public function indexAdmin(): AnonymousResourceCollection
    {
        $tree = Category::query()
            ->whereNull('parent_id')
            ->withCount('products')
            ->with([
                'children' => fn ($q) => $q
                    ->withCount('products')
                    ->orderBy('display_order'),
            ])
            ->orderBy('display_order')
            ->get();

        $this->rollUpCounts($tree);
        return CategoryResource::collection($tree);
    }

    /**
     * Products are tagged to leaf categories (sub-categories), so a parent
     * category's `products_count` is always 0 from the SQL query. Roll
     * the children's counts up so the storefront tiles can show
     * meaningful totals on the top-level cards.
     */
    private function rollUpCounts(\Illuminate\Database\Eloquent\Collection $tree): void
    {
        foreach ($tree as $top) {
            $childSum = 0;
            if ($top->relationLoaded('children')) {
                foreach ($top->children as $child) {
                    $childSum += (int) ($child->products_count ?? 0);
                }
            }
            $top->products_count = (int) ($top->products_count ?? 0) + $childSum;
        }
    }

    /** POST /api/admin/categories */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->toModel();

        if (! array_key_exists('display_order', $data) || $data['display_order'] === null) {
            $data['display_order'] = (int) (Category::query()
                ->where('parent_id', $data['parent_id'])
                ->max('display_order') ?? 0) + 1;
        }

        $category = Category::create($data);

        return (new CategoryResource($category->fresh(['children'])))
            ->response()
            ->setStatusCode(201);
    }

    /** PUT /api/admin/categories/{category} */
    public function update(StoreCategoryRequest $request, Category $category): CategoryResource
    {
        $data = $request->toModel();
        $category->update($data);
        $fresh = $category->fresh(['children']);
        return new CategoryResource($fresh);
    }

    /**
     * DELETE /api/admin/categories/{category}
     * Cascades subcategories too. Products that reference this category get
     * a restrict-on-delete error from the DB FK (frontend should warn the
     * admin to move products out first).
     */
    public function destroy(Category $category): JsonResponse
    {
        // Delete subs first so the FK doesn't restrict.
        $category->children()->delete();
        $category->delete();
        return response()->json(['message' => 'Category deleted.']);
    }

    /**
     * POST /api/admin/categories/reorder
     * Body: { ids: number[] }
     *
     * Assigns `display_order = index + 1` to each id in the array, in a
     * single transaction. The ids must all share the same `parent_id`
     * (top-level or sub-category siblings) — otherwise we'd be shuffling
     * unrelated groups together.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:categories,id'],
        ]);

        $ids = array_values(array_map('intval', $data['ids']));

        $rows = Category::query()->whereIn('id', $ids)->get();
        if ($rows->count() !== count($ids)) {
            return response()->json(['message' => 'Some categories not found.'], 422);
        }

        // Reject mixed-parent batches.
        $parents = $rows->pluck('parent_id')->unique();
        if ($parents->count() > 1) {
            return response()->json(
                ['message' => 'All categories must share the same parent.'],
                422,
            );
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                Category::where('id', $id)->update(['display_order' => $i + 1]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }

    /**
     * POST /api/admin/categories/{category}/move
     * Body: { direction: "up" | "down" }
     * Swaps display_order with the adjacent category at the same level.
     */
    public function move(Request $request, Category $category): JsonResponse
    {
        $direction = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ])['direction'];

        $q = Category::query()
            ->where('id', '!=', $category->id)
            ->where('parent_id', $category->parent_id);

        $neighbor = $direction === 'up'
            ? $q->where('display_order', '<', $category->display_order)
                ->orderByDesc('display_order')
                ->first()
            : $q->where('display_order', '>', $category->display_order)
                ->orderBy('display_order')
                ->first();

        if (! $neighbor) {
            return response()->json(['message' => 'Already at the edge.']);
        }

        $a = $category->display_order;
        $b = $neighbor->display_order;
        $category->update(['display_order' => $b]);
        $neighbor->update(['display_order' => $a]);

        return response()->json(['message' => 'Order updated.']);
    }
}
