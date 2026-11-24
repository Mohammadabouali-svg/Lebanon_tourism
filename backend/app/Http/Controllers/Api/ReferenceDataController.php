<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\RegionResource;
use App\Models\Category;
use App\Models\Region;
use Illuminate\Http\JsonResponse;

/*
| The small lists every form and filter needs (categories, regions with
| their cities), in a single request. The React app caches the answer.
*/
class ReferenceDataController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'categories' => CategoryResource::collection(Category::query()->orderBy('name')->get()),
            'regions' => RegionResource::collection(
                Region::query()->with(['cities' => fn ($query) => $query->orderBy('name')])->orderBy('name')->get(),
            ),
        ]);
    }
}
