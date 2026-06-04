<?php

namespace App\Http\Resources\Admin;

use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AdminJsonResource extends JsonResource
{
    protected function iso(mixed $value): ?string
    {
        if (! $value instanceof CarbonInterface) {
            return null;
        }

        return $value->toISOString();
    }
}
