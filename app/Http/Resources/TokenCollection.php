<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use stdClass;

class TokenCollection extends ResourceCollection {

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>|stdClass
     */
    public function toArray(Request $request): array|stdClass {
        $tokens = [];

        foreach ($this->collection as $token) {
            $id          = $token->policy_hex . '.' . $token->asset_hex;
            $tokens[$id] = (string)$token->quantity;
        }

        if (empty($tokens)) {
            return new stdClass();
        }

        return (object) $tokens;
    }
}
