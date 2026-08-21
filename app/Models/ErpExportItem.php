<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpExportItem extends Model
{
    use HasFactory;

    protected $table = 'erp_export_items';

    // Disable default timestamps because migration only has created_at
    public $timestamps = false;

    protected $fillable = [
        'erp_export_batch_id',
        'payment_slip_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Automatically set created_at when creating
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    public function exportBatch(): BelongsTo
    {
        return $this->belongsTo(ErpExportBatch::class, 'erp_export_batch_id');
    }

    public function paymentSlip(): BelongsTo
    {
        return $this->belongsTo(PaymentSlip::class);
    }
}
