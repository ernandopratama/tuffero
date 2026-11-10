<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantDiscount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kampanye promo (D-073): tema dan periode yang membungkus diskon produk dan voucher.
 */
class CampaignService
{
    public function __construct(private ImageService $images) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Campaign
    {
        return DB::transaction(function () use ($data, $actor) {
            $campaign = new Campaign($data);
            $campaign->forceFill(['slug' => $this->uniqueSlug($data['title'])])->save();

            AuditLog::record('campaign.created', $campaign, ['title' => $campaign->title, 'is_flash' => $campaign->is_flash], $actor->id);

            return $campaign->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Campaign $campaign, array $data, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $data, $actor) {
            AuditLog::saveWithAudit($campaign, $data, 'campaign.updated', $actor);

            return $campaign->refresh();
        });
    }

    public function setImage(Campaign $campaign, UploadedFile $file, User $actor): Campaign
    {
        $old = $campaign->image_path;
        $path = $this->images->storeWebp($file, "campaigns/{$campaign->id}", 1920);

        DB::transaction(function () use ($campaign, $path, $actor) {
            $campaign->forceFill(['image_path' => $path])->save();

            AuditLog::record('campaign.image_changed', $campaign, ['image_path' => $path], $actor->id);
        });

        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return $campaign;
    }

    /** @param array<string, mixed> $data */
    public function addItem(Campaign $campaign, ProductVariant $variant, array $data, User $actor): VariantDiscount
    {
        return DB::transaction(function () use ($campaign, $variant, $data, $actor) {
            // Satu varian satu diskon per segmen di dalam kampanye; ubah yang ada, bukan menambah kembar.
            if ($campaign->discounts()->where('product_variant_id', $variant->id)->where('audience', $data['audience'])->exists()) {
                throw ValidationException::withMessages(['variant_id' => 'Varian ini sudah ada di kampanye untuk segmen yang sama. Ubah yang ada.']);
            }

            $item = new VariantDiscount($data);
            $item->forceFill(['campaign_id' => $campaign->id, 'product_variant_id' => $variant->id])->save();

            AuditLog::record('campaign.item_added', $campaign, ['sku' => $variant->sku, 'type' => $item->type, 'value' => $item->value, 'audience' => $item->audience], $actor->id);

            return $item->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateItem(VariantDiscount $item, array $data, User $actor): VariantDiscount
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            AuditLog::saveWithAudit($item, $data, 'campaign.item_updated', $actor);

            return $item->refresh();
        });
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'promo';
        $slug = $base;

        for ($i = 2; Campaign::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
