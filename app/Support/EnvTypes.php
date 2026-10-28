<?php

namespace App\Support;

/**
 * What kind of value each environment variable holds, which is what decides whether a value
 * may be shown. A value is shown only when it has exactly the shape its variable's type allows,
 * and a variable with no type has no value shown at all: that is the whole policy, so a
 * variable nobody has typed cannot leak.
 *
 * Types:
 *   bool                 true, false, 1, 0, yes, no, on, off
 *   int, float           a number of at most 12 digits
 *   port                 0 to 65535
 *   enum:a|b|c           exactly one of the listed words
 *   host                 a host name or an IP address, in lower case
 *   hostport             a host, with a port if it has one
 *   cookiehost           a host, which may begin with a dot
 *   id                   a short name of lower case letters, digits and . _ -
 *   email                an address with a host in it
 *   url                  an address, shown without anything that could be a secret
 *   list:<type>          a comma separated list, each element of the type
 *
 * A type for a variable is written here, or comes from the PHP type of the default written in
 * the configuration when that is a boolean, an integer or a float. Free text, paths and every
 * other kind of string have no type.
 */
final class EnvTypes
{
    public const BY_NAME = [
        // switches that have no default to take the type from
        'SESSION_SECURE_COOKIE' => 'bool',

        // numbers that have no default
        'POO_PUSH_DELAY' => 'int',
        'POO_PUSH_DELAY_SECONDS' => 'int',
        'QR_EXPORT_WORK_BUDGET' => 'int',
        'PUSHER_APP_ID' => 'int',

        // ports, which some configuration files default as text
        'DB_PORT' => 'port',
        'DBSYNC_PREPROD_PORT' => 'port',
        'DBSYNC_MAINNET_PORT' => 'port',
        'REDIS_PORT' => 'port',
        'MAIL_PORT' => 'port',
        'MEMCACHED_PORT' => 'port',
        'PUSHER_PORT' => 'port',
        'PAPERTRAIL_PORT' => 'port',

        // words from a known set
        'APP_ENV' => 'enum:local|development|dev|testing|test|staging|stage|production|prod',
        'LOG_LEVEL' => 'enum:debug|info|notice|warning|error|critical|alert|emergency',
        'LOG_CHANNEL' => 'enum:stack|single|daily|slack|papertrail|stderr|syslog|errorlog|null|emergency',
        'LOG_DEPRECATIONS_CHANNEL' => 'enum:stack|single|daily|slack|papertrail|stderr|syslog|errorlog|null|emergency',
        'DB_CONNECTION' => 'enum:mysql|mariadb|pgsql|sqlite|sqlsrv',
        'QUEUE_CONNECTION' => 'enum:sync|database|beanstalkd|sqs|redis|deferred|background|failover|null',
        'QUEUE_FAILED_DRIVER' => 'enum:database-uuids|database|dynamodb|file|null',
        'CACHE_DRIVER' => 'enum:array|database|file|memcached|redis|dynamodb|octane|failover|null',
        'SESSION_DRIVER' => 'enum:file|cookie|database|apc|memcached|redis|dynamodb|array|null',
        'MAIL_MAILER' => 'enum:smtp|sendmail|mailgun|ses|ses-v2|postmark|resend|log|array|failover|roundrobin',
        'MAIL_ENCRYPTION' => 'enum:tls|ssl|starttls',
        'TRANSACTION_BACKEND' => 'enum:native|phyrhose|proxy|null',
        'FILESYSTEM_DISK' => 'enum:local|public|private|s3',
        'QR_STORAGE_DISK' => 'enum:local|public|private|s3',
        'BROADCAST_DRIVER' => 'enum:reverb|pusher|ably|redis|log|null',
        'REDIS_CLIENT' => 'enum:phpredis|predis',
        'REDIS_CLUSTER' => 'enum:redis|predis',
        'PUSHER_SCHEME' => 'enum:http|https',
        'APP_NOTICE_TYPE' => 'enum:info|warning',
        'CROSS_ORIGIN_OPENER_POLICY' => 'enum:same-origin|same-origin-allow-popups|unsafe-none',
        'CROSS_ORIGIN_RESOURCE_POLICY' => 'enum:same-origin|same-site|cross-origin',
        'CROSS_ORIGIN_EMBEDDER_POLICY' => 'enum:require-corp|credentialless|unsafe-none',
        'ALLOWED_NETWORKS' => 'list:enum:preprod|preview|mainnet',

        // hosts and lists of hosts
        'DB_HOST' => 'host',
        'DBSYNC_PREPROD_HOST' => 'host',
        'DBSYNC_MAINNET_HOST' => 'host',
        'REDIS_HOST' => 'host',
        'MEMCACHED_HOST' => 'host',
        'MAIL_HOST' => 'host',
        'MAIL_EHLO_DOMAIN' => 'host',
        'MAILGUN_DOMAIN' => 'host',
        'MAILGUN_ENDPOINT' => 'host',
        'CLAIM_DOMAIN' => 'host',
        'ADA_DOMAIN' => 'host',
        'PUSHER_HOST' => 'host',
        'PAPERTRAIL_URL' => 'host',
        'SESSION_DOMAIN' => 'cookiehost',
        'SANCTUM_STATEFUL_DOMAINS' => 'list:hostport',

        // short names
        'DB_DATABASE' => 'id',
        'DBSYNC_PREPROD_DATABASE' => 'id',
        'DBSYNC_MAINNET_DATABASE' => 'id',
        'AWS_DEFAULT_REGION' => 'id',
        'AWS_BUCKET' => 'id',
        'REDIS_DB' => 'id',
        'REDIS_CACHE_DB' => 'id',
        'REDIS_QUEUE' => 'id',
        'SQS_QUEUE' => 'id',
        'DYNAMODB_CACHE_TABLE' => 'id',
        'SESSION_CONNECTION' => 'id',
        'SESSION_STORE' => 'id',
        'MAIL_LOG_CHANNEL' => 'id',
        'QR_STORAGE_PATH' => 'id',
        'POSTMARK_MESSAGE_STREAM_ID' => 'id',
        'PUSHER_APP_CLUSTER' => 'id',

        // addresses
        'ADMIN_EMAIL' => 'email',
        'MAIL_FROM_ADDRESS' => 'email',
        'APP_URL' => 'url',
        'ASSET_URL' => 'url',
        'MAINNET_APP_URL' => 'url',
        'APP_NOTICE_LINK_URL' => 'url',
        'KOIOS_MAINNET_URL' => 'url',
        'KOIOS_PREPROD_URL' => 'url',
        'KOIOS_PREVIEW_URL' => 'url',
        'PHYRHOSE_MAINNET_URL' => 'url',
        'PHYRHOSE_PREPROD_URL' => 'url',
        'PROXY_API_URL' => 'url',
        'NMKR_MAINNET_API_URL' => 'url',
        'NMKR_PREPROD_API_URL' => 'url',
        'AWS_URL' => 'url',
        'AWS_ENDPOINT' => 'url',
        'DYNAMODB_ENDPOINT' => 'url',
        'SQS_PREFIX' => 'url',
    ];

    /**
     * The type of a variable, or null when it has none and no value of it may be shown.
     *
     * @param  array{kind: string, value?: mixed}  $default  The default written in the configuration.
     */
    public static function of(string $name, array $default): ?string
    {
        if (isset(self::BY_NAME[$name])) {
            return self::BY_NAME[$name];
        }

        if (($default['kind'] ?? null) !== 'literal') {
            return null;
        }

        return match (true) {
            is_bool($default['value']) => 'bool',
            is_int($default['value']) => 'int',
            is_float($default['value']) => 'float',
            default => null,
        };
    }
}
