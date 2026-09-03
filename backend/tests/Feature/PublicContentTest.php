<?php

namespace Tests\Feature;

use App\Models\PlatformResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_published_content_pages_and_policies_are_publicly_readable(): void
    {
        (new PlatformResource)->useModule('content_pages')->newQuery()->create(['code' => 'about', 'name' => 'About Us', 'status' => 'published', 'data' => ['body' => 'We move people.']]);
        (new PlatformResource)->useModule('content_pages')->newQuery()->create(['code' => 'draft-page', 'name' => 'Coming Soon', 'status' => 'draft']);
        (new PlatformResource)->useModule('policies')->newQuery()->create(['code' => 'refund-policy', 'name' => 'Refund Policy', 'status' => 'published', 'data' => ['body' => 'Refunds within 24 hours.']]);
        (new PlatformResource)->useModule('faq_articles')->newQuery()->create(['code' => 'faq-1', 'name' => 'How do I book?', 'status' => 'published']);
        (new PlatformResource)->useModule('faq_articles')->newQuery()->create(['code' => 'faq-2', 'name' => 'Unpublished question', 'status' => 'draft']);

        $this->getJson('/api/v1/content-pages/about')->assertOk()->assertJsonPath('name', 'About Us');
        $this->getJson('/api/v1/content-pages/draft-page')->assertNotFound();
        $this->getJson('/api/v1/content-pages/missing')->assertNotFound();
        $this->getJson('/api/v1/policies/refund-policy')->assertOk()->assertJsonPath('name', 'Refund Policy');
        $this->getJson('/api/v1/faq')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'How do I book?');
    }
}
