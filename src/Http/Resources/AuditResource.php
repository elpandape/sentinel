<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Http\Resources;

use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The entry over HTTP, and nothing the entry did not already say. It adds no key, renames none and
 * hides none: there is one public shape for an entry and it is toArray(), so a resource with a
 * shape of its own would be a second contract to keep in step with the first.
 *
 * What it is for is the envelope — collection(), pagination, the response wrapper — which is the
 * part an application does want from Laravel and the part toArray() has no business knowing about.
 *
 * The package mounts no routes for it. Which entries a request may see is an authorisation
 * question, and Sentinel has no standing to answer it for your application.
 *
 * @mixin Audit
 */
final class AuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Audit $audit */
        $audit = $this->resource;

        return $audit->toArray();
    }
}
