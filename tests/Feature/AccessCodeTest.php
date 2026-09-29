<?php

namespace Tests\Feature;

use App\Models\AccessCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccessCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_command_creates_an_access_code(): void
    {
        $this->artisan('upload:code', ['label' => 'Ada'])
            ->expectsOutputToContain('Zugangscode')
            ->assertSuccessful();

        $accessCode = AccessCode::query()->first();

        $this->assertNotNull($accessCode);
        $this->assertSame('Ada', $accessCode->label);
        $this->assertSame(8, strlen($accessCode->code));
    }

    public function test_valid_code_unlocks_the_upload_form(): void
    {
        $accessCode = AccessCode::issue('Besucher', null);

        $response = $this->postJson('/api/access', [
            'code' => $accessCode->formattedCode(),
        ]);

        $response->assertOk()
            ->assertJsonPath('unlocked', true)
            ->assertSessionHas('upload_access_code_id', $accessCode->id);
    }

    public function test_invalid_code_returns_validation_error(): void
    {
        $this->postJson('/api/access', ['code' => 'ZZZZ-ZZZZ'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_revoked_code_is_rejected(): void
    {
        $accessCode = AccessCode::issue('Alt', null);
        $accessCode->forceFill(['revoked_at' => now()])->save();

        $this->postJson('/api/access', ['code' => $accessCode->code])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_expired_code_is_rejected(): void
    {
        $accessCode = AccessCode::issue('Alt', Carbon::yesterday()->endOfDay());

        $this->postJson('/api/access', ['code' => $accessCode->code])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_upload_without_code_returns_403(): void
    {
        $this->postJson('/api/uploads', [
            'original_name' => 'datei.bin',
            'total_size' => 10,
            'sender_name' => 'Ada Lovelace',
            'sender_email' => 'ada@example.com',
        ])->assertForbidden();
    }

    public function test_terminal_command_revokes_an_access_code(): void
    {
        $accessCode = AccessCode::issue('Ada', null);

        $this->artisan('upload:revoke', ['code' => $accessCode->formattedCode()])
            ->assertSuccessful();

        $this->assertNotNull($accessCode->fresh()->revoked_at);
    }
}
