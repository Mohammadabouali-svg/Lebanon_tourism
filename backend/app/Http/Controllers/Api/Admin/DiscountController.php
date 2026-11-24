<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DiscountRequest;
use App\Http\Resources\DiscountResource;
use App\Models\Booking;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DiscountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DiscountResource::collection(Discount::query()->latest('id')->paginate(25));
    }

    public function store(DiscountRequest $request): JsonResponse
    {
        $discount = Discount::create($request->validated());

        return DiscountResource::make($discount)->response()->setStatusCode(201);
    }

    public function update(DiscountRequest $request, Discount $discount): DiscountResource
    {
        $discount->update($request->validated());

        return DiscountResource::make($discount);
    }

    /** Used codes are kept for the booking history; deactivate them instead. */
    public function destroy(Discount $discount): Response
    {
        if (Booking::query()->whereBelongsTo($discount)->exists()) {
            throw BusinessRuleException::stillInUse('discount code', 'Deactivate it instead.');
        }

        $discount->delete();

        return response()->noContent();
    }
}
