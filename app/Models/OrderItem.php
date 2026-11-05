<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_variant_id', 'sku_snapshot', 'product_name_snapshot', 'variant_name_snapshot', 'unit_price', 'quantity', 'weight_gram_snapshot', 'line_total'])]
class OrderItem extends Model {}
