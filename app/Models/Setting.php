<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value', 'type', 'group', 'is_public', 'updated_by_user_id'])]
class Setting extends Model
{
    /** Grup yang boleh diubah Admin Toko lewat settings.manage_contact (D-054); sisanya hanya Super Admin. */
    public const STORE_ADMIN_GROUPS = ['contact', 'reviews', 'discount'];

    /**
     * Kunci yang boleh diubah dari dashboard beserta validasinya (docs/database/DATABASE.md).
     *
     * @var array<string, list<string>>
     */
    public const RULES = [
        'contact.whatsapp_number' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        'contact.admin_email' => ['required', 'string', 'email', 'max:255'],
        'refund.claim_window_hours' => ['required', 'integer', 'min:1', 'max:8760'],
        'refund.return_window_days' => ['required', 'integer', 'min:1', 'max:30'],
        'refund.review_sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        'refund.payout_days_max' => ['required', 'integer', 'min:1', 'max:90'],
        'refund.terms_version' => ['nullable', 'string', 'max:50'],
        'cancellation.process_days_max' => ['required', 'integer', 'min:1', 'max:90'],
        'reviews.rating' => ['required', 'numeric', 'between:1,5'],
        'reviews.count' => ['required', 'integer', 'min:0', 'max:1000000'],
        'antifraud.max_unpaid_orders' => ['required', 'integer', 'min:1', 'max:50'],
        'antifraud.max_orders_per_day' => ['required', 'integer', 'min:1', 'max:100'],
        'discount.max_percent' => ['required', 'integer', 'min:1', 'max:90'],
        'checkout.reservation_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        'inventory.low_stock_threshold' => ['required', 'integer', 'min:0', 'max:1000'],
        'marketing.posts_per_week' => ['required', 'integer', 'min:0', 'max:21'],
        'marketing.videos_per_week' => ['required', 'integer', 'min:0', 'max:14'],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }
}
