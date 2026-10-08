<?php

namespace Tests\Unit;

use Dotenv\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Keeps ops/production/env.production.example, ops/private-server/env.example and .env.example in step
 * with every env() key read in config/. Pure file inspection: no application boot, values or secrets.
 */
class ProductionEnvironmentTemplateTest extends TestCase
{
    private const TEMPLATE = 'ops/production/env.production.example';

    private const HOST_TEMPLATE = 'ops/private-server/env.example';

    /** Config files whose env() keys belong to the payment/production preparation families. */
    private const FAMILY_CONFIG = ['production_checkout', 'payments', 'commerce', 'contracts', 'delivery', 'customer',
        'customer-suppression', 'production-customer-identity', 'production-identity-smtp', 'memberships',
        'production-memberships', 'member-grants', 'free-grants', 'refund-resolution', 'unpaid-release', 'transactional-notifications'];

    /** Family config read in code only. Activation is a reviewed code change, never an environment switch. */
    private const CODE_LITERAL_FAMILY = ['customer-suppression', 'production-customer-identity', 'production-identity-smtp',
        'production-memberships', 'member-grants'];

    /**
     * Framework/other keys not yet documented in .env.example (recorded 2026-10-07). Shrink-only: a new
     * undocumented key fails, and documenting a listed key requires removing it here.
     */
    private const KNOWN_UNDOCUMENTED_IN_ENV_EXAMPLE = [
        'APP_PREVIOUS_KEYS', 'AUTH_GUARD', 'AUTH_MODEL', 'AUTH_PASSWORD_BROKER', 'AUTH_PASSWORD_RESET_TOKEN_TABLE',
        'AUTH_PASSWORD_TIMEOUT', 'AWS_ENDPOINT', 'AWS_URL', 'BEANSTALKD_QUEUE', 'BEANSTALKD_QUEUE_HOST',
        'BEANSTALKD_QUEUE_RETRY_AFTER', 'CACHE_STORAGE_DISK', 'CACHE_STORAGE_PATH',
        'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED', 'CONTACT_TEST_ORDER_INQUIRIES_ENABLED', 'DB_CACHE_CONNECTION',
        'DB_CACHE_LOCK_CONNECTION', 'DB_CACHE_LOCK_TABLE', 'DB_CACHE_TABLE', 'DB_CHARSET', 'DB_COLLATION', 'DB_ENCRYPT',
        'DB_FOREIGN_KEYS', 'DB_QUEUE', 'DB_QUEUE_CONNECTION', 'DB_QUEUE_TABLE', 'DB_SOCKET', 'DB_SSLMODE',
        'DB_TRUST_SERVER_CERTIFICATE', 'DB_URL', 'DISCOVERY_SITEMAP_ENABLED', 'DYNAMODB_CACHE_TABLE', 'DYNAMODB_ENDPOINT',
        'LOG_DAILY_DAYS', 'LOG_DEPRECATIONS_TRACE', 'LOG_PAPERTRAIL_HANDLER', 'LOG_SLACK_EMOJI', 'LOG_SLACK_USERNAME',
        'LOG_SLACK_WEBHOOK_URL', 'LOG_STDERR_FORMATTER', 'LOG_SYSLOG_FACILITY', 'MAIL_EHLO_DOMAIN', 'MAIL_LOG_CHANNEL',
        'MAIL_SENDMAIL_PATH', 'MAIL_URL', 'MEMCACHED_PASSWORD', 'MEMCACHED_PERSISTENT_ID', 'MEMCACHED_PORT',
        'MEMCACHED_USERNAME', 'MYSQL_ATTR_SSL_CA', 'PAPERTRAIL_PORT', 'PAPERTRAIL_URL', 'POSTMARK_API_KEY',
        'POSTMARK_MESSAGE_STREAM_ID', 'QUEUE_FAILED_DRIVER', 'REDIS_BACKOFF_ALGORITHM', 'REDIS_BACKOFF_BASE',
        'REDIS_BACKOFF_CAP', 'REDIS_CACHE_CONNECTION', 'REDIS_CACHE_DB', 'REDIS_CACHE_LOCK_CONNECTION', 'REDIS_CLUSTER',
        'REDIS_DB', 'REDIS_MAX_RETRIES', 'REDIS_PERSISTENT', 'REDIS_PREFIX', 'REDIS_QUEUE', 'REDIS_QUEUE_CONNECTION',
        'REDIS_URL', 'REDIS_USERNAME', 'RESEND_API_KEY', 'SESSION_CONNECTION', 'SESSION_COOKIE', 'SESSION_EXPIRE_ON_CLOSE',
        'SESSION_HTTP_ONLY', 'SESSION_PARTITIONED_COOKIE', 'SESSION_SAME_SITE', 'SESSION_SECURE_COOKIE', 'SESSION_STORE',
        'SESSION_TABLE', 'SLACK_BOT_USER_DEFAULT_CHANNEL', 'SLACK_BOT_USER_OAUTH_TOKEN', 'SQS_PREFIX', 'SQS_QUEUE', 'SQS_SUFFIX',
        'VASEY_LISTENING_V2_PROMOTION_ENABLED', 'VASEY_LISTENING_V2_ROLLOUT_REVIEW_REFERENCE',
        'VASEY_SUPPORT_ATTACHMENTS_FIXTURE_ENABLED', 'VASEY_TEST_CUSTOMER_CONSENT_ENABLED',
        'VASEY_TEST_SERVICE_PROJECTS_ENABLED',
    ];

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    /** @return array<string, list<string>> key => config file basenames */
    private static function configKeys(): array
    {
        $keys = [];
        foreach (glob(self::path('config/*.php')) as $file) {
            preg_match_all("/env\\(\\s*'([A-Z0-9_]+)'/", file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                $keys[$key][] = basename($file, '.php');
            }
        }
        ksort($keys);

        return $keys;
    }

