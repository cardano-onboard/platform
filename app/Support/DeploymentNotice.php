<?php

namespace App\Support;

/**
 * Assembles the operator-facing deployment notice shared with Inertia pages.
 *
 * Built from config('notice.*') here rather than left as raw config() reads in the
 * middleware, so the shape handed to the frontend and the rule that drops a broken link
 * live in one place. share() returns null exactly when no message is configured, which is
 * what lets the middleware leave the prop out of the response entirely rather than pass a
 * null down that every layout would still have to check for.
 */
class DeploymentNotice
{
    /**
     * @return array{message: string, type: 'info'|'warning', link: ?array{url: string, text: string}}|null
     */
    public static function share(): ?array
    {
        $message = trim((string) config('notice.message'));

        if ($message === '') {
            return null;
        }

        return [
            'message' => $message,
            'type' => config('notice.type') === 'warning' ? 'warning' : 'info',
            'link' => static::link(),
        ];
    }

    /**
     * The configured link, or null when none is set or the URL is not http(s).
     *
     * @return array{url: string, text: string}|null
     */
    protected static function link(): ?array
    {
        $url = trim((string) config('notice.link.url'));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        if (! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        $text = trim((string) config('notice.link.text'));

        return [
            'url' => $url,
            'text' => $text !== '' ? $text : $url,
        ];
    }
}
