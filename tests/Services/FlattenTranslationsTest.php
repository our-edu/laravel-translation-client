<?php

declare(strict_types=1);

namespace OurEdu\TranslationClient\Tests\Services;

use Illuminate\Support\Facades\Log;
use OurEdu\TranslationClient\Services\TranslationClient;
use OurEdu\TranslationClient\Tests\TestCase;

/**
 * One flattening implementation, and it agrees with what the service accepts.
 *
 * There used to be two near-copies — `TranslationClient::flattenTranslations()`
 * and one in `TranslationProcessingTrait` — and §6.8 described them as
 * byte-for-byte identical. They were not. The trait skipped empty values and
 * the client did not, so the same lang file behaved differently depending on
 * which import command read it:
 *
 *   - `translations:import` pushed the blank, the service's
 *     `translations.*.value => required` rejected it, and the **whole batch**
 *     failed with a 422;
 *   - `translations:import-namespaced` dropped the key and reported success.
 *
 * Skipping is the correct half. Laravel's `required` rejects null, `[]`, `''`
 * *and* whitespace-only strings, so a blank is nothing the service can store.
 */
class FlattenTranslationsTest extends TestCase
{
    private function flatten(array $data, string $group = 'messages'): array
    {
        return (new TranslationClient())->flattenTranslations($data, 'ar', $group);
    }

    /** @return array<string, mixed> key => value */
    private function keyed(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }

        return $out;
    }

    public function test_a_populated_value_is_kept(): void
    {
        $this->assertSame(
            ['greeting' => 'مرحبا'],
            $this->keyed($this->flatten(['greeting' => 'مرحبا']))
        );
    }

    public function test_every_value_the_service_would_reject_is_dropped(): void
    {
        // Matches Laravel's `required`, which the write API applies to each value.
        $rows = $this->flatten([
            'kept' => 'value',
            'null' => null,
            'blank' => '',
            'spaces' => '   ',
            'tab' => "\t",
            'empty_array' => [],
        ]);

        $this->assertSame(['kept' => 'value'], $this->keyed($rows));
    }

    public function test_whitespace_only_is_dropped_which_the_old_trait_copy_missed(): void
    {
        // The trait compared `=== ''`, so '   ' slipped through and still 422'd
        // the batch. This is the case that made the two copies differ *and* the
        // survivor wrong.
        $this->assertSame([], $this->flatten(['spaces' => '   ']));
    }

    public function test_nested_groups_are_flattened_with_dotted_keys(): void
    {
        // Recursion needs a non-translatable array — one whose values are not
        // all strings. An all-string array is a value in its own right, see
        // below.
        $rows = $this->flatten(['auth' => ['nested' => ['failed' => 'Nope'], 'blank' => '']]);

        $this->assertSame(['auth.nested' => ['failed' => 'Nope']], $this->keyed($rows));
    }

    public function test_a_blank_inside_a_preserved_array_is_left_alone(): void
    {
        // The skip applies to a row's whole value, not to leaves inside one. A
        // non-empty array passes the service's `required`, so there is nothing
        // to reject and nothing to drop.
        $rows = $this->flatten(['size' => ['min' => 'Too small', 'max' => '']]);

        $this->assertSame(['size' => ['min' => 'Too small', 'max' => '']], $this->keyed($rows));
    }

    public function test_a_translatable_array_is_preserved_as_a_value(): void
    {
        // All-string arrays are values the API JSON-encodes, not groups to
        // flatten — pluralisation and validation shapes rely on this.
        $rows = $this->flatten(['size' => ['min' => 'Too small', 'max' => 'Too big']]);

        $this->assertSame(['size' => ['min' => 'Too small', 'max' => 'Too big']], $this->keyed($rows));
    }

    public function test_numeric_and_empty_keys_are_ignored(): void
    {
        $this->assertSame([], $this->flatten([0 => 'positional', '' => 'nameless']));
    }

    public function test_rows_carry_the_configured_client_and_prefixed_group(): void
    {
        config()->set('translation-client.app_name_prefix', 'PAYMENT');
        config()->set('translation-client.client', 'mobile');

        $rows = (new TranslationClient())->flattenTranslations(['greeting' => 'hi'], 'ar', 'messages');

        $this->assertSame('PAYMENT:messages', $rows[0]['group']);
        $this->assertSame('mobile', $rows[0]['client']);
    }

    public function test_dropped_keys_are_recorded_and_readable_once(): void
    {
        $client = new TranslationClient();
        $client->flattenTranslations(['blank' => '', 'kept' => 'v'], 'ar', 'messages');

        $skipped = $client->takeSkippedKeys();

        $this->assertCount(1, $skipped);
        $this->assertStringContainsString('messages.blank', $skipped[0]);
        $this->assertSame([], $client->takeSkippedKeys(), 'reading clears, so a key is reported once');
    }

    public function test_dropping_a_key_is_logged_rather_than_silent(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'empty value') && $context['total'] === 2;
            });

        $client = new TranslationClient();
        $client->flattenTranslations(['a' => '', 'b' => null, 'c' => 'kept'], 'ar', 'messages');

        $client->reportSkippedKeys();
    }

    public function test_nothing_is_logged_when_nothing_was_dropped(): void
    {
        Log::shouldReceive('warning')->never();

        $client = new TranslationClient();
        $client->flattenTranslations(['a' => 'kept'], 'ar', 'messages');

        $client->reportSkippedKeys();
    }

    public function test_the_trait_no_longer_carries_its_own_copy(): void
    {
        $this->assertFalse(
            method_exists(\OurEdu\TranslationClient\Jobs\ImportNamespacedTranslationsJob::class, 'flattenTranslations'),
            'the namespaced job must flatten through TranslationClient, not a private near-copy'
        );
    }
}