    private static function familyKeys(): array
    {
        return array_keys(array_filter(self::configKeys(), fn (array $files): bool => array_intersect($files, self::FAMILY_CONFIG) !== []));
    }

    /** @return array<string, string> documented key => value ('' for documented-unset `# KEY=` lines) */
    private static function documented(string $relative): array
    {
        preg_match_all('/^(#\s?)?([A-Z][A-Z0-9_]*)=(.*)$/m', file_get_contents(self::path($relative)), $matches, PREG_SET_ORDER);
        $keys = [];
        foreach ($matches as [, $comment, $key, $value]) {
            $keys[$key] = $comment === '' ? $value : '';
        }

        return $keys;
    }

    public function test_every_config_env_key_is_a_literal_and_covered_by_the_two_ops_templates(): void
    {
        foreach (glob(self::path('config/*.php')) as $file) {
            $this->assertSame(0, preg_match('/\benv\(\s*[^\s\']/', file_get_contents($file)), basename($file).' has a non-literal env() key.');
        }
        $keys = array_keys(self::configKeys());
        $this->assertGreaterThan(150, count($keys));
        $covered = array_keys(self::documented(self::TEMPLATE) + self::documented(self::HOST_TEMPLATE));
        $this->assertSame([], array_values(array_diff($keys, $covered)), 'env() keys missing from both ops templates.');
        $this->assertSame([], array_values(array_diff(array_keys(self::documented(self::TEMPLATE)), $keys)), 'Template documents a key no config reads.');
    }

    public function test_every_family_key_is_annotated_in_the_production_template_and_documented_in_env_example(): void
    {
        $family = self::familyKeys();
        $this->assertGreaterThanOrEqual(46, count($family));
        $this->assertSame([], array_values(array_diff($family, array_keys(self::documented(self::TEMPLATE)))));
        $this->assertSame([], array_values(array_diff($family, array_keys(self::documented('.env.example')))));
        foreach (['PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY', 'PRODUCTION_CHECKOUT_PROVIDER_IO_ENABLED', 'STRIPE_WEBHOOK_SECRET'] as $key) {
            $this->assertContains($key, $family);
        }
    }

    public function test_non_family_keys_outside_env_example_are_only_the_recorded_shrink_only_set(): void
    {
        $documented = array_keys(self::documented('.env.example'));
        $nonFamily = array_diff(array_keys(self::configKeys()), self::familyKeys());
        $undocumented = array_values(array_diff($nonFamily, $documented));
        sort($undocumented);
        $known = self::KNOWN_UNDOCUMENTED_IN_ENV_EXAMPLE;
        sort($known);
        $this->assertSame([], array_values(array_diff($undocumented, $known)), 'New config env() key is undocumented in .env.example.');
        $this->assertSame([], array_values(array_diff($known, $undocumented)), 'Remove now-documented or deleted keys from the recorded set.');
    }

