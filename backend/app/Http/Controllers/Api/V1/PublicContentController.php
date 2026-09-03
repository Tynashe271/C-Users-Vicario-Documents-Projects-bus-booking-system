<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformResource;
use Illuminate\Http\JsonResponse;

class PublicContentController extends Controller
{
    /**
     * Published content pages (homepage, about, FAQ, contact, travel guides, maintenance
     * announcements, ...) are authored through the authenticated `content_pages` module by
     * marketing.manage staff, but this — reading a published one by slug — is what the public
     * website itself needs, with no authentication.
     */
    public function contentPage(string $slug): JsonResponse
    {
        $page = (new PlatformResource)->useModule('content_pages')->newQuery()->where('code', $slug)->where('status', 'published')->first();
        abort_unless($page, 404);

        return response()->json($page);
    }

    /** Same idea for standing policy documents: terms, privacy, refund policy, and the like. */
    public function policy(string $slug): JsonResponse
    {
        $policy = (new PlatformResource)->useModule('policies')->newQuery()->where('code', $slug)->where('status', 'published')->first();
        abort_unless($policy, 404);

        return response()->json($policy);
    }

    public function faq(): JsonResponse
    {
        return response()->json((new PlatformResource)->useModule('faq_articles')->newQuery()->where('status', 'published')->orderBy('name')->get());
    }
}
