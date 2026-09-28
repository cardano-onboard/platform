<?php

namespace App\Support;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Whether a claim request carries a Sanctum bearer token entitled to raise its account's
 * claim rate limit for this campaign and to name the client that is claiming on the
 * attendee's behalf.
 *
 * The claim routes carry no auth middleware — an attendee's own unauthenticated claim has
 * to keep working exactly as it does today — so this asks the sanctum guard directly for
 * whatever the request is carrying, the same way the rate limiter and the controller both
 * need to, rather than the ability middleware the rest of the code API is gated by.
 *
 * A token is only good for a campaign its own account can already see. codes:create and
 * codes:status tokens are scoped to a campaign the same way, through CampaignPolicy::view
 * against the token's own user, because a Sanctum ability is a bare string and carries no
 * notion of "this campaign" by itself. Reapplying that check here is what stops a claim
 * token minted for one customer from raising another customer's claim limit merely by
 * naming their campaign in the URL.
 *
 * Anything that does not clear every check resolves to null rather than throwing: a
 * missing, expired, malformed, ability-less or wrongly-scoped token is exactly what an
 * ordinary public claim looks like, and the claim itself must never be refused for it.
 *
 * Checked with an exact match against the token's own abilities list, never
 * PersonalAccessToken::can(), because can() answers true for a wildcard token. The
 * profile page mints exactly that kind of token — ['*'], for the proxy API, valid 24
 * hours — and every campaign owner already holds one. Accepting can() here would let any
 * of them claim the elevated per-account limit and the forwarded-header trust with a
 * token that was never issued codes:claim.
 *
 * $campaign takes either the hydrated model or the raw route value. The claim-api rate
 * limiter runs before route model binding does — ThrottleRequests sits ahead of
 * SubstituteBindings in the framework's own middleware priority — so at that point
 * $request->route('campaign') is still the string the URL carried, not a Campaign. The
 * lookup this does for that case only runs once a token has already passed the guard and
 * ability checks above, so an ordinary public claim, which is nearly all claim traffic,
 * never pays for it.
 */
final class ClaimToken
{
    public static function resolve(Request $request, Campaign|string $campaign): ?PersonalAccessToken
    {
        $user = Auth::guard('sanctum')->setRequest($request)->user();

        if ($user === null) {
            return null;
        }

        $token = $user->currentAccessToken();

        // A first-party browser session authenticates through this same guard and carries
        // a TransientToken, which has no id and no real abilities list to check, and is
        // never eligible here. Only an actual Sanctum personal access token — the kind
        // api:token mints — is.
        if (! $token instanceof PersonalAccessToken || ! in_array(ApiAbilities::CODES_CLAIM, $token->abilities ?? [], true)) {
            return null;
        }

        $campaign = $campaign instanceof Campaign ? $campaign : Campaign::find($campaign);

        if ($campaign === null || ! $user->can('view', $campaign)) {
            return null;
        }

        return $token;
    }
}
