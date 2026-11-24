<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CityResource;
use App\Http\Resources\RegionResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/*
| Reference data managed by admins: regions, cities and categories.
|
| Three tiny CRUDs share one controller because they are nearly identical.
| If one of them grows real logic, give it its own controller.
|
| Deleting is refused while something still points to the record. The
| database would refuse anyway (foreign keys with RESTRICT), but checking
| first lets us answer with a clear message instead of a 500 error.
*/
class CatalogController extends Controller
{
    // --- Regions --------------------------------------------------------

    public function regions(): AnonymousResourceCollection
    {
        return RegionResource::collection(Region::query()->withCount('cities')->orderBy('name')->get());
    }

    public function storeRegion(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:regions,name']]);

        return RegionResource::make(Region::create($data))->response()->setStatusCode(201);
    }

    public function updateRegion(Request $request, Region $region): RegionResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('regions', 'name')->ignore($region)]]);
        $region->update($data);

        return RegionResource::make($region);
    }

    public function destroyRegion(Region $region): Response
    {
        if ($region->cities()->exists()) {
            throw BusinessRuleException::stillInUse('region', 'Delete or move its cities first.');
        }
        $region->delete();

        return response()->noContent();
    }

    // --- Cities ---------------------------------------------------------

    public function cities(): AnonymousResourceCollection
    {
        return CityResource::collection(City::query()->with('region')->withCount('tours')->orderBy('name')->get());
    }

    public function storeCity(Request $request): JsonResponse
    {
        $data = $request->validate($this->cityRules());

        return CityResource::make(City::create($data)->load('region'))->response()->setStatusCode(201);
    }

    public function updateCity(Request $request, City $city): CityResource
    {
        $city->update($request->validate($this->cityRules($city)));

        return CityResource::make($city->load('region'));
    }

    public function destroyCity(City $city): Response
    {
        if ($city->tours()->exists()) {
            throw BusinessRuleException::stillInUse('city', 'Some tours take place there.');
        }
        $city->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function cityRules(?City $city = null): array
    {
        return [
            'region_id' => ['required', 'integer', 'exists:regions,id'],
            // Unique *within the region* (mirrors the composite unique index).
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('cities', 'name')
                    ->where('region_id', request()->integer('region_id'))
                    ->ignore($city),
            ],
        ];
    }

    // --- Categories -----------------------------------------------------

    public function categories(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::query()->withCount('tours')->orderBy('name')->get());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:categories,name']]);

        return CategoryResource::make(Category::create($data))->response()->setStatusCode(201);
    }

    public function updateCategory(Request $request, Category $category): CategoryResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->ignore($category)]]);
        $category->update($data);

        return CategoryResource::make($category);
    }

    public function destroyCategory(Category $category): Response
    {
        if ($category->tours()->exists()) {
            throw BusinessRuleException::stillInUse('category', 'Some tours use it.');
        }
        $category->delete();

        return response()->noContent();
    }
}
