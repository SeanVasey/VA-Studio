<?php

namespace Tests\Unit;

use Tests\Support\DisposableNativeDatabase;
use Tests\TestCase;

/** The CI path is admitted only when every condition holds; the dedicated schema stays admitted as before. */
class DisposableNativeDatabaseTest extends TestCase
{
    private const VARIABLES = ['VA_CI_DISPOSABLE_MYSQL', 'CI', 'DB_DATABASE'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        parent::tearDown();
    }

    /** @param array<string, string|null> $values null unsets the variable */
    private function environment(array $values): void
    {
        foreach ($values as $name => $value) {
            putenv($value === null ? $name : $name.'='.$value);
        }
    }

    private function ci(): void
    {
        $this->environment(['VA_CI_DISPOSABLE_MYSQL' => '1', 'CI' => 'true', 'DB_DATABASE' => 'vaseyaudio_test']);
    }

    public function test_the_dedicated_schema_is_admitted_without_any_ci_marker(): void
    {
        $this->environment(['VA_CI_DISPOSABLE_MYSQL' => null, 'CI' => null, 'DB_DATABASE' => 'vaseyaudio_free_grants']);
        $this->assertTrue(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', 'vaseyaudio_free_grants'));
    }

    public function test_the_ci_jobs_disposable_database_is_admitted_when_every_condition_holds(): void
    {
        $this->ci();
        $this->assertTrue(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', 'vaseyaudio_test'));
    }

    public function test_a_missing_or_different_disposable_marker_is_refused(): void
    {
        foreach ([null, '0', 'true', ''] as $marker) {
            $this->ci();
            $this->environment(['VA_CI_DISPOSABLE_MYSQL' => $marker]);
            $this->assertFalse(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', 'vaseyaudio_test'), var_export($marker, true));
        }
    }

    public function test_a_run_outside_ci_is_refused(): void
    {
        foreach ([null, '1', 'false', ''] as $ci) {
            $this->ci();
            $this->environment(['CI' => $ci]);
            $this->assertFalse(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', 'vaseyaudio_test'), var_export($ci, true));
        }
    }

    public function test_an_environment_other_than_testing_is_refused(): void
    {
        $this->ci();
        $original = $this->app['env'];
        try {
            foreach (['local', 'staging', 'production'] as $environment) {
                $this->app['env'] = $environment;
                $this->assertFalse(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', 'vaseyaudio_test'), $environment);
            }
        } finally {
            $this->app['env'] = $original;
        }
    }

    public function test_a_connection_whose_database_is_not_the_selected_one_is_refused(): void
    {
        $this->ci();
        $this->assertFalse(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', 'vaseyaudio_other'));
        $this->environment(['DB_DATABASE' => null]);
        $this->assertFalse(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', 'vaseyaudio_test'));
    }

    public function test_an_empty_database_name_is_refused(): void
    {
        $this->ci();
        $this->environment(['DB_DATABASE' => '']);
        $this->assertFalse(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', ''));
        $this->assertFalse(DisposableNativeDatabase::isAdmitted('', ''));
    }

    public function test_the_application_schema_and_production_looking_names_are_refused(): void
    {
        foreach (['vaseyaudio', 'VaseyAudio', 'vaseyaudio_prod', 'production', 'vaseyaudio_live', ':memory:', 'vaseyaudio-test', str_repeat('a', 65)] as $name) {
            $this->ci();
            $this->environment(['DB_DATABASE' => $name]);
            $this->assertFalse(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants', $name), $name);
        }
    }
}
