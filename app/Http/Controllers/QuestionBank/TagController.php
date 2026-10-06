<?php

namespace App\Http\Controllers\QuestionBank;

use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Models\Tag;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Free labels authors add to questions, kept per campus. Adding one that already exists returns it,
 * so two authors typing the same word share a tag instead of making duplicates.
 */
class TagController extends Controller
{
    public function store(Request $request, ActiveBranch $activeBranch): JsonResponse
    {
        $branchId = $activeBranch->id($request->user('web')) ?? abort(403, 'You do not work in any campus.');
        $input = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $name = trim($input['name']);
        $slug = Str::slug($name) ?: mb_strtolower($name);

        $tag = Tag::query()->firstOrCreate(
            ['branch_id' => $branchId, 'slug' => mb_substr($slug, 0, 60)],
            ['name' => $name, 'created_by' => $request->user('web')->id],
        );

        return response()->json(['id' => $tag->id, 'name' => $tag->name], $tag->wasRecentlyCreated ? 201 : 200);
    }
}