    public function test_template_annotations_are_complete_and_secrets_and_flags_are_default_off(): void
    {
        $source = file_get_contents(self::path(self::TEMPLATE));
        $this->assertStringEndsWith("\n", $source);
        $this->assertStringNotContainsString("\r", $source);
        preg_match_all('/^# ([A-Z][A-Z0-9_]*) \| owner: (Sean|host|provider) \| secret: (yes|no) \| default: (\S.*)$/m', $source, $annotated, PREG_SET_ORDER);
        $annotations = [];
        foreach ($annotated as [, $key, $owner, $secret, $default]) {
            $this->assertArrayNotHasKey($key, $annotations, $key.' annotated twice.');
            $annotations[$key] = compact('owner', 'secret', 'default');
        }
        $this->assertSame(preg_match_all('/^# [A-Z][A-Z0-9_]* \\|/m', $source), count($annotations), 'Malformed annotation line.');

        $lines = explode("\n", rtrim($source, "\n"));
        $assigned = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^(# )?([A-Z][A-Z0-9_]*)=(.*)$/', $line, $match) !== 1) {
                $this->assertTrue($line === '' || str_starts_with($line, '#'), 'Unexpected line: '.$line);

                continue;
            }
            [, $comment, $key, $value] = $match;
            $this->assertArrayNotHasKey($key, $assigned, $key.' assigned twice.');
            $this->assertArrayHasKey($key, $annotations, $key.' lacks an owner/secret/default annotation.');
            // The annotation heads the comment block immediately above the assignment.
            for ($cursor = $index - 1; $cursor >= 0 && str_starts_with($lines[$cursor], '#   '); $cursor--) {
            }
            $this->assertStringStartsWith('# '.$key.' | owner: ', $lines[$cursor]);
            $unset = $comment !== '';
            $assigned[$key] = ['value' => $value, 'unset' => $unset];

            $annotation = $annotations[$key];
            if ($unset) {
                $this->assertSame('', $value, $key.' documented-unset line carries a value.');
                $this->assertStringStartsWith('unset', $annotation['default']);
            } elseif ($value === '') {
                $this->assertStringStartsWith('blank', $annotation['default']);
            } else {
                $this->assertStringStartsWith($value.' ', $annotation['default'].' ', $key.' default annotation differs from its value.');
            }
            if ($annotation['secret'] === 'yes') {
                $this->assertSame('', $value, $key.' is a secret and must stay blank.');
            }
            if (preg_match('/(?:SECRET|SECRET_KEY|PASSWORD|_TOKEN|API_KEY|ACCESS_KEY_ID|WEBHOOK_URL)\z/', $key) === 1) {
                $this->assertSame('yes', $annotation['secret'], $key.' looks secret but is not annotated as one.');
            }
            if (str_ends_with($key, '_ENABLED')) {
                $this->assertSame('false', $unset ? 'false' : $value, $key.' must be off.');
                $this->assertStringStartsWith('false', $annotation['default']);
            }
            $this->assertDoesNotMatchRegularExpression('/\A(?:sk|rk|pk)_(?:test|live)_|\Awhsec_|\Aacct_[A-Za-z0-9]|@|https?:/', $value, $key.' carries a credential, identifier, address or URL.');
        }
        $this->assertSame([], array_values(array_diff(array_keys($annotations), array_keys($assigned))), 'Annotation without an assignment.');
        $this->assertSame('test', $assigned['STRIPE_MODE']['value']);

        // phpdotenv accepts the file as-is; every active line parses as one defined assignment.
        $entries = (new Parser)->parse($source);
        $this->assertCount(count(array_filter($assigned, fn (array $a): bool => ! $a['unset'])), $entries);
    }

    public function test_keys_carried_by_both_templates_have_identical_values(): void
    {
        $production = self::documented(self::TEMPLATE);
        $host = self::documented(self::HOST_TEMPLATE);
        $shared = array_intersect_key($production, $host);
        $this->assertNotEmpty($shared);
        foreach ($shared as $key => $value) {
            $this->assertSame($host[$key], $value, $key.' differs between the production and private-server templates.');
            $this->assertContains($key, self::familyKeys(), $key.' is a host baseline key; keep it only in '.self::HOST_TEMPLATE.'.');
        }
    }

    public function test_code_literal_families_read_no_environment_and_stay_disabled(): void
    {
        $template = file_get_contents(self::path(self::TEMPLATE));
        foreach (self::CODE_LITERAL_FAMILY as $name) {
            $source = file_get_contents(self::path('config/'.$name.'.php'));
            $this->assertStringNotContainsString('env(', $source, $name);
            $config = require self::path('config/'.$name.'.php');
            $this->assertIsArray($config);
            $this->assertNotSame([], array_intersect_key($config, ['enabled' => true, 'settings' => true]), $name);
            $this->assertFalse($config['enabled'] ?? false, $name.' is not disabled.');
            $this->assertNull($config['settings'] ?? null, $name.' has settings.');
            $this->assertStringContainsString('config/'.$name.'.php', $template, $name.' is not described in the template.');
        }
    }
}
