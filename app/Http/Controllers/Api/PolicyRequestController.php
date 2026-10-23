<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest as PolicyRequestModel;
use App\Services\MockInsurerService;
use App\Services\PremiumCalculator;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    public function store(
        Request $request,
        PremiumCalculator $calculator
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'client_request_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'string', 'max:100'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'destination' => [
                'required',
                Rule::in(['DOMESTIC', 'INTERNATIONAL']),
            ],
            'trip_start' => ['required', 'date', 'after_or_equal:today'],
            'trip_end' => ['required', 'date', 'after_or_equal:trip_start'],
            'plan_code' => [
                'required',
                Rule::in(['BASIC', 'PLUS', 'PREMIUM']),
            ],
            'sum_insured' => [
                'required',
                'integer',
                Rule::in([100000, 300000, 500000]),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $age = Carbon::parse($data['date_of_birth'])
            ->diffInYears(Carbon::parse($data['trip_start']));

        if ($age < 18 || $age > 100) {
            return response()->json([
                'message' => 'Customer age must be between 18 and 100.',
            ], 422);
        }

        $existing = PolicyRequestModel::where(
            'agent_id',
            $data['agent_id']
        )->where(
            'client_request_id',
            $data['client_request_id']
        )->first();

        if ($existing) {
            return response()->json([
                'message' => 'Duplicate request',
                'data' => $existing,
            ], 200);
        }

        $data['premium'] = $calculator->calculate($data);
        $data['status'] = 'RECEIVED';

        try {
            $policy = DB::transaction(
                fn () => PolicyRequestModel::create($data)
            );
        } catch (QueryException $e) {
            $existing = PolicyRequestModel::where(
                'agent_id',
                $data['agent_id']
            )->where(
                'client_request_id',
                $data['client_request_id']
            )->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Duplicate request',
                    'data' => $existing,
                ], 200);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Policy request accepted',
            'data' => $policy,
        ], 201);
    }

    public function issue(
        int $id,
        MockInsurerService $insurer
    ): JsonResponse {
        $policy = PolicyRequestModel::find($id);

        if (!$policy) {
            return response()->json([
                'message' => 'Policy request not found',
            ], 404);
        }

        if ($policy->status === 'ISSUED') {
            return response()->json([
                'message' => 'Policy already issued',
                'data' => $policy,
            ], 200);
        }

        if ($policy->status !== 'RECEIVED') {
            return response()->json([
                'message' => 'Policy cannot be issued in its current status',
                'data' => $policy,
            ], 409);
        }

        try {
            $result = $insurer->issuePolicy($policy->toArray());

            if (
                empty($result['success']) ||
                empty($result['provider_reference']) ||
                empty($result['policy_number'])
            ) {
                $policy->update([
                    'status' => 'FAILED',
                    'failure_reason' => $result['message']
                        ?? 'Invalid or unsuccessful insurer response',
                    'last_provider_response' => $result,
                    'attempt_count' => $policy->attempt_count + 1,
                ]);

                return response()->json([
                    'message' => 'Policy issuance failed',
                    'data' => $policy->fresh(),
                ], 502);
            }

            $policy->update([
                'status' => 'ISSUED',
                'provider_reference' => $result['provider_reference'],
                'policy_number' => $result['policy_number'],
                'last_provider_response' => $result,
                'attempt_count' => $policy->attempt_count + 1,
                'failure_reason' => null,
            ]);

            return response()->json([
                'message' => 'Policy issued successfully',
                'data' => $policy->fresh(),
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            $policy->update([
                'status' => 'FAILED',
                'failure_reason' => 'Insurer service error',
                'attempt_count' => $policy->attempt_count + 1,
            ]);

            return response()->json([
                'message' => 'Policy issuance failed',
            ], 502);
        }
    }

    public function show(int $id): JsonResponse
    {
        $policy = PolicyRequestModel::find($id);

        if (!$policy) {
            return response()->json([
                'message' => 'Policy request not found',
            ], 404);
        }

        return response()->json([
            'data' => $policy,
        ], 200);
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'status' => [
                'sometimes',
                'string',
                Rule::in([
                    'RECEIVED',
                    'ISSUED',
                    'FAILED',
                ]),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = PolicyRequestModel::query();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = (int) $request->query('per_page', 10);

        return response()->json(
            $query->latest()->paginate($perPage),
            200
        );
    }
}
