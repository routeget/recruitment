<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant_isolation', function (Builder $builder) {
            $context = app(TenantContextService::class);
            if ($context->hasActiveTenant()) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $context->getActiveTenantId());
            }
        });

        static::creating(function ($model) {
            $context = app(TenantContextService::class);
            if (empty($model->tenant_id) && $context->hasActiveTenant()) {
                $model->tenant_id = $context->getActiveTenantId();
            }
            if (in_array('uuid', $model->getFillable(), true) && empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant_isolation');
    }
}
