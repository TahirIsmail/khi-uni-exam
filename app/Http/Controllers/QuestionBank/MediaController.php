<?php

namespace App\Http\Controllers\QuestionBank;

use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Models\Media;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pictures used in questions. They are kept out of the public web root and served only to staff who
 * may see questions of that campus, because a leaked picture can give a question away.
 *
 * The same file uploaded twice in a campus is stored once (its SHA-256 is the key).
 */
class MediaController extends Controller
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    public function store(Request $request): JsonResponse
    {
        $branchId = $this->activeBranch->id($request->user()) ?? abort(403, 'You do not work in any campus.');

        $input = $request->validate([
            'file' => [
                'required', 'file', 'image',
                'mimetypes:'.implode(',', (array) config('qbank.media.mime_types')),
                'max:'.(int) config('qbank.media.max_size_kb'),
            ],
            'alt_text' => ['required', 'string', 'max:255'],
        ], [
            'alt_text.required' => 'Describe the picture, so it also works for screen readers and printed papers.',
        ]);

        $file = $request->file('file');
        $checksum = hash_file('sha256', $file->getRealPath());
        $existing = Media::query()->where(['branch_id' => $branchId, 'checksum' => $checksum])->first();

        if ($existing !== null) {
            return response()->json($this->present($existing));
        }

        $size = @getimagesize($file->getRealPath());
        $path = $file->store("qbank/{$branchId}", 'local');

        $media = Media::query()->create([
            'branch_id' => $branchId,
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'checksum' => $checksum,
            'width' => is_array($size) ? (int) $size[0] : null,
            'height' => is_array($size) ? (int) $size[1] : null,
            'alt_text' => $input['alt_text'],
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($this->present($media), 201);
    }

    public function show(Request $request, Media $media): StreamedResponse
    {
        abort_unless((int) $media->branch_id === $this->activeBranch->id($request->user()), 404);
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        // SecurityHeaders adds "no-store, private": exam pictures are not cached anywhere.
        return Storage::disk($media->disk)->response($media->path, null, ['Content-Type' => $media->mime_type]);
    }

    /**
     * @return array{id: int, url: string, alt: string, width: int|null, height: int|null}
     */
    private function present(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => route('questions.media.show', $media->id, absolute: false),
            'alt' => $media->alt_text,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }
}
