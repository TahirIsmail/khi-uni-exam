<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Candidate\Actions\ApproveDevice;
use App\Domain\Candidate\Actions\SaveCentre;
use App\Domain\Candidate\Actions\SaveRoom;
use App\Domain\Candidate\Models\CandidateDevice;
use App\Domain\Candidate\Models\Centre;
use App\Domain\Candidate\Models\Room;
use App\Domain\Candidate\Queries\CentreData;
use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exam centres and their rooms: campus infrastructure, reused across every examination held there.
 */
class CentreController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function index(Request $request, CentreData $data): Response
    {
        $branchId = $this->branchId($request);

        return Inertia::render('exams/conduct/Centres', [
            'centres' => $data->list($branchId),
            'can' => $data->abilities($request->user('web')),
        ]);
    }

    public function store(Request $request, SaveCentre $save): RedirectResponse
    {
        $input = $this->validated($request);
        $save($request->user('web'), $this->branchId($request), null, $input);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Centre added.')]);

        return to_route('conduct.centres');
    }

    public function update(Request $request, Centre $centre, SaveCentre $save): RedirectResponse
    {
        $this->guard($request, $centre);
        $input = $this->validated($request);
        $save($request->user('web'), $this->branchId($request), $centre, $input);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return to_route('conduct.centres');
    }

    public function storeRoom(Request $request, Centre $centre, SaveRoom $save): RedirectResponse
    {
        $this->guard($request, $centre);
        $input = $this->validatedRoom($request);
        $save($request->user('web'), $centre, null, $input);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room added.')]);

        return to_route('conduct.centres');
    }

    public function updateRoom(Request $request, Centre $centre, Room $room, SaveRoom $save): RedirectResponse
    {
        $this->guard($request, $centre);
        $input = $this->validatedRoom($request);
        $save($request->user('web'), $centre, $room, $input);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return to_route('conduct.centres');
    }

    public function devices(Request $request, Centre $centre, CentreData $data): JsonResponse
    {
        $this->guard($request, $centre);

        return response()->json(['devices' => $data->pendingDevices($centre->id)]);
    }

    public function approveDevice(Request $request, Centre $centre, CandidateDevice $device, ApproveDevice $approve): RedirectResponse
    {
        $this->guard($request, $centre);
        abort_unless($device->centre_id === $centre->id, 404);

        $approve($request->user('web'), $device);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device approved.')]);

        return to_route('conduct.centres');
    }

    /** Every computer waiting at this centre, in one go (a hall of new computers on the day). */
    public function approveAllDevices(Request $request, Centre $centre, ApproveDevice $approve): RedirectResponse
    {
        $this->guard($request, $centre);
        $count = 0;
        CandidateDevice::query()->where('centre_id', $centre->id)->whereNull('approved_at')->orderBy('id')
            ->each(function (CandidateDevice $device) use ($request, $approve, &$count): void {
                $approve($request->user('web'), $device);
                $count++;
            });

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':n devices approved.', ['n' => $count])]);

        return to_route('conduct.centres');
    }

    private function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
    }

    /** A centre of another campus does not exist for this user. */
    private function guard(Request $request, Centre $centre): void
    {
        abort_unless($centre->branch_id === $this->branchId($request), 404);
    }

    /**
     * @return array{name: string, code: string, address: ?string, is_active: bool}
     */
    private function validated(Request $request): array
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'address' => ['nullable', 'string', 'max:300'],
            'is_active' => ['boolean'],
        ]);

        return [
            'name' => $input['name'],
            'code' => $input['code'],
            'address' => $input['address'] ?? null,
            'is_active' => (bool) ($input['is_active'] ?? true),
        ];
    }

    /**
     * @return array{name: string, capacity: int, is_active: bool}
     */
    private function validatedRoom(Request $request): array
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'capacity' => ['required', 'integer', 'min:1', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        return [
            'name' => $input['name'],
            'capacity' => (int) $input['capacity'],
            'is_active' => (bool) ($input['is_active'] ?? true),
        ];
    }
}
