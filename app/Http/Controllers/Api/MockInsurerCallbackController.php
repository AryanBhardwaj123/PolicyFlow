<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MockInsurerCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_id' => ['required', 'string', 'max:100'],
            'provider_reference' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['SUCCESS', 'FAILED'])],
            'policy_number' => ['required_if:status,SUCCESS', 'nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $result = DB::transaction(function () use ($data) {
                $policy = PolicyRequest::where(
                    'provider_reference',
                    $data['provider_reference']
                )->lockForUpdate()->first();

                if (!$policy) {
                    return ['code' => 404, 'message' => 'Policy not found'];
                }

                $inserted = DB::table('provider_events')->insertOrIgnore([
                    'event_id' => $data['event_id'],
                    'provider_reference' => $data['provider_reference'],
                    'payload' => json_encode($data),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (!$inserted) {
                    return ['code' => 200, 'message' => 'Duplicate callback ignored'];
                }

                if ($policy->status === 'ISSUED') {
                    return ['code' => 200, 'message' => 'Policy already issued'];
                }

                if ($data['status'] === 'SUCCESS') {
                    $policy->update([
                        'status' => 'ISSUED',
                        'policy_number' => $data['policy_number'],
                        'failure_reason' => null,
                    ]);
                } else {
                    $policy->update([
                        'status' => 'FAILED',
                        'failure_reason' => 'Provider reported failure',
                    ]);
                }

                return ['code' => 200, 'message' => 'Callback processed'];
            });

            return response()->json([
                'message' => $result['message'],
            ], $result['code']);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Callback processing failed',
            ], 500);
        }
    }
}
