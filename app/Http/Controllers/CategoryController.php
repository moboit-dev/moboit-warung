<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * GET /api/categories
     * Tidak perlu filter tenant manual — global scope di BelongsToTenant
     * sudah otomatis membatasi ke tenant yang sedang login.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->withCount('products')
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->input('search') . '%');
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json($categories);
    }

    /**
     * POST /api/categories
     * tenant_id TIDAK dikirim dari sini — biar hook `creating` di
     * BelongsToTenant yang mengisi otomatis dari user yang login.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $category = Category::create($validated);

        return response()->json(['data' => $category], 201);
    }

    /**
     * GET /api/categories/{category}
     * Route model binding aman: kalau category milik tenant lain,
     * global scope bikin query-nya tidak ketemu -> Laravel otomatis 404.
     */
    public function show(Category $category): JsonResponse
    {
        $category->loadCount('products');

        return response()->json(['data' => $category]);
    }

    /**
     * PUT/PATCH /api/categories/{category}
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
        ]);

        $category->update($validated);

        return response()->json(['data' => $category]);
    }

    /**
     * DELETE /api/categories/{category}
     * Cegah hapus kategori yang masih punya produk aktif, supaya produk
     * tidak jadi "yatim" tanpa kategori secara tidak sengaja.
     * Kalau memang mau paksa hapus, arahkan produk ke kategori lain dulu
     * lewat endpoint update produk.
     */
    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json([
                'message' => 'Kategori masih dipakai oleh produk lain. Pindahkan produk ke kategori lain dulu sebelum menghapus.',
            ], 422);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}
