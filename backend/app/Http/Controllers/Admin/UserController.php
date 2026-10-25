<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @group Admin: users
 */
class UserController extends Controller
{
    /**
     * List users
     *
     * @queryParam search string Name, email or student number. Example: student1
     * @queryParam role string admin, lecturer or student. Example: student
     * @queryParam active boolean Filter by active flag. Example: 1
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);
        $request->validate([
            'role' => ['nullable', Rule::enum(Role::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $users = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')).'%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('student_number', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return UserResource::collection($users);
    }

    /**
     * Create a user (AUTH-6)
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        Audit::log('user.created', $user, ['role' => $user->role->value]);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    /**
     * Import users from CSV (AUTH-6)
     *
     * Columns (header row required): name, email, role, student_number (optional),
     * password (optional; a random one is generated and returned when missing).
     * Rows are validated individually; valid rows are created, invalid rows reported.
     *
     * @bodyParam file file required CSV file.
     */
    public function import(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => Str::snake(trim((string) $h)), fgetcsv($handle) ?: []);
        $created = [];
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($handle, $header, &$created, &$errors, &$line) {
            $seen = [];
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }
                $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
                $data = array_map(fn ($v) => $v === null ? null : trim($v), $data);
                $data['email'] = mb_strtolower((string) ($data['email'] ?? ''));
                $data['role'] = mb_strtolower((string) ($data['role'] ?? 'student')) ?: 'student';
                $data['student_number'] = ($data['student_number'] ?? null) ?: null;
                $generated = empty($data['password']);
                $data['password'] = $generated ? Str::password(12, symbols: false) : $data['password'];

                $v = Validator::make($data, [
                    'name' => ['required', 'string', 'min:2', 'max:120'],
                    'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                    'role' => ['required', Rule::enum(Role::class)],
                    'student_number' => ['nullable', 'string', 'max:30', 'unique:users,student_number'],
                    'password' => ['required', 'string', 'min:8'],
                ]);
                if ($v->fails() || isset($seen[$data['email']])) {
                    $errors[] = ['line' => $line, 'email' => $data['email'], 'errors' => $v->fails() ? $v->errors()->all() : ['Duplicate email in file.']];

                    continue;
                }
                $seen[$data['email']] = true;
                $user = User::create($v->validated());
                Audit::log('user.created', $user, ['role' => $user->role->value, 'source' => 'csv']);
                $created[] = ['id' => $user->id, 'email' => $user->email, 'password' => $generated ? $data['password'] : null];
            }
        });
        fclose($handle);

        return response()->json([
            'message' => count($created).' users created, '.count($errors).' rows rejected.',
            'created' => $created,
            'errors' => $errors,
        ], $created ? 201 : 422);
    }

    /**
     * Update a user (AUTH-6)
     *
     * Change details, role, password or active flag. Deactivating revokes every token.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->validated();
        if ((isset($data['role']) && $data['role'] !== $user->role->value) || (array_key_exists('is_active', $data) && ! $data['is_active'])) {
            Gate::authorize('changeAccess', $user);
        }

        $oldRole = $user->role->value;
        $wasActive = $user->is_active;
        $user->fill($data)->save();

        if ($oldRole !== $user->role->value) {
            Audit::log('user.role_changed', $user, ['from' => $oldRole, 'to' => $user->role->value]);
        }
        if (isset($data['password'])) {
            // a password set by an admin signs the user out everywhere
            $user->tokens()->delete();
            Audit::log('user.password_reset', $user);
        }
        if ($wasActive && ! $user->is_active) {
            $user->tokens()->delete();
            Audit::log('user.deactivated', $user);
        } elseif (! $wasActive && $user->is_active) {
            Audit::log('user.activated', $user);
        }

        return new UserResource($user);
    }
}
