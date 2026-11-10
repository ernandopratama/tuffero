<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Foto profil akun staf: JPG/JPEG/PNG dipotong persegi dan disimpan WebP, hanya untuk akun sendiri. */
class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    private function staff(string $role = 'marketing', array $attributes = []): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now(), ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function upload(UploadedFile $file)
    {
        return $this->post('/api/v1/admin/profile/avatar', ['image' => $file], ['Accept' => 'application/json']);
    }

    public function test_upload_is_cropped_square_converted_to_webp_and_returned_in_profile(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        $response = $this->upload(UploadedFile::fake()->image('saya.jpg', 1600, 900))->assertOk();

        $path = $user->fresh()->avatar_path;
        $this->assertStringStartsWith("avatars/{$user->id}/", $path);
        $this->assertStringEndsWith('.webp', $path);
        [$w, $h, $type] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([512, 512, IMAGETYPE_WEBP], [$w, $h, $type]);
        $response->assertJsonPath('data.avatar_url', '/storage/'.$path)->assertJsonPath('data.user.avatar_url', '/storage/'.$path);
        $this->getJson('/api/v1/admin/profile')->assertJsonPath('data.avatar_url', '/storage/'.$path);
        $this->assertSame(1, AuditLog::where('action', 'profile.avatar_changed')->count());
    }

    public function test_replacing_removes_the_old_file_and_removing_clears_it(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        $this->upload(UploadedFile::fake()->image('a.png', 600, 600))->assertOk();
        $first = $user->fresh()->avatar_path;
        $this->upload(UploadedFile::fake()->image('b.jpeg', 600, 600))->assertOk();
        Storage::disk('public')->assertMissing($first);

        $second = $user->fresh()->avatar_path;
        $this->deleteJson('/api/v1/admin/profile/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame(1, AuditLog::where('action', 'profile.avatar_removed')->count());
    }

    public function test_only_jpg_jpeg_png_and_small_files_are_accepted(): void
    {
        $this->actingAs($this->staff());

        foreach (['x.webp', 'x.gif'] as $name) {
            $this->upload(UploadedFile::fake()->image($name, 400, 400))->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->upload(UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;'))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->upload(UploadedFile::fake()->image('big.jpg', 600, 600)->size(3000))->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_requires_login_staff_role_and_enrolled_mfa(): void
    {
        $file = UploadedFile::fake()->image('a.jpg', 300, 300);

        $this->upload($file)->assertUnauthorized();

        $this->actingAs(User::factory()->create(['mfa_totp_confirmed_at' => now()]));
        $this->upload($file)->assertForbidden();

        $this->actingAs($this->staff('marketing', ['mfa_totp_confirmed_at' => null]));
        $this->upload($file)->assertForbidden();
    }

    public function test_name_can_be_changed_without_the_current_password_when_email_is_unchanged(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        $this->patchJson('/api/v1/admin/profile', ['name' => 'Nama Baru', 'email' => $user->email])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Nama Baru');
    }
}
