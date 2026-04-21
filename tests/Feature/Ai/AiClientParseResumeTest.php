<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\Exceptions\ParseFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientParseResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_structured_json_on_success(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'text' => 'Plain-text summary.',
                        'structured' => [
                            'name' => 'Jane Doe',
                            'titles' => ['Staff Engineer'],
                            'companies' => ['Acme'],
                            'skills' => ['PHP', 'Postgres'],
                            'years_experience' => 12,
                        ],
                    ]),
                ]],
                'usage' => ['input_tokens' => 500, 'output_tokens' => 200],
            ]),
        ]);

        $user = User::factory()->create();
        $result = app(AiClient::class)->forUser($user)->parseResume('dummy raw text');

        $this->assertSame('Plain-text summary.', $result['text']);
        $this->assertSame('Jane Doe', $result['structured']['name']);
    }

    public function test_throws_parsefailed_on_unparseable_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => 'not json at all',
                ]],
                'usage' => ['input_tokens' => 500, 'output_tokens' => 20],
            ]),
        ]);

        $user = User::factory()->create();
        $this->expectException(ParseFailed::class);
        app(AiClient::class)->forUser($user)->parseResume('dummy');
    }
}
