<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestEmailChangeRequest;
use App\Http\Resources\UserResource;
use App\Services\EmailChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
| Thin HTTP layer for the e-mail change (D20). Rules live in
| EmailChangeService; who may call what is declared in routes/api.php.
*/
class EmailChangeController extends Controller
{
    public function __construct(private readonly EmailChangeService $emailChanges) {}

    /** POST /api/user/email-change: 202, the change waits for the link. */
    public function store(RequestEmailChangeRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->emailChanges->request($user, $request->validated('email'));

        return UserResource::make($user->load('operatorProfile', 'wallet'))
            ->response()
            ->setStatusCode(202);
    }

    /** DELETE /api/user/email-change */
    public function destroy(Request $request): UserResource
    {
        $user = $request->user();
        $this->emailChanges->cancel($user);

        return UserResource::make($user->load('operatorProfile', 'wallet'));
    }

    /*
    | POST /api/email/change/{id}/{hash}, reached from the link in the e-mail.
    | No login needed (the link is often opened on another device): the
    | `signed:relative` middleware has already checked signature and expiry.
    */
    public function confirm(int $id, string $hash): JsonResponse
    {
        $newEmail = $this->emailChanges->confirm($id, $hash);

        return response()->json([
            'message' => "Your e-mail address is now {$newEmail}. Use it the next time you sign in.",
        ]);
    }
}
