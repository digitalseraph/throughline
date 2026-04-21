<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocialAccountEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_at_rest(): void
    {
        $account = SocialAccount::factory()->create([
            'access_token' => 'plaintext-access',
            'refresh_token' => 'plaintext-refresh',
        ]);

        $raw = DB::table('social_accounts')->where('id', $account->id)->first();

        $this->assertNotSame('plaintext-access', $raw->access_token);
        $this->assertNotSame('plaintext-refresh', $raw->refresh_token);
        $this->assertSame('plaintext-access', $account->fresh()->access_token);
        $this->assertSame('plaintext-refresh', $account->fresh()->refresh_token);
    }
}
